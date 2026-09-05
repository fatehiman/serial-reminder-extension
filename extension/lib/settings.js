/**
 * The only two things the user configures: the API key and the API URL.
 */

export const DEFAULT_API_URL = 'https://serial-reminder.kimiasoft.com/api';

export async function getSettings() {
  const s = await chrome.storage.sync.get({ apiKey: '', apiUrl: DEFAULT_API_URL });
  return {
    apiKey: (s.apiKey || '').trim(),
    apiUrl: normalizeUrl(s.apiUrl) || DEFAULT_API_URL,
  };
}

export async function setSettings({ apiKey, apiUrl }) {
  await chrome.storage.sync.set({
    apiKey: (apiKey || '').trim(),
    apiUrl: normalizeUrl(apiUrl) || DEFAULT_API_URL,
  });
}

/** Accepts "example.com", "https://example.com/", "https://example.com/api". */
export function normalizeUrl(raw) {
  let u = (raw || '').trim();
  if (!u) return '';
  if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
  u = u.replace(/\/+$/, '');
  return u;
}

export function isConfigured(settings) {
  return Boolean(settings.apiKey && settings.apiUrl);
}
