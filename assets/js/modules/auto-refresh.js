/**
 * Автообновление таблицы и кнопка «Наверх».
 *
 * Вынесено из dashboard-init.js 2026-08-09 — пятый шаг разбора.
 *
 * Два сюжета в одном модуле не случайно: оба про фоновое поведение страницы,
 * оба работают с таймерами и скроллом и оба ни от чего в дашборде не зависят,
 * кроме refreshDashboardData. Разносить их по разным файлам ради чистоты
 * классификации смысла нет.
 *
 * Состояние (таймер автообновления и флаг включённости) живёт внутри модуля —
 * раньше это были две глобальные let-переменные в dashboard-init.js.
 *
 * Зависимости берутся из глобальной области в момент вызова:
 * getElementById и refreshDashboardData.
 */
(function () {
  'use strict';

  // ===== Автообновление данных =====
  let autoRefreshInterval = null;
  let isAutoRefreshEnabled = false;
  // refreshController, refreshQueued — в dashboard-refresh.js

  function initializeAutoRefresh() {
    const toggleBtn = getElementById('autoRefreshToggle');
    if (!toggleBtn) return;
  
    toggleBtn.addEventListener('click', function() {
      if (isAutoRefreshEnabled) {
        stopAutoRefresh();
      } else {
        startAutoRefresh();
      }
    });
  
    // Загружаем состояние из localStorage
    const savedState = localStorage.getItem('dashboard_auto_refresh');
    if (savedState === 'enabled') {
      startAutoRefresh();
    }
  }

  function startAutoRefresh() {
    isAutoRefreshEnabled = true;
    const toggleBtn = getElementById('autoRefreshToggle');
    if (!toggleBtn) return;
  
    toggleBtn.classList.add('active');
    toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
    toggleBtn.title = 'Остановить автообновление';
  
    // Обновляем каждые 30 секунд; сбросим предыдущий интервал на всякий случай.
    // В фоновой вкладке не дёргаем сервер — обновимся сразу при возвращении.
    if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
    let autoRefreshSkipped = false;
    autoRefreshInterval = setInterval(() => {
      if (document.visibilityState === 'hidden') { autoRefreshSkipped = true; return; }
      autoRefreshSkipped = false;
      refreshDashboardData();
    }, 30000);
    document.addEventListener('visibilitychange', function onAutoRefreshVisible() {
      if (!isAutoRefreshEnabled) { document.removeEventListener('visibilitychange', onAutoRefreshVisible); return; }
      if (document.visibilityState === 'visible' && autoRefreshSkipped) {
        autoRefreshSkipped = false;
        refreshDashboardData();
      }
    });
  
    localStorage.setItem('dashboard_auto_refresh', 'enabled');
    // Не показываем уведомление постоянно
  }

  function stopAutoRefresh() {
    isAutoRefreshEnabled = false;
    const toggleBtn = getElementById('autoRefreshToggle');
    if (!toggleBtn) return;
  
    toggleBtn.classList.remove('active');
    toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
    toggleBtn.title = 'Включить автообновление';
  
    if (autoRefreshInterval) {
      clearInterval(autoRefreshInterval);
      autoRefreshInterval = null;
    }
    // Отменяем текущий запрос, если он есть
    try { if (window.refreshController) window.refreshController.abort(); } catch(_) {}
  
    localStorage.setItem('dashboard_auto_refresh', 'disabled');
    showToast('Автообновление отключено', 'info');
  }

  // ===== refreshDashboardData перенесена в dashboard-refresh.js =====

  // ===== Кнопка "Наверх" =====
  function initScrollToTop() {
    const scrollToTopBtn = getElementById('scrollToTop');
    if (!scrollToTopBtn) return;

    // Показываем/скрываем кнопку по позиции скролла. Без layout-чтений в scroll (только pageYOffset и classList).
    var scrollToTopTicking = false;
    function toggleScrollToTop() {
      if (scrollToTopTicking) return;
      scrollToTopTicking = true;
      requestAnimationFrame(function() {
        scrollToTopTicking = false;
        if (window.pageYOffset > 300) {
          scrollToTopBtn.classList.add('show');
        } else {
          scrollToTopBtn.classList.remove('show');
        }
      });
    }

    // Плавный скролл наверх
    function scrollToTop() {
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    }

    window.addEventListener('scroll', toggleScrollToTop, { passive: true });
    scrollToTopBtn.addEventListener('click', scrollToTop);

    toggleScrollToTop();
  }


  // Наружу — только то, что зовёт dashboard-init.js при инициализации.
  // startAutoRefresh/stopAutoRefresh экспортированы для ручного управления
  // из консоли и на случай будущей кнопки «пауза».
  window.initializeAutoRefresh = initializeAutoRefresh;
  window.startAutoRefresh      = startAutoRefresh;
  window.stopAutoRefresh       = stopAutoRefresh;
  window.initScrollToTop       = initScrollToTop;
})();
