/* Dashboard actions. Authenticated by the session cookie plus the
   X-SR-Dashboard header (see Auth::requireApiUser). */
(function () {
  'use strict';

  function api(path, method, body) {
    return fetch('/api' + path, {
      method: method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-SR-Dashboard': '1' },
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; });
    });
  }

  function busy(el, on, label) {
    el.disabled = on;
    if (on) { el.dataset.old = el.textContent; el.textContent = label || '…'; }
    else if (el.dataset.old) { el.textContent = el.dataset.old; }
  }

  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-act]');
    if (!el) return;
    var act = el.dataset.act;
    var id = el.dataset.id;

    if (act === 'seen') {
      ev.preventDefault();
      busy(el, true, 'Saving…');
      api('/serials/' + id + '/mark-up-to', 'POST', {
        season: Number(el.dataset.season) || 1,
        episode: Number(el.dataset.episode)
      }).then(function (r) {
        if (r.ok) location.reload(); else { busy(el, false); alert(r.error || 'Failed'); }
      });
    }

    if (act === 'check') {
      ev.preventDefault();
      busy(el, true, 'Checking…');
      api('/check', 'POST', { serialId: Number(id) }).then(function (r) {
        if (r.ok && r.result && r.result.error) {
          busy(el, false);
          alert('Could not read the site: ' + r.result.error);
        } else if (r.ok) {
          location.reload();
        } else {
          busy(el, false);
          alert(r.error || 'Failed');
        }
      });
    }

    if (act === 'delete') {
      ev.preventDefault();
      var card = el.closest('.card');
      var name = card.querySelector('h3').textContent;
      if (!confirm('Stop following "' + name + '"? Its watch history is deleted too.')) return;
      busy(el, true);
      api('/serials/' + id, 'DELETE').then(function (r) {
        if (r.ok) card.remove(); else { busy(el, false); alert(r.error || 'Failed'); }
      });
    }
  });

  document.addEventListener('change', function (ev) {
    var el = ev.target.closest('select[data-act="status"]');
    if (!el) return;
    el.disabled = true;
    api('/serials/' + el.dataset.id, 'PATCH', { status: el.value }).then(function (r) {
      el.disabled = false;
      if (!r.ok) alert(r.error || 'Failed');
      else location.reload();
    });
  });

  // Type a card's number, then Enter (or wait a moment): open that card in
  // this tab. Uses ev.code, so it works whatever keyboard layout is active
  // (the TV box toggles us/fa). Used by the TV's voice remote.
  var typed = '', typedTimer, typedBox;
  function openTyped() {
    clearTimeout(typedTimer);
    var n = parseInt(typed, 10);
    typed = '';
    if (typedBox) { typedBox.remove(); typedBox = null; }
    var card = document.querySelectorAll('.grid .card')[n - 1];
    var link = card && card.querySelector('.card-link');
    if (link && link.getAttribute('href') !== '#') location.href = link.href;
  }
  document.addEventListener('keydown', function (ev) {
    if (ev.ctrlKey || ev.altKey || ev.metaKey || ev.target.closest('input, select, textarea')) return;
    var m = /^(?:Digit|Numpad)(\d)$/.exec(ev.code);
    if (m && typed.length < 3) {
      ev.preventDefault();
      typed += m[1];
      if (!typedBox) { typedBox = document.createElement('div'); typedBox.className = 'num-typed'; document.body.appendChild(typedBox); }
      typedBox.textContent = typed;
      clearTimeout(typedTimer);
      typedTimer = setTimeout(openTyped, 1500);
    } else if (typed && (ev.code === 'Enter' || ev.code === 'NumpadEnter')) {
      ev.preventDefault();
      openTyped();
    } else if (typed && ev.code === 'Escape') {
      typed = '';
      clearTimeout(typedTimer);
      if (typedBox) { typedBox.remove(); typedBox = null; }
    }
  });

  var all = document.getElementById('check-all');
  if (all) {
    all.addEventListener('click', function () {
      var out = document.getElementById('check-status');
      busy(all, true, 'Checking every show…');
      out.textContent = 'This can take a moment.';
      api('/check', 'POST', {}).then(function (r) {
        if (r.ok && r.result) {
          out.textContent = r.result.checked + ' checked, ' + r.result.added +
            ' new episode(s) found' + (r.result.errors ? ', ' + r.result.errors + ' failed' : '') + '.';
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          busy(all, false);
          out.textContent = r.error || 'Failed.';
        }
      });
    });
  }
})();
