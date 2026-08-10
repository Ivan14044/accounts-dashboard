/**
 * ui.js — поведение собственной системы интерфейса (assets/css/ui.css).
 *
 * Заменяет то, ради чего страницы тянули Bootstrap JS: переключение темы,
 * выпадающие меню, модальные окна, всплывающие уведомления. Вместе с ui.css
 * это значит, что страница на новой системе не подключает bootstrap.bundle.js
 * (≈80 КБ) вообще.
 *
 * Разметка декларативная — JS ничего не рисует и ничего не знает про конкретные
 * страницы:
 *     <button data-ui-menu="userMenu">…      → открывает #userMenu
 *     <button data-ui-open="settingsModal">… → открывает #settingsModal
 *     <button data-ui-close>…                → закрывает своё окно
 *     <button data-ui-theme>…                → переключает тему
 *
 * Наружу торчит один объект `window.UI` с методами toast/openModal/closeModal —
 * этого хватает остальным модулям.
 *
 * Чего здесь осознанно НЕТ: анимационного движка, фокус-трапа с полной
 * реализацией ARIA-паттерна и позиционирования меню «умным» алгоритмом.
 * Меню открывается под кнопкой, окно центрируется CSS-ом — этого достаточно,
 * а лишний код здесь дороже пользы.
 */
(function () {
  'use strict';

  var THEME_KEY = 'dashboard-theme';
  var root = document.documentElement;

  /* ------------------------------------------------------------------ тема */

  function currentTheme() {
    // Пока не все страницы переехали, тема живёт в двух атрибутах сразу:
    // новый data-theme и старый data-bs-theme (его читают core-*.css).
    return (root.getAttribute('data-theme') || root.getAttribute('data-bs-theme')) === 'dark' ? 'dark' : 'light';
  }

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    root.setAttribute('data-bs-theme', theme);
    try { localStorage.setItem(THEME_KEY, theme); } catch (e) { /* приватный режим */ }

    var buttons = document.querySelectorAll('[data-ui-theme]');
    for (var i = 0; i < buttons.length; i++) {
      var dark = theme === 'dark';
      buttons[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
      buttons[i].setAttribute('title', dark ? 'Светлая тема' : 'Тёмная тема');
      buttons[i].setAttribute('aria-label', dark ? 'Включить светлую тему' : 'Включить тёмную тему');
      var icons = buttons[i].querySelectorAll('[data-ui-theme-icon]');
      for (var j = 0; j < icons.length; j++) {
        var isSun = icons[j].getAttribute('data-ui-theme-icon') === 'sun';
        icons[j].style.display = (dark === isSun) ? '' : 'none';
      }
    }
  }

  /* ------------------------------------------------------------------ меню */

  var openMenu = null;

  function closeMenu() {
    if (!openMenu) return;
    openMenu.menu.hidden = true;
    openMenu.trigger.setAttribute('aria-expanded', 'false');
    openMenu = null;
  }

  function toggleMenu(trigger) {
    var id = trigger.getAttribute('data-ui-menu');
    var menu = document.getElementById(id);
    if (!menu) return;

    var wasOpen = openMenu && openMenu.menu === menu;
    closeMenu();
    if (wasOpen) return;

    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    openMenu = { menu: menu, trigger: trigger };
  }

  /* ---------------------------------------------------------------- окна */

  var openModals = [];

  /**
   * Открывает модальное окно по id.
   *
   * @param {string} id
   * @returns {HTMLElement|null} само окно, если оно нашлось
   */
  function openModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return null;

    modal.hidden = false;
    openModals.push({ modal: modal, returnTo: document.activeElement });
    // Фон не должен прокручиваться под окном.
    document.body.style.overflow = 'hidden';

    var first = modal.querySelector('[autofocus], input, select, textarea, button');
    if (first) first.focus();
    return modal;
  }

  /**
   * Закрывает верхнее окно (или конкретное, если передан id).
   *
   * @param {string} [id]
   */
  function closeModal(id) {
    var entry;
    if (id) {
      for (var i = openModals.length - 1; i >= 0; i--) {
        if (openModals[i].modal.id === id) { entry = openModals.splice(i, 1)[0]; break; }
      }
    } else {
      entry = openModals.pop();
    }
    if (!entry) return;

    entry.modal.hidden = true;
    if (!openModals.length) document.body.style.overflow = '';
    if (entry.returnTo && entry.returnTo.focus) entry.returnTo.focus();
  }

  /* --------------------------------------------------------- уведомления */

  function toastHost() {
    var host = document.getElementById('uiToasts');
    if (!host) {
      host = document.createElement('div');
      host.id = 'uiToasts';
      host.className = 'ui-toasts';
      host.setAttribute('role', 'status');
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    return host;
  }

  /**
   * Показывает уведомление.
   *
   * @param {string} text  текст (вставляется как текст, не как HTML)
   * @param {string} [tone] 'ok' | 'danger' | undefined
   * @param {number} [ms]  сколько держать, по умолчанию 4500
   */
  function toast(text, tone, ms) {
    var host = toastHost();
    var el = document.createElement('div');
    el.className = 'ui-toast';
    if (tone) el.setAttribute('data-tone', tone);

    var body = document.createElement('span');
    body.textContent = String(text);
    el.appendChild(body);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'ui-icon-btn ui-icon-btn--sm ui-toast__close';
    close.setAttribute('aria-label', 'Закрыть уведомление');
    close.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>';
    close.addEventListener('click', function () { el.remove(); });
    el.appendChild(close);

    host.appendChild(el);
    setTimeout(function () { el.remove(); }, ms || 4500);
    return el;
  }

  /* -------------------------------------------------------------- события */

  document.addEventListener('click', function (e) {
    var themeBtn = e.target.closest ? e.target.closest('[data-ui-theme]') : null;
    if (themeBtn) {
      applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
      return;
    }

    var menuBtn = e.target.closest ? e.target.closest('[data-ui-menu]') : null;
    if (menuBtn) {
      e.preventDefault();
      toggleMenu(menuBtn);
      return;
    }

    var openBtn = e.target.closest ? e.target.closest('[data-ui-open]') : null;
    if (openBtn) {
      e.preventDefault();
      openModal(openBtn.getAttribute('data-ui-open'));
      return;
    }

    var closeBtn = e.target.closest ? e.target.closest('[data-ui-close]') : null;
    if (closeBtn) {
      e.preventDefault();
      var owner = closeBtn.closest('.ui-modal');
      closeModal(owner ? owner.id : undefined);
      return;
    }

    // Клик по затемнению закрывает окно, клик внутри — нет.
    if (e.target.classList && e.target.classList.contains('ui-modal')) {
      closeModal(e.target.id);
      return;
    }

    // Любой клик мимо открытого меню закрывает его.
    if (openMenu && !openMenu.menu.contains(e.target) && !openMenu.trigger.contains(e.target)) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (openModals.length) { closeModal(); return; }
    closeMenu();
  });

  /* ------------------------------------------------------------------ старт */

  function init() {
    applyTheme(currentTheme());
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.UI = {
    toast: toast,
    openModal: openModal,
    closeModal: closeModal,
    theme: applyTheme
  };
})();
