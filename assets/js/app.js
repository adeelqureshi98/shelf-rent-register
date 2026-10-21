/* Shelf Rent & Incentive Register — shared front-end behaviour (v4).
 * Theme toggle, floating toasts, submit spinners, KPI count-up and
 * progressive live search. All enhancements are progressive: with
 * JS off, every page still works exactly as before. */
(function () {
  'use strict';

  /* ---- Theme: light / dark, persisted in cookie + localStorage.
   * The pre-paint script in the page head applies the stored theme
   * before CSS paints, so there is never a flash of wrong theme. */
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-bs-theme', theme);
    try {
      localStorage.setItem('srr-theme', theme);
      document.cookie = 'srr_theme=' + theme + '; path=/; max-age=31536000; samesite=lax';
    } catch (e) { /* storage unavailable — theme still applies for this page */ }
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      var dark = theme === 'dark';
      btn.querySelector('i').className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars');
      var lbl = btn.querySelector('[data-theme-label]');
      if (lbl) lbl.textContent = dark ? btn.getAttribute('data-light-label') : btn.getAttribute('data-dark-label');
    });
    if (window.__srrCharts && window.Chart) {
      var cs = getComputedStyle(document.documentElement);
      Chart.defaults.color = cs.getPropertyValue('--muted').trim() || '#67766c';
      Chart.defaults.borderColor = cs.getPropertyValue('--line').trim() || '#d9e0d8';
      window.__srrCharts.forEach(function (c) { c.update(); });
    }
  }
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  });

  /* ---- Toasts: flash messages float and auto-dismiss. */
  document.querySelectorAll('.luxe-toast').forEach(function (toast, i) {
    setTimeout(function () { toast.classList.add('show'); }, 40 + i * 90);
    setTimeout(function () {
      toast.classList.remove('show');
      setTimeout(function () { toast.remove(); }, 350);
    }, 5200 + i * 90);
  });

  /* ---- Submit buttons: spinner + disable (double-submit guard). */
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]:not([data-no-spin])');
      if (!btn || btn.disabled) return;
      btn.disabled = true;
      var spin = document.createElement('span');
      spin.className = 'spinner-border spinner-border-sm me-1';
      spin.setAttribute('role', 'status');
      btn.prepend(spin);
      setTimeout(function () { btn.disabled = false; spin.remove(); }, 8000); // safety valve
    });
  });

  /* ---- KPI count-up (respects prefers-reduced-motion). */
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('[data-countup]').forEach(function (el) {
    var target = parseFloat(el.getAttribute('data-countup'));
    if (isNaN(target)) return;
    var prefix = el.getAttribute('data-prefix') || '';
    var fmt = function (n) { return prefix + Math.round(n).toLocaleString('en-US'); };
    if (reduceMotion || target === 0) { el.textContent = fmt(target); return; }
    var start = null, dur = 900;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min(1, (ts - start) / dur);
      el.textContent = fmt(target * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(step); else el.textContent = fmt(target);
    }
    requestAnimationFrame(step);
  });

  /* ---- Live search: filter [data-livesearch] rows instantly.
   * Rows carry data-search="name code ..." (lowercased server-side).
   * The server-side GET search keeps working when JS is off. */
  document.querySelectorAll('[data-livesearch-input]').forEach(function (input) {
    var targetSel = input.getAttribute('data-livesearch-input');
    var rows = document.querySelectorAll(targetSel + ' [data-search]');
    var emptyRow = document.querySelector(targetSel + ' [data-search-empty]');
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var visible = 0;
      rows.forEach(function (row) {
        var hit = q === '' || row.getAttribute('data-search').indexOf(q) !== -1;
        row.style.display = hit ? '' : 'none';
        if (hit) visible++;
      });
      if (emptyRow) emptyRow.style.display = visible ? 'none' : '';
    });
  });
})();
