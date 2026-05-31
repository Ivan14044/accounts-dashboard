/**
 * theme-toggle.js — light/dark theme switch (INSTANT, no animation).
 *
 * Theme is driven by [data-bs-theme] on <html>; an inline <head> script sets it
 * before paint (no flash). Switching is instant: while we flip [data-bs-theme]
 * we add a `theme-switching` class that disables ALL CSS transitions for one
 * frame, so components (buttons, inputs, cards, toggles) don't fade their
 * colours — the whole page snaps to the new theme at once. The class is removed
 * on the next frame so normal hover transitions keep working.
 */
(function () {
  'use strict';

  var KEY = 'dashboard-theme';
  var root = document.documentElement;

  // One-time <style> that kills transitions during the theme swap.
  function ensureKillSwitch() {
    if (document.getElementById('theme-instant-style')) return;
    var st = document.createElement('style');
    st.id = 'theme-instant-style';
    // Удвоенный класс (.theme-switching.theme-switching) поднимает специфичность
    // до (0,2,1), чтобы перебить компонентные transition с !important
    // (напр. .btn:not(.btn-close) в core-theme.css, специфичность 0,2,0).
    st.textContent =
      'html.theme-switching.theme-switching, ' +
      'html.theme-switching.theme-switching *, ' +
      'html.theme-switching.theme-switching *::before, ' +
      'html.theme-switching.theme-switching *::after ' +
      '{ transition: none !important; }';
    (document.head || document.documentElement).appendChild(st);
  }

  function current() {
    return root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
  }

  function syncButton(theme) {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    var dark = theme === 'dark';
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    btn.setAttribute('title', dark ? 'Светлая тема' : 'Тёмная тема');
    btn.setAttribute('aria-label', dark ? 'Включить светлую тему' : 'Включить тёмную тему');
    var icon = btn.querySelector('i');
    if (icon) icon.className = (dark ? 'fas fa-sun' : 'fas fa-moon');
  }

  function setTheme(theme) {
    ensureKillSwitch();
    root.classList.add('theme-switching');          // отключаем все transition
    root.setAttribute('data-bs-theme', theme);       // меняем тему
    try { localStorage.setItem(KEY, theme); } catch (e) { /* private mode */ }
    syncButton(theme);
    void root.offsetWidth;                            // мгновенная перерисовка в новой теме
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { root.classList.remove('theme-switching'); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    ensureKillSwitch();
    syncButton(current());
    var btn = document.getElementById('themeToggle');
    if (btn) {
      btn.addEventListener('click', function () {
        setTheme(current() === 'dark' ? 'light' : 'dark');
      });
    }
  });
})();
