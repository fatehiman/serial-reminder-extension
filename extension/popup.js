const $ = (id) => document.getElementById(id);

$('dash').addEventListener('click', () => {
  chrome.runtime.sendMessage({ type: 'open-dashboard' });
  window.close();
});
$('opts').addEventListener('click', () => {
  chrome.runtime.openOptionsPage();
  window.close();
});

(async () => {
  const { queue = [], providers = [] } = await chrome.storage.local.get({ queue: [], providers: [] });

  const res = await chrome.runtime.sendMessage({ type: 'ping' }).catch(() => null);
  if (res && res.ok) {
    $('state').textContent = 'Connected as ' + res.data.user.username
      + ' · ' + providers.length + ' site script' + (providers.length === 1 ? '' : 's');
    $('state').className = 'state ok';
  } else {
    $('state').textContent = res ? res.error : 'Not connected.';
    $('state').className = 'state bad';
  }

  $('queue').textContent = queue.length
    ? queue.length + ' report(s) waiting to send'
    : 'Everything is sent.';

  // Is the tab we are looking at being tracked?
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || !tab.url) return;
  const covered = providers.some((p) => (p.matches || []).some((m) => {
    try {
      const host = m.split('://')[1].split('/')[0].replace(/^\*\./, '');
      return new URL(tab.url).hostname.endsWith(host);
    } catch { return false; }
  }));
  if (covered) {
    $('watching').hidden = false;
    $('watching').textContent = 'This site is being watched. Play an episode and it saves itself.';
  }
})();
