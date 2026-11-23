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
 *   tableFilters    filter chips above a table (e.g. users by account type)
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
      if (btn.dataset.pwWired) return;   // already wired by a page fallback
      btn.dataset.pwWired = '1';
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
    if (!root || root.dataset.wired) return;    // inline fallback already wired it
    root.dataset.wired = '1';

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
      form.append('action', 'seen');
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
   * Filter chips: <div class="filter-bar" data-filter-for="TABLE_ID">
   * with <button data-filter="type">, rows carry data-types="a b".
   * The chosen filter is kept in the URL (?type=) so a reload or a
   * shared link opens on the same view.
   * ---------------------------------------------------------------- */
  // Delegated (one listener on document) and re-applied after a live
  // refresh, so the chips keep working when liveRefresh swaps the table in.
  function tableFilters() {
    function applyBar(bar, type) {
      var table = document.getElementById(bar.dataset.filterFor);
      if (!table) return;
      var chips = $$('[data-filter]', bar);
      var rows  = $$('tbody tr', table);
      var empty = document.getElementById(bar.dataset.filterFor + '-empty');
      if (!chips.some(function (c) { return c.dataset.filter === type; })) type = 'all';
      apply(type);

      function apply(type) {
        var shown = 0;
        rows.forEach(function (tr) {
          var match = type === 'all' || (' ' + (tr.dataset.types || '') + ' ').indexOf(' ' + type + ' ') !== -1;
          tr.hidden = !match;
          if (match) shown++;
        });
        chips.forEach(function (c) {
          var on = c.dataset.filter === type;
          c.classList.toggle('is-on', on);
          c.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (empty) empty.hidden = shown > 0;
        try {
          var url = new URL(location.href);
          if (type === 'all') url.searchParams.delete('type'); else url.searchParams.set('type', type);
          history.replaceState(null, '', url.pathname + url.search + location.hash);
        } catch (err) { /* old browser: filter still works, just not remembered */ }
      }
    }
    function applyAll() {
      var type = new URLSearchParams(location.search).get('type') || 'all';
      $$('.filter-bar[data-filter-for]').forEach(function (bar) { applyBar(bar, type); });
    }

    on(document, 'click', function (ev) {
      var chip = ev.target.closest && ev.target.closest('.filter-bar[data-filter-for] [data-filter]');
      if (chip) applyBar(chip.closest('.filter-bar'), chip.dataset.filter);
    });
    on(document, 'live:updated', applyAll);
    applyAll();
  }

  /* ------------------------------------------------------------------
   * Live data: every [data-live] region is re-fetched in the background
   * and swapped in when it changed, so admins see new sign-ups, status
   * changes and counts without reloading. A region is left alone while
   * someone is using it: focus inside, an edited field, or an open popup.
   * Requests carry ?auto=1, which the server does not count as activity
   * (the 5-minute idle logout still applies) and which leaves flash
   * messages unread.
   * ---------------------------------------------------------------- */
  function liveRefresh() {
    var regions = $$('[data-live]');
    if (!regions.length || !window.fetch || !window.DOMParser) return;
    var every = +(document.body.dataset.liveEvery || 8000);
    var busy = false, stopped = false;

    function dirty(el) {
      if (el.contains(document.activeElement) && document.activeElement !== document.body) return true;
      if (el.querySelector('dialog[open], details[open] form')) return true;
      return $$('input, select, textarea', el).some(function (f) {
        if (f.type === 'hidden' || f.type === 'submit' || f.type === 'button') return false;
        if (f.type === 'checkbox' || f.type === 'radio') return f.checked !== f.defaultChecked;
        if (f.tagName === 'SELECT') return [].some.call(f.options, function (o) { return o.selected !== o.defaultSelected; });
        if (f.type === 'file') return !!f.value;
        return f.value !== f.defaultValue;
      });
    }

    function tick() {
      if (busy || stopped || document.hidden) return;
      busy = true;
      var url = new URL(location.href);
      url.hash = '';
      url.searchParams.set('auto', '1');
      fetch(url.toString(), { credentials: 'same-origin', headers: { 'X-Live': '1' } })
        .then(function (res) {
          // Signed out or session ended: stop; the idle watchdog takes over.
          if (!res.ok || res.redirected) { stopped = true; return null; }
          return res.text();
        })
        .then(function (html) {
          if (!html) return;
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var changed = false;
          $$('[data-live]').forEach(function (el) {
            var fresh = doc.querySelector('[data-live="' + el.dataset.live + '"]');
            if (!fresh || fresh.innerHTML === el.innerHTML || dirty(el)) return;
            el.innerHTML = fresh.innerHTML;
            if (fresh.hidden !== el.hidden) el.hidden = fresh.hidden;
            changed = true;
          });
          if (changed) document.dispatchEvent(new CustomEvent('live:updated'));
        })
        ['catch'](function () { /* offline for a moment: try again next tick */ })
        .then(function () { busy = false; });
    }

    setInterval(tick, every);
    on(document, 'visibilitychange', function () { if (!document.hidden) tick(); });
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
      data.append('action', 'ping');
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

  /* ------------------------------------------------------------------
   * Search + filter chips for a table:
   *   <div class="net-tools" data-net-for="TABLE_ID">
   *     <input type="search" class="net-q">
   *     <div class="filter-bar"><button data-net-group="status" data-net-val="all">…
   *     <span class="net-count"></span>
   *   </div>
   * Rows carry data-q="searchable text" and data-tags="status:active plan:basic".
   * State is kept per table in memory and re-applied after a live refresh.
   * ---------------------------------------------------------------- */
  function netFilters() {
    var state = {};

    function stateFor(id) { return state[id] || (state[id] = { q: '', g: {} }); }

    function apply(tools) {
      var id = tools.dataset.netFor;
      var table = document.getElementById(id);
      if (!table) return;
      var st = stateFor(id);
      var input = $('.net-q', tools);
      if (input && input.value !== st.q) input.value = st.q;
      var words = st.q.toLowerCase().split(/\s+/).filter(Boolean);
      var groups = Object.keys(st.g).filter(function (k) { return st.g[k] && st.g[k] !== 'all'; });
      // Only the table's own rows — not rows of tables inside its pop-ups.
      var rows = table.tBodies[0] ? Array.prototype.slice.call(table.tBodies[0].rows) : [], shown = 0;
      rows.forEach(function (tr) {
        var text = (tr.dataset.q || tr.textContent).toLowerCase();
        var tags = ' ' + (tr.dataset.tags || '') + ' ';
        var ok = words.every(function (w) { return text.indexOf(w) !== -1; })
              && groups.every(function (k) { return tags.indexOf(' ' + k + ':' + st.g[k] + ' ') !== -1; });
        tr.hidden = !ok;
        if (ok) shown++;
      });
      $$('[data-net-group]', tools).forEach(function (b) {
        var on = (st.g[b.dataset.netGroup] || 'all') === b.dataset.netVal;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      var count = $('.net-count', tools);
      if (count) count.textContent = shown === rows.length ? rows.length + ' shown' : shown + ' of ' + rows.length + ' shown';
      var none = document.getElementById(id + '-none');
      if (none) none.hidden = shown > 0;
    }
    function applyAll() { $$('.net-tools[data-net-for]').forEach(apply); }

    on(document, 'input', function (ev) {
      var input = ev.target.closest && ev.target.closest('.net-tools .net-q');
      if (!input) return;
      var tools = input.closest('.net-tools');
      stateFor(tools.dataset.netFor).q = input.value;
      apply(tools);
    });
    on(document, 'click', function (ev) {
      var b = ev.target.closest && ev.target.closest('.net-tools [data-net-group]');
      if (!b) return;
      var tools = b.closest('.net-tools');
      stateFor(tools.dataset.netFor).g[b.dataset.netGroup] = b.dataset.netVal;
      apply(tools);
    });
    on(document, 'keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      var input = ev.target.closest && ev.target.closest('.net-tools .net-q');
      if (!input || !input.value) return;
      input.value = '';
      stateFor(input.closest('.net-tools').dataset.netFor).q = '';
      apply(input.closest('.net-tools'));
    });
    on(document, 'live:updated', applyAll);
    applyAll();
  }

  /* ---------------------------------------------------------------- */
  function boot() {
    [authTabs, passwordToggles, termsChecklist, scrollSpy, notifications,
     countdowns, tableHints, copyButtons, adminPanels, tableFilters, netFilters,
     statusFields, idleWatch, liveRefresh].forEach(function (mod) {
      try { mod(); } catch (err) { if (window.console) console.error('[app]', mod.name, err); }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
