/*! KAM Team // app.js — shared frontend behaviour.
 *
 * One file, one small module per behaviour. Every module is opt-in: it looks
 * for its own markup and exits quietly when the page does not use it, so the
 * same script is safe on the login page, the dashboards and the reports.
 *
 * Modules
 *   authTabs        sign-in / register tab switch
 *   passwordToggles show / hide password
 *   termsChecklist  read-to-the-end gate on the program manual
 *   scrollSpy       highlights the sidebar link for the section on screen
 *   notifications   notification bell: mark read, click-outside, bfcache
 *   countdowns      live lifecycle countdown
 *   tableHints      "scroll for more" affordance on wide tables
 *   copyButtons     copy-to-clipboard with feedback
 *   adminPanels     admin sidebar tabs and their panels
 *   statusFields    work tracker: fields that depend on the chosen status
 *   idleWatch       5-minute inactivity sign-out (all signed-in pages)
 */
(function () {
  'use strict';

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  function on(el, type, fn, opts) { if (el) el.addEventListener(type, fn, opts); }

  /* ------------------------------------------------------------------
   * Sign-in / register tabs
   * ---------------------------------------------------------------- */
  function authTabs() {
    var panels = { login: $('#panel-login'), register: $('#panel-register') };
    if (!panels.login || !panels.register) return;
    var tabs = $$('.tab');

    function show(mode) {
      Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== mode; });
      tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.mode === mode ? 'true' : 'false'); });
      var first = panels[mode].querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox])');
      if (first) first.focus();
    }

    $$('[data-mode]').forEach(function (el) {
      on(el, 'click', function () { show(el.dataset.mode); });
    });

    // Left/right arrows move between tabs, as a tablist should.
    tabs.forEach(function (tab, i) {
      on(tab, 'keydown', function (ev) {
        if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
        ev.preventDefault();
        var next = tabs[(i + (ev.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        next.focus();
        show(next.dataset.mode);
      });
    });
  }

  /* ------------------------------------------------------------------
   * Show / hide password
   * ---------------------------------------------------------------- */
  function passwordToggles() {
    $$('.pw-toggle').forEach(function (btn) {
      btn.setAttribute('aria-pressed', 'false');
      on(btn, 'click', function () {
        var input = btn.parentElement.querySelector('input');
        if (!input) return;
        var visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        btn.textContent = visible ? 'Show' : 'Hide';
        btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
        btn.setAttribute('aria-label', (visible ? 'Show' : 'Hide') + ' password');
      });
    });
  }

  /* ------------------------------------------------------------------
   * Program terms: the accept box unlocks only after the rules are read
   * ---------------------------------------------------------------- */
  function termsChecklist() {
    var root = $('#terms-checklist');
    if (!root) return;

    var box    = $('input[data-terms]', root);
    var open   = $('[data-open]', root);
    var dlg    = $('#terms-dlg');
    var body   = $('.terms-dlg-body', dlg);
    var agree  = $('[data-agree]', dlg);
    var submit = root.dataset.submit ? document.getElementById(root.dataset.submit) : null;

    function refresh() {
      root.classList.toggle('done', box.checked);
      if (submit) submit.disabled = !box.checked;
    }
    function checkRead() {
      if (body.scrollTop + body.clientHeight >= body.scrollHeight - 8) {
        agree.disabled = false;
        dlg.classList.add('is-read');
      }
    }
    function close() { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }

    on(body, 'scroll', checkRead);
    on(open, 'click', function () {
      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
      body.scrollTop = 0;
      checkRead();
    });
    on($('[data-close]', dlg), 'click', close);
    on(dlg, 'click', function (ev) { if (ev.target === dlg) close(); });
    on(agree, 'click', function () {
      box.disabled = false;
      box.checked = true;
      open.textContent = 'Read again';
      close();
      refresh();
    });
    // Clicking a locked box opens the rules instead of doing nothing.
    on(box.closest('label'), 'click', function (ev) {
      if (box.disabled) { ev.preventDefault(); open.click(); }
    });
    on(box, 'change', refresh);
    refresh();
  }

  /* ------------------------------------------------------------------
   * Sidebar scroll spy
   * ---------------------------------------------------------------- */
  function scrollSpy() {
    var links = $$('.side-nav a[href^="#"]');
    if (!links.length || !('IntersectionObserver' in window)) return;

    var byId = {};
    links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        links.forEach(function (a) { a.classList.remove('active'); a.removeAttribute('aria-current'); });
        var link = byId[entry.target.id];
        if (link) { link.classList.add('active'); link.setAttribute('aria-current', 'true'); }
      });
    }, { rootMargin: '-30% 0px -60% 0px' });

    Object.keys(byId).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) io.observe(el);
    });
  }

  /* ------------------------------------------------------------------
   * Notification bell
   * ---------------------------------------------------------------- */
  function notifications() {
    var bell = $('.notif[data-csrf]');
    if (!bell) return;

    on(bell, 'toggle', function () {
      if (!bell.open || !$('.notif-count', bell)) return;
      var form = new FormData();
      form.append('csrf', bell.dataset.csrf);
      fetch('/notifications.php', { method: 'POST', body: form, credentials: 'same-origin' })
        .then(function () {
          var count = $('.notif-count', bell);
          if (count) count.remove();
        })
        .catch(function () { /* the badge clears on the next page load */ });
    });

    on(document, 'click', function (ev) { if (bell.open && !bell.contains(ev.target)) bell.open = false; });
    on(document, 'keydown', function (ev) { if (ev.key === 'Escape' && bell.open) bell.open = false; });
  }

  /* ------------------------------------------------------------------
   * Lifecycle countdown
   * ---------------------------------------------------------------- */
  function countdowns() {
    $$('[data-countdown]').forEach(function (el) {
      var expires = +el.dataset.expires;
      var start   = +el.dataset.start;
      var skew    = +el.dataset.now - Date.now();     // trust the server clock
      var q       = function (key) { return $('[data-cd="' + key + '"]', el); };
      var pad     = function (n) { return n < 10 ? '0' + n : '' + n; };

      q('expiry').textContent = 'Expires ' + new Date(expires).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });

      (function tick() {
        var left = Math.max(0, expires - (Date.now() + skew));
        var secs = Math.floor(left / 1000);
        var days = Math.floor(secs / 86400);
        var hrs  = Math.floor(secs % 86400 / 3600);
        var mins = Math.floor(secs % 3600 / 60);

        q('d').textContent = days;
        q('h').textContent = pad(hrs);
        q('m').textContent = pad(mins);
        q('s').textContent = pad(secs % 60);
        q('total').textContent = left > 0
          ? Math.floor(secs / 3600).toLocaleString() + ' hours ' + pad(mins) + ' minutes remaining'
          : 'Account expired';

        var pct = Math.round(left / Math.max(expires - start, 1) * 100);
        var bar = q('bar');
        bar.style.width = pct + '%';
        bar.className = 'lifecycle-fill ' + (pct < 20 ? 'critical' : pct < 40 ? 'warn' : 'ok');
        el.classList.toggle('is-expired', left === 0);
        if (left > 0) setTimeout(tick, 1000 - (Date.now() % 1000));
      })();
    });
  }

  /* ------------------------------------------------------------------
   * Wide tables: show a hint while there is more to scroll
   * ---------------------------------------------------------------- */
  function tableHints() {
    $$('.table-scroll').forEach(function (box) {
      function sync() {
        var more = box.scrollWidth - box.clientWidth - box.scrollLeft > 4;
        box.classList.toggle('has-more', more);
      }
      on(box, 'scroll', sync, { passive: true });
      on(window, 'resize', sync);
      sync();
    });
  }

  /* ------------------------------------------------------------------
   * Copy to clipboard
   * ---------------------------------------------------------------- */
  function copyButtons() {
    $$('[data-copy]').forEach(function (btn) {
      on(btn, 'click', function () {
        var src = document.querySelector(btn.dataset.copy);
        if (!src) return;
        var text = src.value !== undefined ? src.value : src.textContent;
        var done = function () {
          var label = btn.textContent;
          btn.textContent = 'Copied';
          btn.classList.add('is-copied');
          setTimeout(function () { btn.textContent = label; btn.classList.remove('is-copied'); }, 1600);
        };
        if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () {});
        else { src.select && src.select(); document.execCommand('copy'); done(); }
      });
    });
  }

  /* ------------------------------------------------------------------
   * Admin dashboard: the sidebar tabs swap panels in place
   * ---------------------------------------------------------------- */
  function adminPanels() {
    var tabs = $$('.admin-tab');
    var panels = $$('.admin-panel');
    if (!tabs.length) return;

    function openPanel(tab) {
      var panel = document.getElementById('panel-' + tab.dataset.panel);
      if (!panel) return;
      tabs.forEach(function (t) { t.classList.remove('active'); t.removeAttribute('aria-current'); });
      panels.forEach(function (p) { p.classList.remove('active'); p.setAttribute('aria-hidden', 'true'); });
      tab.classList.add('active');
      tab.setAttribute('aria-current', 'true');
      panel.classList.add('active');
      panel.setAttribute('aria-hidden', 'false');
      history.replaceState(null, '', '#' + tab.dataset.panel);
    }

    tabs.forEach(function (tab) { on(tab, 'click', function () { openPanel(tab); }); });

    var hash = location.hash.replace('#', '');
    if (hash) {
      var match = tabs.filter(function (t) { return t.dataset.panel === hash; })[0];
      if (match) openPanel(match);
    }
  }

  /* ------------------------------------------------------------------
   * Work tracker: show only the fields the chosen status needs
   * ---------------------------------------------------------------- */
  function statusFields() {
    var select = $('#st-sel');
    if (!select) return;
    function sync() {
      $$('.st-extra').forEach(function (el) { el.hidden = el.dataset.for !== select.value; });
    }
    on(select, 'change', sync);
    sync();
  }

  /* ------------------------------------------------------------------
   * Inactivity sign-out
   *
   * The server owns the rule (api/lib/supabase.php, IDLE_TIMEOUT). This
   * mirrors the same clock so the user gets a warning and a one-click way
   * to stay, instead of losing a half-filled form without explanation.
   * Activity in any tab counts for all of them, via localStorage.
   * ---------------------------------------------------------------- */
  function idleWatch() {
    var body = document.body;
    var limit = +(body.dataset.idleTimeout || 0);
    if (!limit) return;

    var warnAt  = +(body.dataset.idleWarning || 60);
    var dialog  = $('#idle-dialog');
    var form    = $('#idle-logout');
    var counter = dialog && $('[data-idle-countdown]', dialog);
    var KEY     = 'kt:lastActivity';
    var PING_GAP = 60000;                 // tell the server at most once a minute

    var last     = Date.now();
    var lastPing = Date.now();
    var warned   = false;
    var out      = false;

    function store(ts) {
      try { localStorage.setItem(KEY, String(ts)); } catch (err) { /* private mode */ }
    }
    function read() {
      try {
        var raw = +localStorage.getItem(KEY);
        return raw > 0 ? raw : last;
      } catch (err) { return last; }
    }

    function ping() {
      if (!form) return;
      lastPing = Date.now();
      var data = new FormData();
      data.append('csrf', form.elements.csrf.value);
      fetch('/session.php', { method: 'POST', body: data, credentials: 'same-origin' })
        .catch(function () { /* offline: the local clock still protects the session */ });
    }

    function activity(force) {
      if (out) return;
      last = Date.now();
      store(last);
      hideWarning();
      if (force || Date.now() - lastPing > PING_GAP) ping();
    }

    function showWarning() {
      if (!dialog || warned) return;
      warned = true;
      dialog.hidden = false;
      body.classList.add('idle-warning');
      var stay = $('[data-idle-stay]', dialog);
      if (stay) stay.focus();
    }
    function hideWarning() {
      if (!dialog || !warned) return;
      warned = false;
      dialog.hidden = true;
      body.classList.remove('idle-warning');
    }

    function signOut() {
      if (out || !form) return;
      out = true;
      store(0);
      form.submit();
    }

    function tick() {
      if (out) return;
      var idle = Date.now() - read();
      var left = Math.ceil((limit * 1000 - idle) / 1000);

      if (left <= 0) { signOut(); return; }
      if (left <= warnAt) {
        showWarning();
        if (counter) counter.textContent = left;
      } else {
        hideWarning();
      }
    }

    // Real interaction only — a page that merely sits open does not count.
    ['mousedown', 'keydown', 'touchstart', 'pointerdown', 'wheel', 'submit', 'change'].forEach(function (type) {
      on(document, type, function () { activity(false); }, { passive: true, capture: true });
    });
    on(document, 'visibilitychange', function () { if (!document.hidden) tick(); });
    on(window, 'pageshow', function (ev) { if (ev.persisted) location.reload(); });

    // Buttons in the warning.
    if (dialog) {
      on($('[data-idle-stay]', dialog), 'click', function () { activity(true); });
      on($('[data-idle-out]', dialog), 'click', signOut);
      on(document, 'keydown', function (ev) {
        if (!warned) return;
        if (ev.key === 'Escape') activity(true);
        // Keep focus inside the warning while it is open.
        if (ev.key === 'Tab') {
          var focusable = $$('button', dialog);
          var first = focusable[0], lastEl = focusable[focusable.length - 1];
          if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); lastEl.focus(); }
          else if (!ev.shiftKey && document.activeElement === lastEl) { ev.preventDefault(); first.focus(); }
        }
      });
    }

    store(last);
    setInterval(tick, 1000);
    tick();
  }

  /* ---------------------------------------------------------------- */
  function boot() {
    [authTabs, passwordToggles, termsChecklist, scrollSpy, notifications,
     countdowns, tableHints, copyButtons, idleWatch].forEach(function (mod) {
      try { mod(); } catch (err) { if (window.console) console.error('[app]', mod.name, err); }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
