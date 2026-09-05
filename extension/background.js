import { api, ApiError } from './lib/api.js';
import { getSettings, isConfigured } from './lib/settings.js';

const PROVIDER_ALARM = 'refresh-providers';
const QUEUE_ALARM    = 'flush-queue';
const SCRIPT_ID      = 'sr-tracker';

/* ------------------------------------------------------------ provider list */

/**
 * Provider scripts live on the server, not in this extension. That is the whole
 * point: to support a new movie site you upload one JSON file to the server and
 * every browser picks it up on its own — no new extension version.
 */
async function refreshProviders({ quiet = true } = {}) {
  const settings = await getSettings();
  if (!isConfigured(settings)) {
    await setStatus({ providers: 0, lastError: 'No API key set.' });
    return [];
  }
  try {
    const res = await api.providers();
    const providers = Array.isArray(res.providers) ? res.providers : [];
    await chrome.storage.local.set({ providers, providersHash: res.hash, providersAt: Date.now() });
    await registerTracker(providers);
    await setStatus({ providers: providers.length, lastError: null, lastSync: Date.now() });
    return providers;
  } catch (e) {
    if (!quiet) throw e;
    await setStatus({ lastError: e.message });
    const { providers = [] } = await chrome.storage.local.get({ providers: [] });
    await registerTracker(providers);   // keep working with the cached copy
    return providers;
  }
}

/** Inject the tracker only on the sites the provider scripts actually cover. */
async function registerTracker(providers) {
  const matches = [];
  for (const p of providers) {
    for (const m of p.matches || []) {
      if (typeof m === 'string' && m.includes('://')) matches.push(m);
    }
  }

  try {
    const existing = await chrome.scripting.getRegisteredContentScripts({ ids: [SCRIPT_ID] });
    if (existing.length) await chrome.scripting.unregisterContentScripts({ ids: [SCRIPT_ID] });
  } catch { /* nothing registered yet */ }

  if (!matches.length) return;

  try {
    await chrome.scripting.registerContentScripts([{
      id: SCRIPT_ID,
      js: ['content/tracker.js'],
      matches,
      runAt: 'document_idle',
      allFrames: false,
      persistAcrossSessions: true,
    }]);
  } catch (e) {
    console.warn('[serial-reminder] could not register the tracker:', e.message, matches);
    await setStatus({ lastError: 'Cannot watch these sites: ' + e.message });
  }
}

/* ------------------------------------------------------------ report queue */

/**
 * Watch reports must not be lost when the network or the server is down, so they
 * are queued in local storage and retried. Reports for the same episode are
 * merged, and their watch time added together.
 */
async function enqueue(report) {
  const { queue = [] } = await chrome.storage.local.get({ queue: [] });
  const key = [report.provider, report.seriesKey, report.season, report.episode].join('|');
  const found = queue.find((q) => q._key === key);

  if (found) {
    found.watchedDelta = (found.watchedDelta || 0) + (report.watchedDelta || 0);
    found.position = Math.max(found.position || 0, report.position || 0);
    found.duration = report.duration || found.duration;
    found.ended = found.ended || report.ended;
  } else {
    queue.push({ ...report, _key: key });
  }
  await chrome.storage.local.set({ queue: queue.slice(-200) });
  return flushQueue();
}

let flushing = false;

async function flushQueue() {
  if (flushing) return { sent: 0, left: -1 };
  const settings = await getSettings();
  if (!isConfigured(settings)) return { sent: 0, left: -1 };

  flushing = true;
  let sent = 0;
  try {
    for (;;) {
      const { queue = [] } = await chrome.storage.local.get({ queue: [] });
      if (!queue.length) break;

      const item = queue[0];
      const { _key, ...payload } = item;
      try {
        const res = await api.watch(payload);
        sent++;
        await chrome.storage.local.set({ queue: queue.slice(1) });
        await setStatus({ lastError: null, lastSent: Date.now() });

        // A show we just started following has no episode list yet — ask the
        // server to look it up now instead of waiting for the hourly cron.
        if (res && res.newSerial) {
          api.check(res.serialId).catch(() => {});
        }
      } catch (e) {
        if (e instanceof ApiError && e.status >= 400 && e.status < 500 && e.status !== 429) {
          // The server will never accept this one. Drop it, do not block the rest.
          console.warn('[serial-reminder] dropping a bad report:', e.message, payload);
          await chrome.storage.local.set({ queue: queue.slice(1) });
          await setStatus({ lastError: e.message });
          continue;
        }
        await setStatus({ lastError: e.message });   // retry later
        break;
      }
    }
  } finally {
    flushing = false;
  }
  const { queue = [] } = await chrome.storage.local.get({ queue: [] });
  return { sent, left: queue.length };
}

