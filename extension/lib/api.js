import { getSettings, isConfigured } from './settings.js';

export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

async function request(path, { method = 'GET', body = null } = {}) {
  const settings = await getSettings();
  if (!isConfigured(settings)) {
    throw new ApiError('Not set up yet — open the extension options and paste your API key.', 0);
  }

  let res;
  try {
    res = await fetch(settings.apiUrl + path, {
      method,
      headers: {
        'Authorization': 'Bearer ' + settings.apiKey,
        'Content-Type': 'application/json',
      },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new ApiError('Cannot reach the server: ' + e.message, 0);
  }

  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { /* not JSON */ }

  if (!res.ok || (data && data.ok === false)) {
    const msg = (data && data.error) || ('Server returned HTTP ' + res.status);
    throw new ApiError(msg, res.status);
  }
  return data;
}

export const api = {
  ping:        () => request('/ping'),
  providers:   () => request('/providers'),
  serials:     () => request('/serials'),
  watch:       (payload) => request('/watch', { method: 'POST', body: payload }),
  check:       (serialId) => request('/check', { method: 'POST', body: serialId ? { serialId } : {} }),
  account:     (payload) => request('/account', { method: 'POST', body: payload }),
  sessionTicket: () => request('/session-ticket', { method: 'POST' }),
};
