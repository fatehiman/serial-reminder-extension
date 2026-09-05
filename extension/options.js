import { getSettings, setSettings, DEFAULT_API_URL } from './lib/settings.js';

const $ = (id) => document.getElementById(id);

function say(text, kind = '') {
  const el = $('msg');
  el.textContent = text;
  el.className = 'msg ' + kind;
}

async function load() {
  const s = await getSettings();
  $('apiKey').value = s.apiKey;
  $('apiUrl').value = s.apiUrl || DEFAULT_API_URL;
  await showStatus();
}

async function showStatus() {
  const { status = {}, queue = [], providers = [] } = await chrome.storage.local.get({
    status: {}, queue: [], providers: [],
  });
  $('info').hidden = false;
  $('i-prov').textContent  = providers.length ? providers.map((p) => p.label || p.name).join(', ') : 'none yet';
  $('i-queue').textContent = String(queue.length);
  $('i-err').textContent   = status.lastError || 'none';

  const res = await chrome.runtime.sendMessage({ type: 'ping' }).catch(() => null);
  $('i-user').textContent = res && res.ok ? res.data.user.username : (res ? res.error : 'not connected');
}

$('form').addEventListener('submit', async (e) => {
  e.preventDefault();
  await setSettings({ apiKey: $('apiKey').value, apiUrl: $('apiUrl').value });
  const s = await getSettings();
  $('apiUrl').value = s.apiUrl;
  say('Saved.', 'ok');
  const res = await chrome.runtime.sendMessage({ type: 'refresh-providers' }).catch(() => null);
  if (res && !res.ok) say('Saved, but the server said: ' + res.error, 'bad');
  await showStatus();
});

$('test').addEventListener('click', async () => {
  await setSettings({ apiKey: $('apiKey').value, apiUrl: $('apiUrl').value });
  say('Checking…');
  const res = await chrome.runtime.sendMessage({ type: 'ping' }).catch(() => null);
  if (res && res.ok) say('Connected as "' + res.data.user.username + '". Everything is set up.', 'ok');
  else say(res ? res.error : 'The extension did not answer.', 'bad');
  await showStatus();
});

$('reload-prov').addEventListener('click', async () => {
  say('Downloading site scripts…');
  const res = await chrome.runtime.sendMessage({ type: 'refresh-providers' }).catch(() => null);
  say(res && res.ok ? res.count + ' site script(s) loaded.' : (res ? res.error : 'Failed.'),
      res && res.ok ? 'ok' : 'bad');
  await showStatus();
});

$('open-dash').addEventListener('click', () => chrome.runtime.sendMessage({ type: 'open-dashboard' }));

load();