/* --------------------------------------------------------------- accounts */

/**
 * Which mobile number each site is logged in with. Sent up when it changes, and
 * otherwise at most once every refreshHours, so it is a cheap heartbeat rather
 * than a call on every page.
 */
async function recordAccount(acct) {
  if (!acct || !acct.provider || !acct.label) return;

  const { accountSent = {} } = await chrome.storage.local.get({ accountSent: {} });
  const previous = accountSent[acct.provider];
  const hours = Number(acct.refreshHours) || 20;
  const fresh = previous
    && previous.label === acct.label
    && Date.now() - (previous.at || 0) < hours * 3600 * 1000;

  if (fresh) return;

  try {
    await api.account({
      provider: acct.provider,
      label: acct.label,
      name: acct.name || null,
      note: acct.note || null,
    });
    accountSent[acct.provider] = { label: acct.label, at: Date.now() };
    await chrome.storage.local.set({ accountSent });
    await setStatus({ lastError: null });
  } catch (e) {
    await setStatus({ lastError: e.message });
  }
}

/* ------------------------------------------------------------------ status */

async function setStatus(patch) {
  const { status = {} } = await chrome.storage.local.get({ status: {} });
  await chrome.storage.local.set({ status: { ...status, ...patch } });
}

/* ---------------------------------------------------------------- dashboard */

/**
 * Open the dashboard already logged in: swap the API key for a one-time ticket
 * so the user never sees a login form.
 */
async function openDashboard() {
  const settings = await getSettings();
  const base = settings.apiUrl.replace(/\/api$/, '');
  let url = base + '/dashboard';
  try {
    const res = await api.sessionTicket();
    if (res && res.url) url = res.url;
  } catch (e) {
    console.warn('[serial-reminder] no login ticket, opening the plain dashboard:', e.message);
  }
  await chrome.tabs.create({ url });
}

/* ----------------------------------------------------------------- wiring */

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  (async () => {
    switch (msg && msg.type) {
      case 'get-provider': {
        const { providers = [] } = await chrome.storage.local.get({ providers: [] });
        sendResponse({ ok: true, providers });
        break;
      }
      case 'report':
        await enqueue(msg.report);
        sendResponse({ ok: true });
        break;
      case 'account':
        await recordAccount(msg.account);
        sendResponse({ ok: true });
        break;
      case 'open-dashboard':
        await openDashboard();
        sendResponse({ ok: true });
        break;
      case 'refresh-providers':
        try {
          const p = await refreshProviders({ quiet: false });
          sendResponse({ ok: true, count: p.length });
        } catch (e) {
          sendResponse({ ok: false, error: e.message });
        }
        break;
      case 'flush':
        sendResponse({ ok: true, ...(await flushQueue()) });
        break;
      case 'ping':
        try {
          sendResponse({ ok: true, data: await api.ping() });
        } catch (e) {
          sendResponse({ ok: false, error: e.message });
        }
        break;
      default:
        sendResponse({ ok: false, error: 'unknown message' });
    }
  })();
  return true;   // keep the channel open for the async reply
});

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create(PROVIDER_ALARM, { periodInMinutes: 360, delayInMinutes: 1 });
  chrome.alarms.create(QUEUE_ALARM, { periodInMinutes: 5, delayInMinutes: 2 });
  refreshProviders();
});

chrome.runtime.onStartup.addListener(() => { refreshProviders(); flushQueue(); });

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === PROVIDER_ALARM) refreshProviders();
  if (alarm.name === QUEUE_ALARM) flushQueue();
});

// Changing the key or the URL means a different account or server: start fresh.
chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'sync' && (changes.apiKey || changes.apiUrl)) {
    chrome.storage.local.remove('accountSent');
    refreshProviders();
  }
});
