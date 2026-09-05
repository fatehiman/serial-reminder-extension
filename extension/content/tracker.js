/**
 * Serial Reminder — page tracker.
 *
 * Runs on the movie sites listed by the provider scripts. It works out which
 * show and episode the page is playing, then watches the <video> element and
 * reports real playing time back to the server.
 *
 * There is no eval() here on purpose. Provider scripts are data (URL patterns,
 * selectors, JSON paths), never code, so a provider file downloaded from the
 * server can never run arbitrary JavaScript in your browser.
 */
(() => {
  'use strict';
  if (window.__serialReminderLoaded) return;
  window.__serialReminderLoaded = true;

  const TICK_MS        = 2000;   // how often we look at the player
  const REPORT_MS      = 60000;  // how often we send progress
  const MIN_DELTA_SEC  = 15;     // do not send tiny scraps

  let provider   = null;
  let current    = null;   // { seriesKey, episode, ... } for the page we are on
  let pending    = 0;      // seconds played since the last report
  let lastTick   = 0;      // video.currentTime at the previous tick
  let lastReport = 0;      // Date.now() of the last report
  let lastUrl    = location.href;
  let enrichCache = new Map();

  /* ------------------------------------------------------------- helpers */

  const log = (...a) => { if (window.__srDebug) console.log('[serial-reminder]', ...a); };

  function digits(s) {
    return String(s == null ? '' : s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                                     .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
  }

  function toInt(v) {
    if (typeof v === 'number') return Math.trunc(v);
    const m = digits(v).match(/-?\d+/);
    return m ? parseInt(m[0], 10) : null;
  }

  /** Read "a.b.c" out of a nested object. */
  function path(obj, p) {
    if (!p) return obj;
    let node = obj;
    for (const part of String(p).split('.')) {
      if (node == null || typeof node !== 'object' || !(part in node)) return undefined;
      node = node[part];
    }
    return node;
  }

  /** Fill "{a.b}" placeholders. */
  function template(tpl, vars) {
    return String(tpl).replace(/\{([A-Za-z0-9_.\-]+)\}/g, (_, key) => {
      const v = path(vars, key);
      return v == null || typeof v === 'object' ? '' : String(v);
    });
  }

  function hasHoles(s) { return /\{[A-Za-z0-9_.\-]+\}/.test(s); }

  /** Fill "{...}" placeholders everywhere inside a nested object (a POST body). */
  function templateDeep(data, vars) {
    if (typeof data === 'string') return template(data, vars);
    if (Array.isArray(data)) return data.map((x) => templateDeep(x, vars));
    if (data && typeof data === 'object') {
      const out = {};
      for (const [k, v] of Object.entries(data)) out[template(k, vars)] = templateDeep(v, vars);
      return out;
    }
    return data;
  }

  /**
   * Turn words into values: {"season one": 1, "season two": 2}.
   * Exact match first, then "is this key inside the text", longest key first so
   * "chapter twelve" never matches a "chapter two" key.
   */
  function applyMap(value, map) {
    const needle = digits(String(value)).trim();
    for (const k of Object.keys(map)) {
      if (digits(k).trim() === needle) return map[k];
    }
    const keys = Object.keys(map).sort((a, b) => b.length - a.length);
    for (const k of keys) {
      const kk = digits(k).trim();
      if (kk && needle.includes(kk)) return map[k];
    }
    return null;
  }

  /**
   * First row of `rows` where every `match` key equals its (templated) value.
   * Lets a field say "the season whose id is the one this episode belongs to".
   */
  function findIn(rows, match, vars) {
    for (const row of rows || []) {
      if (!row || typeof row !== 'object') continue;
      let ok = true;
      for (const [p, wanted] of Object.entries(match)) {
        const want = typeof wanted === 'string' && wanted.includes('{') ? template(wanted, vars) : wanted;
        if (String(path(row, p)) !== String(want)) { ok = false; break; }
      }
      if (ok) return row;
    }
    return null;
  }

  /**
   * A field rule: a dot path, a "{...}" template, or
   * {path, as, map, find, extract, default}.
   */
  function field(rule, vars) {
    let as = null;
    let map = null;
    let find = null;
    let pick = null;
    let extract = null;
    let def;
    if (rule && typeof rule === 'object') {
      as = rule.as || null;
      map = rule.map || null;
      find = rule.find || null;
      pick = rule.pick || null;
      extract = rule.extract || null;
      def = rule.default;
      rule = rule.path != null ? rule.path : rule.template;
    }
    if (typeof rule !== 'string') return def;

    let value = rule.includes('{') ? template(rule, vars) : path(vars, rule);

    if (find) {
      value = findIn(Array.isArray(value) ? value : [], find, vars);
      if (value == null) return def;
      if (typeof pick === 'string') value = path(value, pick);
    }

    if (value == null || value === '') return def;

    // "extract": keep one group out of the text before anything else reads it.
    // Namava names an episode "فصل ۴ قسمت ۷" — two numbers in one string, so
    // "first integer found" would take the season for the episode number.
    if (typeof extract === 'string' && extract && typeof value !== 'object') {
      let m = null;
      try { m = new RegExp(extract).exec(String(value)); }
      catch (e) { log('bad extract pattern', extract, e.message); return def; }
      if (!m) return def;
      value = m[1] != null ? m[1] : m[0];
    }

    if (map && (typeof value === 'string' || typeof value === 'number')) {
      const mapped = applyMap(value, map);
      if (mapped == null) return def;
      value = mapped;
    }

    if (as === 'int') return toInt(value);
    if (as === 'float') { const n = parseFloat(digits(value)); return Number.isFinite(n) ? n : def; }
    if (as === 'bool') return Boolean(value);
    if (as === 'string') return String(value);
    if (as === 'duration') return toDuration(value);
    if (as === 'minutes') { const n = toInt(value); return n == null ? def : n * 60; }
    if (as === 'phone') return toPhone(value);
    return value;
  }

  /** "01:05:39" or "56:27" or "3600" -> seconds. */
  function toDuration(v) {
    const t = digits(String(v)).trim();
    const m = /^(?:(\d+):)?(\d{1,2}):(\d{2})$/.exec(t);
    if (m) return (Number(m[1] || 0) * 3600) + (Number(m[2]) * 60) + Number(m[3]);
    return toInt(t);
  }

  /**
   * Show an Iranian mobile the way people write it: 989133169571 and
   * +98 913 316 9571 both become 09133169571.
   */
  function toPhone(v) {
    const d = digits(String(v)).replace(/\D+/g, '');
    if (!d) return String(v).trim();
    if (d.length === 12 && d.startsWith('98')) return '0' + d.slice(2);
    if (d.length === 10 && d[0] === '9') return '0' + d;
    return d;
  }

  /* ------------------------------------------------------------ detection */

  /** Which provider script covers this page? */
  async function loadProvider() {
    const res = await chrome.runtime.sendMessage({ type: 'get-provider' }).catch(() => null);
    const list = (res && res.providers) || [];
    provider = list.find((p) => (p.matches || []).some(matchesPattern)) || null;
    log('provider:', provider && provider.name);
    return provider;
  }

  /** Chrome match pattern -> does it cover this page? */
  function matchesPattern(pattern) {
    const m = /^(\*|https?|file|ftp):\/\/(\*|\*\.[^/*]+|[^/*]*)(\/.*)$/.exec(pattern);
    if (!m) return false;
    const [, scheme, host, pathPart] = m;

    if (scheme !== '*' && scheme !== location.protocol.replace(':', '')) return false;

    if (host !== '*') {
      if (host.startsWith('*.')) {
        const base = host.slice(2);
        if (location.hostname !== base && !location.hostname.endsWith('.' + base)) return false;
      } else if (host !== location.hostname) {
        return false;
      }
    }

    const re = new RegExp('^' + pathPart.split('*').map(escapeRe).join('.*') + '$');
    return re.test(location.pathname + location.search);
  }

  const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  /**
   * Work out what is playing. Two ways, in order:
   *   1. "enrich" — ask the site's own API (exact numbers, no guessing)
   *   2. "fallback" — a regex over the page title
   */
  async function detect() {
    if (!provider || !provider.watch) return null;
    const w = provider.watch;

    const re = new RegExp(w.urlPattern || '.^');
    const m  = re.exec(location.href);
    if (!m) return null;

    const vars = { url: location.href, title: document.title, host: location.hostname };
    (w.urlFields || []).forEach((name, i) => { vars[name] = m[i + 1]; });

    let info = null;
    if (w.enrich) info = await enrich(w.enrich, vars);
    if (!info && w.fallback) info = fromFallback(w.fallback, vars);
    if (!info) return null;

    if (!info.seriesKey || !info.seriesTitle || info.episode == null) {
      log('not enough information, ignoring this page', info);
      return null;
    }
    info.provider = provider.name;
    info.season = info.season == null ? 1 : info.season;
    return info;
  }

  /** Ask the site's own API: exact numbers instead of guessing from the page. */
  async function enrich(spec, vars) {
    const url = template(spec.url || '', vars);
    if (!url || hasHoles(url)) return null;

    // GraphQL sites need a POST with a JSON body.
    //
    // "same-origin" still sends the site's own cookies to its own API, which is
    // what most providers need. Do not switch this to "include" lightly: sheyda's
    // gateway answers 504 to a cross-origin request that carries credentials.
    // A provider can still ask for it with "credentials": "include".
    const method = (spec.method || 'GET').toUpperCase();
    const init = {
      method,
      credentials: spec.credentials || 'same-origin',
      headers: { 'Accept': 'application/json', ...(spec.headers || {}) },
    };
    if (method !== 'GET' && spec.body) {
      const body = JSON.stringify(templateDeep(spec.body, vars));
      // Every episode uses the same GraphQL URL, so a hole here would silently
      // ask about the wrong thing.
      if (hasHoles(body)) { log('enrich body still has a placeholder', body.slice(0, 200)); return null; }
      init.headers['Content-Type'] = 'application/json';
      init.body = body;
    }

    // The cache key must include the body: with GraphQL the URL never changes.
    const cacheKey = method + ' ' + url + ' ' + (init.body || '');
    const ttl = (spec.cacheSeconds || 3600) * 1000;
    const hit = enrichCache.get(cacheKey);
    if (hit && Date.now() - hit.at < ttl) return hit.value;

    let data;
    try {
      const res = await fetch(url, init);
      if (!res.ok) { log('enrich HTTP', res.status, url); return null; }
      data = await res.json();
    } catch (e) {
      log('enrich failed', e.message, url);
      return null;
    }

    if (spec.requireTruthy && !path(data, spec.requireTruthy)) {
      log('not a serial (requireTruthy failed) — ignoring');
      enrichCache.set(cacheKey, { at: Date.now(), value: null });
      return null;
    }

    const scope = { ...vars, ...data };
    const out = {};
    for (const [name, rule] of Object.entries(spec.fields || {})) {
      out[name] = field(rule, scope);
    }
    for (const name of spec.lowercase || []) {
      if (typeof out[name] === 'string') out[name] = out[name].toLowerCase();
    }
    out.season  = toInt(out.season);
    out.episode = toInt(out.episode);

    enrichCache.set(cacheKey, { at: Date.now(), value: out });
    return out;
  }

  /** Last resort: pull the numbers out of the page title. */
  function fromFallback(spec, vars) {
    const source = spec.source === 'document.title'
      ? document.title
      : (spec.selector ? (document.querySelector(spec.selector)?.textContent || '') : document.title);

    const m = new RegExp(spec.pattern, spec.flags || '').exec(source.trim());
    if (!m) { log('fallback pattern did not match', source); return null; }

    const out = {};
    (spec.fields || []).forEach((name, i) => { out[name] = m[i + 1]; });
    out.season  = toInt(out.season) ?? 1;
    out.episode = toInt(out.episode);

    if (spec.seriesKeyFrom) {
      const base = out[spec.seriesKeyFrom] || '';
      out.seriesKey = 'title:' + base.trim().toLowerCase().replace(/\s+/g, '-');
    }
    if (spec.episodeUrl) out.episodeUrl = template(spec.episodeUrl, vars);
    if (spec.seriesUrl)  out.seriesUrl  = template(spec.seriesUrl, vars);
    return out;
  }

  /* -------------------------------------------------------------- account */

  /**
   * Which account is this site logged in with?
   *
   * Every platform signs you up by mobile number, and the subscription may sit
   * on a different number for each site. Reading it here, while something is
   * really playing, is proof that this account's subscription works.
   *
   * The number is read out of what the site already keeps in the browser, or
   * from the site's own profile API. Nothing is guessed and nothing is typed.
   */
  let accountDone = false;

  function readStore(kind, key) {
    try {
      if (kind === 'localStorage') return localStorage.getItem(key);
      if (kind === 'sessionStorage') return sessionStorage.getItem(key);
      if (kind === 'cookie') {
        for (const part of document.cookie.split(';')) {
          const i = part.indexOf('=');
          if (part.slice(0, i).trim() === key) return decodeURIComponent(part.slice(i + 1));
        }
        return null;
      }
    } catch (e) { log('storage read failed', kind, key, e.message); }
    return null;
  }

  /** Read a JWT's payload. We are reading our own token for a label, not trusting it. */
  function decodeJwt(token) {
    const parts = String(token).split('.');
    if (parts.length < 2) return null;
    let b64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
    b64 += '='.repeat((4 - (b64.length % 4)) % 4);
    try {
      const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
      return JSON.parse(new TextDecoder().decode(bytes));
    } catch (e) { log('jwt decode failed', e.message); return null; }
  }

  async function resolveAccount(spec, vars) {
    let data = null;
    const source = spec.source || 'url';

    if (source === 'url') {
      const url = template(spec.url || '', vars);
      if (!url || hasHoles(url)) return null;
      const init = {
        method: (spec.method || 'GET').toUpperCase(),
        credentials: spec.credentials || 'same-origin',
        headers: { Accept: 'application/json', ...(spec.headers || {}) },
      };
      if (init.method !== 'GET' && spec.body) {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(templateDeep(spec.body, vars));
      }
      try {
        const res = await fetch(url, init);
        if (!res.ok) { log('account HTTP', res.status, url); return null; }
        data = await res.json();
      } catch (e) { log('account fetch failed', e.message); return null; }
    } else {
      const raw = readStore(source, spec.key || '');
      if (!raw) return null;
      const decode = spec.decode || 'json';
      if (decode === 'jwt') data = decodeJwt(raw);
      else if (decode === 'none') data = { value: raw };
      else { try { data = JSON.parse(raw); } catch (e) { log('account json failed', e.message); return null; } }
      if (!data) return null;
    }

    const scope = { ...vars, ...data };
    const out = {};
    for (const [name, rule] of Object.entries(spec.fields || {})) out[name] = field(rule, scope);
    if (!out.label) { log('account had no label', out); return null; }
    return out;
  }

  /** Once per page, after we know something is really playing. */
  async function reportAccountOnce() {
    if (accountDone || !provider || !provider.account || !current) return;
    accountDone = true;

    const acct = await resolveAccount(provider.account, {
      url: location.href, title: document.title, host: location.hostname,
    });
    if (!acct) return;

    chrome.runtime.sendMessage({
      type: 'account',
      account: {
        provider: provider.name,
        label: String(acct.label),
        name: acct.name ? String(acct.name) : null,
        note: acct.note ? String(acct.note) : null,
        refreshHours: Number(provider.account.refreshHours) || 20,
      },
    }).catch(() => { accountDone = false; });   // service worker asleep: try again
    log('account', provider.name, acct.label);
  }

  /* --------------------------------------------------------------- player */

  function findVideo() {
    const sel = (provider && provider.watch && provider.watch.video && provider.watch.video.selector) || 'video';
    const list = [...document.querySelectorAll(sel)];
    // The one that is actually playing wins; otherwise the longest one.
    return list.find((v) => !v.paused && !v.ended && v.readyState > 2)
        || list.sort((a, b) => (b.duration || 0) - (a.duration || 0))[0]
        || null;
  }

  function tick() {
    if (location.href !== lastUrl) {
      onNavigate();
      return;
    }
    if (!current) return;

    const video = findVideo();
    if (!video) return;

    const now = video.currentTime;

    // Count only forward, normal-speed playback. A seek or a rewind adds nothing.
    if (!video.paused && !video.ended && lastTick > 0) {
      const step = now - lastTick;
      const maxStep = (TICK_MS / 1000) * Math.max(1, video.playbackRate) * 2.5;
      if (step > 0 && step <= maxStep) pending += step;
    }
    lastTick = now;

    if (Number.isFinite(video.duration) && video.duration > 0) current.duration = Math.round(video.duration);
    current.position = Math.round(now);

    // The player is running, so this account really does have a subscription.
    if (!video.paused && pending > 0) reportAccountOnce();

    const nearEnd = current.duration > 0 && now >= current.duration - 30;
    if (video.ended || nearEnd) current.ended = true;

    const due = Date.now() - lastReport >= REPORT_MS;
    if ((due && pending >= MIN_DELTA_SEC) || (current.ended && pending > 0)) report();
  }

  function report(final = false) {
    if (!current) return;
    const delta = Math.round(pending);
    if (delta < 1 && !final && !current.ended) return;

    pending = 0;
    lastReport = Date.now();

    chrome.runtime.sendMessage({
      type: 'report',
      report: {
        provider:     current.provider,
        seriesKey:    String(current.seriesKey),
        seriesTitle:  current.seriesTitle,
        seriesUrl:    current.seriesUrl || null,
        poster:       current.poster || null,
        season:       current.season,
        episode:      current.episode,
        episodeTitle: current.episodeTitle || null,
        episodeUrl:   current.episodeUrl || location.href,
        position:     current.position || 0,
        duration:     current.duration || 0,
        watchedDelta: delta,
        ended:        Boolean(current.ended),
      },
    }).catch(() => { pending += delta; });   // service worker asleep: keep the time
    log('reported', current.seriesTitle, 'S' + current.season + 'E' + current.episode, '+' + delta + 's');
  }

  /* ------------------------------------------------------------ lifecycle */

  async function onNavigate() {
    if (current && pending > 0) report(true);
    lastUrl = location.href;
    current = null;
    pending = 0;
    lastTick = 0;
    accountDone = false;

    if (!provider) return;
    current = await detect();
    if (current) {
      lastReport = Date.now();
      log('now tracking', current);
    }
  }

  async function start() {
    if (!(await loadProvider())) return;
    await onNavigate();

    setInterval(tick, TICK_MS);

    // Single page apps change the URL without reloading, so watch for that too.
    for (const evt of ['popstate', 'hashchange']) window.addEventListener(evt, onNavigate);
    for (const fn of ['pushState', 'replaceState']) {
      const orig = history[fn];
      history[fn] = function (...args) { const r = orig.apply(this, args); setTimeout(onNavigate, 50); return r; };
    }

    // Do not lose the last minute when the tab is closed or hidden.
    document.addEventListener('visibilitychange', () => { if (document.hidden) report(true); });
    window.addEventListener('pagehide', () => report(true));
  }

  start();
})();
