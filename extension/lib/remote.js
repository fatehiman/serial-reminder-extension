/**
 * TV remote. On the TV box a small local service, Sound Commander, takes voice
 * and phone commands. The ones that act on Chrome — open the dashboard, open show
 * number N, play/pause or seek the video — come here over a WebSocket to
 * 127.0.0.1, because only the extension can do them reliably: typed keys land
 * wherever the focus happens to be (the address bar, and the digits become a
 * Google search).
 *
 * Off unless "TV remote" is ticked in the options, so other computers never try
 * to connect. When on, a lost connection is retried every minute.
 */
import { api } from './api.js';
import { getSettings } from './settings.js';

const REMOTE_URL = 'ws://127.0.0.1:8765';
export const REMOTE_ALARM = 'tv-remote';

let socket = null;

export async function isRemoteOn() {
  const { tvRemote = false } = await chrome.storage.local.get({ tvRemote: false });
  return tvRemote;
}

export async function setRemoteOn(on) {
  await chrome.storage.local.set({ tvRemote: Boolean(on) });
}

export function remoteConnected() {
  return Boolean(socket && socket.readyState === WebSocket.OPEN);
}

export async function startRemote() {
  if (!(await isRemoteOn())) {
    chrome.alarms.clear(REMOTE_ALARM);
    if (socket) socket.close();
    return;
  }
  chrome.alarms.create(REMOTE_ALARM, { periodInMinutes: 1 });
  connect();
}

function connect() {
  if (socket && socket.readyState <= WebSocket.OPEN) return;
  const ws = new WebSocket(REMOTE_URL);
  socket = ws;
  ws.onmessage = async (ev) => {
    let msg;
    try { msg = JSON.parse(ev.data); } catch { return; }
    let reply;
    try {
      reply = await handle(msg);
    } catch (e) {
      reply = { ok: false, error: e.message };
    }
    if (msg.id != null && ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ id: msg.id, ...reply }));
    }
  };
  ws.onclose = () => { if (socket === ws) socket = null; };
  ws.onerror = () => {};   // onclose follows; the alarm retries
}

/* ---------------------------------------------------------------- commands */

async function handle(msg) {
  switch (msg.cmd) {
    case 'ping':     return { ok: true };
    case 'focused':  return { ok: true, focused: Boolean((await focusedWindow())?.focused) };
    case 'index':    return showIndex();
    case 'pick':     return pickShow(Number(msg.n));
    case 'media':    return media(msg);
    case 'reload':
      setTimeout(() => chrome.runtime.reload(), 200);
      return { ok: true };
    default:         return { ok: false, error: 'unknown command ' + msg.cmd };
  }
}

async function focusedWindow() {
  try {
    return await chrome.windows.getLastFocused({ windowTypes: ['normal'] });
  } catch {
    return null;
  }
}

async function siteBase() {
  return (await getSettings()).apiUrl.replace(/\/api$/, '');
}

/** The dashboard, logged in, in the current tab — whatever played there stops. */
async function showIndex() {
  const base = await siteBase();
  let url = base + '/dashboard';
  try {
    const res = await api.sessionTicket();
    if (res && res.url) url = res.url;
  } catch { /* not set up, or offline: the plain dashboard may still be logged in */ }

  const win = await focusedWindow();
  if (!win) {
    await chrome.windows.create({ url, state: 'maximized' });
    return { ok: true };
  }
  const [tab] = await chrome.tabs.query({ active: true, windowId: win.id });
  if (tab) await chrome.tabs.update(tab.id, { url, active: true });
  else await chrome.tabs.create({ url, windowId: win.id });
  await chrome.windows.update(win.id, { focused: true });
  return { ok: true };
}

/** Open the Nth card of the dashboard (the number printed on its poster). */
async function pickShow(n) {
  if (!(n > 0)) return { ok: false, error: 'No number' };
  const base = await siteBase();
  const win = await focusedWindow();
  let [tab] = win ? await chrome.tabs.query({ active: true, windowId: win.id }) : [];
  if (!tab || !tab.url || !tab.url.startsWith(base + '/dashboard')) {
    const tabs = await chrome.tabs.query({ url: base + '/dashboard*' });
    tab = tabs.find((t) => t.active) || tabs[0];
  }
  if (!tab) return { ok: false, error: 'Say "open index" first' };

  const [{ result }] = await chrome.scripting.executeScript({
    target: { tabId: tab.id },
    args: [n],
    func: (n) => {
      const cards = document.querySelectorAll('.grid .card');
      const card = cards[n - 1];
      const link = card && card.querySelector('.card-link');
      return {
        count: cards.length,
        href: link && link.getAttribute('href') !== '#' ? link.href : null,
        title: card ? (card.querySelector('h3') || {}).textContent : null,
      };
    },
  });
  if (!result.href) {
    return { ok: false, error: result.title ? 'Show ' + n + ' has no link' : 'There is no show ' + n + ' (only ' + result.count + ')' };
  }
  await chrome.tabs.update(tab.id, { url: result.href, active: true });
  await chrome.windows.update(tab.windowId, { focused: true });
  return { ok: true, title: result.title };
}

/** Play/pause or seek the video in the active tab, in whichever frame has it. */
async function media({ op, steps = 5, seconds = 5, delay = 500 }) {
  const win = await focusedWindow();
  if (!win || !win.focused) return { ok: false, error: 'Chrome is not the active window' };
  const [tab] = await chrome.tabs.query({ active: true, windowId: win.id });
  if (!tab || !/^https?:/.test(tab.url || '')) return { ok: false, error: 'No web page' };

  const results = await chrome.scripting.executeScript({
    target: { tabId: tab.id, allFrames: true },
    args: [op, steps, seconds, delay],
    func: async (op, steps, seconds, delay) => {
      const videos = [...document.querySelectorAll('video')].filter((v) => v.readyState > 0);
      if (!videos.length) return null;
      const area = (v) => v.clientWidth * v.clientHeight;
      const v = videos.find((x) => !x.paused) || videos.sort((a, b) => area(b) - area(a))[0];
      if (op === 'playpause') {
        if (!v.paused) { v.pause(); return 'paused'; }
        try { await v.play(); } catch { return null; }   // autoplay blocked: let real keys do it
        return 'playing';
      }
      const sign = op === 'forward' ? 1 : -1;
      for (let i = 0; i < steps; i++) {
        if (i) await new Promise((r) => setTimeout(r, delay));
        v.currentTime = Math.max(0, v.currentTime + sign * seconds);
      }
      return (sign > 0 ? 'forward ' : 'back ') + steps * seconds + ' s';
    },
  }).catch(() => []);
  const hit = results.find((r) => r.result);
  return hit ? { ok: true, done: hit.result } : { ok: false, error: 'No video on this page' };
}
