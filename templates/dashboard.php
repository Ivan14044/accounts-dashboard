<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Accounts Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="alternate icon" href="assets/favicon.svg">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <!-- Минималистичная дизайн-система -->
  <link href="assets/css/minimal-design-system.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/minimal-components.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/minimal-layout.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/minimal-overrides.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/minimal-performance.css?v=<?= time() ?>" rel="stylesheet">
  <!-- Единая дизайн-система (в правильном порядке) -->
  <link href="assets/css/design-system.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/components-unified.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/filters-modern.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/toast.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/modern-header.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/sticky-scrollbar.css?v=<?= time() ?>" rel="stylesheet">
  <!-- Единая тема для всех элементов -->
  <link href="assets/css/unified-theme.css?v=<?= time() ?>" rel="stylesheet">
  <!-- Новая таблица -->
  <link href="assets/css/table-core.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/table-theme.css?v=<?= time() ?>" rel="stylesheet">
  
  <!-- СИНХРОННОЕ скрытие карточек ДО загрузки DOM для предотвращения мигания -->
  <script>
    (function() {
      try {
        const saved = localStorage.getItem('dashboard_hidden_cards');
        if (saved) {
          const hiddenIds = JSON.parse(saved);
          if (Array.isArray(hiddenIds) && hiddenIds.length > 0) {
            // Сохраняем список для применения после загрузки DOM
            window._hiddenCardsToHide = new Set(hiddenIds);
            
            // Функция для немедленного скрытия карточки
            function hideCardImmediately(card) {
              const cardId = card.getAttribute('data-card');
              if (!cardId) {
                return; // Пропускаем карточки без ID
              }
              
              if (window._hiddenCardsToHide.has(cardId)) {
                // Применяем все способы скрытия для надежности
                card.classList.add('hidden');
                card.style.setProperty('display', 'none', 'important');
                card.style.setProperty('visibility', 'hidden', 'important');
                card.style.setProperty('opacity', '0', 'important');
                card.setAttribute('hidden', '');
                console.log('⚡ Немедленно скрыта карточка (MutationObserver):', cardId);
              } else {
                // Логируем карточки, которые не в списке скрытых (для отладки)
                if (cardId === 'custom:email_twofa') {
                  console.log('🔍 Карточка "Email + 2FA" найдена, но НЕ в списке скрытых. Список скрытых:', Array.from(window._hiddenCardsToHide));
                }
              }
            }
            
            // Используем MutationObserver для отслеживания появления карточек
            const observer = new MutationObserver(function(mutations) {
              mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                  if (node.nodeType === 1) { // Element node
                    // Проверяем сам узел
                    if (node.classList && node.classList.contains('stat-card')) {
                      hideCardImmediately(node);
                    }
                    // Проверяем дочерние элементы
                    if (node.querySelectorAll) {
                      const cards = node.querySelectorAll('.stat-card');
                      cards.forEach(hideCardImmediately);
                    }
                  }
                });
              });
            });
            
            // Начинаем наблюдение сразу
            if (document.body) {
              observer.observe(document.body, {
                childList: true,
                subtree: true
              });
            } else {
              // Если body еще не готов, ждем его
              document.addEventListener('DOMContentLoaded', function() {
                observer.observe(document.body, {
                  childList: true,
                  subtree: true
                });
              });
            }
            
            // Также применяем скрытие к уже существующим карточкам
            function applyHidingToExistingCards() {
              if (document.querySelectorAll) {
                const cards = document.querySelectorAll('.stat-card');
                let hiddenCount = 0;
                let emailTwoFaFound = false;
                
                cards.forEach(function(card) {
                  const cardId = card.getAttribute('data-card');
                  if (!cardId) return;
                  
                  // Специальная проверка для карточки "Email + 2FA"
                  if (cardId === 'custom:email_twofa') {
                    emailTwoFaFound = true;
                    // Если карточка должна быть скрыта, но не в списке - добавляем в список
                    if (!window._hiddenCardsToHide.has(cardId)) {
                      console.warn('⚠️ Карточка "Email + 2FA" найдена, но НЕ в списке скрытых. Добавляем в список для скрытия.');
                      window._hiddenCardsToHide.add(cardId);
                      // Сохраняем обновленный список в localStorage
                      try {
                        const updatedList = Array.from(window._hiddenCardsToHide);
                        localStorage.setItem('dashboard_hidden_cards', JSON.stringify(updatedList));
                        console.log('✅ Обновлен список скрытых карточек в localStorage');
                      } catch (e) {
                        console.error('❌ Ошибка обновления localStorage:', e);
                      }
                    }
                  }
                  
                  if (window._hiddenCardsToHide.has(cardId)) {
                    hideCardImmediately(card);
                    hiddenCount++;
                  }
                });
                
                if (hiddenCount > 0) {
                  console.log('⚡ Применено скрытие к существующим карточкам:', hiddenCount);
                }
                
                if (!emailTwoFaFound) {
                  console.warn('⚠️ Карточка "Email + 2FA" не найдена в DOM при применении скрытия');
                }
              }
            }
            
            // Пытаемся применить сразу, если DOM уже готов
            // Используем несколько попыток для надежности
            function tryApplyHiding() {
              if (document.body && document.querySelectorAll) {
                applyHidingToExistingCards();
                // Повторяем через небольшую задержку на случай, если карточки еще не загружены
                setTimeout(applyHidingToExistingCards, 10);
                setTimeout(applyHidingToExistingCards, 50);
                setTimeout(applyHidingToExistingCards, 100);
              }
            }
            
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', tryApplyHiding);
            } else {
              tryApplyHiding();
            }
            
            // Дополнительная попытка после полной загрузки
            window.addEventListener('load', function() {
              setTimeout(applyHidingToExistingCards, 0);
            });
          }
        }
      } catch (e) {
        console.error('Error reading hidden cards:', e);
      }
    })();
  </script>

  <style>
    /* Современный строгий дизайн */
    :root {
      /* Основные цвета */
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --secondary: #64748b;
      --success: #059669;
      --warning: #d97706;
      --danger: #dc2626;
      --info: #0891b2;
      
      /* Нейтральные цвета */
      --gray-50: #f8fafc;
      --gray-100: #f1f5f9;
      --gray-200: #e2e8f0;
      --gray-300: #cbd5e1;
      --gray-400: #94a3b8;
      --gray-500: #64748b;
      --gray-600: #475569;
      --gray-700: #334155;
      --gray-800: #1e293b;
      --gray-900: #0f172a;
      
      /* Фон и границы */
      --bg-primary: #ffffff;
      --bg-secondary: #f8fafc;
      --bg-tertiary: #f1f5f9;
      --border-light: #e2e8f0;
      --border-medium: #cbd5e1;
      
      /* Тени */
      --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
      --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
      --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
      --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
      
      /* Радиусы */
      --radius-sm: 0.375rem;
      --radius-md: 0.5rem;
      --radius-lg: 0.75rem;
      --radius-xl: 1rem;
      
      /* Отступы */
      --space-1: 0.25rem;
      --space-2: 0.5rem;
      --space-3: 0.75rem;
      --space-4: 1rem;
      --space-5: 1.25rem;
      --space-6: 1.5rem;
      --space-8: 2rem;
      --space-10: 2.5rem;
      --space-12: 3rem;
      
      /* Оптимизация производительности */
      --animation-duration: 0.2s;
      --transition-duration: 0.2s;
    }
    
    /* Оптимизации для слабых устройств */
    .low-end-device *,
    .low-end-device *::before,
    .low-end-device *::after {
      animation-duration: 0.01ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: 0.01ms !important;
      scroll-behavior: auto !important;
    }
    
    /* Отключить сложные эффекты на слабых устройствах */
    .low-end-device .card {
      box-shadow: none !important;
    }
    
    .low-end-device .table tbody tr:hover {
      background: var(--gray-50) !important;
    }
    
    /* Использовать contain для изоляции рендеринга */
    .table tbody tr {
      contain: layout style paint;
    }
    
    /* Оптимизация для prefers-reduced-motion */
    @media (prefers-reduced-motion: reduce) {
      *,
      *::before,
      *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
      }
    }

    /* Базовые стили */
    * {
      box-sizing: border-box;
    }

    html {
      font-size: 16px;
      line-height: 1.5;
      scroll-behavior: smooth;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: var(--bg-secondary);
      color: var(--gray-900);
      margin: 0;
      padding: 0;
      min-height: 100vh;
      font-weight: 400;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* Навигация */
    .navbar {
      background: var(--bg-primary) !important;
      border-bottom: 1px solid var(--border-light);
      box-shadow: var(--shadow-sm);
      padding: var(--space-2) 0;
      min-height: auto;
    }

    .navbar-brand {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--gray-900) !important;
      text-decoration: none;
      padding: 0;
    }

    /* Компактные стили для хедера */
    .navbar .container-fluid {
      padding: 0 var(--space-3);
    }

    .navbar .btn-sm {
      padding: 0.375rem 0.5rem;
      font-size: 0.8125rem;
    }

    .navbar .text-muted.small {
      font-size: 0.8125rem;
    }

    /* Основной контейнер */
    .container-fluid {
      max-width: none;
      margin: 0;
      padding: 0 var(--space-4);
      width: 100%;
    }

    main {
      padding: var(--space-6) 0;
    }

    /* Карточки статистики */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.25rem; /* Tailwind gap-5 */
      margin-bottom: var(--space-6);
    }
    @media (min-width: 768px) { /* md */
      .stats-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (min-width: 1280px) { /* xl */
      .stats-grid { grid-template-columns: repeat(5, 1fr); }
    }

    .stat-card {
      background: rgba(255,255,255,0.5); /* bg-white/50 */
      border: 1px solid rgba(255,255,255,0.4); /* border-white/40 */
      border-radius: 16px; /* rounded-2xl */
      position: relative; /* Для позиционирования иконки скрытия */
      padding: 1.5rem; /* p-6 */
      box-shadow: 0 10px 20px rgba(0,0,0,0.10); /* shadow-lg */
      transition: box-shadow .25s ease, transform .25s ease;
      position: relative;
      overflow: hidden;
      text-align: center;
      display: flex;
      flex-direction: column;
      justify-content: center;
      backdrop-filter: blur(24px); /* backdrop-blur-2xl */
      -webkit-backdrop-filter: blur(24px);
      will-change: transform, opacity;
    }

    .stat-card.fade-in {
      animation: fadeInUp 0.5s ease-out;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    /* Анимация последовательного появления (аналог framer-motion delay) */
    .stats-grid .stat-card { opacity: 0; animation: fadeInUp .25s ease-out both; }
    .stats-grid .stat-card:nth-child(1) { animation-delay: .00s; }
    .stats-grid .stat-card:nth-child(2) { animation-delay: .05s; }
    .stats-grid .stat-card:nth-child(3) { animation-delay: .10s; }
    .stats-grid .stat-card:nth-child(4) { animation-delay: .15s; }
    .stats-grid .stat-card:nth-child(5) { animation-delay: .20s; }
    .stats-grid .stat-card:nth-child(6) { animation-delay: .25s; }
    .stats-grid .stat-card:nth-child(7) { animation-delay: .30s; }
    .stats-grid .stat-card:nth-child(8) { animation-delay: .35s; }
    .stats-grid .stat-card:nth-child(9) { animation-delay: .40s; }
    .stats-grid .stat-card:nth-child(10) { animation-delay: .45s; }

    .stat-card:hover { box-shadow: 0 14px 24px rgba(0,0,0,0.14); transform: translateY(-2px); }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px; /* h-1 */
      background: linear-gradient(90deg, #3b82f6, #4f46e5); /* from-blue-500 to-indigo-600 */
      border-radius: 16px 16px 0 0;
    }

    /* Персональные градиенты (как Tailwind bg-gradient-to-r from-.. to-..) */
    .stat-card[data-card="total"]::before { background: linear-gradient(90deg, #3b82f6, #4f46e5); } /* from-blue-500 to-indigo-600 */
    .stat-card[data-card="custom:email_twofa"]::before { background: linear-gradient(90deg, #6366f1, #2563eb); } /* from-indigo-500 to-blue-600 */
    
    /* Кастомные карточки с цветом (применяется только если есть CSS переменная) */
    .stat-card[data-card^="custom:"][style*="--card-color"]::before {
      background: linear-gradient(90deg, var(--card-color), var(--card-color-dark, var(--card-color)));
    }
    
    /* Стили для множественного выбора статусов в модальном окне */
    #customCardStatuses {
      border: 1px solid #dee2e6;
      border-radius: 0.375rem;
    }
    
    #customCardStatuses option {
      padding: 0.5rem;
      border-bottom: 1px solid #f0f0f0;
    }
    
    #customCardStatuses option:checked {
      background: linear-gradient(90deg, #3b82f6, #2563eb);
      color: white;
      font-weight: 600;
    }
    
    #customCardStatuses option:hover {
      background-color: #f8f9fa;
    }
    
    .stat-card[data-status="INVALID_EMAIL"]::before { background: linear-gradient(90deg, #ef4444, #f97316); } /* from-red-500 to-orange-500 */
    .stat-card[data-status="NEW_TAR"]::before { background: linear-gradient(90deg, #10b981, #059669); } /* from-green-500 to-emerald-600 */
    .stat-card[data-card="empty_status"]::before { background: linear-gradient(90deg, #f59e0b, #ef4444); }

    /* Иконка скрытия карточки */
    .stat-card-hide-btn {
      position: absolute;
      top: 8px;
      right: 8px;
      left: auto;
      width: 28px;
      height: 28px;
      border-radius: 6px;
      background: rgba(0, 0, 0, 0.05);
      border: 1px solid rgba(0, 0, 0, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      opacity: 0;
      transition: all 0.2s ease;
      z-index: 10;
      color: #6b7280;
      font-size: 13px;
    }
    
    .stat-card:hover .stat-card-hide-btn {
      opacity: 1;
    }
    
    .stat-card-hide-btn:hover {
      background: rgba(99, 102, 241, 0.1);
      border-color: rgba(99, 102, 241, 0.3);
      color: #6366f1;
      transform: scale(1.05);
    }
    
    .stat-card-hide-btn:active {
      transform: scale(0.95);
    }
    
    /* Скрытая карточка */
    .stat-card.hidden {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
    }
    
    /* Дополнительное правило для предотвращения мигания при загрузке */
    .stat-card[data-card].hidden {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
    }
    
    /* Временное скрытие карточек до применения JavaScript (предотвращает мигание) */
    .stat-card[data-card] {
      /* Карточки по умолчанию видимы, но скрываются через JavaScript */
    }

    .stat-header {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: var(--space-4);
      position: relative;
      padding-top: 0; /* Убираем отступ сверху, так как кнопка теперь справа */
    }

    .stat-title {
      font-size: 0.875rem; /* text-sm */
      font-weight: 500; /* font-medium */
      color: #1f2937; /* text-gray-800 */
      text-transform: uppercase;
      letter-spacing: 0.08em; /* tracking-wide */
      margin: 0 0 0.5rem 0; /* mb-2 */
      padding: 0 32px 0 0; /* Отступ справа для кнопки скрытия */
      text-align: center;
      font-family: 'Outfit', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      /* Обработка длинных названий статусов */
      word-wrap: break-word;
      overflow-wrap: break-word;
      white-space: normal;
      line-height: 1.4;
      max-height: 4.2em; /* Ограничиваем максимум 3 строки (1.4 * 3) */
      overflow: hidden;
      display: -webkit-box;
      -webkit-line-clamp: 3; /* Максимум 3 строки */
      -webkit-box-orient: vertical;
    }

    .stat-icon {
      position: absolute;
      right: 0;
      top: 50%;
      transform: translateY(-50%);
      width: 40px;
      height: 40px;
      border-radius: var(--radius-lg);
      background: var(--gray-100);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--primary);
      font-size: 1.25rem;
    }

    .stat-value {
      font-size: 2.25rem; /* text-4xl */
      font-weight: 600; /* font-semibold */
      color: #111827; /* text-gray-900 */
      margin: 0;
      line-height: 1.1;
      font-variant-numeric: tabular-nums;
      text-align: center;
      text-shadow: 0 1px 1px rgba(0,0,0,0.02); /* drop-shadow-sm imitation */
      font-family: 'Manrope', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    }

    .stat-change {
      font-size: 0.875rem;
      color: var(--gray-500);
      margin-top: var(--space-2);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-1);
    }

    .stat-change.positive {
      color: var(--success);
    }

    .stat-change.negative {
      color: var(--danger);
    }

    /* Панель инструментов */
    .toolbar {
      background: var(--bg-primary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-xl);
      padding: var(--space-2);
      margin-bottom: var(--space-4);
      box-shadow: var(--shadow-sm);
    }

    .toolbar-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: var(--space-2);
    }

    .toolbar-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: var(--gray-900);
      margin: 0;
    }

    .toolbar-actions {
      display: flex;
      gap: var(--space-2);
      align-items: center;
      flex-wrap: wrap;
    }
    
    /* Стили для уведомления о выборе строк */
    .selection-notice-wrapper {
      margin-top: var(--space-2);
      padding-top: var(--space-2);
      border-top: 1px solid var(--border-light);
      animation: slideDown 0.2s ease-out;
    }
    
    .selection-notice {
      display: flex;
      align-items: center;
      padding: 0.5rem 0.75rem;
      background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
      border-left: 3px solid #2196f3;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      color: #1565c0;
      gap: 0.5rem;
    }
    
    .selection-notice i {
      color: #2196f3;
      font-size: 1rem;
      flex-shrink: 0;
    }
    
    .selection-notice-text {
      flex: 1;
      line-height: 1.5;
    }
    
    .selection-notice-text a {
      color: #1976d2;
      font-weight: 600;
      text-decoration: none;
      margin-left: 0.25rem;
      transition: color 0.2s ease;
      white-space: nowrap;
    }
    
    .selection-notice-text a:hover {
      color: #0d47a1;
      text-decoration: underline;
    }
    
    .selection-notice-text strong {
      font-weight: 700;
      color: #0d47a1;
    }
    
    @keyframes slideDown {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    @media (max-width: 768px) {
      .toolbar-actions {
        flex-wrap: wrap;
      }
      
      .selection-notice {
        font-size: 0.8125rem;
        padding: 0.4rem 0.6rem;
        flex-wrap: wrap;
      }
      
      .selection-notice-text {
        flex-basis: 100%;
        margin-top: 0.25rem;
      }
      
      .selection-notice-text a {
        display: inline-block;
        margin-left: 0;
        margin-top: 0.25rem;
      }
    }

    .toolbar .text-muted {
      font-size: 0.875rem;
    }

    .toolbar .badge {
      font-size: 0.75rem;
      padding: 0.25rem 0.5rem;
    }

    /* Формы */
    .form-control,
    .form-select {
      border: 1px solid var(--border-medium);
      border-radius: var(--radius-md);
      padding: 0.375rem 0.75rem;
      font-size: 0.875rem;
      transition: all 0.2s ease;
      background: var(--bg-primary);
    }

    .form-control-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.8125rem;
    }

    .form-control:focus,
    .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgb(37 99 235 / 0.1);
      outline: none;
    }

    /* Стили для dropdown с чекбоксами статусов */
    .status-dropdown-menu {
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border: 1px solid var(--border-medium);
      border-radius: 8px;
    }
    
    .status-checkbox-item {
      padding: 8px 10px 8px 12px;
      margin: 1px 0;
      border-radius: 4px;
      transition: background-color 0.15s ease, color 0.15s ease;
      cursor: pointer;
      min-height: 36px;
      display: flex;
      align-items: center;
      color: var(--text-primary, #1f2937);
    }
    
    .status-checkbox-item:hover {
      background-color: var(--hover-bg, #f3f4f6);
      color: var(--text-primary, #1f2937);
    }
    
    .status-checkbox-item:hover .form-check-label {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-checkbox-item:hover .form-check-label * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Бейджи/счетчики всегда синие с белым текстом */
    .status-checkbox-item .status-count,
    .status-checkbox-item .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .status-checkbox-item:hover .status-count,
    .status-checkbox-item:hover .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    /* Стили для dropdown Currency */
    .currency-dropdown-menu {
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border: 1px solid var(--border-medium);
      border-radius: 8px;
    }
    
    .currency-item {
      padding: 8px 10px 8px 12px;
      margin: 1px 0;
      border-radius: 4px;
      transition: background-color 0.15s ease, color 0.15s ease;
      cursor: pointer;
      min-height: 36px;
      display: flex;
      align-items: center;
      color: var(--text-primary, #1f2937);
    }
    
    .currency-item:hover {
      background-color: var(--hover-bg, #f3f4f6);
      color: var(--text-primary, #1f2937);
    }
    
    .currency-item:hover label {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .currency-item:hover label * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .currency-item:hover * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Бейджи всегда синие с белым текстом */
    .currency-item .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .currency-item:hover .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .currency-item.active {
      background-color: rgba(37, 99, 235, 0.1);
      font-weight: 500;
      color: var(--text-primary, #1f2937);
    }
    
    .currency-item label {
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      padding: 0;
      line-height: 1.2;
      color: var(--text-primary, #1f2937) !important;
    }
    
    .currency-item label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .currency-item:hover label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    #currencyDropdown {
      min-height: 31px;
      border-color: var(--border-medium);
      font-size: 0.875rem;
      color: var(--text-primary, #1f2937) !important;
    }
    
    #currencyDropdown:hover {
      background-color: var(--hover-bg, #f8f9fa);
      border-color: var(--border-dark);
      color: var(--text-primary, #1f2937) !important;
    }
    
    #currencyDropdown:active,
    #currencyDropdown:focus,
    #currencyDropdown.show {
      background-color: var(--hover-bg, #f8f9fa) !important;
      border-color: var(--border-dark) !important;
      color: var(--text-primary, #1f2937) !important;
      box-shadow: none;
    }
    
    #currencyDropdownLabel {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем читаемость текста при всех состояниях кнопки */
    #currencyDropdown:hover #currencyDropdownLabel,
    #currencyDropdown:active #currencyDropdownLabel,
    #currencyDropdown:focus #currencyDropdownLabel,
    #currencyDropdown.show #currencyDropdownLabel {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем видимость иконки стрелки dropdown при всех состояниях */
    #currencyDropdown::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    #currencyDropdown:hover::after,
    #currencyDropdown:active::after,
    #currencyDropdown:focus::after,
    #currencyDropdown.show::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    /* Стили для dropdown Geo */
    .geo-dropdown-menu {
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border: 1px solid var(--border-medium);
      border-radius: 8px;
    }
    
    .geo-item {
      padding: 8px 10px 8px 12px;
      margin: 1px 0;
      border-radius: 4px;
      transition: background-color 0.15s ease, color 0.15s ease;
      cursor: pointer;
      min-height: 36px;
      display: flex;
      align-items: center;
      color: var(--text-primary, #1f2937);
    }
    
    .geo-item:hover {
      background-color: var(--hover-bg, #f3f4f6);
      color: var(--text-primary, #1f2937);
    }
    
    .geo-item:hover label {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .geo-item:hover label * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .geo-item:hover * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Бейджи всегда синие с белым текстом */
    .geo-item .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .geo-item:hover .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .geo-item.active {
      background-color: rgba(37, 99, 235, 0.1);
      font-weight: 500;
      color: var(--text-primary, #1f2937);
    }
    
    .geo-item label {
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      padding: 0;
      line-height: 1.2;
      color: var(--text-primary, #1f2937) !important;
    }
    
    .geo-item label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .geo-item:hover label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    #geoDropdown {
      min-height: 31px;
      border-color: var(--border-medium);
      font-size: 0.875rem;
      color: var(--text-primary, #1f2937) !important;
    }
    
    #geoDropdown:hover {
      background-color: var(--hover-bg, #f8f9fa);
      border-color: var(--border-dark);
      color: var(--text-primary, #1f2937) !important;
    }
    
    #geoDropdown:active,
    #geoDropdown:focus,
    #geoDropdown.show {
      background-color: var(--hover-bg, #f8f9fa) !important;
      border-color: var(--border-dark) !important;
      color: var(--text-primary, #1f2937) !important;
      box-shadow: none;
    }
    
    #geoDropdownLabel {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем читаемость текста при всех состояниях кнопки */
    #geoDropdown:hover #geoDropdownLabel,
    #geoDropdown:active #geoDropdownLabel,
    #geoDropdown:focus #geoDropdownLabel,
    #geoDropdown.show #geoDropdownLabel {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем видимость иконки стрелки dropdown при всех состояниях */
    #geoDropdown::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    #geoDropdown:hover::after,
    #geoDropdown:active::after,
    #geoDropdown:focus::after,
    #geoDropdown.show::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    /* Стили для dropdown Status RK */
    .status-rk-dropdown-menu {
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border: 1px solid var(--border-medium);
      border-radius: 8px;
    }
    
    .status-rk-item {
      padding: 8px 10px 8px 12px;
      margin: 1px 0;
      border-radius: 4px;
      transition: background-color 0.15s ease, color 0.15s ease;
      cursor: pointer;
      min-height: 36px;
      display: flex;
      align-items: center;
      color: var(--text-primary, #1f2937);
    }
    
    .status-rk-item:hover {
      background-color: var(--hover-bg, #f3f4f6);
      color: var(--text-primary, #1f2937);
    }
    
    .status-rk-item:hover label {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-rk-item:hover label * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-rk-item:hover * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Бейджи всегда синие с белым текстом */
    .status-rk-item .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .status-rk-item:hover .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .status-rk-item.active {
      background-color: rgba(37, 99, 235, 0.1);
      font-weight: 500;
      color: var(--text-primary, #1f2937);
    }
    
    .status-rk-item label {
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      padding: 0;
      line-height: 1.2;
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-rk-item label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-rk-item:hover label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusRkDropdown {
      min-height: 31px;
      border-color: var(--border-medium);
      font-size: 0.875rem;
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusRkDropdown:hover {
      background-color: var(--hover-bg, #f8f9fa);
      border-color: var(--border-dark);
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusRkDropdown:active,
    #statusRkDropdown:focus,
    #statusRkDropdown.show {
      background-color: var(--hover-bg, #f8f9fa) !important;
      border-color: var(--border-dark) !important;
      color: var(--text-primary, #1f2937) !important;
      box-shadow: none;
    }
    
    #statusRkDropdownLabel {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем читаемость текста при всех состояниях кнопки */
    #statusRkDropdown:hover #statusRkDropdownLabel,
    #statusRkDropdown:active #statusRkDropdownLabel,
    #statusRkDropdown:focus #statusRkDropdownLabel,
    #statusRkDropdown.show #statusRkDropdownLabel {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем видимость иконки стрелки dropdown при всех состояниях */
    #statusRkDropdown::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    #statusRkDropdown:hover::after,
    #statusRkDropdown:active::after,
    #statusRkDropdown:focus::after,
    #statusRkDropdown.show::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    /* Стили для dropdown Status Marketplace */
    .status-marketplace-dropdown-menu {
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      border: 1px solid var(--border-medium);
      border-radius: 8px;
    }
    
    .status-marketplace-item {
      padding: 8px 10px 8px 12px;
      margin: 1px 0;
      border-radius: 4px;
      transition: background-color 0.15s ease, color 0.15s ease;
      cursor: pointer;
      min-height: 36px;
      display: flex;
      align-items: center;
      color: var(--text-primary, #1f2937);
    }
    
    .status-marketplace-item:hover {
      background-color: var(--hover-bg, #f3f4f6);
      color: var(--text-primary, #1f2937);
    }
    
    .status-marketplace-item:hover label {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-marketplace-item:hover label * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-marketplace-item:hover * {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Бейджи всегда синие с белым текстом */
    .status-marketplace-item .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .status-marketplace-item:hover .badge {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    .status-marketplace-item.active {
      background-color: rgba(37, 99, 235, 0.1);
      font-weight: 500;
      color: var(--text-primary, #1f2937);
    }
    
    .status-marketplace-item label {
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      padding: 0;
      line-height: 1.2;
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-marketplace-item label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-marketplace-item:hover label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusMarketplaceDropdown {
      min-height: 31px;
      border-color: var(--border-medium);
      font-size: 0.875rem;
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusMarketplaceDropdown:hover {
      background-color: var(--hover-bg, #f8f9fa);
      border-color: var(--border-dark);
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusMarketplaceDropdown:active,
    #statusMarketplaceDropdown:focus,
    #statusMarketplaceDropdown.show {
      background-color: var(--hover-bg, #f8f9fa) !important;
      border-color: var(--border-dark) !important;
      color: var(--text-primary, #1f2937) !important;
      box-shadow: none;
    }
    
    #statusMarketplaceDropdownLabel {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем читаемость текста при всех состояниях кнопки */
    #statusMarketplaceDropdown:hover #statusMarketplaceDropdownLabel,
    #statusMarketplaceDropdown:active #statusMarketplaceDropdownLabel,
    #statusMarketplaceDropdown:focus #statusMarketplaceDropdownLabel,
    #statusMarketplaceDropdown.show #statusMarketplaceDropdownLabel {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем видимость иконки стрелки dropdown при всех состояниях */
    #statusMarketplaceDropdown::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    #statusMarketplaceDropdown:hover::after,
    #statusMarketplaceDropdown:active::after,
    #statusMarketplaceDropdown:focus::after,
    #statusMarketplaceDropdown.show::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    .status-checkbox-item .form-check-input {
      cursor: pointer;
      margin-top: 0;
      margin-right: 10px;
      margin-left: 0;
      flex-shrink: 0;
      width: 16px;
      height: 16px;
      min-width: 16px;
    }
    
    .status-checkbox-item .form-check-label {
      cursor: pointer;
      user-select: none;
      font-size: 0.875rem;
      padding-left: 0;
      margin-bottom: 0;
      flex: 1;
      line-height: 1.2;
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-checkbox-item .form-check-label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    .status-checkbox-item:hover .form-check-label span {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Единый стиль для всех бейджей в выпадающих списках - синий фон и белый текст */
    .status-count,
    .badge.status-count,
    .badge.bg-secondary.status-count,
    .badge.bg-primary.status-count,
    .badge.bg-warning.status-count {
      font-size: 0.7rem;
      padding: 0.2rem 0.4rem;
      border-radius: 10px;
      font-weight: 500;
      min-width: 20px;
      text-align: center;
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
      opacity: 1 !important;
    }
    
    /* Бейджи в dropdown списках - синий фон и белый текст */
    .status-checkbox-item .badge,
    .status-checkbox-item .badge.bg-secondary,
    .status-checkbox-item .badge.bg-primary,
    .status-checkbox-item .badge.bg-warning,
    .currency-item .badge,
    .currency-item .badge.bg-secondary,
    .currency-item .badge.bg-primary,
    .status-marketplace-item .badge,
    .status-marketplace-item .badge.bg-secondary,
    .status-marketplace-item .badge.bg-primary {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
    }
    
    /* При наведении бейджи остаются синими с белым текстом */
    .status-checkbox-item:hover .status-count,
    .status-checkbox-item:hover .badge,
    .status-checkbox-item:hover .badge.bg-secondary,
    .status-checkbox-item:hover .badge.bg-primary,
    .status-checkbox-item:hover .badge.bg-warning,
    .currency-item:hover .badge,
    .currency-item:hover .badge.bg-secondary,
    .currency-item:hover .badge.bg-primary,
    .status-marketplace-item:hover .badge,
    .status-marketplace-item:hover .badge.bg-secondary,
    .status-marketplace-item:hover .badge.bg-primary {
      background-color: var(--primary, #2563eb) !important;
      color: white !important;
      opacity: 1 !important;
    }
    
    /* Исключаем бейджи из общего правила для span внутри label */
    .status-checkbox-item .form-check-label .status-count,
    .status-checkbox-item .form-check-label .badge.status-count,
    .status-checkbox-item .form-check-label .badge.bg-secondary.status-count,
    .status-checkbox-item .form-check-label .badge.bg-primary.status-count,
    .status-checkbox-item .form-check-label .badge.bg-warning.status-count {
      color: white !important;
    }
    
    .status-checkbox-item:hover .form-check-label .status-count,
    .status-checkbox-item:hover .form-check-label .badge.status-count,
    .status-checkbox-item:hover .form-check-label .badge.bg-secondary.status-count,
    .status-checkbox-item:hover .form-check-label .badge.bg-primary.status-count,
    .status-checkbox-item:hover .form-check-label .badge.bg-warning.status-count {
      color: white !important;
    }
    
    .status-checkbox-item .form-check-input:checked ~ .form-check-label {
      font-weight: 500;
      color: var(--primary, #2563eb) !important;
    }
    
    .status-checkbox-item .form-check-input:checked ~ .form-check-label span {
      color: var(--primary, #2563eb) !important;
    }
    
    #statusDropdown {
      min-height: 31px;
      border-color: var(--border-medium);
      font-size: 0.875rem;
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusDropdown:hover {
      background-color: var(--hover-bg, #f8f9fa);
      border-color: var(--border-dark);
      color: var(--text-primary, #1f2937) !important;
    }
    
    #statusDropdown:active,
    #statusDropdown:focus,
    #statusDropdown.show {
      background-color: var(--hover-bg, #f8f9fa) !important;
      border-color: var(--border-dark) !important;
      color: var(--text-primary, #1f2937) !important;
      box-shadow: none;
    }
    
    #statusDropdownLabel {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем читаемость текста при всех состояниях кнопки */
    #statusDropdown:hover #statusDropdownLabel,
    #statusDropdown:active #statusDropdownLabel,
    #statusDropdown:focus #statusDropdownLabel,
    #statusDropdown.show #statusDropdownLabel {
      color: var(--text-primary, #1f2937) !important;
    }
    
    /* Обеспечиваем видимость иконки стрелки dropdown при всех состояниях */
    #statusDropdown::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    #statusDropdown:hover::after,
    #statusDropdown:active::after,
    #statusDropdown:focus::after,
    #statusDropdown.show::after {
      border-top-color: var(--text-primary, #1f2937) !important;
    }
    
    /* Предотвращаем закрытие dropdown при клике на чекбоксы */
    .status-dropdown-menu {
      padding: 0.75rem 0.5rem;
    }
    
    .status-dropdown-menu .btn-sm {
      font-size: 0.75rem;
      padding: 0.375rem 0.5rem;
      height: auto;
      min-height: 32px;
    }
    
    /* Улучшаем прокрутку */
    .status-dropdown-menu::-webkit-scrollbar {
      width: 6px;
    }

    /* Компактный режим интерфейса отключен */
    
    .status-dropdown-menu::-webkit-scrollbar-track {
      background: var(--bg-light, #f1f1f1);
      border-radius: 3px;
    }
    
    .status-dropdown-menu::-webkit-scrollbar-thumb {
      background: var(--border-dark, #c1c1c1);
      border-radius: 3px;
    }
    
    .status-dropdown-menu::-webkit-scrollbar-thumb:hover {
      background: var(--gray-500, #999);
    }
    
    /* Выравнивание фильтров на одной линии */
    .row.g-2.align-items-end .col-md-3,
    .row.g-2.align-items-end .col-md-4,
    .row.g-2.align-items-end .col-lg-2,
    .row.g-2.align-items-end .col-lg-3 {
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
    }
    
    .row.g-2.align-items-end .form-label {
      margin-bottom: 0.25rem;
    }
    
    .row.g-2.align-items-end .form-control,
    .row.g-2.align-items-end .form-select,
    .row.g-2.align-items-end .dropdown {
      margin-bottom: 0;
    }

    .form-label {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--gray-700);
      margin-bottom: 0.25rem;
    }

    .form-label.small {
      font-size: 0.75rem;
      margin-bottom: 0.125rem;
    }

    /* Кнопки */
    .btn {
      border-radius: var(--radius-md);
      font-weight: 600;
      font-size: 0.875rem;
      padding: var(--space-3) var(--space-4);
      transition: all 0.2s ease;
      border: 1px solid transparent;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
    }

    .btn-primary {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .btn-primary:hover {
      background: var(--primary-dark);
      border-color: var(--primary-dark);
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn-secondary {
      background: var(--gray-100);
      color: var(--gray-700);
      border-color: var(--gray-200);
    }

    .btn-secondary:hover {
      background: var(--gray-200);
      border-color: var(--gray-300);
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn-outline-primary {
      background: transparent;
      color: var(--primary);
      border-color: var(--primary);
    }

    .btn-outline-primary:hover {
      background: var(--primary);
      color: white;
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn-outline-danger {
      background: transparent;
      color: var(--danger);
      border-color: var(--danger);
    }

    .btn-outline-danger:hover {
      background: var(--danger);
      color: white;
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn-outline-success {
      background: transparent;
      color: var(--success);
      border-color: var(--success);
    }

    .btn-outline-success:hover {
      background: var(--success);
      color: white;
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    /* Таблица */
    .table-container {
      background: var(--bg-primary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-xl);
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }

    .table {
      margin: 0;
      font-size: 0.875rem;
    }

    .table thead th {
      background: var(--gray-50);
      border: none;
      padding: var(--space-3) var(--space-2);
      font-weight: 600;
      color: var(--gray-700);
      text-align: left;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .table tbody td {
      padding: var(--space-3) var(--space-2);
      border-top: 1px solid var(--border-light);
      vertical-align: middle;
    }

    .table tbody tr:hover {
      background: var(--gray-50);
    }
    
    /* Стили для кликабельных строк таблицы */
    .table tbody tr[data-id] {
      cursor: pointer;
      transition: background-color 0.15s ease;
    }
    
    /* Hover для обычных строк (не выбранных) */
    .table tbody tr[data-id]:hover:not(.row-selected) {
      background: var(--gray-100) !important;
    }
    
    /* Исключаем курсор pointer для интерактивных элементов внутри строки */
    .table tbody tr[data-id] a,
    .table tbody tr[data-id] button,
    .table tbody tr[data-id] .row-checkbox,
    .table tbody tr[data-id] .field-edit-btn,
    .table tbody tr[data-id] .copy-btn,
    .table tbody tr[data-id] .btn,
    .table tbody tr[data-id] .pw-mask {
      cursor: default;
    }
    
    /* Для кнопок и ссылок внутри строки - обычный курсор */
    .table tbody tr[data-id] button:hover,
    .table tbody tr[data-id] a:hover,
    .table tbody tr[data-id] .btn:hover {
      cursor: pointer;
    }
    
    /* Подсветка выбранной строки - яркий голубой фон с акцентной границей */
    .table tbody tr[data-id].row-selected {
      background-color: #dbeafe !important; /* Яркий голубой оттенок для лучшей видимости */
      border-left: 4px solid var(--primary, #2563eb) !important; /* Синяя акцентная граница слева */
      position: relative;
    }
    
    /* Применяем фон ко всем ячейкам выбранной строки */
    .table tbody tr[data-id].row-selected td {
      background-color: #dbeafe !important;
    }
    
    /* При наведении на выбранную строку - еще более яркий оттенок */
    .table tbody tr[data-id].row-selected:hover {
      background-color: #bfdbfe !important; /* Еще более яркий голубой при наведении */
      border-left-color: var(--primary-dark, #1d4ed8) !important;
    }
    
    /* Применяем фон ко всем ячейкам выбранной строки при наведении */
    .table tbody tr[data-id].row-selected:hover td {
      background-color: #bfdbfe !important;
    }

    /* Статусные бейджи */
    .badge {
      font-size: 0.75rem;
      font-weight: 600;
      padding: var(--space-1) var(--space-3);
      border-radius: var(--radius-sm);
      text-transform: uppercase;
      letter-spacing: 0.025em;
    }

    .badge-new {
      background: var(--gray-100);
      color: var(--gray-700);
    }

    .badge-add_selphi_true {
      background: rgb(16 185 129 / 0.1);
      color: var(--success);
    }

    .badge-error_login {
      background: rgb(239 68 68 / 0.1);
      color: var(--danger);
    }

    .badge-empty-status {
      background: rgb(253 126 20 / 0.1);
      color: var(--warning);
      font-style: italic;
    }

    .badge-selphie_disable {
      background: rgb(217 119 6 / 0.1);
      color: var(--warning);
    }
    
    /* Бейдж по умолчанию - светлый фон и черный текст для читаемости */
    .badge-default,
    .badge.badge-default,
    .badge.badge-default.field-value {
      background-color: var(--gray-200, #e5e7eb) !important;
      color: var(--gray-900, #111827) !important;
    }

    /* Пагинация */
    .pagination {
      display: flex;
      gap: var(--space-1);
      margin: 0;
      padding: var(--space-3);
      justify-content: center;
    }

    .page-link {
      border: 1px solid var(--border-medium);
      border-radius: var(--radius-md);
      padding: var(--space-2) var(--space-3);
      color: var(--gray-600);
      text-decoration: none;
      transition: all 0.2s ease;
      background: var(--bg-primary);
    }

    .page-link:hover {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .page-item.active .page-link {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    /* Большие экраны */
    @media (min-width: 1400px) {
      .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: var(--space-5);
      }

      .stat-card {
        padding: var(--space-5);
      }

      .stat-value {
        font-size: 3rem;
      }

      .toolbar {
        padding: var(--space-3);
      }
    }

    /* Очень большие экраны */
    @media (min-width: 1920px) {
      .container-fluid {
        padding: 0 var(--space-6);
      }

      .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: var(--space-6);
      }

      .stat-card {
        padding: var(--space-6);
      }

      .stat-value {
        font-size: 3.5rem;
      }

      .toolbar {
        padding: var(--space-4);
      }
    }

    /* Плавные переходы для таблицы */
    .table-responsive {
      transition: opacity 0.3s ease;
    }

    .table-responsive.loading {
      opacity: 0.7;
    }

    /* Адаптивность */
    @media (max-width: 768px) {
      .container-fluid {
        padding: 0 var(--space-3);
      }

      .stats-grid {
        grid-template-columns: 1fr;
        gap: var(--space-3);
      }

      .stat-card {
        padding: var(--space-3);
      }

      .stat-value {
        font-size: 2rem;
      }

      .toolbar {
        padding: var(--space-2);
      }

      .toolbar-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-1);
      }

      .toolbar-actions {
        width: 100%;
        justify-content: space-between;
      }

      .scroll-to-top {
        bottom: 1rem;
        right: 1rem;
        width: 2.5rem;
        height: 2.5rem;
        font-size: 1rem;
      }

      .table-responsive {
        font-size: 0.8rem;
      }

      .btn {
        padding: var(--space-2) var(--space-3);
        font-size: 0.8rem;
      }
    }

    /* Анимации */
    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .fade-in {
      animation: fadeIn 0.3s ease-out;
    }

    /* Утилиты */
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .fw-bold { font-weight: 700; }
    .fw-semibold { font-weight: 600; }
    .fw-medium { font-weight: 500; }
    .text-muted { color: var(--gray-500); }
    .text-primary { color: var(--primary); }
    .text-success { color: var(--success); }
    .text-danger { color: var(--danger); }
    .text-warning { color: var(--warning); }
    .text-info { color: var(--info); }

    /* Скрытие элементов */
    .d-none { display: none !important; }
    .d-block { display: block !important; }
    .d-flex { display: flex !important; }
    .d-inline-flex { display: inline-flex !important; }

    /* Отступы */
    .mb-0 { margin-bottom: 0 !important; }
    .mb-1 { margin-bottom: var(--space-1) !important; }
    .mb-2 { margin-bottom: var(--space-2) !important; }
    .mb-3 { margin-bottom: var(--space-3) !important; }
    .mb-4 { margin-bottom: var(--space-4) !important; }
    .mb-5 { margin-bottom: var(--space-5) !important; }
    .mb-6 { margin-bottom: var(--space-6) !important; }

    .mt-0 { margin-top: 0 !important; }
    .mt-1 { margin-top: var(--space-1) !important; }
    .mt-2 { margin-top: var(--space-2) !important; }
    .mt-3 { margin-top: var(--space-3) !important; }
    .mt-4 { margin-top: var(--space-4) !important; }
    .mt-5 { margin-top: var(--space-5) !important; }
    .mt-6 { margin-top: var(--space-6) !important; }

    .me-1 { margin-right: var(--space-1) !important; }
    .me-2 { margin-right: var(--space-2) !important; }
    .me-3 { margin-right: var(--space-3) !important; }
    .me-4 { margin-right: var(--space-4) !important; }

    .ms-1 { margin-left: var(--space-1) !important; }
    .ms-2 { margin-left: var(--space-2) !important; }
    .ms-3 { margin-left: var(--space-3) !important; }
    .ms-4 { margin-left: var(--space-4) !important; }

    /* Gap */
    .gap-1 { gap: var(--space-1) !important; }
    .gap-2 { gap: var(--space-2) !important; }
    .gap-3 { gap: var(--space-3) !important; }
    .gap-4 { gap: var(--space-4) !important; }

    /* Flexbox */
    .align-items-center { align-items: center !important; }
    .justify-content-between { justify-content: space-between !important; }
    .justify-content-center { justify-content: center !important; }

    /* Специальные стили для функционала */
    .pw-mask {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
    }

    .pw-dots {
      letter-spacing: 0.1em;
      font-weight: 600;
      color: var(--gray-500);
    }

    .pw-text:empty::before {
      content: '(пусто)';
      color: var(--gray-400);
      font-style: italic;
    }

    .pw-mask {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }

    .pw-toggle, .pw-edit {
      border: 1px solid var(--border-medium);
      background: var(--bg-secondary);
      padding: var(--space-1) var(--space-2);
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: all 0.2s ease;
      color: var(--gray-600);
      font-size: 0.875rem;
      opacity: 0.7;
    }

    .pw-mask:hover .pw-toggle,
    .pw-mask:hover .pw-edit {
      opacity: 1;
    }

    .pw-toggle:hover {
      background: var(--gray-100);
      color: var(--primary);
      border-color: var(--primary);
      transform: translateY(-1px);
    }

    .pw-edit {
      color: var(--gray-600);
    }

    .pw-edit:hover {
      background: var(--success-50, #f0fdf4);
      color: var(--success);
      border-color: var(--success);
      transform: translateY(-1px);
    }

    /* Стили для кнопок редактирования всех полей */
    .editable-field-wrap {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }

    .field-edit-btn {
      border: 1px solid var(--border-medium);
      background: var(--bg-secondary);
      padding: var(--space-1) var(--space-2);
      border-radius: var(--radius-sm);
      cursor: pointer;
      transition: all 0.2s ease;
      color: var(--gray-600);
      font-size: 0.875rem;
      opacity: 0.6;
    }

    .editable-field-wrap:hover .field-edit-btn {
      opacity: 1;
    }

    .field-edit-btn:hover {
      background: var(--success-50, #f0fdf4);
      color: var(--success);
      border-color: var(--success);
      transform: translateY(-1px);
    }

    .copy-btn {
      border: none;
      background: rgb(37 99 235 / 0.1);
      color: var(--primary);
      padding: var(--space-1) var(--space-2);
      border-radius: var(--radius-sm);
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .copy-btn:hover {
      background: rgb(37 99 235 / 0.2);
    }

    .truncate {
      max-width: 200px;
      display: inline-block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .truncate:hover {
      color: var(--primary);
    }

    /* Форма чекбоксов */
    .form-check-input {
      width: 1.125rem;
      height: 1.125rem;
      border-radius: var(--radius-sm);
      border: 1px solid var(--border-medium);
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .form-check-input:checked {
      background-color: var(--primary);
      border-color: var(--primary);
    }

    /* Стили для редактируемых полей (textarea при редактировании) */
    #accountsTable td textarea {
      min-height: 80px;
      font-family: 'Courier New', monospace;
      font-size: 0.875rem;
    }

    /* Прилипающий горизонтальный скроллбар - стили перемещены в assets/css/sticky-scrollbar.css */

    /* Загрузка */
    .loading-overlay {
      position: absolute;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, 0.9);
      backdrop-filter: blur(3px);
      z-index: 1000;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
    }

    .loading-overlay.show {
      opacity: 1;
      pointer-events: all;
    }

    .loading-overlay .loader {
      width: 48px;
      height: 48px;
    }

    .loading-overlay .loading-text {
      font-size: 0.875rem;
      color: var(--gray-600);
      margin-top: 0.5rem;
    }

    /* Принудительное скрытие элементов */
    .force-hidden {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
      pointer-events: none !important;
    }

    /* Прелоадер для статистических карточек */
    .stats-loading-overlay {
      position: absolute;
      top: 0;
      right: 0;
      bottom: 0;
      left: 0;
      background: rgba(255, 255, 255, 0.8);
      backdrop-filter: blur(2px);
      display: none !important; /* Скрыт по умолчанию */
      align-items: center;
      justify-content: center;
      z-index: 5;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
      border-radius: var(--radius-xl);
      visibility: hidden; /* Дополнительное скрытие */
    }

    .stats-loading-overlay.show {
      display: flex !important; /* Показываем только с классом show */
      opacity: 1;
      pointer-events: all;
      visibility: visible; /* Показываем при классе show */
    }

    .stats-loading-overlay .loader {
      width: 32px;
      height: 32px;
    }

    /* Кнопка "Наверх" */
    .scroll-to-top {
      position: fixed;
      bottom: 2rem;
      right: 2rem;
      width: 3rem;
      height: 3rem;
      background: var(--primary);
      color: white;
      border: none;
      border-radius: 50%;
      box-shadow: var(--shadow-lg);
      cursor: pointer;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transform: translateY(20px);
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.125rem;
    }

    .scroll-to-top.show {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
    }

    .scroll-to-top:hover {
      background: var(--primary-dark);
      transform: translateY(-2px);
      box-shadow: var(--shadow-xl);
    }

    .scroll-to-top:active {
      transform: translateY(0);
    }

    /* Стили для dropdown статусов */
    
    /* Компактный блок фильтров */
    .filters-compact .card-body {
      padding: 0.75rem;
    }

    .filters-compact .row {
      --bs-gutter-x: 0.75rem;
      --bs-gutter-y: 0.5rem;
    }

    .filters-compact .form-label {
      font-size: 0.75rem;
      margin-bottom: 0.125rem;
      font-weight: 500;
    }

    .filters-compact .form-control,
    .filters-compact .form-select {
      padding: 0.25rem 0.5rem;
      font-size: 0.8125rem;
    }

    .filters-compact .btn-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.75rem;
    }

    .filters-compact .card-header {
      padding: 0.5rem 0.75rem;
    }

    /* Красивая центровка и выравнивание фильтров */
    .filters-compact .card-body > .row {
      align-items: flex-end;
    }

    /* Правильное выравнивание колонок */
    .filters-compact .col-md-4,
    .filters-compact .col-md-3,
    .filters-compact .col-md-2,
    .filters-compact .col-lg-2,
    .filters-compact .col-lg-3,
    .filters-compact .col-lg-7 {
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
    }

    /* Центровка для чекбоксов дополнительных фильтров */
    .filters-compact .d-flex.flex-wrap.gap-1 {
      align-items: center;
      gap: 0.5rem !important;
    }

    /* Выравнивание для диапазонов дат и чисел */
    .filters-compact .row.g-1 > div {
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
    }

    /* Красивое выравнивание input полей */
    .filters-compact input[type="number"],
    .filters-compact input[type="date"],
    .filters-compact .form-control,
    .filters-compact .form-select {
      width: 100%;
      text-align: left;
    }

    /* Центровка для label */
    .filters-compact .form-label {
      text-align: left;
      margin-bottom: 0.125rem;
    }

    /* Прелоадер страницы */
    .page-loader {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: var(--bg-primary);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    .page-loader.hidden {
      opacity: 0;
      visibility: hidden;
    }

    .page-loader .middle {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
    }

  </style>
</head>
<body>
  <!-- Прелоадер страницы -->
  <div class="page-loader" id="pageLoader">
    <div class="middle">
      <span class="loader loader-primary"></span>
    </div>
  </div>
  <!-- Современный хедер -->
  <header class="modern-header">
    <!-- Левая часть: профиль -->
    <div class="modern-header-left">
      <!-- Профиль пользователя -->
      <div class="user-profile" id="userProfileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <div class="user-avatar">
          <?php 
          $username = getCurrentUser();
          $initial = mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8');
          echo e($initial);
          ?>
        </div>
        <span class="user-name"><?= e($username) ?></span>
        <i class="fas fa-chevron-down user-dropdown-icon"></i>
      </div>
      
      <!-- Dropdown меню профиля -->
      <ul class="dropdown-menu" aria-labelledby="userProfileDropdown">
        <li><a class="dropdown-item" href="index.php"><i class="fas fa-home me-2"></i>Главная</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Выйти</a></li>
      </ul>
      
      <!-- Иконки действий -->
      <div class="header-actions">
        <button class="header-action-btn" id="autoRefreshToggle" title="Автообновление">
          <i class="fas fa-sync-alt"></i>
        </button>
        <button class="header-action-btn" data-bs-toggle="modal" data-bs-target="#settingsModal" title="Настройки">
          <i class="fas fa-cog"></i>
        </button>
        <a href="trash.php" class="header-action-btn" title="Корзина">
          <i class="fas fa-trash-alt"></i>
        </a>
      </div>
    </div>
    
    <!-- Правая часть: индикатор БД -->
    <div class="modern-header-right">
      <div class="db-status-indicator">
        <span class="db-status-dot"></span>
        <span class="db-status-text">Активное подключение к БД</span>
      </div>
    </div>
  </header>

  <!-- Основной контент -->
  <main class="container-fluid">
    <!-- Статистические карточки -->
    <div class="stats-grid" id="statsRow">
      <!-- Прелоадер для статистических карточек (скрыт по умолчанию, показывается только при обновлении) -->
      <div class="stats-loading-overlay" id="statsLoading" style="display: none;">
        <div class="text-center">
          <span class="loader loader-primary"></span>
        </div>
      </div>
      <!-- Общая статистика -->
      <div class="stat-card fade-in" data-card="total">
        <button type="button" class="stat-card-hide-btn" data-card="total" title="Скрыть карточку" aria-label="Скрыть карточку">
          <i class="fas fa-eye-slash"></i>
        </button>
        <div class="stat-header">
          <h3 class="stat-title">Всего аккаунтов</h3>
        </div>
        <div class="stat-value"><?= number_format((int)$totals['all']) ?></div>
        <?php if ($recentAll !== null): ?>
        <div class="stat-change positive">
          <i class="fas fa-arrow-up"></i>
          <span>+<?= number_format((int)$recentAll) ?> за 24ч</span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Пустые статусы -->
      <div class="stat-card fade-in <?= $emptyStatusCount > 0 ? '' : 'd-none force-hidden' ?>" data-card="empty_status" <?= $emptyStatusCount > 0 ? '' : 'hidden' ?>>
        <button type="button" class="stat-card-hide-btn" data-card="empty_status" title="Скрыть карточку" aria-label="Скрыть карточку">
          <i class="fas fa-eye-slash"></i>
        </button>
        <div class="stat-header">
          <h3 class="stat-title">Пустые статусы</h3>
        </div>
        <div class="stat-value" id="emptyStatusCount"><?= $emptyStatusCount > 0 ? number_format($emptyStatusCount) : '-' ?></div>
        <?php if ($emptyStatusCount > 0): ?>
        <div class="stat-action">
          <a href="empty_status_page.php" class="btn btn-sm btn-warning">
            <i class="fas fa-edit me-1"></i>
            Управление
          </a>
        </div>
        <?php endif; ?>
      </div>

      <!-- Email + 2FA -->
      <div class="stat-card fade-in" data-card="custom:email_twofa">
        <button type="button" class="stat-card-hide-btn" data-card="custom:email_twofa" title="Скрыть карточку" aria-label="Скрыть карточку">
          <i class="fas fa-eye-slash"></i>
        </button>
        <div class="stat-header">
          <h3 class="stat-title">Email + 2FA</h3>
        </div>
        <div class="stat-value"><?= number_format($countEmailTwoFa) ?></div>
      </div>

      <!-- Статистика по статусам -->
      <?php foreach ($byStatus as $stName => $cnt): $safeKey = preg_replace('~[^a-z0-9_]+~i','_', $stName); ?>
      <div class="stat-card fade-in" data-card="status:<?= e($safeKey) ?>" data-status="<?= e($stName) ?>">
        <button type="button" class="stat-card-hide-btn" data-card="status:<?= e($safeKey) ?>" title="Скрыть карточку" aria-label="Скрыть карточку">
          <i class="fas fa-eye-slash"></i>
        </button>
        <div class="stat-header">
          <h3 class="stat-title"><?= e($stName) ?></h3>
        </div>
        <div class="stat-value"><?= number_format($cnt) ?></div>
        <?php if (!empty($recentByStatus) && isset($recentByStatus[$stName])): ?>
        <div class="stat-change positive">
          <i class="fas fa-arrow-up"></i>
          <span>+<?= number_format((int)$recentByStatus[$stName]) ?> за 24ч</span>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>



    <!-- Панель инструментов -->
    <div class="toolbar">
      <div class="toolbar-header">
        <h2 class="toolbar-title">Управление аккаунтами</h2>
        <div class="toolbar-actions">
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted">Выбрано:</span>
            <span class="badge bg-primary" id="selectedCount">0</span>
          </div>
          <div class="d-flex gap-2 flex-wrap align-items-center">
            <button class="btn btn-outline-success" id="exportSelectedCsv" disabled>
              <i class="fas fa-file-csv"></i>
              CSV
            </button>
            <button class="btn btn-outline-info" id="exportSelectedTxt" disabled>
              <i class="fas fa-file-alt"></i>
              TXT
            </button>
            <button class="btn btn-outline-danger" id="deleteSelected" disabled>
              <i class="fas fa-trash"></i>
              Удалить
            </button>
            <button class="btn btn-outline-primary" id="changeStatusSelected" disabled>
              <i class="fas fa-tag"></i>
              Статус
            </button>
            <button class="btn btn-outline-secondary" id="bulkEditFieldBtn" disabled>
              <i class="fas fa-edit"></i>
              Поле
            </button>
            <button class="btn btn-success" id="addAccountBtn" data-bs-toggle="modal" data-bs-target="#addAccountModal">
              <i class="fas fa-plus"></i>
              Добавить аккаунт
            </button>
            <button class="btn btn-outline-warning" id="transferAccountsBtn">
              <i class="fas fa-exchange-alt"></i>
              Перенос
            </button>
            <button class="btn btn-outline-dark" id="clearAllSelectedBtn" style="display: none;">
              <i class="fas fa-times-circle"></i>
              Сбросить все
            </button>
          </div>
        </div>
      </div>
      <!-- Уведомление о выборе строк (отдельная строка для избежания смещения таблицы) -->
      <div class="selection-notice-wrapper" id="selectAllNotice" style="display: none;">
        <div class="selection-notice">
          <i class="fas fa-info-circle me-2"></i>
          <span class="selection-notice-text"></span>
        </div>
      </div>
    </div>

  <!-- Фильтры (Современный дизайн) -->
  <div class="filters-modern">
    <!-- Заголовок -->
    <div class="filters-modern-header">
      <div class="filters-modern-header-left">
        <div class="filters-modern-icon">
          <i class="fas fa-filter"></i>
        </div>
        <span class="filters-modern-title">Фильтры</span>
        <?php if ($activeFiltersCount > 0): ?>
        <span class="filters-modern-badge"><?= (int)$activeFiltersCount ?></span>
        <?php endif; ?>
      </div>
      <div class="filters-modern-actions" id="filtersActionsContainer">
        <div id="savedFiltersContainer" style="display: inline-block; margin-right: 8px;"></div>
        <button class="filters-modern-btn primary" type="button" data-bs-toggle="collapse" data-bs-target="#filtersBody" aria-expanded="true">
          <i class="fas fa-sliders-h"></i>
          <span class="d-none d-md-inline">Настроить</span>
        </button>
      </div>
    </div>

    <!-- Активные фильтры (Chips) -->
    <div class="active-filters-section <?= $activeFiltersCount > 0 ? 'has-filters' : '' ?>" id="activeFiltersSection">
      <div class="active-filters-label">Активные фильтры</div>
      <div class="active-filters-list" id="activeFiltersList">
        <?php if ($q !== ''): ?>
        <div class="filter-chip" data-filter="q">
          <i class="fas fa-search filter-chip-icon"></i>
          <span>Поиск: "<?= e(mb_substr($q, 0, 20)) ?><?= mb_strlen($q) > 20 ? '...' : '' ?>"</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('q')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php foreach ($statusArray as $selectedStatus): ?>
        <div class="filter-chip" data-filter="status" data-status-value="<?= e($selectedStatus) ?>">
          <i class="fas fa-tag filter-chip-icon"></i>
          <span><?= e($selectedStatus) ?></span>
          <button class="filter-chip-remove" title="Удалить">&times;</button>
        </div>
        <?php endforeach; ?>
        
        <?php if (!empty($emptyStatusParam)): ?>
        <div class="filter-chip" data-filter="status" data-status-value="__empty__">
          <i class="fas fa-exclamation-triangle filter-chip-icon"></i>
          <span>Пустой статус</span>
          <button class="filter-chip-remove" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if ($hasEmailParam !== ''): ?>
        <div class="filter-chip" data-filter="has_email">
          <i class="fas fa-envelope filter-chip-icon"></i>
          <span>Есть Email</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_email')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if ($hasTwoFaParam !== ''): ?>
        <div class="filter-chip" data-filter="has_two_fa">
          <i class="fas fa-shield-alt filter-chip-icon"></i>
          <span>Есть 2FA</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_two_fa')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasTokenParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_token">
          <i class="fas fa-key filter-chip-icon"></i>
          <span>Есть Token</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_token')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasFanPageParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_fan_page">
          <i class="fas fa-flag filter-chip-icon"></i>
          <span>Есть Fan Page</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_fan_page')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($pharmaFrom) || !empty($pharmaTo)): ?>
        <div class="filter-chip" data-filter="pharma">
          <i class="fas fa-pills filter-chip-icon"></i>
          <span>Pharma: <?= e($pharmaFrom ?: '0') ?>-<?= e($pharmaTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('pharma')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasAvatarParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_avatar">
          <i class="fas fa-image filter-chip-icon"></i>
          <span>Есть Аватар</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_avatar')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasPasswordParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_password">
          <i class="fas fa-lock filter-chip-icon"></i>
          <span>Есть Пароль</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_password')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($favoritesOnlyParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="favorites_only">
          <i class="fas fa-star filter-chip-icon" style="color: var(--color-warning);"></i>
          <span>Только избранные</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('favorites_only')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasCoverParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_cover">
          <i class="fas fa-image filter-chip-icon"></i>
          <span>Есть Обложка</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_cover')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($fullFilledParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="full_filled">
          <i class="fas fa-check-circle filter-chip-icon"></i>
          <span>Полностью заполненные</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('full_filled')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($friendsFrom) || !empty($friendsTo)): ?>
        <div class="filter-chip" data-filter="friends">
          <i class="fas fa-users filter-chip-icon"></i>
          <span>Друзья: <?= e($friendsFrom ?: '0') ?>-<?= e($friendsTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('friends')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($yearCreatedFrom) || !empty($yearCreatedTo)): ?>
        <div class="filter-chip" data-filter="year_created">
          <i class="fas fa-calendar filter-chip-icon"></i>
          <span>Год: <?= e($yearCreatedFrom ?: '∞') ?>-<?= e($yearCreatedTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('year_created')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($limitRkFrom ?? '') !== '' || ($limitRkTo ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="limit_rk">
          <i class="fas fa-chart-line filter-chip-icon"></i>
          <span>Limit RK: <?= e($limitRkFrom ?: '0') ?>-<?= e($limitRkTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('limit_rk')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($statusMarketplace ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="status_marketplace">
          <i class="fas fa-store filter-chip-icon"></i>
          <span>Marketplace: <?= e($statusMarketplace) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('status_marketplace')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($currencyFilter ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="currency">
          <i class="fas fa-coins filter-chip-icon"></i>
          <span>Currency: <?= e($currencyFilter) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('currency')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($geoFilter ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="geo">
          <i class="fas fa-globe filter-chip-icon"></i>
          <span>Geo: <?= e($geoFilter === '__empty__' ? 'Не указано' : $geoFilter) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('geo')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($statusRkFilter ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="status_rk">
          <i class="fas fa-tag filter-chip-icon"></i>
          <span>Status RK: <?= e($statusRkFilter === '__empty__' ? 'Не указано' : $statusRkFilter) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('status_rk')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Тело фильтров -->
    <div id="filtersBody" class="collapse show">
      <div class="filters-modern-body">
        <form method="get" id="filtersForm">
          <!-- Поисковая строка -->
          <div class="search-field-modern">
            <label class="search-field-modern-label">
              <i class="fas fa-search me-1"></i>Поиск по всем полям
            </label>
            <div class="search-input-wrapper">
              <input 
                type="search" 
                name="q" 
                class="search-input-modern" 
                placeholder="логин, email, имя, фамилия, id..." 
                value="<?= e($q) ?>"
                id="modernSearchInput"
                autocomplete="off">
              <i class="fas fa-search search-input-icon"></i>
              <?php if ($q !== ''): ?>
              <button type="button" class="search-input-clear" onclick="clearSearch()" title="Очистить">
                <i class="fas fa-times"></i>
              </button>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Статусы (Dropdown) -->
          <div class="form-group-modern mt-4">
            <label class="search-field-modern-label">
              <i class="fas fa-tag me-1"></i>Статус
            </label>
            <div class="dropdown w-100">
              <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                      type="button" 
                      id="statusDropdown" 
                      data-bs-toggle="dropdown" 
                      aria-expanded="false"
                      style="min-height: 40px; border-radius: var(--radius-lg); border-width: 1.5px;">
                <span id="statusDropdownLabel">
                  <?php if (empty($statusArray) && empty($emptyStatusParam)): ?>
                    Все статусы
                  <?php else: ?>
                    <?php 
                    $selectedCount = count($statusArray) + (!empty($emptyStatusParam) ? 1 : 0);
                    ?>
                    Выбрано: <?= $selectedCount ?>
                  <?php endif; ?>
                </span>
              </button>
              <div class="dropdown-menu p-2 status-dropdown-menu" aria-labelledby="statusDropdown" style="min-width: 320px; max-height: 450px; overflow-y: auto;">
                <?php if (count($statuses) > 8): ?>
                <div class="mb-2 px-1">
                  <input type="text" class="form-control form-control-sm" id="statusSearch" placeholder="Поиск статусов..." style="font-size: 0.8rem;">
                </div>
                <?php endif; ?>
                
                <div class="d-flex gap-2 mb-2 pb-2 border-bottom">
                  <button type="button" class="btn btn-sm btn-outline-primary flex-fill" id="selectAllStatusesBtn">
                    <i class="fas fa-check-double"></i> Все
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="clearAllStatusesBtn">
                    <i class="fas fa-times"></i> Очистить
                  </button>
                </div>
                
                <!-- Чекбокс для пустых статусов -->
                <div class="form-check status-checkbox-item mb-2 pb-2 border-bottom">
                  <input class="form-check-input status-checkbox" type="checkbox" value="1" id="status_empty" name="empty_status" <?= ($emptyStatusParam??'')!=='' ? 'checked' : '' ?>>
                  <label class="form-check-label w-100 d-flex justify-content-between align-items-center" for="status_empty">
                    <span><i class="fas fa-exclamation-triangle text-warning me-1"></i>Пустой статус</span>
                    <span class="badge bg-warning status-count" data-status="__empty__">
                      <?= isset($byStatus['']) ? number_format($byStatus['']) : 0 ?>
                    </span>
                  </label>
                </div>
                
                <?php foreach ($statuses as $st): ?>
                <div class="form-check status-checkbox-item">
                  <input class="form-check-input status-checkbox" type="checkbox" value="<?= e($st) ?>" id="status_<?= e(preg_replace('/[^a-zA-Z0-9]/', '_', $st)) ?>" name="status[]" <?= in_array($st, $statusArray) ? 'checked' : '' ?>>
                  <label class="form-check-label w-100 d-flex justify-content-between align-items-center" for="status_<?= e(preg_replace('/[^a-zA-Z0-9]/', '_', $st)) ?>">
                    <span><?= e($st) ?></span>
                    <span class="badge bg-secondary status-count" data-status="<?= e($st) ?>">
                      <?= isset($byStatus[$st]) ? number_format($byStatus[$st]) : 0 ?>
                    </span>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Быстрые фильтры (Toggle Switches) -->
          <div class="quick-filters-section">
            <label class="quick-filters-label">
              <i class="fas fa-bolt me-1"></i>Быстрые фильтры
            </label>
            <div class="quick-filters-grid">
              <!-- Email -->
              <div class="toggle-switch-wrapper <?= $hasEmailParam !== '' ? 'active' : '' ?>" 
                   onclick="toggleQuickFilter('has_email', this)">
                <div class="toggle-switch-label-group">
                  <i class="fas fa-envelope toggle-switch-icon"></i>
                  <span class="toggle-switch-label">Email</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_email" value="1" <?= $hasEmailParam !== '' ? 'checked' : '' ?> onchange="event.stopPropagation()">
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- 2FA -->
              <div class="toggle-switch-wrapper <?= $hasTwoFaParam !== '' ? 'active' : '' ?>" 
                   onclick="toggleQuickFilter('has_two_fa', this)">
                <div class="toggle-switch-label-group">
                  <i class="fas fa-shield-alt toggle-switch-icon"></i>
                  <span class="toggle-switch-label">2FA</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_two_fa" value="1" <?= $hasTwoFaParam !== '' ? 'checked' : '' ?> onchange="event.stopPropagation()">
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- Token -->
              <div class="toggle-switch-wrapper <?= ($hasTokenParam ?? '') !== '' ? 'active' : '' ?>" 
                   onclick="toggleQuickFilter('has_token', this)">
                <div class="toggle-switch-label-group">
                  <i class="fas fa-key toggle-switch-icon"></i>
                  <span class="toggle-switch-label">Token</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_token" value="1" <?= ($hasTokenParam ?? '') !== '' ? 'checked' : '' ?> onchange="event.stopPropagation()">
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- Fan Page -->
              <div class="toggle-switch-wrapper <?= ($hasFanPageParam ?? '') !== '' ? 'active' : '' ?>" 
                   onclick="toggleQuickFilter('has_fan_page', this)">
                <div class="toggle-switch-label-group">
                  <i class="fas fa-flag toggle-switch-icon"></i>
                  <span class="toggle-switch-label">Fan Page</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_fan_page" value="1" <?= ($hasFanPageParam ?? '') !== '' ? 'checked' : '' ?> onchange="event.stopPropagation()">
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <?php if (isset($ALL_COLUMNS['avatar'])): ?>
              <!-- Avatar -->
              <div class="toggle-switch-wrapper <?= ($hasAvatarParam ?? '') !== '' ? 'active' : '' ?>" 
                   onclick="toggleQuickFilter('has_avatar', this)">
                <div class="toggle-switch-label-group">
                  <i class="fas fa-user-circle toggle-switch-icon"></i>
                  <span class="toggle-switch-label">Avatar</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_avatar" value="1" <?= ($hasAvatarParam ?? '') !== '' ? 'checked' : '' ?> onchange="event.stopPropagation()">
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              <?php endif; ?>
              
              <!-- Password -->
              <div class="toggle-switch-wrapper <?= ($hasPasswordParam ?? '') !== '' ? 'active' : '' ?>" 
                   onclick="toggleQuickFilter('has_password', this)">
                <div class="toggle-switch-label-group">
                  <i class="fas fa-lock toggle-switch-icon"></i>
                  <span class="toggle-switch-label">Password</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_password" value="1" <?= ($hasPasswordParam ?? '') !== '' ? 'checked' : '' ?> onchange="event.stopPropagation()">
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- Избранное -->
              <div class="toggle-switch-wrapper <?= ($favoritesOnlyParam ?? '') !== '' ? 'active' : '' ?>" 
                   onclick="toggleQuickFilter('favorites_only', this)">
                <div class="toggle-switch-label-group">
                  <i class="fas fa-star toggle-switch-icon" style="color: var(--color-warning);"></i>
                  <span class="toggle-switch-label">Избранное</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="favorites_only" value="1" <?= ($favoritesOnlyParam ?? '') !== '' ? 'checked' : '' ?> onchange="event.stopPropagation()">
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- Дополнительные фильтры (Диапазоны) - Всегда видимые -->
          <?php 
          $hasRangeFilters = isset($ALL_COLUMNS['scenario_pharma']) || 
                            isset($ALL_COLUMNS['quantity_friends']) || 
                            isset($ALL_COLUMNS['year_created']) ||
                            isset($ALL_COLUMNS['limit_rk']) ||
                            isset($ALL_COLUMNS['currency']) ||
                            isset($ALL_COLUMNS['geo']) ||
                            isset($ALL_COLUMNS['status_rk']) ||
                            isset($ALL_COLUMNS['status_marketplace']);
          ?>
          
          <?php if ($hasRangeFilters): ?>
          <div class="mt-4">
            <label class="search-field-modern-label mb-3">
              <i class="fas fa-sliders-h me-1"></i>Дополнительные фильтры
            </label>
            <div class="range-filters-grid">
              <?php if (isset($ALL_COLUMNS['scenario_pharma'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-pills"></i>
                  Сценарий фарма
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="pharma_from" placeholder="От" min="0" max="50" step="1" value="<?= e($pharmaFrom) ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="pharma_to" placeholder="До" min="0" max="50" step="1" value="<?= e($pharmaTo) ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['quantity_friends'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-user-friends"></i>
                  Количество друзей
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="friends_from" placeholder="От" min="0" max="1000" step="1" value="<?= e($friendsFrom) ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="friends_to" placeholder="До" min="0" max="1000" step="1" value="<?= e($friendsTo) ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['year_created'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-calendar"></i>
                  Год создания
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="year_created_from" placeholder="От" min="1900" max="2100" step="1" value="<?= e($yearCreatedFrom ?? '') ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="year_created_to" placeholder="До" min="1900" max="2100" step="1" value="<?= e($yearCreatedTo ?? '') ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['limit_rk'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-chart-line"></i>
                  Limit RK
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="limit_rk_from" placeholder="От" min="0" step="1" value="<?= e($limitRkFrom ?? '') ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="limit_rk_to" placeholder="До" min="0" step="1" value="<?= e($limitRkTo ?? '') ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['status_marketplace']) && (!empty($statusesMarketplace) || $emptyMarketplaceStatusCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-store"></i>
                  Статус Marketplace
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="statusMarketplaceDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="statusMarketplaceDropdownLabel">
                        <?php 
                        if (($statusMarketplace ?? '') === '') {
                          echo 'Все статусы';
                        } elseif (($statusMarketplace ?? '') === '__empty__') {
                          echo 'Не указан';
                        } else {
                          echo e($statusMarketplace);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu status-marketplace-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все статусы -->
                      <li>
                        <div class="status-marketplace-item <?= ($statusMarketplace ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все статусы</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($statusesMarketplace) + $emptyMarketplaceStatusCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Статусы из базы -->
                      <?php foreach ($statusesMarketplace as $statusMkt => $count): ?>
                      <li>
                        <div class="status-marketplace-item <?= ($statusMarketplace ?? '') === $statusMkt ? 'active' : '' ?>" data-value="<?= e($statusMkt) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($statusMkt) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые статусы -->
                      <?php if ($emptyMarketplaceStatusCount > 0): ?>
                      <li>
                        <div class="status-marketplace-item <?= ($statusMarketplace ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указан</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyMarketplaceStatusCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="status_marketplace" id="statusMarketplaceInput" value="<?= e($statusMarketplace ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['currency']) && (!empty($currenciesList) || $emptyCurrencyCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-coins"></i>
                  Валюта
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="currencyDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="currencyDropdownLabel">
                        <?php 
                        if (($currencyFilter ?? '') === '') {
                          echo 'Все валюты';
                        } elseif (($currencyFilter ?? '') === '__empty__') {
                          echo 'Не указана';
                        } else {
                          echo e($currencyFilter);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu currency-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все валюты -->
                      <li>
                        <div class="currency-item <?= ($currencyFilter ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все валюты</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($currenciesList) + $emptyCurrencyCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Валюты из базы -->
                      <?php foreach ($currenciesList as $code => $count): ?>
                      <li>
                        <div class="currency-item <?= ($currencyFilter ?? '') === $code ? 'active' : '' ?>" data-value="<?= e($code) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($code) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые валюты -->
                      <?php if ($emptyCurrencyCount > 0): ?>
                      <li>
                        <div class="currency-item <?= ($currencyFilter ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указана</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyCurrencyCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="currency" id="currencyInput" value="<?= e($currencyFilter ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['geo']) && (!empty($geosList) || $emptyGeoCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-globe"></i>
                  Гео аккаунта
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="geoDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="geoDropdownLabel">
                        <?php 
                        if (($geoFilter ?? '') === '') {
                          echo 'Все geo';
                        } elseif (($geoFilter ?? '') === '__empty__') {
                          echo 'Не указано';
                        } else {
                          echo e($geoFilter);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu geo-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все geo -->
                      <li>
                        <div class="geo-item <?= ($geoFilter ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все geo</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($geosList) + $emptyGeoCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Geo из базы -->
                      <?php foreach ($geosList as $geoValue => $count): ?>
                      <li>
                        <div class="geo-item <?= ($geoFilter ?? '') === $geoValue ? 'active' : '' ?>" data-value="<?= e($geoValue) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($geoValue) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые geo -->
                      <?php if ($emptyGeoCount > 0): ?>
                      <li>
                        <div class="geo-item <?= ($geoFilter ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указано</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyGeoCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="geo" id="geoInput" value="<?= e($geoFilter ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['status_rk']) && (!empty($statusRkList) || $emptyStatusRkCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <i class="fas fa-tag"></i>
                  Status RK
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="statusRkDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="statusRkDropdownLabel">
                        <?php 
                        if (($statusRkFilter ?? '') === '') {
                          echo 'Все статусы RK';
                        } elseif (($statusRkFilter ?? '') === '__empty__') {
                          echo 'Не указано';
                        } else {
                          echo e($statusRkFilter);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu status-rk-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все статусы RK -->
                      <li>
                        <div class="status-rk-item <?= ($statusRkFilter ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все статусы RK</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($statusRkList) + $emptyStatusRkCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Статусы RK из базы -->
                      <?php foreach ($statusRkList as $statusRkValue => $count): ?>
                      <li>
                        <div class="status-rk-item <?= ($statusRkFilter ?? '') === $statusRkValue ? 'active' : '' ?>" data-value="<?= e($statusRkValue) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($statusRkValue) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые статусы RK -->
                      <?php if ($emptyStatusRkCount > 0): ?>
                      <li>
                        <div class="status-rk-item <?= ($statusRkFilter ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указано</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyStatusRkCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="status_rk" id="statusRkInput" value="<?= e($statusRkFilter ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          
          <!-- Разделитель -->
          <div style="height: 1px; background: var(--border-light); margin: var(--space-4) 0;"></div>
          
          <!-- На странице и кнопка применения -->
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
              <label class="form-label small text-muted">Записей на странице</label>
              <select name="per_page" class="form-select form-select-sm" style="width: auto;">
                <?php foreach ([25,50,100,200] as $__pp): ?>
                  <option value="<?= $__pp ?>" <?= $perPage===$__pp ? 'selected' : '' ?>><?= $__pp ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="d-flex flex-column align-items-end gap-1">
              <button type="submit" class="btn btn-sm btn-outline-primary" title="Принудительное обновление страницы" id="applyFiltersBtn">
                <i class="fas fa-sync-alt me-1"></i>
                Обновить
              </button>
              <small class="text-muted" style="font-size: 10px;">
                <i class="fas fa-magic me-1"></i>Фильтры применяются автоматически
              </small>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Таблица -->
  <?php include __DIR__ . '/partials/table/table.php'; ?>
  </main>

  <!-- Кнопка "Наверх" -->
  <button id="scrollToTop" class="scroll-to-top" title="Наверх">
    <i class="fas fa-chevron-up"></i>
  </button>

<!-- Модалка полного значения -->
<div class="modal fade" id="cellModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cellModalTitle">Полное значение</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre class="mono bg-light p-3 rounded" id="cellModalBody" 
             style="white-space: pre-wrap; word-break: break-word;">—</pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="cellCopyBtn">
          <i class="fas fa-copy me-2"></i>Скопировать
        </button>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Закрыть</button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка настроек -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-cog me-2"></i>Настройки дашборда
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6">
            <h6 class="mb-3">
              <i class="fas fa-columns me-2"></i>Видимые колонки
            </h6>
            <div class="column-settings">
              <?php foreach ($ALL_COLUMNS as $k => $title): ?>
              <div class="form-check">
                <input class="form-check-input column-toggle" type="checkbox" 
                       value="<?= e($k) ?>" id="col_<?= e($k) ?>" 
                       data-col="<?= e($k) ?>" checked>
                <label class="form-check-label" for="col_<?= e($k) ?>">
                  <?= e($title) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-md-6">
            <h6 class="mb-3">
              <i class="fas fa-eye me-2"></i>Видимые карточки статистики
            </h6>
            <div class="card-settings">
              <div class="form-check">
                <input class="form-check-input card-toggle" type="checkbox" 
                       value="total" id="card_total" data-card="total" checked>
                <label class="form-check-label" for="card_total">
                  Общее количество
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input card-toggle" type="checkbox"
                       value="custom:email_twofa" id="card_email_twofa" data-card="custom:email_twofa" checked>
                <label class="form-check-label" for="card_email_twofa">
                  Email + 2FA
                </label>
              </div>
              <?php foreach ($byStatus as $stName => $cnt): $safeKey = preg_replace('~[^a-z0-9_]+~i','_', $stName); ?>
              <div class="form-check">
                <input class="form-check-input card-toggle" type="checkbox" 
                       value="status:<?= e($safeKey) ?>" id="card_<?= e($safeKey) ?>" 
                       data-card="status:<?= e($safeKey) ?>" checked>
                <label class="form-check-label" for="card_<?= e($safeKey) ?>">
                  <?= e($stName) ?> <span class="badge bg-secondary ms-2"><?= number_format((int)$cnt) ?></span>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        
        <!-- Секция кастомных карточек -->
        <div class="row mt-4">
          <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0">
                <i class="fas fa-magic me-2"></i>Кастомные карточки статистики
              </h6>
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#customCardModal" id="addCustomCardBtn">
                <i class="fas fa-plus me-1"></i>Создать карточку
              </button>
            </div>
            <div id="customCardsList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
              <div class="text-muted text-center">Загрузка...</div>
            </div>
          </div>
        </div>
        
        <!-- Секция управления названиями блоков -->
        <div class="row mt-4">
          
        </div>

        
      </div>
    </div>
  </div>
</div>

<!-- Модалка подтверждения удаления -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">
          <i class="fas fa-exclamation-triangle me-2"></i>Подтверждение удаления
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Вы действительно хотите удалить <strong id="deleteCount">0</strong> выбранных аккаунтов?</p>
        <p class="text-muted small">Это действие нельзя отменить.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-danger" id="confirmDelete">
          <i class="fas fa-trash me-2"></i>Удалить
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Модальное окно добавления аккаунта -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-plus-circle me-2"></i>Добавить новый аккаунт
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="addAccountErrors" class="alert alert-danger d-none" role="alert"></div>
        <div id="addAccountSuccess" class="alert alert-success d-none" role="alert"></div>
        
        <!-- Инструкция -->
        <div class="alert alert-info mb-4">
          <i class="fas fa-info-circle me-2"></i>
          <strong>Как использовать:</strong>
          <ol class="mb-0 mt-2">
            <li>Нажмите кнопку <strong>"Скачать шаблон CSV"</strong> ниже</li>
            <li>Откройте скачанный файл в Excel или Google Sheets</li>
            <li>Заполните данные аккаунтов (обязательные поля: <strong>login</strong> и <strong>status</strong>)</li>
            <li>Сохраните файл и загрузите его через форму ниже</li>
          </ol>
        </div>
        
        <!-- Кнопка скачивания шаблона -->
        <div class="text-center mb-4">
          <a href="download_account_template.php" class="btn btn-primary btn-lg" id="downloadTemplateBtn">
            <i class="fas fa-download me-2"></i>
            Скачать шаблон CSV
          </a>
        </div>
        
        <hr>
        
        <!-- Форма загрузки файла -->
        <form id="uploadAccountsForm" method="POST" enctype="multipart/form-data" action="import_accounts.php" onsubmit="return false;">
          <?php 
          require_once __DIR__ . '/../auth.php';
          $csrfToken = getCsrfToken();
          ?>
          <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="format" value="csv">
          <input type="hidden" name="duplicate_action" value="skip">
          
          <div class="mb-3">
            <label for="accountsFile" class="form-label">
              <i class="fas fa-file-csv me-2"></i>
              <strong>Выберите заполненный CSV файл:</strong>
            </label>
            <input 
              type="file" 
              class="form-control" 
              id="accountsFile" 
              name="import_file" 
              accept=".csv,.txt"
              required
            >
            <div class="form-text">
              Поддерживаются файлы CSV. Максимальный размер: 20 MB
            </div>
          </div>
          
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="submit" form="uploadAccountsForm" class="btn btn-success" id="uploadAccountsBtn">
          <i class="fas fa-upload me-2"></i>Загрузить аккаунты
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка предварительного просмотра отключена -->

<!-- Модальное окно создания/редактирования кастомной карточки -->
<div class="modal fade" id="customCardModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-magic me-2"></i>Создать кастомную карточку статистики
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="customCardForm">
          <!-- Название карточки -->
          <div class="mb-3">
            <label class="form-label fw-semibold small">Название карточки</label>
            <input type="text" class="form-control form-control-sm" id="customCardName" placeholder="Например: Готовые к продаже с Email" required>
          </div>

          <!-- Фильтры -->
          <div class="mb-3">
            <h6 class="mb-2 small">
              <i class="fas fa-filter me-1"></i>Фильтры для подсчета
            </h6>
            
            <!-- Статусы и булевы фильтры -->
            <div class="row mb-2">
              <!-- Статусы (множественный выбор) -->
              <div class="col-md-6 mb-2">
                <label class="form-label small">Статусы</label>
                <select class="form-select form-select-sm" id="customCardStatuses" multiple size="5" style="font-size: 0.875rem;">
                  <?php foreach ($statuses as $st): 
                    $statusCount = isset($byStatus[$st]) ? (int)$byStatus[$st] : 0;
                  ?>
                  <option value="<?= e($st) ?>">
                    <?= e($st) ?> (<?= number_format($statusCount) ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted" style="font-size: 0.7rem;">
                  Ctrl/Cmd+Click для множественного выбора
                </small>
              </div>
              
              <!-- Булевы фильтры -->
              <div class="col-md-6 mb-2">
                <label class="form-label small">Дополнительные условия</label>
                <div class="row g-1">
                  <div class="col-6">
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasEmail">
                      <label class="form-check-label small" for="customHasEmail">Есть Email</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasTwoFa">
                      <label class="form-check-label small" for="customHasTwoFa">Есть 2FA</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasToken">
                      <label class="form-check-label small" for="customHasToken">Есть Token</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasAvatar">
                      <label class="form-check-label small" for="customHasAvatar">Есть Аватар</label>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasCover">
                      <label class="form-check-label small" for="customHasCover">Есть Обложка</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasPassword">
                      <label class="form-check-label small" for="customHasPassword">Есть Пароль</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasFanPage">
                      <label class="form-check-label small" for="customHasFanPage">Есть Fan Page</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customFullFilled">
                      <label class="form-check-label small" for="customFullFilled">Полностью заполненные</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Диапазоны -->
            <div class="row g-2 mb-2">
              <?php if (isset($ALL_COLUMNS['scenario_pharma'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Сценарий фарма (от)</label>
                <input type="number" class="form-control form-control-sm" id="customPharmaFrom" min="0" max="50" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Сценарий фарма (до)</label>
                <input type="number" class="form-control form-control-sm" id="customPharmaTo" min="0" max="50" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['quantity_friends'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Друзья (от)</label>
                <input type="number" class="form-control form-control-sm" id="customFriendsFrom" min="0" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Друзья (до)</label>
                <input type="number" class="form-control form-control-sm" id="customFriendsTo" min="0" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['year_created'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Год (от)</label>
                <input type="number" class="form-control form-control-sm" id="customYearCreatedFrom" min="2000" max="2100" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Год (до)</label>
                <input type="number" class="form-control form-control-sm" id="customYearCreatedTo" min="2000" max="2100" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['limit_rk'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Limit RK (от)</label>
                <input type="number" class="form-control form-control-sm" id="customLimitRkFrom" min="0" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Limit RK (до)</label>
                <input type="number" class="form-control form-control-sm" id="customLimitRkTo" min="0" placeholder="До">
              </div>
              <?php endif; ?>
            </div>
            
            <!-- Одиночные фильтры -->
            <div class="row g-2 mb-2">
              <?php if (isset($ALL_COLUMNS['status_marketplace'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Marketplace</label>
                <select class="form-select form-select-sm" id="customStatusMarketplace">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($statusesMarketplace) && is_array($statusesMarketplace)): ?>
                    <?php foreach ($statusesMarketplace as $st => $count): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['status_rk']) && (!empty($statusRkList) || $emptyStatusRkCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Status RK</label>
                <select class="form-select form-select-sm" id="customStatusRk">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($statusRkList)): ?>
                    <?php foreach ($statusRkList as $st => $count): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['currency']) && (!empty($currenciesList) || $emptyCurrencyCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Валюта</label>
                <select class="form-select form-select-sm" id="customCurrency">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($currenciesList)): ?>
                    <?php foreach ($currenciesList as $code => $count): ?>
                    <option value="<?= e($code) ?>"><?= e($code) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['geo']) && (!empty($geosList) || $emptyGeoCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Гео аккаунта</label>
                <select class="form-select form-select-sm" id="customGeo">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($geosList)): ?>
                    <?php foreach ($geosList as $geo => $count): ?>
                    <option value="<?= e($geo) ?>"><?= e($geo) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Целевой статус -->
          <div class="mb-2">
            <h6 class="mb-2 small">
              <i class="fas fa-tag me-1"></i>Действие при клике
            </h6>
            <div class="mb-2">
              <label class="form-label small">Установить статус</label>
              <select class="form-select form-select-sm" id="customCardTargetStatus">
                <option value="">Не изменять статус</option>
                <?php foreach ($statuses as $st): ?>
                <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
                <option value="__new__">+ Создать новый статус</option>
              </select>
              <div id="newStatusInputGroup" class="mt-1" style="display: none;">
                <input type="text" class="form-control form-control-sm" id="customCardNewStatus" placeholder="Введите название нового статуса">
              </div>
            </div>
          </div>

          <!-- Цвет карточки -->
          <div class="mb-2">
            <label class="form-label small">Цвет карточки</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" class="form-control form-control-color" id="customCardColor" value="#3b82f6" style="width: 60px; height: 35px;">
              <span class="text-muted" style="font-size: 0.75rem;">Выберите цвет для карточки</span>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="saveCustomCardBtn">
          <i class="fas fa-save me-2"></i>Сохранить карточку
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка смены статуса -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-tag me-2"></i>Изменить статус
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Выберите статус</label>
          <select class="form-select" id="statusSelect">
            <option value="">— Выберите —</option>
            <?php foreach ($statuses as $st): ?>
              <option value="<?= e($st) ?>"><?= e($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="text-center text-muted my-2">или</div>
        <div class="mb-2">
          <label class="form-label">Новый статус</label>
          <input type="text" class="form-control" id="statusNewInput" placeholder="Введите новый статус">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="applyStatusBtn">
          <i class="fas fa-save me-2"></i>Применить
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка переноса аккаунтов -->
<!-- Модальное окно: Массовый перенос аккаунтов (V3.0) -->
<div class="modal fade" id="transferAccountsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning bg-opacity-10">
        <h5 class="modal-title">
          <i class="fas fa-exchange-alt me-2 text-warning"></i>
          Массовый перенос аккаунтов
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        
        <!-- Информация о лимитах -->
        <div class="alert alert-info small mb-3">
          <i class="fas fa-info-circle me-2"></i>
          <strong>Рекомендации:</strong><br>
          • Оптимально: <strong>1,000-2,000 строк</strong> за один запрос (обработка ~5-15 сек)<br>
          • Максимум: 50,000 строк или 20MB текста<br>
          • Для больших объёмов (10,000+) разбейте данные на части по 1,000-2,000 строк
        </div>
        
        <!-- Поле для ввода текста с ID -->
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <i class="fas fa-paste me-1"></i>
            Вставьте текст с ID аккаунтов
          </label>
          <textarea 
            class="form-control font-monospace" 
            id="transferText" 
            rows="10" 
            placeholder="10abc123def456&#10;61xyz789qwe012&#10;&#10;Или строки содержащие ID:&#10;account_10abc123def456_some_data&#10;user: 61xyz789qwe012, status: active"
            style="resize: vertical; min-height: 150px;"></textarea>
          <small class="text-muted mt-1 d-block">
            <strong>Поддерживаемые форматы:</strong><br>
            • <strong>ID аккаунтов:</strong> Начинаются с <code>10</code> или <code>61</code>, затем 10-23 символа (буквы/цифры)<br>
            • <strong>Примеры:</strong> <code>61582480965170</code>, <code>61560904628043</code>, <code>10abc123def456789</code><br>
            • <strong>Числовые ID:</strong> Чистые числа будут искаться по полю <code>id</code><br>
            • Система автоматически извлечет все валидные ID из любого текста (даже из строк с разделителями)
          </small>
        </div>
        
        <!-- Выбор статуса -->
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <i class="fas fa-tag me-1"></i>
            Новый статус
          </label>
          <div class="row g-2">
            <div class="col-md-6">
              <select class="form-select" id="transferStatusSelect">
                <option value="">— Выберите из существующих —</option>
                <?php foreach ($statuses as $st): ?>
                  <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <input 
                type="text" 
                class="form-control" 
                id="transferStatusCustom" 
                placeholder="Или введите новый статус"
                maxlength="100">
            </div>
          </div>
          <div class="form-text">
            <i class="fas fa-search me-1"></i>
            Поиск выполняется в 3 колонках: <strong>id_soc_account</strong> (точное совпадение), <strong>social_url</strong> и <strong>cookies</strong> (вхождение)
          </div>
        </div>
        
        <!-- Дополнительные опции поиска -->
        <div class="mb-3">
          <label class="form-label fw-semibold">
            <i class="fas fa-cog me-1"></i>
            Опции поиска
          </label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="transferEnableLike">
            <label class="form-check-label" for="transferEnableLike">
              Использовать расширенный поиск (LIKE) по <code>social_url</code> и <code>cookies</code>
              <small class="text-muted d-block">
                <strong>⚠️ Медленно!</strong> Если точное совпадение по <code>id_soc_account</code> не найдено, 
                искать вхождение ID в полях <code>social_url</code> и <code>cookies</code>. 
                Может значительно замедлить обработку больших объёмов.
              </small>
            </label>
          </div>
        </div>
        
        <!-- Предупреждение -->
        <div class="alert alert-warning small mb-0">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <strong>Внимание:</strong> Операция необратима. Статусы всех найденных аккаунтов будут изменены.
        </div>
        
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-1"></i>Отмена
        </button>
        <button type="button" class="btn btn-warning" id="applyTransferBtn">
          <i class="fas fa-check me-2"></i>Применить перенос
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Модалка массового редактирования поля -->
<div class="modal fade" id="bulkFieldModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Массовое изменение поля</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Поле</label>
          <select class="form-select" id="bulkFieldSelect">
            <?php foreach ($ALL_COLUMNS as $k => $title): if (in_array($k, ['id'])) continue; ?>
              <option value="<?= e($k) ?>"><?= e($title) ?> (<?= e($k) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Значение</label>
          <input type="text" class="form-control" id="bulkFieldValue" placeholder="Введите значение">
        </div>
        <div class="alert alert-warning small" id="bulkGlobalWarning" style="display: none;">
          <div class="fw-semibold mb-1">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Вы собираетесь изменить поле <span class="bulk-global-field">—</span> для всех записей (без фильтров)
          </div>
          <p class="mb-2">
            Будут обновлены <strong><span class="bulk-global-count">0</span></strong> строк. Это действие нельзя отменить.
          </p>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="bulkGlobalConfirm">
            <label class="form-check-label" for="bulkGlobalConfirm">
              Я понимаю последствия и подтверждаю массовое изменение
            </label>
          </div>
        </div>
        <div class="form-text">Будет применено ко всем выбранным записям<?= isset($filteredTotal) ? ' или ко всем по фильтру' : '' ?>.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="applyBulkFieldBtn"><i class="fas fa-save me-2"></i>Применить</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/nouislider@15.7.1/dist/nouislider.min.js"></script>
<script>
// Тёмная тема отключена

// Флаг для внешних скриптов, чтобы не дублировать обработчики
window.__INLINE_DASHBOARD_ACTIVE__ = true;

// ===== Основные функции =====
// Переведены в assets/js/dashboard.js; ниже — защитные определения на случай отсутствия глобальных версий
if (typeof window.copyToClipboard !== 'function') {
  window.copyToClipboard = function(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => {
        if (typeof window.showToast === 'function') window.showToast('Скопировано в буфер обмена', 'success');
      }).catch(() => {
        window.fallbackCopyTextToClipboard(text);
      });
    } else {
      window.fallbackCopyTextToClipboard(text);
    }
  };
}

if (typeof window.fallbackCopyTextToClipboard !== 'function') {
  window.fallbackCopyTextToClipboard = function(text) {
    const textArea = document.createElement('textarea');
    textArea.value = String(text || '');
    // Для Firefox: элемент должен быть видимым, но можно сделать его очень маленьким
    textArea.style.position = 'fixed';
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.width = '2px';
    textArea.style.height = '2px';
    textArea.style.padding = '0';
    textArea.style.border = 'none';
    textArea.style.outline = 'none';
    textArea.style.boxShadow = 'none';
    textArea.style.background = 'transparent';
    textArea.setAttribute('readonly', '');
    document.body.appendChild(textArea);
    
    // Для Firefox: используем setSelectionRange вместо select()
    textArea.focus();
    textArea.setSelectionRange(0, textArea.value.length);
    
    try {
      const successful = document.execCommand('copy');
      if (successful && typeof window.showToast === 'function') {
        window.showToast('Скопировано в буфер обмена', 'success');
      } else if (!successful && typeof window.showToast === 'function') {
        window.showToast('Ошибка копирования', 'error');
      }
    } catch (err) {
      if (typeof window.showToast === 'function') {
        window.showToast('Ошибка копирования', 'error');
      }
    } finally {
      document.body.removeChild(textArea);
    }
  };
}

if (typeof window.showToast !== 'function') {
  window.showToast = function(message, type = 'info', duration = 3000) {
    // Используем улучшенный класс Toast с progress bar
    if (typeof window.Toast !== 'undefined' && window.Toast.show) {
      // Нормализуем тип
      const normalizedType = type === 'danger' || type === 'error' ? 'error' : 
                            type === 'warning' ? 'warning' : 
                            type === 'success' ? 'success' : 'info';
      
      return window.Toast.show(message, {
        type: normalizedType,
        duration: duration,
        closable: true
      });
    }
    
    // Fallback для старых версий
    const toast = document.createElement('div');
    const bgColor = type === 'success' ? 'success' : (type === 'error' ? 'danger' : 'info');
    toast.className = `toast align-items-center text-white bg-${bgColor} border-0 position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
    toast.innerHTML = `
      <div class="d-flex">
        <div class="toast-body">
          <i class="fas fa-${type === 'success' ? 'check' : (type === 'error' ? 'exclamation-triangle' : 'info-circle')} me-2"></i>
          ${message}
        </div>
        <button type="button" class="toast-close" aria-label="Закрыть" title="Закрыть"><i class="fas fa-times"></i></button>
      </div>
    `;
    document.body.appendChild(toast);
    const closeBtn = toast.querySelector('.toast-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        toast.style.opacity = '0';
        setTimeout(() => {
          if (toast.parentNode) {
            document.body.removeChild(toast);
          }
        }, 300);
      });
    }
    setTimeout(() => {
      toast.style.opacity = '1';
    }, 10);
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => {
        if (toast.parentNode) {
          document.body.removeChild(toast);
        }
      }, 300);
    }, duration);
  };
}

// ===== Управление настройками =====
const LS_KEY_COLUMNS = 'dashboard_visible_columns';
const LS_KEY_CARDS = 'dashboard_visible_cards';
const LS_KEY_KNOWN_COLS = 'dashboard_known_columns';
const LS_KEY_SELECTED = 'dashboard_selected_ids'; // Ключ для хранения выбранных ID
const LS_KEY_HIDDEN_CARDS = 'dashboard_hidden_cards'; // Ключ для хранения скрытых карточек

// ===== Управление чекбоксами =====
let selectedIds = new Set();
let selectedAllFiltered = false; // режим: выделены все по текущему фильтру
let filteredTotalLive = <?= (int)($filteredTotal ?? 0) ?>;
const ACTIVE_FILTERS_COUNT = <?= (int)$activeFiltersCount ?>;

// Инициализация ползунка Scenario pharma
function initializePharmaSlider() {
  const slider = document.getElementById('pharmaSlider');
  if (!slider || typeof noUiSlider === 'undefined') return;
  const min = parseInt(slider.getAttribute('data-min') || '0', 10);
  const max = parseInt(slider.getAttribute('data-max') || '50', 10);
  const fromInit = slider.getAttribute('data-from');
  const toInit = slider.getAttribute('data-to');
  const from = (fromInit!==null && fromInit!=='') ? parseInt(fromInit, 10) : min;
  const to = (toInit!==null && toInit!=='') ? parseInt(toInit, 10) : max;
  const fromInput = document.getElementById('pharma_from');
  const toInput = document.getElementById('pharma_to');
  const fromDisp = document.getElementById('pharmaFromDisplay');
  const toDisp = document.getElementById('pharmaToDisplay');

  noUiSlider.create(slider, {
    start: [Math.max(min, from), Math.min(max, to)],
    connect: true,
    range: { min, max },
    step: 1,
    behaviour: 'tap-drag',
    tooltips: false,
    format: {
      to: (v) => Math.round(v),
      from: (v) => Number(v)
    }
  });

  slider.noUiSlider.on('update', (values) => {
    const [vFrom, vTo] = values.map(Number);
    if (fromDisp) fromDisp.textContent = String(vFrom);
    if (toDisp) toDisp.textContent = String(vTo);
    if (fromInput) fromInput.value = String(vFrom);
    if (toInput) toInput.value = String(vTo);
  });
  slider.noUiSlider.on('change', debounce(() => {
    // Получаем значения из слайдера
    const values = slider.noUiSlider.get();
    const vFrom = Math.round(Number(values[0]));
    const vTo = Math.round(Number(values[1]));
    
    // Обновляем URL параметры
    const url = new URL(window.location);
    if (vFrom > min) {
      url.searchParams.set('pharma_from', String(vFrom));
    } else {
      url.searchParams.delete('pharma_from');
    }
    if (vTo < max) {
      url.searchParams.set('pharma_to', String(vTo));
    } else {
      url.searchParams.delete('pharma_to');
    }
    url.searchParams.set('page', '1');
    
    // Обновляем URL без перезагрузки
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; 
    selectedIds.clear(); 
    updateSelectedCount();
    // Обновляем данные через AJAX
    refreshDashboardData();
  }, 500)); // Дебаунс 500ms для слайдеров
}

function initializeFriendsSlider() {
  const slider = document.getElementById('friendsSlider');
  if (!slider || typeof noUiSlider === 'undefined') return;
  const min = parseInt(slider.getAttribute('data-min') || '0', 10);
  const max = parseInt(slider.getAttribute('data-max') || '1000', 10);
  const fromInit = slider.getAttribute('data-from');
  const toInit = slider.getAttribute('data-to');
  const from = (fromInit!==null && fromInit!=='') ? parseInt(fromInit, 10) : min;
  const to = (toInit!==null && toInit!=='') ? parseInt(toInit, 10) : max;
  const fromInput = document.getElementById('friends_from');
  const toInput = document.getElementById('friends_to');
  const fromDisp = document.getElementById('friendsFromDisplay');
  const toDisp = document.getElementById('friendsToDisplay');

  noUiSlider.create(slider, {
    start: [Math.max(min, from), Math.min(max, to)],
    connect: true,
    range: { min, max },
    step: 1,
    behaviour: 'tap-drag',
    tooltips: false,
    format: {
      to: (v) => Math.round(v),
      from: (v) => Number(v)
    }
  });

  slider.noUiSlider.on('update', (values) => {
    const [vFrom, vTo] = values.map(Number);
    if (fromDisp) fromDisp.textContent = String(vFrom);
    if (toDisp) toDisp.textContent = String(vTo);
    if (fromInput) fromInput.value = String(vFrom);
    if (toInput) toInput.value = String(vTo);
  });
  slider.noUiSlider.on('change', debounce(() => {
    // Получаем значения из слайдера
    const values = slider.noUiSlider.get();
    const vFrom = Math.round(Number(values[0]));
    const vTo = Math.round(Number(values[1]));
    
    // Обновляем URL параметры
    const url = new URL(window.location);
    if (vFrom > min) {
      url.searchParams.set('friends_from', String(vFrom));
    } else {
      url.searchParams.delete('friends_from');
    }
    if (vTo < max) {
      url.searchParams.set('friends_to', String(vTo));
    } else {
      url.searchParams.delete('friends_to');
    }
    url.searchParams.set('page', '1');
    
    // Обновляем URL без перезагрузки
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; 
    selectedIds.clear(); 
    updateSelectedCount();
    // Обновляем данные через AJAX
    refreshDashboardData();
  }, 500)); // Дебаунс 500ms для слайдеров
}

function loadSelectedIds() {
  try {
    const saved = localStorage.getItem(LS_KEY_SELECTED);
    if (saved) {
      selectedIds = new Set(JSON.parse(saved));
    }
  } catch (e) {
    console.error('Error loading selected IDs:', e);
  }
}

function saveSelectedIds() {
  try {
    localStorage.setItem(LS_KEY_SELECTED, JSON.stringify(Array.from(selectedIds)));
  } catch (e) {
    console.error('Error saving selected IDs:', e);
  }
}

function updateSelectedCount() {
  const count = selectedIds.size;
  const selectedCountEl = document.getElementById('selectedCount');
  if (selectedCountEl) {
    selectedCountEl.textContent = selectedAllFiltered ? 'Все по фильтру' : count;
  }
  const exportBtns = document.querySelectorAll('#exportSelectedCsv, #exportSelectedTxt, #deleteSelected, #changeStatusSelected, #bulkEditFieldBtn');
  exportBtns.forEach(btn => btn.disabled = (!selectedAllFiltered && count === 0));
  
  // Показываем/скрываем универсальную кнопку "Сбросить все"
  // Кнопка показывается, если есть выбранные строки ИЛИ активные фильтры
  const clearAllBtn = document.getElementById('clearAllSelectedBtn');
  if (clearAllBtn) {
    const hasSelection = selectedAllFiltered || count > 0;
    // Проверяем наличие активных фильтров через активные чипсы
    const hasActiveFilters = document.querySelectorAll('.filter-chip').length > 0;
    clearAllBtn.style.display = (hasSelection || hasActiveFilters) ? '' : 'none';
  }
  
  const notice = document.getElementById('selectAllNotice');
  if (!notice) return;
  const noticeText = notice.querySelector('.selection-notice-text');
  if (!noticeText) return;
  
  const totalFiltered = filteredTotalLive;
  if (!selectedAllFiltered && count > 0 && totalFiltered > count) {
    notice.style.display = '';
    noticeText.innerHTML = `Выбраны <strong>${count}</strong> на этой странице. <a href="#" id="selectAllFilteredLink">Выделить все ${totalFiltered.toLocaleString('ru-RU')} по фильтру</a>`;
  } else if (selectedAllFiltered) {
    notice.style.display = '';
    noticeText.innerHTML = `Выделены все <strong>${totalFiltered.toLocaleString('ru-RU')}</strong> по фильтру. <a href="#" id="clearSelectionLink">Очистить выбор</a>`;
  } else {
    notice.style.display = 'none';
    noticeText.innerHTML = '';
  }
  // Обновляем компактный счётчик "Отмечено X из Y"
  updateSelectedOnPageCounter();
}

function updateSelectedOnPageCounter() {
  const el = document.getElementById('selectedOnPageCount');
  if (!el) return;
  
  // Используем getAllRowIdsOnPage для правильного подсчета с учетом виртуализации
  // Считаем, сколько строк на странице реально выбрано (из selectedIds)
  const allRowIds = getAllRowIdsOnPage();
  const selectedCount = allRowIds.filter(id => selectedAllFiltered || selectedIds.has(id)).length;
  
  el.textContent = String(selectedCount);
  
  // Также обновляем общее количество строк на странице (showingOnPageTop)
  // Это нужно, так как при виртуализации количество может измениться
  const showingEl = document.getElementById('showingOnPageTop');
  if (showingEl) {
    showingEl.textContent = String(allRowIds.length);
  }
}

function toggleRowSelection(id, checked) {
  if (checked) {
    selectedIds.add(id);
    console.log('✅ Добавлен ID:', id, '| Всего выбрано:', selectedIds.size);
  } else {
    selectedIds.delete(id);
    console.log('❌ Удалён ID:', id, '| Всего выбрано:', selectedIds.size);
  }
  saveSelectedIds();
  updateSelectedCount();
  console.log('📦 Список выбранных ID:', Array.from(selectedIds));
}

// ===== Управление скрытием карточек =====
// Загрузка скрытых карточек из БД
async function loadHiddenCards() {
  try {
    
    // Сначала проверяем localStorage
    const localHiddenCards = (() => {
      try {
        const saved = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
        return saved ? JSON.parse(saved) : [];
      } catch (_) {
        return [];
      }
    })();
    
    // Пытаемся загрузить из БД
    const response = await fetch('api_user_settings.php?type=hidden_cards');
    if (response.ok) {
      const data = await response.json();
      if (data.success && Array.isArray(data.value)) {
        let cardsToHide = data.value;
        
        // КРИТИЧНО: Если БД возвращает пустой массив, но в localStorage есть данные,
        // используем localStorage и синхронизируем с БД
        if (cardsToHide.length === 0 && localHiddenCards.length > 0) {
          cardsToHide = localHiddenCards;
          
          // Синхронизируем БД с localStorage
          try {
            const syncResponse = await fetch('api_user_settings.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
              },
              body: JSON.stringify({
                type: 'hidden_cards',
                value: cardsToHide
              })
            });
            if (syncResponse.ok) {
              // БД синхронизирована с localStorage
            }
          } catch (syncError) {
            console.warn('⚠️ Ошибка синхронизации БД:', syncError);
          }
        } else if (cardsToHide.length > 0) {
          // Если БД содержит данные, обновляем localStorage
          try {
            localStorage.setItem(LS_KEY_HIDDEN_CARDS, JSON.stringify(cardsToHide));
          } catch (_) {}
        }
        
        // Применяем скрытие к карточкам
        if (cardsToHide.length > 0) {
          cardsToHide.forEach(cardId => {
            const card = document.querySelector(`.stat-card[data-card="${cardId}"]`);
            if (card) {
              card.classList.add('hidden');
              card.style.display = 'none'; // Дополнительное скрытие
            }
          });
        }
        return;
      }
    }
    
    // Fallback на localStorage
    loadHiddenCardsFromLocalStorage();
  } catch (error) {
    console.warn('Error loading hidden cards from server:', error);
    loadHiddenCardsFromLocalStorage();
  }
}

// Резервная загрузка из localStorage
function loadHiddenCardsFromLocalStorage() {
  try {
    const saved = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
    if (saved) {
      const hiddenIds = JSON.parse(saved);
      hiddenIds.forEach(cardId => {
        const card = document.querySelector(`.stat-card[data-card="${cardId}"]`);
        if (card) {
          card.classList.add('hidden');
          card.style.display = 'none'; // Дополнительное скрытие
        }
      });
    }
  } catch (e) {
    console.error('Error loading hidden cards from localStorage:', e);
  }
}

// Сохранение скрытых карточек в БД
async function saveHiddenCards() {
  try {
    // Собираем все скрытые карточки
    const allHiddenCards = document.querySelectorAll('.stat-card.hidden');
    console.log('🔍 Найдено скрытых карточек в DOM:', allHiddenCards.length);
    
    // Логируем все найденные карточки для отладки
    allHiddenCards.forEach((card, index) => {
      const cardId = card.getAttribute('data-card');
      console.log(`  [${index}] Карточка ID: "${cardId}", классы:`, card.className);
    });
    
    const hiddenCards = Array.from(allHiddenCards)
      .map(card => card.getAttribute('data-card'))
      .filter(id => id !== null && id !== '');
    
    // Проверяем, есть ли карточка "Email + 2FA"
    const emailTwoFaCard = document.querySelector('.stat-card[data-card="custom:email_twofa"]');
    if (emailTwoFaCard) {
      const isHidden = emailTwoFaCard.classList.contains('hidden');
      console.log('🔍 Карточка "Email + 2FA" найдена, скрыта:', isHidden, 'ID:', emailTwoFaCard.getAttribute('data-card'));
    } else {
      console.warn('⚠️ Карточка "Email + 2FA" не найдена в DOM!');
    }
    
    
    // Сохраняем в localStorage как резервную копию
    try {
      localStorage.setItem(LS_KEY_HIDDEN_CARDS, JSON.stringify(hiddenCards));
    } catch (_) {
      console.error('❌ Ошибка сохранения в localStorage');
    }
    
    // Сохраняем в БД
    try {
      const response = await fetch('api_user_settings.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          type: 'hidden_cards',
          value: hiddenCards
        })
      });
      
      if (!response.ok) {
        const errorText = await response.text();
        console.warn('⚠️ Failed to save hidden cards to server:', response.status, errorText);
        console.warn('⚠️ Saved to localStorage only');
      } else {
        const data = await response.json();
      }
    } catch (fetchError) {
      console.error('❌ Ошибка при сохранении в БД:', fetchError);
      console.warn('⚠️ Saved to localStorage only');
    }
  } catch (e) {
    console.error('❌ Error saving hidden cards:', e);
  }
}

async function hideCard(cardId) {
  if (!cardId || cardId.trim() === '') {
    console.warn('hideCard: cardId is empty');
    return;
  }
  
  
  try {
    // Используем единую функцию для обновления UI
    toggleCardVisibility(cardId, false);
    
    // Проверяем, что карточка действительно скрыта
    const card = document.querySelector(`.stat-card[data-card="${cardId}"]`);
    if (card) {
      const isHidden = card.classList.contains('hidden');
      console.log('🔍 Карточка после скрытия - класс hidden:', isHidden, 'display:', window.getComputedStyle(card).display);
    }
    
    // Сохраняем в БД и localStorage
    await saveHiddenCards();
    
    // Синхронизируем чекбокс, если он существует
    const escapedCardId = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    const checkbox = document.querySelector(`.card-toggle[data-card="${escapedCardId}"]`);
    if (checkbox) {
      checkbox.checked = false;
      }
  } catch (error) {
    console.error('❌ Error hiding card:', error, { cardId });
    // Откатываем изменения UI при ошибке
    toggleCardVisibility(cardId, true);
    throw error;
  }
}

async function showCard(cardId) {
  if (!cardId || cardId.trim() === '') {
    console.warn('showCard: cardId is empty');
    return;
  }
  
  try {
    // Используем единую функцию для обновления UI
    toggleCardVisibility(cardId, true);
    
    // Сохраняем в БД и localStorage
    await saveHiddenCards();
    
    // Синхронизируем чекбокс, если он существует
    const escapedCardId = cardId.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    const checkbox = document.querySelector(`.card-toggle[data-card="${escapedCardId}"]`);
    if (checkbox) {
      checkbox.checked = true;
    }
  } catch (error) {
    console.error('Error showing card:', error, { cardId });
    // Откатываем изменения UI при ошибке
    toggleCardVisibility(cardId, false);
    throw error;
  }
}

// Функция для обновления класса выбранной строки
function updateRowSelectedClass(row, isSelected) {
  if (!row) return;
  if (isSelected) {
    row.classList.add('row-selected');
    // CSS стили применяются автоматически через класс row-selected
  } else {
    row.classList.remove('row-selected');
    // CSS стили убираются автоматически при удалении класса
  }
}

// Вспомогательная функция для получения всех ID строк на странице (с учетом виртуализации)
// Должна быть определена до initCheckboxStates
function getAllRowIdsOnPage() {
  const rowIds = [];
  
  // Пытаемся использовать виртуализацию, если она включена
  if (window.tableVirtualization && window.tableVirtualization.enabled && window.tableVirtualization.allRows) {
    // Виртуализация включена - используем allRows
    window.tableVirtualization.allRows.forEach(row => {
      const checkbox = row.querySelector('.row-checkbox');
      if (checkbox) {
        const rowId = parseInt(checkbox.value);
        if (Number.isFinite(rowId)) {
          rowIds.push(rowId);
        }
      }
    });
  } else {
    // Виртуализация отключена - используем DOM
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => {
      const rowId = parseInt(cb.value);
      if (Number.isFinite(rowId)) {
        rowIds.push(rowId);
      }
    });
  }
  
  return rowIds;
}

// Инициализация состояния чекбоксов (без добавления обработчиков)
function initCheckboxStates() {
  // Обновляем состояние видимых чекбоксов в DOM
  document.querySelectorAll('.row-checkbox').forEach(cb => {
    const rowId = parseInt(cb.value);
    const isChecked = selectedAllFiltered || selectedIds.has(rowId);
    cb.checked = isChecked;
    // Обновляем класс выбранной строки - обязательно после установки checked
    const row = cb.closest('tr[data-id]');
    if (row) {
      updateRowSelectedClass(row, isChecked);
    }
  });
  
  // Обновляем состояние чекбокса "Выбрать все"
  // Используем getAllRowIdsOnPage для правильного подсчета с учетом виртуализации
  const selectAllCheckbox = document.getElementById('selectAll');
  if (selectAllCheckbox) {
    const allRowIds = getAllRowIdsOnPage();
    const selectedCount = allRowIds.filter(id => selectedAllFiltered || selectedIds.has(id)).length;
    selectAllCheckbox.checked = allRowIds.length > 0 && selectedCount === allRowIds.length;
  }
}

// Дополнительная функция для принудительного обновления подсветки всех выбранных строк
function updateAllSelectedRowsHighlight() {
  document.querySelectorAll('tr[data-id]').forEach(row => {
    const checkbox = row.querySelector('.row-checkbox');
    if (checkbox) {
      // Проверяем состояние чекбокса И сохранённые ID для надёжности
      const rowId = parseInt(checkbox.value);
      const isChecked = checkbox.checked || selectedAllFiltered || selectedIds.has(rowId);
      // Обновляем чекбокс, если он должен быть выбран, но не выбран
      if (isChecked && !checkbox.checked) {
        checkbox.checked = true;
      }
      // Обновляем подсветку строки
      updateRowSelectedClass(row, isChecked);
    }
  });
}

// ===== Функции настроек =====
function loadSettings() {
  try {
    // Загружаем настройки колонок
    const savedColumns = localStorage.getItem(LS_KEY_COLUMNS);
    const visibleColumns = savedColumns ? JSON.parse(savedColumns) : null;
    // Определяем новые колонки (в схеме появились новые поля)
    let knownCols = [];
    try { const k = localStorage.getItem(LS_KEY_KNOWN_COLS); if (k) knownCols = JSON.parse(k) || []; } catch(_) {}
    const ALL_COL_KEYS = Array.from(document.querySelectorAll('.column-toggle')).map(cb => cb.getAttribute('data-col'));
    const newCols = ALL_COL_KEYS.filter(c => !knownCols.includes(c));

    document.querySelectorAll('.column-toggle').forEach(cb => {
      const colName = cb.getAttribute('data-col');
      let isChecked = cb.checked; // дефолт по HTML
      if (visibleColumns) {
        isChecked = visibleColumns.includes(colName) || newCols.includes(colName);
      }
      cb.checked = isChecked;
      toggleColumnVisibility(colName, isChecked);
    });
    // Сохраняем актуально известный список колонок
    localStorage.setItem(LS_KEY_KNOWN_COLS, JSON.stringify(ALL_COL_KEYS));
    
    // Упрощенная логика: используем только список скрытых карточек
    // Загружаем скрытые карточки из localStorage
    const hiddenCards = [];
    try {
      const savedHidden = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
      if (savedHidden) {
        hiddenCards.push(...JSON.parse(savedHidden));
      }
    } catch (e) {
      console.error('Error loading hidden cards in loadSettings:', e);
    }
    
    // Упрощенная логика: используем только список скрытых карточек
    // Карточка видима, если она НЕ в списке скрытых
    document.querySelectorAll('.card-toggle').forEach(cb => {
      const cardName = cb.getAttribute('data-card');
      if (!cardName || cardName.trim() === '') {
        console.warn('loadSettings: card-toggle has empty data-card attribute', {
          element: cb,
          id: cb.id,
          value: cb.value
        });
        return;
      }
      const isVisible = !hiddenCards.includes(cardName);
      cb.checked = isVisible;
      toggleCardVisibility(cardName, isVisible);
    });

    // Компактный режим отключен
  } catch (e) {
    console.error('Error loading settings:', e);
  }
}

function saveSettings() {
  try {
    // Сохраняем настройки колонок
    const visibleColumns = [];
    document.querySelectorAll('.column-toggle:checked').forEach(cb => {
      visibleColumns.push(cb.getAttribute('data-col'));
    });
    localStorage.setItem(LS_KEY_COLUMNS, JSON.stringify(visibleColumns));
    // Обновляем известные колонки (для детекта будущих изменений схемы)
    const ALL_COL_KEYS = Array.from(document.querySelectorAll('.column-toggle')).map(cb => cb.getAttribute('data-col'));
    localStorage.setItem(LS_KEY_KNOWN_COLS, JSON.stringify(ALL_COL_KEYS));
    
    // Упрощенная логика: настройки карточек сохраняются через saveHiddenCards()
    // Здесь только синхронизируем скрытые карточки с чекбоксами
    // Список скрытых карточек уже сохранен в saveHiddenCards()
    
    showToast('Настройки сохранены', 'success');
  } catch (e) {
    console.error('Error saving settings:', e);
    showToast('Ошибка сохранения настроек', 'error');
  }
}

function toggleColumnVisibility(colName, visible) {
  const colElements = document.querySelectorAll(`[data-col="${colName}"]`);
  colElements.forEach(el => {
    if (visible) {
      el.style.display = '';
    } else {
      el.style.display = 'none';
    }
  });
}

// Применяет сохранённую видимость колонок к текущей таблице (включая новые строки)
function applySavedColumnVisibility() {
  try {
    const savedColumns = localStorage.getItem(LS_KEY_COLUMNS);
    if (!savedColumns) return;
    const visibleColumns = JSON.parse(savedColumns);
    const allToggles = Array.from(document.querySelectorAll('.column-toggle'));
    const allCols = allToggles.map(cb => cb.getAttribute('data-col'));
    allCols.forEach(col => {
      const isVisible = visibleColumns.includes(col);
      toggleColumnVisibility(col, isVisible);
    });
  } catch (_) { /* ignore */ }
}

function toggleCardVisibility(cardName, visible) {
  if (!cardName || cardName.trim() === '') {
    console.warn('toggleCardVisibility: cardName is empty');
    return;
  }
  
  // Экранируем специальные символы в селекторе для безопасности
  const escapedCardName = cardName.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
  
  // Используем селектор для поиска карточки с правильным атрибутом
  const cardElement = document.querySelector(`.stat-card[data-card="${escapedCardName}"]`);
  
  if (!cardElement) {
    console.warn(`Card not found: ${cardName}`, {
      searched: escapedCardName,
      available: Array.from(document.querySelectorAll('.stat-card')).map(c => c.getAttribute('data-card'))
    });
    return;
  }
  
  if (visible) {
    // КРИТИЧНО: сначала убираем класс hidden, иначе CSS правило с !important не даст показать карточку
    cardElement.classList.remove('hidden', 'd-none', 'force-hidden');
    cardElement.removeAttribute('hidden');
    
    // Принудительно устанавливаем стили через setProperty с important, чтобы переопределить CSS
    // Это необходимо, так как CSS имеет правила с !important для показа карточек
    cardElement.style.setProperty('display', 'flex', 'important');
    cardElement.style.setProperty('opacity', '1', 'important');
    cardElement.style.setProperty('visibility', 'visible', 'important');
    cardElement.style.setProperty('pointer-events', 'auto', 'important');
    
    // Через небольшую задержку сбрасываем important, чтобы не ломать другие стили
    // Но оставляем класс hidden удаленным
    requestAnimationFrame(() => {
      if (cardElement && !cardElement.classList.contains('hidden')) {
        // Проверяем, что карточка все еще должна быть видима
        // Сбрасываем inline стили, чтобы CSS правила работали нормально
        cardElement.style.removeProperty('display');
        cardElement.style.removeProperty('opacity');
        cardElement.style.removeProperty('visibility');
        cardElement.style.removeProperty('pointer-events');
      }
    });
  } else {
    // Скрываем карточку: добавляем класс hidden и устанавливаем стили
    cardElement.classList.add('hidden');
    cardElement.setAttribute('hidden', '');
    
    // Используем setProperty с important для переопределения CSS правил с !important
    cardElement.style.setProperty('display', 'none', 'important');
    cardElement.style.setProperty('opacity', '0', 'important');
    cardElement.style.setProperty('visibility', 'hidden', 'important');
    cardElement.style.setProperty('pointer-events', 'none', 'important');
    
    // Убираем другие классы скрытия для чистоты
    cardElement.classList.remove('d-none', 'force-hidden');
  }
  
  // Принудительно обновляем отображение через reflow
  void cardElement.offsetHeight;
}

// ===== Обработчики событий =====
// Обработчик скрытия карточек (делегирование событий)
document.addEventListener('click', function(e) {
  const hideBtn = e.target.closest('.stat-card-hide-btn');
  if (hideBtn) {
    e.preventDefault();
    e.stopPropagation();
    
    const cardId = hideBtn.getAttribute('data-card');
    if (cardId) {
      hideCard(cardId).catch(err => console.error('Error hiding card:', err));
    }
    return;
  }
  
  // Обработчик клика на кастомные карточки
  const card = e.target.closest('.stat-card[data-card-type="custom"]');
  if (card) {
    // Игнорируем клик на кнопку скрытия
    if (e.target.closest('.stat-card-hide-btn')) {
      return;
    }
    
    // Подсвечиваем карточку
    document.querySelectorAll('.stat-card[data-card-type="custom"]').forEach(c => {
      c.classList.remove('active');
    });
    card.classList.add('active');
    
    // Принудительно применяем стили через inline стили для надежности
    card.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.4) 0%, rgba(59, 130, 246, 0.6) 100%)';
    card.style.border = '2px solid var(--card-color, #3b82f6)';
    card.style.boxShadow = '0 0 0 3px var(--card-color, #3b82f6), 0 14px 24px rgba(59, 130, 246, 0.4)';
    card.style.opacity = '1';
    
    // Логируем для отладки
    console.log('Card clicked, active class added:', card);
    console.log('Card has active class:', card.classList.contains('active'));
    console.log('Card computed styles:', window.getComputedStyle(card).background);
    
    // Применяем фильтры
    handleCardSwipe(card);
  }
});

document.addEventListener('DOMContentLoaded', function() {
  loadSelectedIds();
  // ВАЖНО: Сначала применяем скрытие карточек СИНХРОННО из localStorage
  // Это предотвращает мигание скрытых карточек
  if (window._hiddenCardsToHide) {
    const hiddenCardsSet = window._hiddenCardsToHide instanceof Set 
      ? window._hiddenCardsToHide 
      : new Set(Array.isArray(window._hiddenCardsToHide) ? window._hiddenCardsToHide : []);
    
    // Специальная проверка для карточки "Email + 2FA"
    // Если пользователь говорит, что она должна быть скрыта, но её нет в списке,
    // добавляем её в список и скрываем
    const emailTwoFaCard = document.querySelector('.stat-card[data-card="custom:email_twofa"]');
    if (emailTwoFaCard && !hiddenCardsSet.has('custom:email_twofa')) {
      hiddenCardsSet.add('custom:email_twofa');
      window._hiddenCardsToHide = hiddenCardsSet; // Обновляем глобальную переменную
      
      // Сохраняем обновленный список в localStorage
      try {
        const updatedList = Array.from(hiddenCardsSet);
        localStorage.setItem('dashboard_hidden_cards', JSON.stringify(updatedList));
      } catch (e) {
        console.error('❌ Ошибка обновления localStorage:', e);
      }
    }
    
    // Применяем скрытие ко всем карточкам сразу
    hiddenCardsSet.forEach(cardId => {
      const card = document.querySelector(`.stat-card[data-card="${cardId}"]`);
      if (card) {
        // Применяем все способы скрытия для надежности
        card.classList.add('hidden');
        card.style.setProperty('display', 'none', 'important');
        card.style.setProperty('visibility', 'hidden', 'important');
        card.style.setProperty('opacity', '0', 'important');
      }
    });
    
    // Очищаем после применения, но оставляем Set для MutationObserver
    // window._hiddenCardsToHide остается для MutationObserver
  } else {
    // Если список скрытых карточек не загружен, проверяем карточку "Email + 2FA"
    // и скрываем её, если она должна быть скрыта
    const emailTwoFaCard = document.querySelector('.stat-card[data-card="custom:email_twofa"]');
    if (emailTwoFaCard) {
      try {
        const saved = localStorage.getItem('dashboard_hidden_cards');
        if (saved) {
          const hiddenIds = JSON.parse(saved);
          if (Array.isArray(hiddenIds) && hiddenIds.includes('custom:email_twofa')) {
            emailTwoFaCard.classList.add('hidden');
            emailTwoFaCard.style.setProperty('display', 'none', 'important');
            emailTwoFaCard.style.setProperty('visibility', 'hidden', 'important');
            emailTwoFaCard.style.setProperty('opacity', '0', 'important');
          }
        }
      } catch (e) {
        console.error('❌ Ошибка проверки localStorage:', e);
      }
    }
  }
  
  // Проверяем прелоадеры сразу
  const statsLoading = document.getElementById('statsLoading');
  const tableLoading = document.getElementById('tableLoading');
  
  if (statsLoading) {
    // Скрываем прелоадер сразу (несколько способов для надежности)
    statsLoading.classList.remove('show');
    statsLoading.style.display = 'none';
    statsLoading.style.visibility = 'hidden';
    statsLoading.style.opacity = '0';
  } else {
    console.error('❌ statsLoading элемент не найден!');
  }
  
  if (tableLoading) {
    tableLoading.classList.remove('show');
    tableLoading.style.display = 'none';
  }
  
  // Загружаем скрытые карточки из БД (синхронное скрытие уже применено выше)
  // Это обновит список из БД и синхронизирует с localStorage
  loadHiddenCards().catch(err => console.error('Error loading hidden cards:', err));
  
  // Инициализируем кастомные карточки
  initializeCustomCards().catch(err => console.error('Error initializing custom cards:', err));
  
  // ===== ОПТИМИЗАЦИЯ ПРОИЗВОДИТЕЛЬНОСТИ =====
  // Определение слабых устройств
  const isLowEndDevice = 
    (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2) || 
    (navigator.deviceMemory && navigator.deviceMemory <= 2) ||
    /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  
  // Применяем оптимизации для слабых устройств
  if (isLowEndDevice) {
    document.documentElement.classList.add('low-end-device');
    // Отключаем анимации через CSS переменную
    document.documentElement.style.setProperty('--animation-duration', '0ms');
    document.documentElement.style.setProperty('--transition-duration', '0ms');
    
    // Упрощаем sticky элементы (они могут тормозить)
    const stickyElements = document.querySelectorAll('.sticky-id, .sticky-actions');
    stickyElements.forEach(el => {
      el.style.position = 'relative';
      el.style.left = 'auto';
      el.style.right = 'auto';
    });
    
    // Уменьшаем количество строк по умолчанию
    const perPageSelect = document.querySelector('select[name="per_page"]');
    if (perPageSelect && !perPageSelect.value) {
      perPageSelect.value = '25';
    }
  }
  
  // Кэширование часто используемых селекторов
  const cachedSelectors = {
    tbody: document.querySelector('#accountsTable tbody'),
    table: document.getElementById('accountsTable'),
    tableWrap: document.getElementById('tableWrap'),
    selectAll: document.getElementById('selectAll'),
    tableLoading: document.getElementById('tableLoading')
  };
  
  // Тёмная тема отключена
  
  // НЕ сохраняем выбранные строки при перезагрузке - очищаем выбор
  localStorage.removeItem(LS_KEY_SELECTED);
  selectedIds.clear();
  selectedAllFiltered = false;
  
  // Инициализируем состояние чекбоксов (все сняты)
  initCheckboxStates();
  
  // Принудительно обновляем подсветку всех выбранных строк
  updateAllSelectedRowsHighlight();
  
  // Обновляем счетчик и видимость кнопки "Сбросить все"
  updateSelectedCount();
  loadSettings();
  // Пересчитываем ширины колонок после применения видимости
  requestAnimationFrame(() => {
    syncHeaderWidths();
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
  });
  if (typeof initializePharmaSlider === 'function') { initializePharmaSlider(); }
  if (typeof initializeFriendsSlider === 'function') { initializeFriendsSlider(); }
  // Гарантируем синхронизацию значений ползунков перед отправкой формы
  document.addEventListener('submit', function(e){
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    // Pharma
    const p = document.getElementById('pharmaSlider');
    if (p && p.noUiSlider) {
      const [vFrom, vTo] = p.noUiSlider.get().map(Number);
      const pf = document.getElementById('pharma_from');
      const pt = document.getElementById('pharma_to');
      if (pf) pf.value = String(vFrom);
      if (pt) pt.value = String(vTo);
    }
    // Friends
    const f = document.getElementById('friendsSlider');
    if (f && f.noUiSlider) {
      const [vFrom, vTo] = f.noUiSlider.get().map(Number);
      const ff = document.getElementById('friends_from');
      const ft = document.getElementById('friends_to');
      if (ff) ff.value = String(vFrom);
      if (ft) ft.value = String(vTo);
    }
  });
  // Синхронизация чекбоксов в настройках с фактически скрытыми карточками
  function syncCardCheckboxesWithHidden() {
    try {
      // Получаем скрытые карточки из localStorage
      const hiddenCards = [];
      const savedHidden = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
      if (savedHidden) {
        try {
          hiddenCards.push(...JSON.parse(savedHidden));
        } catch (e) {
          console.error('Error parsing hidden cards:', e);
        }
      }
      
      // Синхронизируем все чекбоксы с реальным состоянием карточек в DOM
      document.querySelectorAll('.card-toggle').forEach(cb => {
        const cardName = cb.getAttribute('data-card');
        if (!cardName) return;
        
        // Экранируем специальные символы в селекторе
        const escapedCardName = cardName.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
        
        // Находим соответствующую карточку в DOM
        const cardElement = document.querySelector(`.stat-card[data-card="${escapedCardName}"]`);
        
        if (cardElement) {
          // Проверяем реальное состояние карточки в DOM
          // Используем getComputedStyle для получения финального значения display
          const computedStyle = window.getComputedStyle(cardElement);
          const displayValue = computedStyle.display;
          
          const isHiddenInDOM = cardElement.classList.contains('hidden') || 
                               cardElement.style.display === 'none' ||
                               displayValue === 'none' ||
                               cardElement.hasAttribute('hidden') ||
                               cardElement.classList.contains('d-none') ||
                               cardElement.classList.contains('force-hidden');
          
          // Проверяем состояние в localStorage
          const isHiddenInStorage = hiddenCards.includes(cardName);
          
          // Карточка скрыта, если она скрыта в DOM ИЛИ в localStorage
          const isHidden = isHiddenInDOM || isHiddenInStorage;
          
          // Обновляем чекбокс в соответствии с реальным состоянием
          cb.checked = !isHidden;
        } else {
          // Если карточка не найдена в DOM, проверяем только localStorage
          const isHiddenInStorage = hiddenCards.includes(cardName);
          cb.checked = !isHiddenInStorage;
          
          // Логируем для отладки
          if (cardName && !cardName.includes('custom:')) {
            console.warn(`syncCardCheckboxesWithHidden: Card not found in DOM: ${cardName}`, {
              searched: escapedCardName,
              available: Array.from(document.querySelectorAll('.stat-card')).slice(0, 5).map(c => c.getAttribute('data-card'))
            });
          }
        }
      });
    } catch (e) {
      console.error('Error syncing card checkboxes:', e);
    }
  }

  // Обработчик открытия модального окна настроек
  const settingsModalEl = document.getElementById('settingsModal');
  if (settingsModalEl) {
    settingsModalEl.addEventListener('show.bs.modal', function() {
      // Синхронизируем чекбоксы при открытии модального окна
      syncCardCheckboxesWithHidden();
    });
  }

  // Реакция на переключение чекбоксов настроек (колонки/карточки)
  document.addEventListener('change', function(e) {
    const t = e.target;
    if (t && t.classList && t.classList.contains('column-toggle')) {
      const colName = t.getAttribute('data-col');
      const isVisible = !!t.checked;
      toggleColumnVisibility(colName, isVisible);
      saveSettings();
      // Пересчитываем ширины колонок после изменения видимости
      requestAnimationFrame(() => {
        syncHeaderWidths();
        // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
      });
    }
    if (t && t.classList && t.classList.contains('card-toggle')) {
      const cardName = t.getAttribute('data-card');
      
      // Проверяем, что cardName существует и не пустой
      if (!cardName || cardName.trim() === '') {
        console.warn('card-toggle: data-card attribute is empty or missing', {
          element: t,
          id: t.id,
          value: t.value
        });
        return;
      }
      
      const isVisible = !!t.checked;
      
      console.log('Card toggle changed:', { cardName, isVisible, element: t });
      
      // Сохраняем исходное состояние для отката при ошибке
      const previousState = !isVisible;
      
      // Используем единые функции hideCard/showCard, которые уже содержат toggleCardVisibility
      // и обработку ошибок с откатом
      if (isVisible) {
        // Показываем карточку и сохраняем в БД
        showCard(cardName).catch(err => {
          console.error('Error showing card:', err, { cardName });
          // Откатываем чекбокс при ошибке
          t.checked = previousState;
          showToast('Ошибка показа карточки', 'error');
        });
      } else {
        // Скрываем карточку и сохраняем в БД
        hideCard(cardName).catch(err => {
          console.error('Error hiding card:', err, { cardName });
          // Откатываем чекбокс при ошибке
          t.checked = previousState;
          showToast('Ошибка скрытия карточки', 'error');
        });
      }
      
      // Сохраняем настройки (колонки и другие)
      saveSettings();
    }
    // uiCompactToggle отключен
  });
  
  // Редактирование названий статистических блоков отключено

  // Отключаем JavaScript обработчик пагинации - пусть работают обычные ссылки
  // document.addEventListener('click', function(e){
  //   const a = e.target.closest('.pagination a.page-link');
  //   if (!a) return;
  //   // если пункт disabled — игнорируем
  //   const li = a.closest('li');
  //   if (li && li.classList.contains('disabled')) { 
  //     e.preventDefault(); 
  //     return; 
  //   }
  //   // Обычный переход по href - это должно работать
  //   console.log('Pagination click:', a.getAttribute('href'), 'data-page:', a.getAttribute('data-page'));
  // });
  
  // Select All и Individual checkboxes теперь обрабатываются через делегирование событий ниже
  // Удалён дублирующийся код (см. строки 4778+ и 5315+)
  
  // Password toggle
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.pw-toggle');
    if (!btn) return;
    
    const wrap = btn.closest('.pw-mask');
    const dots = wrap.querySelector('.pw-dots');
    const text = wrap.querySelector('.pw-text');
    const icon = btn.querySelector('i');
    
    if (text.classList.contains('d-none')) {
      // Показываем пароль
      text.classList.remove('d-none');
      dots.classList.add('d-none');
      icon.className = 'fas fa-eye-slash';
      btn.title = 'Скрыть пароль';
    } else {
      // Скрываем пароль
      text.classList.add('d-none');
      dots.classList.remove('d-none');
      icon.className = 'fas fa-eye';
      btn.title = 'Показать пароль';
    }
  });

  // Password edit
  document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.pw-edit');
    if (!editBtn) return;
    
    const wrap = editBtn.closest('.pw-mask');
    const rowId = parseInt(wrap.getAttribute('data-row-id'));
    const field = wrap.getAttribute('data-field');
    const pwText = wrap.querySelector('.pw-text');
    const currentPassword = pwText.textContent.trim();
    
    // Создаем input для редактирования
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control form-control-sm';
    input.value = currentPassword;
    input.style.width = '150px';
    input.style.display = 'inline-block';
    
    // Создаем кнопки сохранения и отмены
    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-sm btn-success ms-1';
    saveBtn.innerHTML = '<i class="fas fa-check"></i>';
    saveBtn.title = 'Сохранить';
    
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-sm btn-secondary ms-1';
    cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
    cancelBtn.title = 'Отмена';
    
    // Сохраняем оригинальное содержимое
    const originalContent = wrap.innerHTML;
    
    // Заменяем содержимое на поля редактирования
    wrap.innerHTML = '';
    wrap.appendChild(input);
    wrap.appendChild(saveBtn);
    wrap.appendChild(cancelBtn);
    input.focus();
    input.select();
    
    // Обработчик сохранения
    const save = async () => {
      const newPassword = input.value.trim();
      
      try {
        const response = await fetch('update_field.php', {
          method: 'POST',
          headers: { 
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            id: rowId,
            field: field,
            value: newPassword,
            csrf: '<?= e($csrfToken) ?>'
          })
        });
        
        const data = await response.json();
        
        if (data.success) {
          // Обновляем отображение пароля
          wrap.innerHTML = originalContent;
          const updatedPwText = wrap.querySelector('.pw-text');
          const updatedPwDots = wrap.querySelector('.pw-dots');
          updatedPwText.textContent = newPassword;
          // Обновляем отображение точек
          if (newPassword === '') {
            updatedPwDots.innerHTML = '<span class="text-muted">(не задан)</span>';
          } else {
            updatedPwDots.textContent = '••••••••';
          }
          showToast('Пароль успешно обновлен', 'success');
        } else {
          showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
          wrap.innerHTML = originalContent;
        }
      } catch (error) {
        console.error('Error:', error);
        showToast('Ошибка при сохранении пароля', 'error');
        wrap.innerHTML = originalContent;
      }
    };
    
    // Обработчик отмены
    const cancel = () => {
      wrap.innerHTML = originalContent;
    };
    
    saveBtn.addEventListener('click', save);
    cancelBtn.addEventListener('click', cancel);
    
    // Сохранение по Enter
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        save();
      } else if (e.key === 'Escape') {
        cancel();
      }
    });
  });
  
  // Cell modal
  document.addEventListener('click', function(e) {
    const target = e.target.closest('[data-full]');
    if (!target) return;
    
    const full = target.getAttribute('data-full') || '';
    const title = target.getAttribute('data-title') || 'Полное значение';
    
    const cellModalTitle = document.getElementById('cellModalTitle');
    const cellModalBody = document.getElementById('cellModalBody');
    const cellModal = document.getElementById('cellModal');
    
    if (cellModalTitle) cellModalTitle.textContent = title;
    if (cellModalBody) cellModalBody.textContent = full;
    
    if (cellModal) {
      const modal = new bootstrap.Modal(cellModal);
      modal.show();
    }
  });
  
  // Copy cell content
  const cellCopyBtn = document.getElementById('cellCopyBtn');
  if (cellCopyBtn) {
    cellCopyBtn.addEventListener('click', function() {
      const body = document.getElementById('cellModalBody');
      copyToClipboard(body.textContent || '');
    });
  }
  
  // Обработчик для всех кнопок копирования (совместимость с Firefox)
  // Используем делегирование событий для динамически созданных элементов
  document.addEventListener('click', function(e) {
    const copyBtn = e.target.closest('.copy-btn');
    if (!copyBtn) return;
    
    // Получаем текст для копирования из data-атрибута или из ближайшего элемента
    let textToCopy = copyBtn.getAttribute('data-copy-text');
    
    // Если data-атрибут не задан, пытаемся найти значение из контекста
    if (!textToCopy) {
      // Для паролей - берем из .pw-text
      const pwMask = copyBtn.closest('.pw-mask');
      if (pwMask) {
        const pwText = pwMask.querySelector('.pw-text');
        if (pwText) {
          textToCopy = pwText.textContent || pwText.innerText || '';
        }
      }
      
      // Для email/login - берем из .field-value или ссылки
      if (!textToCopy) {
        const fieldWrap = copyBtn.closest('.editable-field-wrap');
        if (fieldWrap) {
          const fieldValue = fieldWrap.querySelector('.field-value');
          if (fieldValue) {
            textToCopy = fieldValue.textContent || fieldValue.innerText || '';
            // Если это ссылка, берем href
            if (fieldValue.tagName === 'A' && fieldValue.href) {
              textToCopy = fieldValue.href.replace('mailto:', '');
            }
          }
        }
      }
      
      // Для token и других длинных полей
      if (!textToCopy) {
        const truncateSpan = copyBtn.previousElementSibling;
        if (truncateSpan && truncateSpan.hasAttribute('data-full')) {
          textToCopy = truncateSpan.getAttribute('data-full') || '';
        }
      }
      
      // Если все еще не нашли, пытаемся взять из любого соседнего элемента с текстом
      if (!textToCopy) {
        const parent = copyBtn.parentElement;
        if (parent) {
          // Ищем span или другой элемент с текстом
          const textElement = parent.querySelector('span, a, pre');
          if (textElement) {
            textToCopy = textElement.textContent || textElement.innerText || '';
            // Если это ссылка, убираем mailto:
            if (textElement.tagName === 'A' && textElement.href) {
              textToCopy = textElement.href.replace(/^mailto:/, '');
            }
          }
        }
      }
    }
    
    if (textToCopy) {
      copyToClipboard(textToCopy);
    } else {
      console.warn('Не удалось найти текст для копирования', copyBtn);
    }
  });

  // Пагинация без прокрутки вверх (AJAX)
  document.addEventListener('click', function(e){
    const a = e.target.closest('ul.pagination a.page-link');
    if (!a) return;
    const li = a.closest('li');
    if (li && li.classList.contains('disabled')) { e.preventDefault(); return; }
    e.preventDefault();
    const href = a.getAttribute('href') || '';
    if (!href) return;
    const url = new URL(href, window.location.origin);
    const pageParam = parseInt(url.searchParams.get('page') || '1');
    const current = new URL(window.location);
    current.searchParams.set('page', String(pageParam));
    history.replaceState(null, '', current.toString());
    // Обновляем номер страницы в футере немедленно
    const pageNumEl = document.getElementById('pageNum');
    if (pageNumEl) pageNumEl.textContent = String(pageParam);
    // Обновляем селект страниц
    const pageSelectEl = document.getElementById('pageSelect');
    if (pageSelectEl) pageSelectEl.value = String(pageParam);
    // НЕ очищаем selectedIds при пагинации - выбранные строки должны сохраняться между страницами
    // selectedAllFiltered сбрасываем, так как это относится к текущему фильтру
    selectedAllFiltered = false;
    updateSelectedCount();
    refreshDashboardData();
  });
  
  // Export selected CSV
  const exportSelectedCsv = document.getElementById('exportSelectedCsv');
  if (exportSelectedCsv) {
    exportSelectedCsv.addEventListener('click', function() {
      if (!selectedAllFiltered && selectedIds.size === 0) return;
      
      // Создаем скрытую форму для корректной обработки заголовков скачивания
      const form = document.createElement('form');
      form.method = 'GET';
      form.action = 'export.php';
      // Не указываем target, чтобы браузер правильно обработал Content-Disposition: attachment
      
      const currentSort = '<?= $sort ?>';
      const currentDir = '<?= $dir ?>';
      
      if (selectedAllFiltered) {
        // Добавляем все параметры из текущего URL
        const params = new URLSearchParams(window.location.search);
        params.set('select', 'all');
        params.set('format', 'csv');
        params.set('sort', currentSort);
        params.set('dir', currentDir);
        
        // Добавляем все параметры как скрытые поля формы
        params.forEach((value, key) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = value;
          form.appendChild(input);
        });
      } else {
        // Экспорт выбранных ID
        const ids = Array.from(selectedIds).join(',');
        
        const fields = {
          'ids': ids,
          'format': 'csv',
          'sort': currentSort,
          'dir': currentDir
        };
        
        Object.keys(fields).forEach(key => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = fields[key];
          form.appendChild(input);
        });
      }

      // Добавляем форму в DOM, отправляем и удаляем
      document.body.appendChild(form);
      form.submit();
      document.body.removeChild(form);
    });
  }
   
  // Export selected TXT (pipe-delimited, только видимые колонки)
  const exportSelectedTxt = document.getElementById('exportSelectedTxt');
  if (exportSelectedTxt) {
    exportSelectedTxt.addEventListener('click', function() {
      if (!selectedAllFiltered && selectedIds.size === 0) return;
      const currentSort = '<?= $sort ?>';
      const currentDir = '<?= $dir ?>';
      let visibleCols = [];
      try { const saved = localStorage.getItem('dashboard_visible_columns'); if (saved) visibleCols = JSON.parse(saved); } catch (_) {}
      if (!Array.isArray(visibleCols) || visibleCols.length === 0) {
        visibleCols = Array.from(document.querySelectorAll('#accountsTable thead th[data-col]')).map(th => th.getAttribute('data-col'));
      }
      const ALL_COL_KEYS = <?= json_encode(array_keys($ALL_COLUMNS)) ?>;
      visibleCols = (visibleCols || []).filter(c => ALL_COL_KEYS.includes(c));
      // Убираем ID из экспорта, если он есть
      visibleCols = visibleCols.filter(c => c !== 'id');

      // Создаем скрытую форму для корректной обработки заголовков скачивания
      const form = document.createElement('form');
      form.method = 'GET';
      form.action = 'export.php';
      // Не указываем target, чтобы браузер правильно обработал Content-Disposition: attachment

      if (selectedAllFiltered) {
        // Добавляем все параметры из текущего URL
        const params = new URLSearchParams(window.location.search);
        params.set('select', 'all');
        params.set('format', 'txt');
        params.set('sort', currentSort);
        params.set('dir', currentDir);
        params.set('cols', visibleCols.join(','));
        
        // Добавляем все параметры как скрытые поля формы
        params.forEach((value, key) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = value;
          form.appendChild(input);
        });
      } else {
        // Экспорт выбранных ID
        const ids = Array.from(selectedIds).join(',');
        const cols = visibleCols.join(',');
        
        const fields = {
          'ids': ids,
          'format': 'txt',
          'sort': currentSort,
          'dir': currentDir,
          'cols': cols
        };
        
        Object.keys(fields).forEach(key => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = key;
          input.value = fields[key];
          form.appendChild(input);
        });
      }

      // Добавляем форму в DOM, отправляем и удаляем
      document.body.appendChild(form);
      form.submit();
      document.body.removeChild(form);
    });
  }
  
  // Delete selected
  const deleteSelectedBtn = document.getElementById('deleteSelected');
  if (deleteSelectedBtn) {
    deleteSelectedBtn.addEventListener('click', function() {
      if (!selectedAllFiltered && selectedIds.size === 0) return;
      
      // Обновляем счётчик в модальном окне
      const deleteCount = document.getElementById('deleteCount');
      if (deleteCount) {
        deleteCount.textContent = selectedAllFiltered 
          ? 'все по фильтру' 
          : selectedIds.size;
      }
      
      const modalEl = document.getElementById('deleteConfirmModal');
      if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      }
    });
  }
  
  // Настройки сохраняются автоматически при изменении, обработчик кнопки не нужен
  
  // Reset stat labels
  const resetStatLabelsBtn = document.getElementById('resetStatLabels');
  if (resetStatLabelsBtn) {
    resetStatLabelsBtn.addEventListener('click', function() {
      if (confirm('Вы действительно хотите сбросить все названия блоков к исходным значениям?')) {
        resetStatLabels();
        showToast('Названия блоков сброшены к исходным значениям', 'success');
      }
    });
  }
  
  // Preview stat labels
  const previewStatLabelsBtn = document.getElementById('previewStatLabels');
  if (previewStatLabelsBtn) {
    previewStatLabelsBtn.addEventListener('click', function() {
      previewStatLabels();
    });
  }
  
  // Confirm delete - КРИТИЧЕСКИ ВАЖНО для работы удаления!
  const confirmDeleteBtn = document.getElementById('confirmDelete');
  if (confirmDeleteBtn) {
    confirmDeleteBtn.addEventListener('click', async function() {
      const btn = this;
      const originalText = btn.innerHTML;
    
    // Показываем индикатор загрузки
    btn.disabled = true;
    btn.innerHTML = '<span class="loader loader-sm loader-white me-2" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;"></span>Удаление...';
    
    try {
      let response;
      
      // Режим "все по фильтру"
      if (selectedAllFiltered) {
        console.log('🗑️ Удаление всех по фильтру');
        const params = new URLSearchParams(window.location.search);
        response = await fetch('delete.php?select=all&' + params.toString(), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({ ids: [], csrf: '<?= e($csrfToken) ?>' })
        });
      } 
      // Обычный режим - удаление выбранных ID
      else {
        if (selectedIds.size === 0) {
          console.warn('⚠️ Попытка удаления без выбранных ID');
          showToast('Не выбрано ни одной записи для удаления', 'warning');
          btn.disabled = false;
          btn.innerHTML = originalText;
          return;
        }
        
        const ids = Array.from(selectedIds);
        const requestBody = { ids: ids, csrf: '<?= e($csrfToken) ?>' };
        
        console.group('🗑️ Отправка запроса на удаление');
        console.log('ID для удаления:', ids);
        console.log('Количество:', ids.length);
        console.log('Тело запроса:', requestBody);
        console.groupEnd();
        
        response = await fetch('delete.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify(requestBody)
        });
        
        console.log('📡 Статус ответа:', response.status, response.statusText);
      }
      
      if (!response.ok) {
        console.error('❌ HTTP ошибка:', response.status, response.statusText);
        const text = await response.text();
        console.error('Тело ответа:', text);
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      
      const data = await response.json();
      
      if (data.success) {
        if (data.deleted_count === 0) {
          showToast('⚠️ Ни одна запись не была удалена. Возможно, записи уже нет в базе.', 'warning');
        } else {
          showToast(data.message, 'success');
        }
        
        // Очищаем выбор
        selectedAllFiltered = false;
        selectedIds.clear();
        saveSelectedIds();
        updateSelectedCount();
        
        // Снимаем галочки
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('selectAll').checked = false;
        
        // Закрываем модалку
        const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'));
        if (modal) {
          modal.hide();
        }
        
        console.log('✅ Удаление завершено успешно. Обновляем статистику...');
        
        // Обновляем статистику сразу после удаления
        refreshDashboardData().then(() => {
          console.log('✅ Статистика обновлена');
        }).catch(err => {
          console.error('❌ Ошибка обновления статистики:', err);
        });
        
        // Обновляем данные через AJAX вместо перезагрузки страницы
        selectedAllFiltered = false; 
        selectedIds.clear(); 
        updateSelectedCount();
        await refreshDashboardData();
        showToast(`Удалено ${data.deleted || 0} записей`, 'success');
      } else {
        showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
      }
    } catch (error) {
      console.error('Error:', error);
      showToast('Ошибка сети при удалении', 'error');
    } finally {
      // Восстанавливаем кнопку
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
    });
  }
  
  // Селект быстрого перехода по страницам
  const pageSelect = document.getElementById('pageSelect');
  if (pageSelect) {
    pageSelect.addEventListener('change', () => {
      const selectedPage = parseInt(pageSelect.value);
      if (selectedPage && selectedPage > 0) {
        const url = new URL(window.location);
        url.searchParams.set('page', String(selectedPage));
        history.replaceState(null, '', url.toString());
        // Обновляем номер страницы в футере немедленно
        const pageNumEl = document.getElementById('pageNum');
        if (pageNumEl) pageNumEl.textContent = String(selectedPage);
        selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
        refreshDashboardData();
      }
    });
  }
});

// ===== Адаптивность таблицы =====
let isRefreshing = false;

// Простая функция настройки плотности таблицы
function adjustTableDensity() {
  if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
    window.tableLayoutManager.refresh();
  }
}

// applyCompactMode отключен

let overlayShownAt = 0;

// Функции для управления глобальным прелоадером
function showPageLoader() {
  let loader = document.getElementById('pageLoader');
  if (!loader) {
    // Создаём прелоадер если его нет
    loader = document.createElement('div');
    loader.className = 'page-loader';
    loader.id = 'pageLoader';
    loader.innerHTML = `
      <div class="middle">
        <span class="loader loader-primary"></span>
      </div>
    `;
    document.body.appendChild(loader);
  }
  loader.classList.remove('hidden');
}

function hidePageLoader() {
  const loader = document.getElementById('pageLoader');
  if (loader && !loader.classList.contains('hidden')) {
    loader.classList.add('hidden');
    // НЕ удаляем элемент - он будет использоваться повторно
  }
}

function collectRefreshParams() {
  const params = new URLSearchParams(window.location.search);
  syncNumericRange(params, 'pharma', 'pharma_from', 'pharma_to', 'pharmaSlider');
  syncNumericRange(params, 'friends', 'friends_from', 'friends_to', 'friendsSlider');
  return params;
}

function syncNumericRange(params, prefix, fromId, toId, sliderId) {
  const fromInput = document.getElementById(fromId);
  const toInput = document.getElementById(toId);
  const slider = document.getElementById(sliderId);
  const min = slider ? parseInt(slider.getAttribute('data-min') || '0', 10) : null;
  const max = slider ? parseInt(slider.getAttribute('data-max') || '0', 10) : null;
  const fromVal = fromInput ? fromInput.value.trim() : '';
  const toVal = toInput ? toInput.value.trim() : '';

  if (fromVal !== '') {
    params.set(`${prefix}_from`, fromVal);
  } else {
    params.delete(`${prefix}_from`);
  }

  if (toVal !== '') {
    params.set(`${prefix}_to`, toVal);
  } else {
    params.delete(`${prefix}_to`);
  }

  if (min !== null && max !== null && fromVal !== '' && toVal !== '') {
    const numericFrom = parseInt(fromVal, 10);
    const numericTo = parseInt(toVal, 10);
    if (!Number.isNaN(numericFrom) && !Number.isNaN(numericTo) && numericFrom <= min && numericTo >= max) {
      params.delete(`${prefix}_from`);
      params.delete(`${prefix}_to`);
    }
  }
}

function setTableLoadingState(isLoading) {
  console.log('setTableLoadingState called with:', isLoading);
  const tableOverlay = document.getElementById('tableLoading');
  const statsOverlay = document.getElementById('statsLoading');
  const tableResponsive = document.querySelector('.table-responsive');

  if (isLoading) {
    if (tableOverlay) {
      tableOverlay.style.display = '';
      tableOverlay.classList.add('show');
      overlayShownAt = Date.now();
    }
    if (statsOverlay) {
      statsOverlay.style.display = '';
      statsOverlay.classList.add('show');
    }
    if (tableResponsive) {
      tableResponsive.classList.add('loading');
    }
    return;
  }

  if (tableOverlay) {
    const elapsed = Date.now() - (overlayShownAt || 0);
    const minMs = 300;
    const hide = () => tableOverlay.classList.remove('show');
    if (elapsed < minMs) {
      setTimeout(hide, Math.max(minMs - elapsed, 0));
    } else {
      hide();
    }
  }

  if (statsOverlay) {
    statsOverlay.classList.remove('show');
  }

  if (tableResponsive) {
    tableResponsive.classList.remove('loading');
  }
}

// ===== Фиксированный горизонтальный скролл таблицы =====
// Код перемещен в assets/js/sticky-scrollbar.js
// Оптимизированный обработчик resize с троттлингом
let resizeTimeout;
const optimizedResizeHandler = () => {
  if (resizeTimeout) return;
  resizeTimeout = requestAnimationFrame(() => {
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
    // Пересчитываем плотность таблицы при изменении размера окна
    adjustTableDensity();
    resizeTimeout = null;
  });
};
window.addEventListener('resize', optimizedResizeHandler, { passive: true });
// Оптимизированный обработчик скролла с дебаунсингом
let scrollTimeout;
const optimizedUpdateStickyHScroll = () => {
  clearTimeout(scrollTimeout);
  scrollTimeout = requestAnimationFrame(() => {
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
  });
};
window.addEventListener('scroll', optimizedUpdateStickyHScroll, { passive: true });

// ===== Редактирование названий статистических блоков =====
function initializeStatCardEditing() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  
  statLabels.forEach(label => {
    label.addEventListener('click', function(e) {
      // Не редактируем при клике на иконку
      if (e.target.classList.contains('fas') || e.target.classList.contains('edit-icon')) {
        return;
      }
      
      startEditing(this);
    });
  });
}

function startEditing(labelElement) {
  const labelText = labelElement.querySelector('.label-text');
  const originalText = labelText.textContent;
  const cardType = labelElement.getAttribute('data-card');
  
  // Создаем поле ввода
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'form-control form-control-sm stat-edit-input';
  input.value = originalText;
  input.style.cssText = `
    text-align: center;
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: 2px solid #667eea;
    border-radius: 8px;
    padding: 0.25rem 0.5rem;
    background: white;
    color: #495057;
    width: 100%;
    max-width: 200px;
  `;
  
  // Заменяем текст на поле ввода
  labelText.style.display = 'none';
  labelElement.appendChild(input);
  input.focus();
  input.select();
  
  // Обработчики событий
  function finishEditing() {
    const newText = input.value.trim();
    
    if (newText === '') {
      newText = originalText;
    }
    
    // Обновляем текст
    labelText.textContent = newText;
    labelText.style.display = 'inline';
    
    // Удаляем поле ввода
    input.remove();
    
    // Сохраняем в localStorage
    saveStatLabel(cardType, newText);
    
    // Показываем уведомление
    if (newText !== originalText) {
      showToast(`Название блока "${originalText}" изменено на "${newText}"`, 'success');
    }
  }
  
  input.addEventListener('blur', finishEditing);
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      finishEditing();
    } else if (e.key === 'Escape') {
      labelText.textContent = originalText;
      labelText.style.display = 'inline';
      input.remove();
    }
  });
}

function saveStatLabel(cardType, label) {
  const key = `stat_label_${cardType}`;
  localStorage.setItem(key, label);
}

function loadStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  
  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const key = `stat_label_${cardType}`;
    const savedLabel = localStorage.getItem(key);
    
    if (savedLabel) {
      const labelText = label.querySelector('.label-text');
      labelText.textContent = savedLabel;
    }
  });
}

// Загружаем сохраненные названия при инициализации
document.addEventListener('DOMContentLoaded', function() {
  // Загружаем выбранные ID из localStorage при инициализации
  loadSelectedIds();
  
  // ВАЖНО: Сначала применяем скрытие карточек СИНХРОННО из localStorage
  // Это предотвращает мигание скрытых карточек
  loadHiddenCardsFromLocalStorage();
  
  loadStatLabels();
  initStatValues();
  initializeAutoRefresh();
  initializeTouchGestures();
  initScrollToTop();
  // loadEmptyStatusCount(); // Отключено - функционал встроен в основной фильтр
  
  // Скрываем прелоадеры при загрузке страницы (данные уже загружены сервером)
  const statsOverlay = document.getElementById('statsLoading');
  if (statsOverlay) {
    statsOverlay.classList.remove('show');
    statsOverlay.style.display = 'none';
  }
  
  const tableOverlay = document.getElementById('tableLoading');
  if (tableOverlay) {
    tableOverlay.classList.remove('show');
    tableOverlay.style.display = 'none';
  }
});

// Загрузка количества пустых статусов (ОТКЛЮЧЕНО - функционал встроен в основной фильтр)
/*
async function loadEmptyStatusCount() {
  try {
    console.log('📊 Загружаем количество пустых статусов...');
    const response = await fetch('empty_status_manager.php?action=get_empty_status_count');
    const data = await response.json();
    
    console.log('📊 Ответ API пустых статусов:', data);
    
    if (data.success) {
      const countEl = document.getElementById('emptyStatusCount');
      const cardEl = document.querySelector('[data-card="empty_status"]');
      const navBtnEl = document.getElementById('emptyStatusNavBtn');
      
      console.log('📊 Элементы найдены:', {
        countEl: !!countEl,
        cardEl: !!cardEl,
        navBtnEl: !!navBtnEl,
        count: data.count
      });
      
      if (countEl && cardEl) {
        // Обновляем значение
        updateStatValue(countEl, data.count);
        
        // Показываем/скрываем плитку и кнопку навигации в зависимости от количества
        if (data.count > 0) {
          console.log('📊 Показываем плитку пустых статусов (count > 0)');
          cardEl.classList.remove('force-hidden', 'd-none');
          cardEl.removeAttribute('hidden');
          if (navBtnEl) {
            navBtnEl.classList.remove('force-hidden', 'd-none');
            navBtnEl.removeAttribute('hidden');
          }
        } else {
          cardEl.classList.add('force-hidden', 'd-none');
          cardEl.setAttribute('hidden', 'true');
          if (navBtnEl) {
            navBtnEl.classList.add('force-hidden', 'd-none');
            navBtnEl.setAttribute('hidden', 'true');
          }
        }
      }
    } else {
      console.error('📊 API вернул ошибку:', data.error);
    }
  } catch (error) {
    console.error('Ошибка загрузки пустых статусов:', error);
  }
}
*/

// Анимация чисел в статистических блоках
function animateStatNumbers() {
  const statValues = document.querySelectorAll('.stat-value');
  
  statValues.forEach(valueElement => {
    const finalNumber = parseInt(valueElement.textContent.replace(/,/g, ''));
    const duration = 2000; // 2 секунды
    const steps = 60;
    const stepValue = finalNumber / steps;
    let currentStep = 0;
    
    valueElement.textContent = '0';
    
    const timer = setInterval(() => {
      currentStep++;
      const currentValue = Math.floor(stepValue * currentStep);
      
      if (currentStep >= steps) {
        valueElement.textContent = finalNumber.toLocaleString();
        clearInterval(timer);
      } else {
        valueElement.textContent = currentValue.toLocaleString();
      }
    }, duration / steps);
  });
}

// Инициализация числовых значений без анимации и анимированное обновление только изменившихся
function getElementNumericValue(el) {
  const ds = el.getAttribute('data-value');
  if (ds !== null && ds !== '') {
    const n = Number(ds);
    if (!Number.isNaN(n)) return n;
  }
  const t = (el.textContent || '').replace(/[^\d\-]/g, '');
  const n = parseInt(t || '0', 10);
  return Number.isNaN(n) ? 0 : n;
}

function initStatValues() {
  const statValues = document.querySelectorAll('.stat-value');
  statValues.forEach(el => {
    const n = getElementNumericValue(el);
    el.setAttribute('data-value', String(n));
    // Приводим отображение к локализованному формату без анимации
    el.textContent = Number(n).toLocaleString();
  });
}

function updateStatValue(el, nextNumber, duration = 600) {
  const next = Number(nextNumber);
  if (Number.isNaN(next)) return;
  const from = getElementNumericValue(el);
  if (from === next) return; // Нет изменений — без анимации
  // Отменяем предыдущую анимацию, если была
  if (el.__animFrameId) { try { cancelAnimationFrame(el.__animFrameId); } catch(_) {} }
  const startTime = performance.now();
  const animate = (now) => {
    const p = Math.min(1, (now - startTime) / duration);
    const current = Math.round(from + (next - from) * p);
    el.textContent = Number(current).toLocaleString();
    if (p < 1) {
      el.__animFrameId = requestAnimationFrame(animate);
    } else {
      el.__animFrameId = null;
      el.setAttribute('data-value', String(next));
      el.textContent = Number(next).toLocaleString();
    }
  };
  el.__animFrameId = requestAnimationFrame(animate);
}

// Сброс названий блоков к исходным значениям
function resetStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  
  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const originalText = label.getAttribute('data-original');
    const labelText = label.querySelector('.label-text');
    
    // Восстанавливаем исходное название
    labelText.textContent = originalText;
    
    // Удаляем из localStorage
    const key = `stat_label_${cardType}`;
    localStorage.removeItem(key);
  });
}

// Предварительный просмотр названий блоков
function previewStatLabels() {
  const statLabels = document.querySelectorAll('.stat-label.editable');
  let previewText = 'Текущие названия блоков:\n\n';
  
  statLabels.forEach(label => {
    const cardType = label.getAttribute('data-card');
    const currentText = label.querySelector('.label-text').textContent;
    const originalText = label.getAttribute('data-original');
    
    previewText += `• ${cardType}: "${currentText}"`;
    if (currentText !== originalText) {
      previewText += ` (было: "${originalText}")`;
    }
    previewText += '\n';
  });
  
  // Показываем в модальном окне
  const previewModal = document.getElementById('previewModal');
  const previewModalTitle = document.getElementById('previewModalTitle');
  const previewModalBody = document.getElementById('previewModalBody');
  
  if (previewModalTitle) previewModalTitle.textContent = 'Предварительный просмотр названий';
  if (previewModalBody) previewModalBody.textContent = previewText;
  
  if (previewModal) {
    const modal = new bootstrap.Modal(previewModal);
    modal.show();
  }
}



// ===== Автообновление данных =====
let autoRefreshInterval = null;
let isAutoRefreshEnabled = false;
let refreshController = null;
let refreshQueued = false;

function initializeAutoRefresh() {
  const toggleBtn = document.getElementById('autoRefreshToggle');
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
  const toggleBtn = document.getElementById('autoRefreshToggle');
  if (!toggleBtn) return;
  
  toggleBtn.classList.add('active');
  toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
  toggleBtn.title = 'Остановить автообновление';
  
  // Обновляем каждые 30 секунд; сбросим предыдущий интервал на всякий случай
  if (autoRefreshInterval) { clearInterval(autoRefreshInterval); autoRefreshInterval = null; }
  autoRefreshInterval = setInterval(() => {
    refreshDashboardData();
  }, 30000);
  
  localStorage.setItem('dashboard_auto_refresh', 'enabled');
  // Не показываем уведомление постоянно
}

function stopAutoRefresh() {
  isAutoRefreshEnabled = false;
  const toggleBtn = document.getElementById('autoRefreshToggle');
  if (!toggleBtn) return;
  
  toggleBtn.classList.remove('active');
  toggleBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
  toggleBtn.title = 'Включить автообновление';
  
  if (autoRefreshInterval) {
    clearInterval(autoRefreshInterval);
    autoRefreshInterval = null;
  }
  // Отменяем текущий запрос, если он есть
  try { if (refreshController) refreshController.abort(); } catch(_) {}
  
  localStorage.setItem('dashboard_auto_refresh', 'disabled');
  showToast('Автообновление отключено', 'info');
}

async function refreshDashboardData() {
  // Single-flight: если уже идёт обновление, поставим перезапуск в очередь
  if (refreshController) {
    refreshQueued = true;
    try { refreshController.abort(); } catch(_) {}
  }
    const params = new URLSearchParams(window.location.search);
    const url = 'refresh.php?' + params.toString();
  refreshController = new AbortController();
  const signal = refreshController.signal;
  try {
    const res = await fetch(url, { 
      credentials: 'same-origin', 
      signal, 
      cache: 'no-store',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    if (!res.ok) return;
    const data = await res.json();
    if (!data.success) return;

    // Обновляем KPI
    const totalEl = document.querySelector('[data-card="total"] .stat-value');
    if (totalEl && data.totals && typeof data.totals.all === 'number') {
      updateStatValue(totalEl, data.totals.all);
    }
    if (typeof data.filteredTotal === 'number') {
      filteredTotalLive = data.filteredTotal;
    }

    // Обновляем карточки по статусам
    // Ищем только элементы .stat-card с атрибутом data-card, начинающимся с "status:"
    // Исключаем кнопки, чекбоксы и другие элементы
    const statusCards = document.querySelectorAll('.stat-card[data-card^="status:"]');
    console.log('🔄 Обновление карточек статистики:', {
      'cards_found': statusCards.length,
      'byStatus_keys': data.byStatus ? Object.keys(data.byStatus) : []
    });
    
    statusCards.forEach(cardElement => {
      // Берем статус прямо с элемента карточки (он сам является .stat-card)
      const statusKey = cardElement.getAttribute('data-status');
      
      // Пропускаем элементы без data-status (это не карточки статусов)
      if (!statusKey) {
        return;
      }
      
      // Ищем значение в byStatus (используем реальное имя статуса)
      const cnt = data.byStatus && typeof data.byStatus[statusKey] !== 'undefined' 
        ? data.byStatus[statusKey] 
        : null;
      
      if (cnt !== null) {
        const valEl = cardElement.querySelector('.stat-value');
        if (valEl) {
          updateStatValue(valEl, cnt);
        }
      } else {
        // Если статус не найден в byStatus, возможно он был удален или изменен
        // Устанавливаем 0 для таких карточек
        const valEl = cardElement.querySelector('.stat-value');
        if (valEl) {
          updateStatValue(valEl, 0);
        }
      }
    });

    // Обновляем счетчики в dropdown статусов
    if (data.byStatus) {
      const statusCountElements = document.querySelectorAll('.status-count');
      statusCountElements.forEach(el => {
        const status = el.getAttribute('data-status');
        const count = data.byStatus[status] || 0;
        el.textContent = count;
      });
    }

    // Обновляем таблицу
    if (window.tableModule && typeof window.tableModule.updateRows === 'function') {
      window.tableModule.updateRows(data);
    } else {
      const fallbackBody = document.querySelector('#accountsTable tbody');
      if (fallbackBody && Array.isArray(data.rows)) {
        const columnsCount = document.querySelectorAll('#accountsTable thead th').length || 1;
        if (!data.rows.length) {
          fallbackBody.innerHTML = `<tr><td colspan="${columnsCount}" class="text-center text-muted py-5">Ничего не найдено</td></tr>`;
        } else {
          fallbackBody.innerHTML = data.rows
            .map(row => `<tr><td colspan="${columnsCount}" class="text-muted">#${row.id}</td></tr>`)
            .join('');
        }
      }
    }
    
    // Обновляем счетчики в тулбаре после обновления таблицы
    // showingCountTop - сколько строк показано на странице
    const showingCountTopEl = document.getElementById('showingCountTop');
    if (showingCountTopEl && Array.isArray(data.rows)) {
      showingCountTopEl.textContent = String(data.rows.length);
    }
    
    // showingOnPageTop - общее количество строк на странице (для "Отмечено: X из Y")
    // Обновляется через updateSelectedOnPageCounter(), но обновим и здесь для надежности
    const showingOnPageTopEl = document.getElementById('showingOnPageTop');
    if (showingOnPageTopEl && Array.isArray(data.rows)) {
      showingOnPageTopEl.textContent = String(data.rows.length);
    }
    
    // Обновляем счетчик выбранных строк на странице
    if (typeof updateSelectedOnPageCounter === 'function') {
      updateSelectedOnPageCounter();
    }

  } catch (error) {
    // Игнорируем AbortError (когда запрос отменяется намеренно)
    if (error.name === 'AbortError' || error.message?.includes('aborted')) {
      // Запрос был отменен намеренно, это не ошибка
      return;
    }
    
    // Обработка реальных ошибок AJAX
    console.error('❌ Ошибка обновления данных:', error);
    
    // Показываем сообщение об ошибке пользователю
    const errorMessage = error.message || 'Не удалось обновить данные';
    
    if (typeof showToast === 'function') {
      showToast(`Ошибка обновления: ${errorMessage}`, 'error');
    } else {
      console.error('Toast не доступен:', errorMessage);
    }
    
    // Скрываем прелоадеры при ошибке
    const tableOverlay = document.getElementById('tableLoading');
    const statsOverlay = document.getElementById('statsLoading');
    
    if (tableOverlay) {
      tableOverlay.classList.remove('show');
    }
    if (statsOverlay) {
      statsOverlay.classList.remove('show');
      statsOverlay.style.display = 'none';
    }
    
    // Опционально: добавляем кнопку "Повторить" в интерфейс
    const retryButton = document.createElement('button');
    retryButton.textContent = 'Повторить попытку';
    retryButton.className = 'btn btn-sm btn-primary mt-2';
    retryButton.onclick = () => {
      retryButton.remove();
      refreshDashboardData();
    };
    
    // Добавляем кнопку в контейнер таблицы (если нужно)
    const tbody = document.querySelector('#accountsTable tbody');
    if (tbody && tbody.children.length === 0) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 100;
      td.className = 'text-center py-5';
      td.innerHTML = `
        <i class="fas fa-exclamation-triangle fa-2x mb-3 text-danger"></i>
        <div class="mb-3">${errorMessage}</div>
      `;
      td.appendChild(retryButton);
      tr.appendChild(td);
      tbody.innerHTML = '';
      tbody.appendChild(tr);
    }
  } finally {
    // Пересчёт позиции/ширины фиксированного скролла после обновления данных
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
    // Сбрасываем флаг обновления
    isRefreshing = false;
    
    // Финальный пересчет верстки таблицы с задержкой для гарантии корректного отображения
    // Это исправляет проблему, когда верстка "сыпется" после AJAX обновления
    setTimeout(() => {
      const table = document.getElementById('accountsTable');
      if (!table) return;
      
      // Принудительно вызываем reflow для корректного расчета размеров
      void table.offsetHeight;
      
      // Пересчитываем верстку таблицы после обновления
      // Используем новый менеджер верстки, если он доступен
      if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
        window.tableLayoutManager.refresh();
      } else {
        // Fallback на старые функции
        requestAnimationFrame(() => {
          adjustTableDensity();
          syncHeaderWidths();
        });
      }
      
      if (window.tableVirtualization && typeof window.tableVirtualization.refresh === 'function') {
        window.tableVirtualization.refresh();
      }
      
      if (typeof window.updateStickyScrollbar === 'function') {
        window.updateStickyScrollbar();
      }
    }, 200);
    
    // Скрываем прелоадеры
    const tableOverlay = document.getElementById('tableLoading');
    const statsOverlay = document.getElementById('statsLoading');
    const tableResponsive = document.querySelector('.table-responsive');
    
    if (tableOverlay) {
      const elapsed = Date.now() - (overlayShownAt || 0);
      const minMs = 300;
      if (elapsed < minMs) {
        setTimeout(() => tableOverlay.classList.remove('show'), minMs - elapsed);
      } else {
        tableOverlay.classList.remove('show');
      }
    }
    
    if (statsOverlay) {
      statsOverlay.classList.remove('show');
      statsOverlay.style.display = 'none';
    }
    
    if (tableResponsive) {
      tableResponsive.classList.remove('loading');
    }
  }
}

// ===== Кнопка "Наверх" =====
function initScrollToTop() {
  const scrollToTopBtn = document.getElementById('scrollToTop');
  if (!scrollToTopBtn) return;

  // Показываем/скрываем кнопку в зависимости от позиции скролла
  function toggleScrollToTop() {
    if (window.pageYOffset > 300) {
      scrollToTopBtn.classList.add('show');
    } else {
      scrollToTopBtn.classList.remove('show');
    }
  }

  // Плавный скролл наверх
  function scrollToTop() {
    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  }

  // Обработчики событий
  window.addEventListener('scroll', toggleScrollToTop);
  scrollToTopBtn.addEventListener('click', scrollToTop);

  // Инициализация
  toggleScrollToTop();
}

// ===== Touch-жесты и адаптивные карточки =====
function initializeTouchGestures() {
  const touchCards = document.querySelectorAll('.touch-card');
  
  touchCards.forEach(card => {
    let startX = 0;
    let startY = 0;
    let currentX = 0;
    let currentY = 0;
    
    // Touch события
    card.addEventListener('touchstart', function(e) {
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      currentX = startX;
      currentY = startY;
      
      this.classList.add('touching');
    });
    
    card.addEventListener('touchmove', function(e) {
      currentX = e.touches[0].clientX;
      currentY = e.touches[0].clientY;
      
      const deltaX = currentX - startX;
      const deltaY = currentY - startY;
      
      // Swipe влево - показать детали
      if (deltaX < -50 && Math.abs(deltaY) < 50) {
        this.style.transform = `translateX(${deltaX}px)`;
      }
    });
    
    card.addEventListener('touchend', function(e) {
      const deltaX = currentX - startX;
      const deltaY = currentY - startY;
      
      this.classList.remove('touching');
      this.style.transform = '';
      
      // Swipe влево - показать детали
      if (deltaX < -100 && Math.abs(deltaY) < 50) {
        handleCardSwipe(this);
      }
      
      // Tap - редактирование названия
      if (Math.abs(deltaX) < 10 && Math.abs(deltaY) < 10) {
        const label = this.querySelector('.stat-label.editable');
        if (label) {
          startEditing(label);
        }
      }
    });
    
    // Mouse события для десктопа
    card.addEventListener('mousedown', function(e) {
      startX = e.clientX;
      startY = e.clientY;
      this.classList.add('touching');
    });
    
    card.addEventListener('mousemove', function(e) {
      if (this.classList.contains('touching')) {
        currentX = e.clientX;
        currentY = e.clientY;
        
        const deltaX = currentX - startX;
        const deltaY = currentY - startY;
        
        if (deltaX < -50 && Math.abs(deltaY) < 50) {
          this.style.transform = `translateX(${deltaX}px)`;
        }
      }
    });
    
    card.addEventListener('mouseup', function(e) {
      if (this.classList.contains('touching')) {
        const deltaX = currentX - startX;
        const deltaY = currentY - startY;
        
        this.classList.remove('touching');
        this.style.transform = '';
        
        if (deltaX < -100 && Math.abs(deltaY) < 50) {
          handleCardSwipe(this);
        }
      }
    });
    
    // Hover эффекты для десктопа
    card.addEventListener('mouseenter', function() {
      if (!this.classList.contains('touching')) {
        this.style.transform = 'translateY(-5px) scale(1.02)';
      }
    });
    
    card.addEventListener('mouseleave', function() {
      if (!this.classList.contains('touching')) {
        this.style.transform = '';
      }
    });
  });
}

async function handleCardSwipe(card) {
  const cardType = card.getAttribute('data-card-type');
  const status = card.getAttribute('data-status');
  
  if (cardType === 'total') {
    // Показать общую статистику
    showToast('Показать детальную статистику по всем аккаунтам', 'info');
  } else if (cardType === 'status') {
    // Фильтровать по статусу - БЕЗ перезагрузки страницы
    const url = new URL(window.location);
    // Удаляем все старые статусы
    const keysToDelete = [];
    for (const key of url.searchParams.keys()) {
      if (key === 'status[]' || key === 'status') {
        keysToDelete.push(key);
      }
    }
    keysToDelete.forEach(key => {
      while (url.searchParams.has(key)) {
        url.searchParams.delete(key);
      }
    });
    // Добавляем новый статус
    url.searchParams.append('status[]', status);
    url.searchParams.set('page', '1');
    // Обновляем URL без перезагрузки
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; 
    selectedIds.clear(); 
    updateSelectedCount();
    // Обновляем данные через AJAX
    refreshDashboardData();
  } else if (cardType === 'custom') {
    // Применяем все фильтры из кастомной карточки
    const cardKey = card.getAttribute('data-card-key');
    if (!cardKey) {
      console.warn('Card swipe: no card key found');
      return;
    }
    
    // Используем синхронную загрузку из localStorage для быстрого доступа
    const cards = loadCustomCardsFromLocalStorage();
    const cardData = cards.find(c => c.key === cardKey);
    if (!cardData) {
      console.warn('Card swipe: card not found', cardKey);
      showToast('Карточка не найдена', 'error');
      return;
    }
    
    const url = new URL(window.location);
    url.search = ''; // Очищаем все текущие фильтры
    
    const filters = cardData.filters || {};
    
    // Логируем для отладки
    console.log('Applying filters from card:', cardKey, filters);
    
    // Статусы (множественный выбор - передаем как массив)
    if (filters.status && Array.isArray(filters.status) && filters.status.length > 0) {
      // Для множественного выбора статусов используем параметр status[] (массив)
      // URLSearchParams.append с одинаковым ключом создаст массив в PHP
      filters.status.forEach(st => {
        url.searchParams.append('status[]', st);
      });
    } else if (filters.status && typeof filters.status === 'string' && filters.status !== '') {
      // Если статус передан как строка (для обратной совместимости)
      url.searchParams.set('status', filters.status);
    }
    
    // Булевы фильтры
    if (filters.has_email) url.searchParams.set('has_email', '1');
    if (filters.has_two_fa) url.searchParams.set('has_two_fa', '1');
    if (filters.has_token) url.searchParams.set('has_token', '1');
    if (filters.has_avatar) url.searchParams.set('has_avatar', '1');
    if (filters.has_cover) url.searchParams.set('has_cover', '1');
    if (filters.has_password) url.searchParams.set('has_password', '1');
    if (filters.has_fan_page) url.searchParams.set('has_fan_page', '1');
    if (filters.full_filled) url.searchParams.set('full_filled', '1');
    if (filters.favorites_only) url.searchParams.set('favorites_only', '1');
    
    // Диапазоны
    if (filters.pharma_from) url.searchParams.set('pharma_from', filters.pharma_from);
    if (filters.pharma_to) url.searchParams.set('pharma_to', filters.pharma_to);
    if (filters.friends_from) url.searchParams.set('friends_from', filters.friends_from);
    if (filters.friends_to) url.searchParams.set('friends_to', filters.friends_to);
    if (filters.year_created_from) url.searchParams.set('year_created_from', filters.year_created_from);
    if (filters.year_created_to) url.searchParams.set('year_created_to', filters.year_created_to);
    
    // Одиночные фильтры
    if (filters.status_marketplace) url.searchParams.set('status_marketplace', filters.status_marketplace);
    if (filters.currency) url.searchParams.set('currency', filters.currency);
    if (filters.geo) url.searchParams.set('geo', filters.geo);
    if (filters.status_rk) url.searchParams.set('status_rk', filters.status_rk);
    
    // Limit RK (диапазон)
    if (filters.limit_rk_from) url.searchParams.set('limit_rk_from', filters.limit_rk_from);
    if (filters.limit_rk_to) url.searchParams.set('limit_rk_to', filters.limit_rk_to);
    
    // Поиск
    if (filters.q) url.searchParams.set('q', filters.q);
    
    // Убираем автоматическое обновление статуса при клике
    // Статус больше не обновляется автоматически - просто применяются фильтры
    
    // Сохраняем активную карточку в URL для восстановления после перезагрузки
    url.searchParams.set('active_card', cardKey);
    url.searchParams.set('page', '1');
    
    // Обновляем URL без перезагрузки страницы
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; 
    selectedIds.clear(); 
    updateSelectedCount();
    // Обновляем данные через AJAX
    refreshDashboardData();
  }
}

// ===== Адаптивность для мобильных устройств =====
function adjustForMobile() {
  const isMobile = window.innerWidth <= 768;
  
  if (isMobile) {
    document.body.classList.add('touch-friendly');
    
    // Увеличиваем размеры кнопок для touch
    document.querySelectorAll('.btn').forEach(btn => {
      btn.classList.add('touch-friendly');
    });
    
    // Адаптируем карточки
    document.querySelectorAll('.stat-card').forEach(card => {
      card.classList.add('touch-friendly');
    });
  } else {
    document.body.classList.remove('touch-friendly');
  }
}

// Вызываем адаптацию при загрузке и изменении размера
window.addEventListener('resize', adjustForMobile);
window.addEventListener('load', function() {
  adjustForMobile();
  loadHiddenCards().catch(err => console.error('Error loading hidden cards:', err)); // Загружаем скрытые карточки при загрузке страницы
});

// ===== КАСТОМНЫЕ КАРТОЧКИ СТАТИСТИКИ =====
// Полностью переписанный функционал с нуля - версия 3.0
const LS_KEY_CUSTOM_CARDS = 'dashboard_custom_cards_v3';

// Вспомогательная функция для конвертации HEX в RGB
function hexToRgb(hex) {
  if (!hex) return null;
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  return result ? {
    r: parseInt(result[1], 16),
    g: parseInt(result[2], 16),
    b: parseInt(result[3], 16)
  } : null;
}

// ===== БАЗОВЫЕ ФУНКЦИИ РАБОТЫ С ХРАНИЛИЩЕМ =====

/**
 * Загрузка кастомных карточек из БД с fallback на localStorage
 */
async function loadCustomCardsFromStorage() {
  try {
    const response = await fetch('api_user_settings.php?type=custom_cards', {
      method: 'GET',
      credentials: 'same-origin'
    });
    
    if (response.ok) {
      const data = await response.json();
      if (data.success && Array.isArray(data.value)) {
        const cards = data.value.filter(x => x && typeof x === 'object' && x.key);
        // Сохраняем в localStorage как резервную копию
        try {
          localStorage.setItem(LS_KEY_CUSTOM_CARDS, JSON.stringify(cards));
        } catch (e) {
          console.warn('Failed to save to localStorage:', e);
        }
        return cards;
      }
    }
  } catch (error) {
    console.warn('Error loading from server, using localStorage:', error);
  }
  
  // Fallback на localStorage
  return loadCustomCardsFromLocalStorage();
}

/**
 * Загрузка из localStorage (резервная)
 */
function loadCustomCardsFromLocalStorage() {
  try {
    const raw = localStorage.getItem(LS_KEY_CUSTOM_CARDS);
    if (!raw) return [];
    const arr = JSON.parse(raw);
    if (!Array.isArray(arr)) return [];
    return arr.filter(x => x && typeof x === 'object' && x.key);
  } catch (e) {
    console.error('Error loading from localStorage:', e);
    return [];
  }
}

/**
 * Сохранение кастомных карточек в БД и localStorage
 */
async function saveCustomCardsToStorage(cards) {
  if (!Array.isArray(cards)) {
    console.error('Invalid cards array');
    return false;
  }
  
  // Сохраняем в localStorage сразу
  try {
    localStorage.setItem(LS_KEY_CUSTOM_CARDS, JSON.stringify(cards));
  } catch (e) {
    console.warn('Failed to save to localStorage:', e);
  }
  
  // Сохраняем в БД
  try {
    const response = await fetch('api_user_settings.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        type: 'custom_cards',
        value: cards
      })
    });
    
    if (!response.ok) {
      console.warn('Failed to save to server, saved to localStorage only');
      return false;
    }
    
    return true;
  } catch (error) {
    console.error('Error saving to server:', error);
    return false;
  }
}

// ===== ФУНКЦИИ ОТОБРАЖЕНИЯ =====

/**
 * Отображение списка карточек в настройках
 */
async function renderCustomCardsSettings() {
  const list = document.getElementById('customCardsList');
  if (!list) {
    console.warn('customCardsList element not found');
    return;
  }
  
  const cards = await loadCustomCardsFromStorage();
  
  if (!cards.length) {
    list.innerHTML = '<div class="text-muted text-center py-3">Нет кастомных карточек. Нажмите "Создать карточку" для добавления.</div>';
    return;
  }
  
  list.innerHTML = cards.map((c, idx) => {
    const filters = c.filters || {};
    const filterDesc = [];
    
    if (filters.status && Array.isArray(filters.status) && filters.status.length > 0) {
      filterDesc.push(`Статусы: ${filters.status.length}`);
    }
    if (filters.has_email) filterDesc.push('Email');
    if (filters.has_two_fa) filterDesc.push('2FA');
    if (filters.has_token) filterDesc.push('Token');
    if (filters.has_avatar) filterDesc.push('Аватар');
    if (filters.has_cover) filterDesc.push('Обложка');
    if (filters.has_password) filterDesc.push('Пароль');
    if (filters.has_fan_page) filterDesc.push('Fan Page');
    if (filters.full_filled) filterDesc.push('Полностью заполнено');
    if (c.targetStatus) filterDesc.push(`→ ${c.targetStatus}`);
    
    return `
    <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
      <div class="flex-grow-1">
        <div class="fw-semibold d-flex align-items-center gap-2">
          ${c.settings?.color ? `<span class="badge" style="background-color: ${c.settings.color}; width: 16px; height: 16px; border-radius: 4px; display: inline-block;"></span>` : ''}
          ${(c.name || 'Без названия')}
        </div>
        <div class="text-muted small">${filterDesc.length > 0 ? filterDesc.join(' • ') : 'Без фильтров'}</div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <div class="form-check">
          <input class="form-check-input card-toggle" type="checkbox" data-card="custom:${c.key}" id="card_custom_${idx}" ${c.visible !== false ? 'checked' : ''}>
          <label class="form-check-label" for="card_custom_${idx}">Показывать</label>
        </div>
        ${c.targetStatus ? `<button type="button" class="btn btn-sm btn-outline-info" data-register-status="${c.targetStatus}" title="Повторно зарегистрировать статус"><i class="fas fa-sync-alt"></i> Обновить</button>` : ''}
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-custom-card="${c.key}" title="Удалить"><i class="fas fa-trash"></i></button>
      </div>
    </div>
    `;
  }).join('');
}

/**
 * Отображение карточек на дашборде
 */
async function renderCustomCardsOnDashboard() {
  const row = document.getElementById('statsRow');
  if (!row) {
    console.warn('statsRow element not found');
    setTimeout(() => renderCustomCardsOnDashboard(), 200);
    return;
  }
  
  // Удаляем старые кастомные карточки
  row.querySelectorAll('[data-card^="custom:"]').forEach(n => n.remove());
  
  const cards = await loadCustomCardsFromStorage();
  if (!cards.length) return;
  
  // Загружаем скрытые карточки
  const hiddenCards = new Set();
  try {
    const savedHidden = localStorage.getItem(LS_KEY_HIDDEN_CARDS);
    if (savedHidden) {
      JSON.parse(savedHidden).forEach(id => {
        if (typeof id === 'string') {
          hiddenCards.add(id);
        }
      });
    }
  } catch (e) {
    console.error('Error loading hidden cards:', e);
  }
  
  // Создаем карточки
  cards.forEach(c => {
    // Проверяем видимость
    if (c.visible === false) return;
    const cardId = `custom:${c.key}`;
    const isHiddenByUser = hiddenCards.has(cardId);
    
    // Создаем элемент карточки
    const cardElement = document.createElement('div');
    cardElement.className = 'stat-card fade-in';
    cardElement.setAttribute('data-card', cardId);
    cardElement.setAttribute('data-card-type', 'custom');
    cardElement.setAttribute('data-card-key', c.key);
    
    // Применяем фильтры как data-атрибуты
    const filters = c.filters || {};
    if (filters.has_email) cardElement.setAttribute('data-has-email', '1');
    if (filters.has_two_fa) cardElement.setAttribute('data-has-two-fa', '1');
    if (filters.has_token) cardElement.setAttribute('data-has-token', '1');
    if (filters.has_avatar) cardElement.setAttribute('data-has-avatar', '1');
    if (filters.has_cover) cardElement.setAttribute('data-has-cover', '1');
    if (filters.full_filled) cardElement.setAttribute('data-full-filled', '1');
    if (filters.pharma_from) cardElement.setAttribute('data-pharma-from', filters.pharma_from);
    if (filters.pharma_to) cardElement.setAttribute('data-pharma-to', filters.pharma_to);
    if (c.targetStatus) cardElement.setAttribute('data-target-status', c.targetStatus);
    
    // Применяем цвет
    const cardColor = c.settings?.color || '#3b82f6';
    const rgb = hexToRgb(cardColor);
    const darkerColor = rgb ? `rgb(${Math.max(0, rgb.r - 30)}, ${Math.max(0, rgb.g - 30)}, ${Math.max(0, rgb.b - 30)})` : cardColor;
    cardElement.style.setProperty('--card-color', cardColor);
    cardElement.style.setProperty('--card-color-dark', darkerColor);
    
    cardElement.innerHTML = `
      <button type="button" class="stat-card-hide-btn" data-card="${cardId}" title="Скрыть карточку">
        <i class="fas fa-eye-slash"></i>
      </button>
      <div class="stat-header">
        <h3 class="stat-title">${(c.name || 'Кастом')}</h3>
      </div>
      <div class="stat-value">0</div>
      <div class="stat-trend"><small class="text-muted">${c.targetStatus ? `→ ${c.targetStatus}` : 'Кастомные условия'}</small></div>
    `;
    
    if (isHiddenByUser) {
      cardElement.classList.add('hidden');
    }
    
    row.appendChild(cardElement);
  });
  
  // Восстанавливаем активную карточку из URL параметров
  const urlParams = new URLSearchParams(window.location.search);
  const activeCardKey = urlParams.get('active_card');
  if (activeCardKey) {
    // Небольшая задержка, чтобы карточки успели отрендериться
    setTimeout(() => {
      const activeCard = document.querySelector(`.stat-card[data-card-key="${activeCardKey}"]`);
      if (activeCard) {
        activeCard.classList.add('active');
        
        // Принудительно применяем стили через inline стили для надежности
        const cardColor = activeCard.style.getPropertyValue('--card-color') || '#3b82f6';
        activeCard.style.background = 'linear-gradient(135deg, rgba(59, 130, 246, 0.4) 0%, rgba(59, 130, 246, 0.6) 100%)';
        activeCard.style.border = `2px solid ${cardColor}`;
        activeCard.style.boxShadow = `0 0 0 3px ${cardColor}, 0 14px 24px rgba(59, 130, 246, 0.4)`;
        activeCard.style.opacity = '1';
        
        console.log('Active card restored from URL:', activeCardKey, activeCard);
        
        // Удаляем параметр из URL без перезагрузки страницы
        urlParams.delete('active_card');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, '', newUrl);
      } else {
        console.warn('Active card not found:', activeCardKey);
      }
    }, 100);
  }
  
  // Обновляем счетчики
  await refreshCustomCardCounts();
}

/**
 * Обновление счетчиков для всех кастомных карточек
 */
async function refreshCustomCardCounts() {
  const cards = await loadCustomCardsFromStorage();
  if (!cards.length) return;
  
  // Обновляем все карточки параллельно
  const updatePromises = cards.map(async (c) => {
    try {
      const filters = c.filters || {};
      
      // Обратная совместимость со старыми карточками
      if (Object.keys(filters).length === 0) {
        if (c.hasEmail) filters.has_email = true;
        if (c.hasTwoFa) filters.has_two_fa = true;
        if (c.hasToken) filters.has_token = true;
        if (c.hasAvatar) filters.has_avatar = true;
        if (c.hasCover) filters.has_cover = true;
        if (c.fullFilled) filters.full_filled = true;
        if (c.pharmaFrom) filters.pharma_from = c.pharmaFrom;
        if (c.pharmaTo) filters.pharma_to = c.pharmaTo;
      }
      
      const response = await fetch('api_custom_card.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify(filters)
      });
      
      if (!response.ok) {
        console.warn(`Failed to refresh card ${c.key}: ${response.status}`);
        return;
      }
      
      const json = await response.json();
      if (!json.success || typeof json.count !== 'number') {
        console.warn(`Invalid response for card ${c.key}:`, json);
        return;
      }
      
      const wrap = document.querySelector(`[data-card="custom:${c.key}"] .stat-value`);
      if (wrap) {
        updateStatValue(wrap, json.count);
      }
      
      // Применяем цвет карточки
      const cardEl = document.querySelector(`[data-card="custom:${c.key}"]`);
      if (cardEl && c.settings?.color) {
        cardEl.style.setProperty('--card-color', c.settings.color);
        const rgb = hexToRgb(c.settings.color);
        const darkerColor = rgb ? `rgb(${Math.max(0, rgb.r - 30)}, ${Math.max(0, rgb.g - 30)}, ${Math.max(0, rgb.b - 30)})` : c.settings.color;
        cardEl.style.setProperty('--card-color-dark', darkerColor);
      }
    } catch (e) {
      console.error(`Error refreshing custom card ${c.key}:`, e);
    }
  });
  
  await Promise.all(updatePromises);
}

/**
 * Создание новой кастомной карточки
 */
async function createCustomCard() {
  const name = (document.getElementById('customCardName')?.value || '').trim();
  if (!name) {
    showToast('Введите название карточки', 'error');
    return;
  }
  
  // Собираем фильтры
  const filters = {};
  
  // Статусы (множественный выбор)
  const statusSelect = document.getElementById('customCardStatuses');
  if (statusSelect) {
    const selectedStatuses = Array.from(statusSelect.selectedOptions).map(opt => opt.value);
    if (selectedStatuses.length > 0) {
      filters.status = selectedStatuses;
    }
  }
  
  // Булевы фильтры
  filters.has_email = !!document.getElementById('customHasEmail')?.checked;
  filters.has_two_fa = !!document.getElementById('customHasTwoFa')?.checked;
  filters.has_token = !!document.getElementById('customHasToken')?.checked;
  filters.has_avatar = !!document.getElementById('customHasAvatar')?.checked;
  filters.has_cover = !!document.getElementById('customHasCover')?.checked;
  filters.has_password = !!document.getElementById('customHasPassword')?.checked;
  filters.has_fan_page = !!document.getElementById('customHasFanPage')?.checked;
  filters.full_filled = !!document.getElementById('customFullFilled')?.checked;
  
  // Диапазоны
  const pharmaFrom = (document.getElementById('customPharmaFrom')?.value || '').trim();
  const pharmaTo = (document.getElementById('customPharmaTo')?.value || '').trim();
  if (pharmaFrom) filters.pharma_from = pharmaFrom;
  if (pharmaTo) filters.pharma_to = pharmaTo;
  
  const friendsFrom = (document.getElementById('customFriendsFrom')?.value || '').trim();
  const friendsTo = (document.getElementById('customFriendsTo')?.value || '').trim();
  if (friendsFrom) filters.friends_from = friendsFrom;
  if (friendsTo) filters.friends_to = friendsTo;
  
  const yearFrom = (document.getElementById('customYearCreatedFrom')?.value || '').trim();
  const yearTo = (document.getElementById('customYearCreatedTo')?.value || '').trim();
  if (yearFrom) filters.year_created_from = yearFrom;
  if (yearTo) filters.year_created_to = yearTo;
  
  // Одиночные фильтры
  const statusMarketplace = document.getElementById('customStatusMarketplace')?.value;
  if (statusMarketplace) filters.status_marketplace = statusMarketplace;
  
  const statusRk = document.getElementById('customStatusRk')?.value;
  if (statusRk) filters.status_rk = statusRk;
  
  // Limit RK (диапазон)
  const limitRkFrom = (document.getElementById('customLimitRkFrom')?.value || '').trim();
  const limitRkTo = (document.getElementById('customLimitRkTo')?.value || '').trim();
  if (limitRkFrom) filters.limit_rk_from = limitRkFrom;
  if (limitRkTo) filters.limit_rk_to = limitRkTo;
  
  const currency = document.getElementById('customCurrency')?.value;
  if (currency) filters.currency = currency;
  
  const geo = document.getElementById('customGeo')?.value;
  if (geo) filters.geo = geo;
  
  // Булевы фильтры
  const favoritesOnly = document.querySelector('input[type="checkbox"][name="favorites_only"]')?.checked;
  if (favoritesOnly) filters.favorites_only = true;
  
  // Целевой статус
  let targetStatus = (document.getElementById('customCardTargetStatus')?.value || '').trim();
  const wasNewStatus = (targetStatus === '__new__');
  
  if (targetStatus === '__new__') {
    targetStatus = (document.getElementById('customCardNewStatus')?.value || '').trim();
    if (!targetStatus) {
      showToast('Введите название нового статуса', 'error');
      return;
    }
  }
  
  // Автоматически регистрируем статус в БД
  if (targetStatus && targetStatus.trim() !== '') {
    try {
      const registerResponse = await fetch('api_register_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: targetStatus })
      });
      
      if (registerResponse.ok) {
        const registerData = await registerResponse.json();
        if (registerData.success) {
          console.log(`Статус "${targetStatus}" ${registerData.exists ? 'уже существует' : 'зарегистрирован'}`);
        }
      }
    } catch (error) {
      console.error('Error registering status:', error);
    }
  }
  
  // Создаем карточку
  const key = `c_${Date.now()}`;
  const card = {
    key,
    name,
    visible: true,
    filters: filters,
    targetStatus: targetStatus || null,
    settings: {
      color: document.getElementById('customCardColor')?.value || '#3b82f6'
    }
  };
  
  // Сохраняем
  const cards = await loadCustomCardsFromStorage();
  cards.push(card);
  await saveCustomCardsToStorage(cards);
  
  // Закрываем модальное окно
  const modal = bootstrap.Modal.getInstance(document.getElementById('customCardModal'));
  if (modal) modal.hide();
  
  // Обновляем UI
  await renderCustomCardsSettings();
  await renderCustomCardsOnDashboard();
  loadStatLabels();
  
  // Уведомление
  if (targetStatus && targetStatus.trim() !== '') {
    sessionStorage.removeItem('statuses_registered');
    if (wasNewStatus) {
      showToast(`Кастомная карточка добавлена. Новый статус "${targetStatus}" зарегистрирован. Обновите страницу, чтобы увидеть его в фильтрах.`, 'success', 5000);
    } else {
      showToast(`Кастомная карточка добавлена. Статус "${targetStatus}" проверен.`, 'success', 4000);
    }
  } else {
    showToast('Кастомная карточка добавлена', 'success');
  }
}

/**
 * Автоматическая регистрация отсутствующих статусов
 */
async function registerMissingStatuses() {
  try {
    const cards = await loadCustomCardsFromStorage();
    const statusesToRegister = cards
      .map(c => c.targetStatus)
      .filter(s => s && s.trim() !== '')
      .map(s => s.trim());
    
    if (statusesToRegister.length === 0) return;
    
    const uniqueStatuses = [...new Set(statusesToRegister)];
    let registeredCount = 0;
    
    for (const status of uniqueStatuses) {
      try {
        const response = await fetch('api_register_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ status: status })
        });
        
        if (response.ok) {
          const data = await response.json();
          if (data.success && !data.exists) {
            registeredCount++;
            console.log(`Статус "${status}" автоматически зарегистрирован`);
          }
        }
      } catch (error) {
        console.warn(`Не удалось зарегистрировать статус "${status}":`, error);
      }
    }
    
    if (registeredCount > 0) {
      showToast(`Зарегистрировано ${registeredCount} новых статусов. Обновите страницу, чтобы увидеть их в фильтрах.`, 'success', 5000);
    }
  } catch (error) {
    console.error('Error registering missing statuses:', error);
  }
}

/**
 * Инициализация кастомных карточек
 */
async function initializeCustomCards() {
  await renderCustomCardsSettings();
  await renderCustomCardsOnDashboard();
  
  // Автоматически регистрируем статусы один раз за сессию
  if (!sessionStorage.getItem('statuses_registered')) {
    await registerMissingStatuses();
    sessionStorage.setItem('statuses_registered', 'true');
  }
  
  // Обработчик кнопки создания карточки
  const addBtn = document.getElementById('addCustomCardBtn');
  if (addBtn) {
    addBtn.addEventListener('click', () => {
      document.getElementById('customCardForm')?.reset();
      document.getElementById('customCardColor').value = '#3b82f6';
      const newStatusInputGroup = document.getElementById('newStatusInputGroup');
      if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
    });
  }
  
  // Обработчик изменения селекта целевого статуса
  const targetStatusSelect = document.getElementById('customCardTargetStatus');
  const newStatusInputGroup = document.getElementById('newStatusInputGroup');
  const newStatusInput = document.getElementById('customCardNewStatus');
  
  if (targetStatusSelect) {
    targetStatusSelect.addEventListener('change', function() {
      if (this.value === '__new__') {
        if (newStatusInputGroup) newStatusInputGroup.style.display = 'block';
        if (newStatusInput) {
          newStatusInput.focus();
          newStatusInput.required = true;
        }
      } else {
        if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
        if (newStatusInput) {
          newStatusInput.value = '';
          newStatusInput.required = false;
        }
      }
    });
  }
  
  // Обработчик сохранения карточки
  const saveBtn = document.getElementById('saveCustomCardBtn');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      await createCustomCard();
    });
  }
  
  // Обработчик закрытия модального окна
  const modal = document.getElementById('customCardModal');
  if (modal) {
    modal.addEventListener('hidden.bs.modal', () => {
      document.getElementById('customCardForm')?.reset();
      if (newStatusInputGroup) newStatusInputGroup.style.display = 'none';
      if (newStatusInput) {
        newStatusInput.value = '';
        newStatusInput.required = false;
      }
    });
  }
  
  // Обработчик переключения видимости карточки
  document.addEventListener('change', async (e) => {
    const t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.classList.contains('card-toggle') && t.getAttribute('data-card')?.startsWith('custom:')) {
      const key = t.getAttribute('data-card')?.slice(7);
      const cards = await loadCustomCardsFromStorage();
      const card = cards.find(x => x.key === key);
      if (card) {
        card.visible = !!t.checked;
        await saveCustomCardsToStorage(cards);
        await renderCustomCardsOnDashboard();
      }
    }
  });
  
  // Обработчик удаления карточки
  document.addEventListener('click', async (e) => {
    const removeBtn = (e.target instanceof HTMLElement) ? e.target.closest('[data-remove-custom-card]') : null;
    if (removeBtn) {
      const key = removeBtn.getAttribute('data-remove-custom-card');
      const cards = (await loadCustomCardsFromStorage()).filter(x => x.key !== key);
      await saveCustomCardsToStorage(cards);
      await renderCustomCardsSettings();
      await renderCustomCardsOnDashboard();
      showToast('Кастомная карточка удалена', 'success');
      return;
    }
    
    // Обработчик регистрации статуса
    const registerBtn = (e.target instanceof HTMLElement) ? e.target.closest('[data-register-status]') : null;
    if (registerBtn) {
      const status = registerBtn.getAttribute('data-register-status');
      if (!status) return;
      
      registerBtn.disabled = true;
      const originalHtml = registerBtn.innerHTML;
      registerBtn.innerHTML = '<span class="loader loader-sm loader-white" style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-top-width:2px;border-right-width:2px;margin-right:8px;"></span> Регистрация...';
      
      try {
        const response = await fetch('api_register_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ status: status })
        });
        
        if (!response.ok) {
          const errorData = await response.json();
          throw new Error(errorData.error || 'Ошибка регистрации статуса');
        }
        
        const data = await response.json();
        if (data.success) {
          showToast(`Статус "${status}" успешно зарегистрирован. Обновите страницу, чтобы увидеть его в фильтрах.`, 'success', 5000);
        } else {
          throw new Error('Не удалось зарегистрировать статус');
        }
      } catch (error) {
        console.error('Error registering status:', error);
        showToast(`Ошибка регистрации статуса: ${error.message}`, 'error');
        registerBtn.disabled = false;
        registerBtn.innerHTML = originalHtml;
      }
    }
  });
}

// ===== ДУБЛИРУЮЩИЙСЯ КОД УДАЛЕН =====
// Все функции кастомных карточек определены выше в новой версии (строки 6300-6924)

// Change status (bulk)
const changeStatusSelected = document.getElementById('changeStatusSelected');
if (changeStatusSelected) {
  changeStatusSelected.addEventListener('click', function() {
    if (!selectedAllFiltered && selectedIds.size === 0) return;
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
  });
}
const applyStatusBtn = document.getElementById('applyStatusBtn');
if (applyStatusBtn) {
  applyStatusBtn.addEventListener('click', async function() {
    const statusSelect = document.getElementById('statusSelect');
    const statusNewInput = document.getElementById('statusNewInput');
    const newStatus = (statusNewInput?.value || '').trim() || statusSelect?.value;
    
    if (!newStatus) { 
      showToast('Укажите статус', 'error'); 
      return; 
    }
    
    try {
      let body;
      if (selectedAllFiltered) {
        const params = new URLSearchParams(window.location.search);
        body = { ids: [], status: newStatus, select: 'all', query: params.toString(), csrf: '<?= e($csrfToken) ?>' };
        console.group('📝 Изменение статуса (все по фильтру)');
      } else {
        const ids = Array.from(selectedIds);
        body = { ids, status: newStatus, csrf: '<?= e($csrfToken) ?>' };
        console.group('📝 Изменение статуса (выбранные)');
        console.log('ID для изменения:', ids);
        console.log('Количество:', ids.length);
      }
      
      console.log('Новый статус:', newStatus);
      console.log('Тело запроса:', body);
      console.groupEnd();
      
      const res = await fetch('status_update.php', { 
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }, 
        body: JSON.stringify(body) 
      });
      
      console.log('📡 Статус ответа:', res.status, res.statusText);
      
      if (!res.ok) {
        const text = await res.text();
        console.error('❌ Ошибка HTTP:', res.status, text);
        
        // Пытаемся распарсить JSON для получения детального сообщения об ошибке
        let errorMessage = `HTTP ${res.status}: ${res.statusText}`;
        try {
          const errorJson = JSON.parse(text);
          if (errorJson.error) {
            errorMessage = errorJson.error;
            // Улучшаем сообщение для ошибок валидации
            if (errorMessage.includes('invalid characters') || errorMessage.includes('Status contains')) {
              errorMessage = 'Статус содержит недопустимые символы. Разрешены только буквы (включая кириллицу), цифры, подчеркивания, дефисы и пробелы.';
            }
          }
        } catch (e) {
          // Если не удалось распарсить JSON, используем стандартное сообщение
        }
        throw new Error(errorMessage);
      }
      
      const json = await res.json();
      console.log('📥 Ответ сервера:', json);
      
      if (!json.success) {
        let errorMessage = json.error || 'Update failed';
        // Улучшаем сообщение для ошибок валидации
        if (errorMessage.includes('invalid characters') || errorMessage.includes('Status contains')) {
          errorMessage = 'Статус содержит недопустимые символы. Разрешены только буквы (включая кириллицу), цифры, подчеркивания, дефисы и пробелы.';
        }
        throw new Error(errorMessage);
      }
      
      showToast(`Статус обновлён для ${json.affected || 0} записей`, 'success');
      
      // Обновляем статистику после успешного обновления статуса
      console.log('🔄 Обновляем статистику после изменения статуса...');
      await refreshDashboardData();
      
      const modalEl = document.getElementById('statusModal');
      if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
      }
      
      await refreshDashboardData();
      
    } catch (e) { 
      console.error('Ошибка изменения статуса:', e);
      showToast('Ошибка изменения статуса: ' + e.message, 'error'); 
    }
  });
}

document.addEventListener('click', function(e) {
  const selAll = e.target && e.target.id === 'selectAllFilteredLink';
  const clearSel = e.target && e.target.id === 'clearSelectionLink';
  if (selAll) {
    e.preventDefault();
    selectedAllFiltered = true;
    // В режиме "все по фильтру" локально убирать id не будем, просто очищаем локальный набор
    selectedIds.clear();
    // Проставим чекбоксы визуально
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = true);
    const sa = document.getElementById('selectAll'); if (sa) sa.checked = true;
    updateSelectedCount();
  }
  if (clearSel) {
    e.preventDefault();
    selectedAllFiltered = false;
    selectedIds.clear();
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    const sa = document.getElementById('selectAll'); if (sa) sa.checked = false;
    updateSelectedCount();
  }
});

// Select All - обработчик удалён, используется делегирование событий ниже (см. строку 5315+)

function debounce(fn, delay) {
  let t; return function(...args){ clearTimeout(t); t = setTimeout(() => fn.apply(this, args), delay); };
}

// Дебаунсированная версия refreshDashboardData для использования в фильтрах
// Определяется после debounce и refreshDashboardData
const debouncedRefreshDashboardData = debounce(() => {
  refreshDashboardData();
}, 300); // 300ms дебаунс для фильтров

document.addEventListener('DOMContentLoaded', function() {
  const searchInput = document.getElementById('modernSearchInput');
  if (searchInput) {
    const applyLiveSearch = debounce(() => {
      const url = new URL(window.location);
      url.searchParams.set('q', searchInput.value || '');
      url.searchParams.set('page', '1');
      history.replaceState(null, '', url.toString());
      selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
      refreshDashboardData();
      
      // Показываем/скрываем кнопку очистки
      const clearBtn = document.querySelector('.header-search-clear');
      if (clearBtn) {
        clearBtn.style.display = searchInput.value ? 'flex' : 'none';
      }
    }, 300);
    searchInput.addEventListener('input', applyLiveSearch);
    searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    
    // Показываем/скрываем кнопку очистки при загрузке
    const clearBtn = document.querySelector('.header-search-clear');
    if (clearBtn) {
      clearBtn.style.display = searchInput.value ? 'flex' : 'none';
    }
  }
  // Блокируем сабмит формы фильтров
  const filterForm = document.querySelector('.card.mb-4 form');
  if (filterForm) {
    filterForm.addEventListener('submit', (e) => e.preventDefault());
  }
  // Статус (множественный выбор через чекбоксы)
  const statusCheckboxes = document.querySelectorAll('.status-checkbox');
  const statusDropdownLabel = document.getElementById('statusDropdownLabel');
  const statusDropdownMenu = document.querySelector('.status-dropdown-menu');
  
  // Функция обновления UI (мгновенно)
  function updateStatusUI() {
    const checkedBoxes = Array.from(statusCheckboxes).filter(cb => cb.checked);
    const selectedCount = checkedBoxes.length;
    const totalCount = statusCheckboxes.length;
    
    // Обновляем метку на кнопке
    if (selectedCount === 0) {
      statusDropdownLabel.textContent = 'Все статусы';
    } else if (selectedCount === totalCount) {
      statusDropdownLabel.textContent = 'Все выбраны';
    } else {
      statusDropdownLabel.textContent = `Выбрано: ${selectedCount}`;
    }
  }
  
  // Функция применения фильтра (с debounce)
  function applyStatusFilter() {
    const checkedBoxes = Array.from(statusCheckboxes).filter(cb => cb.checked);
    const selectedCount = checkedBoxes.length;
    
    // Обновляем URL и данные
    const url = new URL(window.location);
    // Удаляем все старые параметры status и empty_status
    const keysToDelete = [];
    for (const key of url.searchParams.keys()) {
      if (key === 'status[]' || key === 'status' || key === 'empty_status') {
        keysToDelete.push(key);
      }
    }
    keysToDelete.forEach(key => {
      while (url.searchParams.has(key)) {
        url.searchParams.delete(key);
      }
    });
    
    // Добавляем выбранные статусы
    if (selectedCount > 0) {
      checkedBoxes.forEach(cb => {
        if (cb.value === '__empty__') {
          url.searchParams.set('empty_status', '1');
        } else {
          url.searchParams.append('status[]', cb.value);
        }
      });
    }
    
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }
  
  // Debounced версия для применения фильтра
  const debouncedApplyStatusFilter = debounce(applyStatusFilter, 300);
  
  // Обработчик изменения чекбоксов
  statusCheckboxes.forEach(cb => {
    cb.addEventListener('change', () => {
      updateStatusUI(); // Обновляем UI мгновенно
      // НЕ применяем автоматически - только показываем индикатор
      if (typeof markFiltersAsChanged === 'function') {
        markFiltersAsChanged();
      }
    });
  });
  
  // Предотвращаем закрытие dropdown при клике внутри
  if (statusDropdownMenu) {
    statusDropdownMenu.addEventListener('click', (e) => {
      e.stopPropagation();
    });
  }
  
  // Кнопка "Выбрать все"
  const selectAllStatusesBtn = document.getElementById('selectAllStatusesBtn');
  if (selectAllStatusesBtn) {
    selectAllStatusesBtn.addEventListener('click', () => {
      statusCheckboxes.forEach(cb => cb.checked = true);
      updateStatusUI();
      // НЕ применяем автоматически - только показываем индикатор
      if (typeof markFiltersAsChanged === 'function') {
        markFiltersAsChanged();
      }
    });
  }
  
  // Кнопка "Очистить все"
  const clearAllStatusesBtn = document.getElementById('clearAllStatusesBtn');
  if (clearAllStatusesBtn) {
    clearAllStatusesBtn.addEventListener('click', () => {
      statusCheckboxes.forEach(cb => cb.checked = false);
      updateStatusUI();
      // НЕ применяем автоматически - только показываем индикатор
      if (typeof markFiltersAsChanged === 'function') {
        markFiltersAsChanged();
      }
    });
  }
  
  // Поиск по статусам
  const statusSearch = document.getElementById('statusSearch');
  if (statusSearch) {
    statusSearch.addEventListener('input', (e) => {
      const searchTerm = e.target.value.toLowerCase();
      const checkboxItems = document.querySelectorAll('.status-checkbox-item');
      
      checkboxItems.forEach(item => {
        const label = item.querySelector('.form-check-label span');
        const text = label ? label.textContent.toLowerCase() : '';
        const matches = text.includes(searchTerm);
        
        item.style.display = matches ? 'flex' : 'none';
      });
    });
    
    // Предотвращаем закрытие dropdown при клике на поиск
    statusSearch.addEventListener('click', (e) => {
      e.stopPropagation();
    });
  }
  // Статус Marketplace (dropdown с красивым дизайном)
  const statusMarketplaceItems = document.querySelectorAll('.status-marketplace-item');
  const statusMarketplaceDropdownLabel = document.getElementById('statusMarketplaceDropdownLabel');
  const statusMarketplaceInput = document.getElementById('statusMarketplaceInput');
  
  if (statusMarketplaceItems.length > 0 && statusMarketplaceDropdownLabel && statusMarketplaceInput) {
    statusMarketplaceItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        statusMarketplaceItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        statusMarketplaceDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        statusMarketplaceInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('status_marketplace', value); else url.searchParams.delete('status_marketplace');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('statusMarketplaceDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Currency фильтр (dropdown с красивым дизайном)
  const currencyItems = document.querySelectorAll('.currency-item');
  const currencyDropdownLabel = document.getElementById('currencyDropdownLabel');
  const currencyInput = document.getElementById('currencyInput');
  
  if (currencyItems.length > 0 && currencyDropdownLabel && currencyInput) {
    currencyItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        currencyItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        currencyDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        currencyInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('currency', value); else url.searchParams.delete('currency');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('currencyDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Geo фильтр (dropdown с красивым дизайном)
  const geoItems = document.querySelectorAll('.geo-item');
  const geoDropdownLabel = document.getElementById('geoDropdownLabel');
  const geoInput = document.getElementById('geoInput');
  
  if (geoItems.length > 0 && geoDropdownLabel && geoInput) {
    geoItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        geoItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        geoDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        geoInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('geo', value); else url.searchParams.delete('geo');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('geoDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Status RK фильтр (dropdown с красивым дизайном)
  const statusRkItems = document.querySelectorAll('.status-rk-item');
  const statusRkDropdownLabel = document.getElementById('statusRkDropdownLabel');
  const statusRkInput = document.getElementById('statusRkInput');
  
  if (statusRkItems.length > 0 && statusRkDropdownLabel && statusRkInput) {
    statusRkItems.forEach(item => {
      item.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        const value = item.getAttribute('data-value');
        const labelText = item.querySelector('label span:first-child').textContent.trim();
        
        // Обновляем активный элемент
        statusRkItems.forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        
        // Обновляем метку
        statusRkDropdownLabel.textContent = labelText;
        
        // Обновляем скрытое поле
        statusRkInput.value = value;
        
        // Применяем фильтр
        const url = new URL(window.location);
        if (value) url.searchParams.set('status_rk', value); else url.searchParams.delete('status_rk');
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
        
        // Закрываем dropdown
        const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('statusRkDropdown'));
        if (dropdown) dropdown.hide();
      });
    });
  }
  
  // Пер-страница (селект)
  const perPageSelect = document.querySelector('select[name="per_page"]');
  if (perPageSelect) {
    perPageSelect.addEventListener('change', () => {
      const url = new URL(window.location);
      const v = parseInt(perPageSelect.value || '');
      if (!isNaN(v)) url.searchParams.set('per_page', String(v)); else url.searchParams.delete('per_page');
      url.searchParams.set('page', '1');
      history.replaceState(null, '', url.toString());
      selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
      debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
    });
  }
  // Чекбоксы доп. фильтров
  const boolFilters = ['has_email','has_two_fa','has_token','has_avatar','has_cover','has_password','full_filled'];
  boolFilters.forEach(name => {
    document.querySelectorAll(`input[type="checkbox"][name="${name}"]`).forEach(cb => {
      cb.addEventListener('change', () => {
        const url = new URL(window.location);
        if (cb.checked) url.searchParams.set(name, '1'); else url.searchParams.delete(name);
        url.searchParams.set('page', '1');
        history.replaceState(null, '', url.toString());
        selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
        debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
      });
    });
  });
  // Классическая фильтрация: для числовых диапазонов применяем при вводе (debounce)
  const pharmaFrom = document.getElementsByName('pharma_from')[0];
  const pharmaTo   = document.getElementsByName('pharma_to')[0];
  const applyPharma = debounce(() => {
    const url = new URL(window.location);
    const fromVal = pharmaFrom ? pharmaFrom.value.trim() : '';
    const toVal   = pharmaTo ? pharmaTo.value.trim() : '';
    if (fromVal !== '') url.searchParams.set('pharma_from', fromVal); else url.searchParams.delete('pharma_from');
    if (toVal   !== '') url.searchParams.set('pharma_to', toVal); else url.searchParams.delete('pharma_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (pharmaFrom) pharmaFrom.addEventListener('input', applyPharma);
  if (pharmaTo)   pharmaTo.addEventListener('input', applyPharma);

  const friendsFrom = document.getElementsByName('friends_from')[0];
  const friendsTo   = document.getElementsByName('friends_to')[0];
  const applyFriends = debounce(() => {
    const url = new URL(window.location);
    const fromVal = friendsFrom ? friendsFrom.value.trim() : '';
    const toVal   = friendsTo ? friendsTo.value.trim() : '';
    if (fromVal !== '') url.searchParams.set('friends_from', fromVal); else url.searchParams.delete('friends_from');
    if (toVal   !== '') url.searchParams.set('friends_to', toVal); else url.searchParams.delete('friends_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (friendsFrom) friendsFrom.addEventListener('input', applyFriends);
  if (friendsTo)   friendsTo.addEventListener('input', applyFriends);

  // Автоприменение диапазонов годов (year_created)
  const yearCreatedFromEl = document.getElementsByName('year_created_from')[0];
  const yearCreatedToEl   = document.getElementsByName('year_created_to')[0];
  
  const applyYear = debounce(() => {
    const url = new URL(window.location);
    const ycf = yearCreatedFromEl ? yearCreatedFromEl.value.trim() : '';
    const yct = yearCreatedToEl   ? yearCreatedToEl.value.trim()   : '';
    if (ycf) url.searchParams.set('year_created_from', ycf); else url.searchParams.delete('year_created_from');
    if (yct) url.searchParams.set('year_created_to',   yct); else url.searchParams.delete('year_created_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (yearCreatedFromEl) yearCreatedFromEl.addEventListener('input', applyYear);
  if (yearCreatedToEl)   yearCreatedToEl.addEventListener('input', applyYear);

  // Автоприменение диапазона Limit RK
  const limitRkFromEl = document.getElementsByName('limit_rk_from')[0];
  const limitRkToEl   = document.getElementsByName('limit_rk_to')[0];
  
  const applyLimitRk = debounce(() => {
    const url = new URL(window.location);
    const fromVal = limitRkFromEl ? limitRkFromEl.value.trim() : '';
    const toVal   = limitRkToEl ? limitRkToEl.value.trim() : '';
    if (fromVal !== '') url.searchParams.set('limit_rk_from', fromVal); else url.searchParams.delete('limit_rk_from');
    if (toVal   !== '') url.searchParams.set('limit_rk_to', toVal); else url.searchParams.delete('limit_rk_to');
    url.searchParams.set('page', '1');
    history.replaceState(null, '', url.toString());
    selectedAllFiltered = false; selectedIds.clear(); updateSelectedCount();
    debouncedRefreshDashboardData(); // Используем дебаунсированную версию для фильтров
  }, 400);
  if (limitRkFromEl) limitRkFromEl.addEventListener('input', applyLimitRk);
  if (limitRkToEl)   limitRkToEl.addEventListener('input', applyLimitRk);
});

document.addEventListener('click', function(e) {
  const a = e.target && e.target.closest('.pagination a.page-link');
  if (!a) return;
  e.preventDefault();
  let targetPage = '1';
  const href = a.getAttribute('href') || '';
  try {
    const u = new URL(href, window.location.href);
    targetPage = u.searchParams.get('page') || '1';
  } catch (_) { /* fallback */ }
  const cur = new URL(window.location);
  cur.searchParams.set('page', String(targetPage));
  history.replaceState(null, '', cur.toString());
  // НЕ очищаем selectedIds при пагинации - выбранные строки должны сохраняться между страницами
  // selectedAllFiltered сбрасываем, так как это относится к текущему фильтру
  selectedAllFiltered = false;
  updateSelectedCount();
  // Обновляем без перезагрузки
  refreshDashboardData();
  // Убрано автоскролл вверх по запросу
});

function getActionsWidth() {
  const td = document.querySelector('#accountsTable tbody tr td.sticky-actions');
  if (td) return td.offsetWidth;
  const th = document.querySelector('#accountsTable thead th[data-col="actions"]');
  return th ? th.offsetWidth : 0;
}

/**
 * Функция синхронизации ширины заголовков (обертка над TableLayoutManager)
 * Использует новый менеджер верстки для правильного расчета размеров
 */
// Простая функция синхронизации ширины заголовков
function syncHeaderWidths() {
  if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
    window.tableLayoutManager.refresh();
  }
}

// Оптимизированный обработчик resize с троттлингом
let resizeTimeout2;
const optimizedResizeHandler2 = () => {
  if (resizeTimeout2) return;
  resizeTimeout2 = requestAnimationFrame(() => {
    // Обновляем sticky scrollbar после загрузки данных
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
    syncHeaderWidths();
    // Пересчитываем плотность таблицы при изменении размера окна
    adjustTableDensity();
    resizeTimeout2 = null;
  });
};
window.addEventListener('resize', optimizedResizeHandler2, { passive: true });
window.addEventListener('load', () => { 
  adjustForMobile(); 
  
  // Пересчитываем верстку таблицы при загрузке страницы
  const initTableLayout = () => {
    // Используем новый менеджер верстки, если он доступен
    if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
      window.tableLayoutManager.refresh();
    } else {
      // Fallback на старую функцию
      syncHeaderWidths();
    }
    
    adjustTableDensity();
    
    if (typeof window.updateStickyScrollbar === 'function') {
      window.updateStickyScrollbar();
    }
    
    // Финальная проверка через небольшую задержку
    setTimeout(() => {
      if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
        window.tableLayoutManager.refresh();
      } else {
        syncHeaderWidths();
      }
      if (typeof window.updateStickyScrollbar === 'function') {
        window.updateStickyScrollbar();
      }
    }, 200);
  };
  
  // Запускаем инициализацию с небольшой задержкой для гарантии полного рендера
  setTimeout(initTableLayout, 150);
  
  // Дополнительный пересчет верстки после полной загрузки страницы
  // Это особенно важно после сортировки, когда страница перезагружается
  window.addEventListener('load', () => {
    setTimeout(() => {
      if (window.tableLayoutManager && typeof window.tableLayoutManager.refresh === 'function') {
        window.tableLayoutManager.refresh();
      } else {
        syncHeaderWidths();
        adjustTableDensity();
      }
    }, 300);
  });
  
  // Обработка сортировки теперь выполняется модулем table-sorting.js
  // Старый обработчик удален
});

// Обработчик редактирования полей через кнопку
document.addEventListener('click', function(e) {
  const editBtn = e.target.closest('.field-edit-btn');
  if (!editBtn) return;
  
  const wrap = editBtn.closest('.editable-field-wrap');
  if (!wrap) return;
  
  const rowId = parseInt(wrap.getAttribute('data-row-id'));
  const field = wrap.getAttribute('data-field');
  const fieldType = wrap.getAttribute('data-field-type'); // Получаем тип поля
  
  // Получаем текущее значение
  const fieldValue = wrap.querySelector('.field-value');
  let oldVal = '';
  
  // Для числовых полей извлекаем значение по-другому
  if (fieldType === 'numeric') {
    // Для числовых полей берем textContent и очищаем от форматирования
    const textContent = fieldValue.textContent.trim();
    if (textContent === '—' || textContent === '') {
      oldVal = '';
    } else {
      // Извлекаем только число, убирая все нечисловые символы (кроме точки и минуса)
      oldVal = textContent.replace(/[^\d.-]/g, '');
    }
  } else {
    // Для текстовых полей используем стандартную логику
    oldVal = fieldValue.textContent.trim();
    
    // Если поле пустое (показывается "—"), используем пустую строку
    if (oldVal === '—') {
      oldVal = '';
    }
  }
  
  // Для полей с data-full (token, cookies и т.д.) берём полное значение
  const fullValue = fieldValue.getAttribute('data-full');
  if (fullValue !== null) {
    oldVal = fullValue;
  }
  
  // Для ссылок берём href
  if (fieldValue.tagName === 'A') {
    if (field === 'email') {
      // Для email убираем mailto:
      oldVal = fieldValue.href.replace('mailto:', '');
    } else if (field === 'social_url') {
      // Для social_url берём полный URL из href (с протоколом!)
      // Убираем только origin если это относительная ссылка
      oldVal = fieldValue.href;
      if (oldVal.startsWith(window.location.origin)) {
        oldVal = oldVal.substring(window.location.origin.length);
      }
      // Если URL не начинается с http/https, берем из textContent без иконки
      if (!oldVal.match(/^https?:\/\//)) {
        const textWithoutIcon = fieldValue.textContent.replace(/^\s*\S+\s*/, '').trim();
        oldVal = textWithoutIcon || fieldValue.textContent.trim();
      }
    } else {
      // Для остальных ссылок берём текст
      oldVal = fieldValue.textContent.trim();
    }
  }
  
  // Определяем, нужен ли textarea для длинных полей
  const longFields = ['token', 'cookies', 'user_agent', 'extra_info_1', 'extra_info_2', 'extra_info_3', 'extra_info_4'];
  const isLongField = longFields.includes(field);
  
  // Создаём элемент ввода
  const input = document.createElement(isLongField ? 'textarea' : 'input');
  
  if (!isLongField) {
    // Для числовых полей используем type='number'
    if (fieldType === 'numeric') {
      input.type = 'number';
      input.step = 'any'; // Разрешаем десятичные числа
    } else {
      input.type = 'text';
    }
  } else {
    input.rows = 4;
    input.style.resize = 'vertical';
    input.style.minWidth = '300px';
  }
  
  input.className = 'form-control form-control-sm';
  // Устанавливаем значение после создания input
  input.value = oldVal || '';
  
  // ВАЖНО: Блокируем виртуализацию перед созданием input
  const tableModule = window.tableModule;
  const virtualization = tableModule && tableModule.virtualScroller;
  let virtualizationWasEnabled = false;
  if (virtualization && virtualization.enabled) {
    virtualizationWasEnabled = true;
    virtualization.disable(true); // Временно отключаем виртуализацию
  }
  
  // Создаем кнопки сохранения и отмены
  const saveBtn = document.createElement('button');
  saveBtn.className = 'btn btn-sm btn-success ms-1';
  saveBtn.innerHTML = '<i class="fas fa-check"></i>';
  saveBtn.title = 'Сохранить';
  
  const cancelBtn = document.createElement('button');
  cancelBtn.className = 'btn btn-sm btn-secondary ms-1';
  cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
  cancelBtn.title = 'Отмена';
  
  // Сохраняем оригинальное содержимое И оригинальное значение ДО замены
  const originalContent = wrap.innerHTML;
  const originalValue = oldVal; // Сохраняем значение отдельно для восстановления при ошибках
  
  // Добавляем флаг редактирования для защиты от виртуализации
  wrap.setAttribute('data-editing', 'true');
  const row = wrap.closest('tr[data-id]');
  if (row) {
    row.setAttribute('data-editing', 'true');
  }
  // Также устанавливаем флаг на ячейку td для CSS стилей
  const cell = wrap.closest('td');
  if (cell) {
    cell.setAttribute('data-editing', 'true');
  }
  
  // Заменяем содержимое на поля редактирования
  wrap.innerHTML = '';
  wrap.appendChild(input);
  wrap.appendChild(saveBtn);
  wrap.appendChild(cancelBtn);
  
  // Убеждаемся, что input видим и имеет правильные стили
  input.style.display = 'block';
  input.style.visibility = 'visible';
  input.style.opacity = '1';
  input.style.width = 'auto';
  input.style.minWidth = '120px';
  input.style.flex = '1';
  
  // Устанавливаем фокус и выделяем текст
  // Используем setTimeout для гарантии, что DOM обновился
  setTimeout(() => {
    input.focus();
    // Для числовых полей выделяем весь текст, если он есть
    if (oldVal && oldVal !== '') {
      input.select();
    } else {
      // Если значение пустое, просто устанавливаем курсор
      if (input.setSelectionRange) {
        input.setSelectionRange(0, 0);
      }
    }
  }, 0);
  
  // Блокируем скролл во время редактирования для защиты от проблем с виртуализацией
  const scrollContainer = document.getElementById('tableWrap');
  let scrollBlocked = false;
  let savedScrollTop = 0;
  
  if (scrollContainer) {
    scrollBlocked = true;
    savedScrollTop = scrollContainer.scrollTop;
    scrollContainer.style.overflow = 'hidden';
  }
  
  // Функция разблокировки скролла и виртуализации
  const unlockScroll = () => {
    if (scrollBlocked && scrollContainer) {
      scrollContainer.style.overflow = '';
      scrollContainer.scrollTop = savedScrollTop; // Восстанавливаем позицию
      scrollBlocked = false;
    }
    // Удаляем флаг редактирования
    wrap.removeAttribute('data-editing');
    if (row) {
      row.removeAttribute('data-editing');
    }
    // Также удаляем флаг с ячейки td
    const cell = wrap.closest('td');
    if (cell) {
      cell.removeAttribute('data-editing');
    }
    // Восстанавливаем виртуализацию после завершения редактирования
    if (virtualizationWasEnabled && virtualization && tableModule) {
      setTimeout(() => {
        // Проверяем, что редактирование действительно завершено
        const stillEditing = tableModule.tbody && tableModule.tbody.querySelector('tr[data-id][data-editing="true"]');
        if (!stillEditing && tableModule.tbody) {
          const rows = Array.from(tableModule.tbody.querySelectorAll('tr[data-id]'));
          if (rows.length > (virtualization.options.threshold || 80)) {
            virtualization.enable(rows);
          }
        }
      }, 100);
    }
  };
  
  // Функция восстановления оригинального состояния
  const restoreOriginal = () => {
    unlockScroll();
    wrap.innerHTML = originalContent;
    // Восстанавливаем значение в DOM, если оно изменилось
    const restoredFieldValue = wrap.querySelector('.field-value');
    if (restoredFieldValue && originalValue !== oldVal) {
      // Если значение было изменено, но нужно восстановить старое
      if (originalValue === '') {
        restoredFieldValue.textContent = '—';
        restoredFieldValue.classList.add('text-muted');
      } else {
        restoredFieldValue.textContent = originalValue;
      }
    }
  };
  
  // Обработчик сохранения
  const save = async () => {
    let newVal = isLongField ? input.value : input.value.trim();
    
    // Валидация типа на фронтенде
    const fieldType = wrap.getAttribute('data-field-type');
    if (fieldType === 'numeric') {
      // Для числовых полей проверяем, что значение является числом
      if (newVal !== '' && newVal !== null) {
        const trimmed = newVal.trim();
        if (trimmed === '') {
          newVal = ''; // Пустое значение разрешено (будет обработано на бэкенде)
        } else if (isNaN(trimmed) || trimmed === '') {
          showToast('Поле должно содержать число', 'error');
          input.focus();
          input.select();
          return; // Прерываем сохранение
        }
        // Можно также убрать пробелы и лишние символы
        newVal = trimmed;
      }
    }
    
    try {
      const res = await fetch('update_field.php', {
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ id: rowId, field: field, value: newVal, csrf: '<?= e($csrfToken) ?>' })
      });
      
      // Пытаемся прочитать JSON из ответа (даже если статус не OK)
      let json;
      try {
        const text = await res.text();
        json = text ? JSON.parse(text) : { success: false, error: 'Empty response' };
      } catch (parseErr) {
        // Если не удалось распарсить JSON, создаем объект с ошибкой
        json = { success: false, error: `HTTP error! status: ${res.status}` };
      }
      
      // Проверяем статус ответа
      if (!res.ok) {
        throw new Error(json.error || `HTTP error! status: ${res.status}`);
      }
      
      if (!json.success) {
        throw new Error(json.error || 'update failed');
      }
      
      // Восстанавливаем оригинальную структуру и обновляем значение
      wrap.innerHTML = originalContent;
      const updatedFieldValue = wrap.querySelector('.field-value');
      
      if (newVal === '' || newVal === null) {
        updatedFieldValue.textContent = '—';
        updatedFieldValue.classList.add('text-muted');
      } else if (field === 'email') {
        updatedFieldValue.href = 'mailto:' + newVal;
        updatedFieldValue.textContent = newVal;
      } else if (field === 'social_url') {
        // Для social_url всегда пересоздаем структуру
        if (/^https?:\/\//i.test(newVal)) {
          // Если есть протокол - создаем ссылку
          updatedFieldValue.href = newVal;
          updatedFieldValue.target = '_blank';
          updatedFieldValue.rel = 'noopener';
          updatedFieldValue.className = 'text-decoration-none field-value';
          updatedFieldValue.innerHTML = `<i class="fas fa-external-link-alt me-2"></i>${newVal}`;
        } else if (newVal !== '' && newVal !== null) {
          // Если нет протокола но есть значение - добавляем http://
          const urlWithProtocol = 'http://' + newVal;
          updatedFieldValue.href = urlWithProtocol;
          updatedFieldValue.target = '_blank';
          updatedFieldValue.rel = 'noopener';
          updatedFieldValue.className = 'text-decoration-none field-value';
          updatedFieldValue.innerHTML = `<i class="fas fa-external-link-alt me-2"></i>${urlWithProtocol}`;
        } else {
          // Если пустое - показываем прочерк
          updatedFieldValue.textContent = '—';
          updatedFieldValue.classList.add('text-muted');
        }
      } else if (isLongField) {
        const clip = newVal.substring(0, 100) + (newVal.length > 100 ? '…' : '');
        updatedFieldValue.setAttribute('data-full', newVal);
        updatedFieldValue.textContent = clip;
      } else if (field === 'status') {
        updatedFieldValue.textContent = newVal;
        // Обновляем класс badge
        let statusClass = 'badge-default';
        let statusDisplay = newVal;
        const statusValue = String(newVal).toLowerCase();
        
        // Специальная обработка для пустых статусов
        if (newVal === null || newVal === '' || newVal === undefined) {
          statusClass = 'badge-empty-status';
          statusDisplay = 'Пустой статус';
        } else if (statusValue.includes('new')) {
          statusClass = 'badge-new';
        } else if (statusValue.includes('add_selphi_true')) {
          statusClass = 'badge-add_selphi_true';
        } else if (statusValue.includes('error')) {
          statusClass = 'badge-error_login';
        }
        
        updatedFieldValue.className = 'badge ' + statusClass + ' field-value';
        updatedFieldValue.textContent = statusDisplay;
      } else {
        updatedFieldValue.textContent = newVal;
      }
      
      unlockScroll(); // Разблокируем скролл при успешном сохранении
      showToast('Поле успешно обновлено', 'success');
    } catch (err) {
      // Восстанавливаем оригинальное состояние при любой ошибке (сеть, сервер, парсинг)
      restoreOriginal();
      
      // Показываем понятное сообщение об ошибке
      let errorMessage = 'Ошибка сохранения';
      if (err instanceof TypeError && err.message.includes('fetch')) {
        errorMessage = 'Ошибка сети. Проверьте подключение к интернету.';
      } else if (err.message) {
        errorMessage = 'Ошибка сохранения: ' + err.message;
      }
      
      showToast(errorMessage, 'error');
      console.error('Field update error:', err);
    }
  };
  
  // Обработчик отмены
  const cancel = () => {
    unlockScroll();
    wrap.innerHTML = originalContent;
  };
  
  saveBtn.addEventListener('click', save);
  cancelBtn.addEventListener('click', cancel);
  
  // Сохранение по Enter / Ctrl+Enter
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') {
      if (isLongField) {
        if (ev.ctrlKey) {
          ev.preventDefault();
          save();
        }
      } else {
        if (!ev.shiftKey) {
          ev.preventDefault();
          save();
        }
      }
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      cancel();
    }
  });
});

// ===== Централизованная обработка чекбоксов через делегирование событий =====
// Инициализация состояния чекбоксов при загрузке страницы
// (функция вызывается в DOMContentLoaded, здесь только регистрируем обработчики)
// Примечание: функция getAllRowIdsOnPage определена выше, перед initCheckboxStates

// Делегирование событий для чекбоксов (обрабатываем клики на уровне документа)
document.addEventListener('change', function(e) {
  // Обработка чекбокса "Выбрать все"
  if (e.target && e.target.id === 'selectAll') {
    selectedAllFiltered = false;
    const isChecked = e.target.checked;
    
    // Получаем все ID строк на странице (с учетом виртуализации)
    const allRowIds = getAllRowIdsOnPage();
    
    console.log(`[SELECT ALL] Выделение всех строк на странице: ${allRowIds.length} строк, checked: ${isChecked}`);
    
    // Выделяем все строки по их ID
    allRowIds.forEach(rowId => {
      toggleRowSelection(rowId, isChecked);
      
      // Обновляем чекбокс, если он видим в DOM
      const checkbox = document.querySelector(`.row-checkbox[value="${rowId}"]`);
      if (checkbox) {
        checkbox.checked = isChecked;
        const row = checkbox.closest('tr[data-id]');
        if (row) {
          updateRowSelectedClass(row, isChecked);
        }
      }
    });
    
    // Обновляем счетчик и состояние всех кнопок (включая "Сбросить все")
    updateSelectedCount();
    updateSelectedOnPageCounter();
    return;
  }
  
  // Обработка индивидуальных чекбоксов строк
  if (e.target && e.target.classList.contains('row-checkbox')) {
    const rowId = parseInt(e.target.value);
    if (!Number.isFinite(rowId)) {
      console.warn('Invalid row ID:', e.target.value);
      return;
    }
    
    selectedAllFiltered = false;
    toggleRowSelection(rowId, e.target.checked);
    
    const row = e.target.closest('tr[data-id]');
    if (row) {
      updateRowSelectedClass(row, e.target.checked);
    }
    
    // Обновляем счетчик и состояние всех кнопок (включая "Сбросить все")
    updateSelectedCount();
    updateSelectedOnPageCounter();
    
    // Обновляем состояние чекбокса "Выбрать все"
    // Используем getAllRowIdsOnPage для правильного подсчета с учетом виртуализации
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
      const allRowIds = getAllRowIdsOnPage();
      const selectedCount = allRowIds.filter(id => selectedIds.has(id)).length;
      selectAllCheckbox.checked = allRowIds.length > 0 && selectedCount === allRowIds.length;
    }
    return;
  }
});

// Обработка клика по строке таблицы (для выбора строки кликом в любом месте)
document.addEventListener('click', function(e) {
  // Находим строку таблицы
  const row = e.target.closest('tr[data-id]');
  if (!row) return;
  
  // Исключаем клики по самому чекбоксу (его обрабатывает событие change отдельно)
  if (e.target.classList && e.target.classList.contains('row-checkbox')) {
    return;
  }
  
  // Исключаем клики по интерактивным элементам и их дочерним элементам:
  // - ссылки (a)
  // - кнопки (button, .btn)
  // - кнопки редактирования (.field-edit-btn)
  // - кнопки копирования (.copy-btn)
  // - элементы внутри pw-mask (для паролей)
  // - все input, select, textarea
  // Проверяем как сам элемент, так и его родителей
  const interactiveSelectors = 'a, button, .row-checkbox, .field-edit-btn, .copy-btn, .btn, .pw-mask, input, select, textarea, .pw-toggle, .pw-edit';
  
  // Проверяем, не является ли сам кликнутый элемент интерактивным
  const isDirectlyInteractive = e.target.matches && e.target.matches(interactiveSelectors);
  
  // Проверяем, не находится ли кликнутый элемент внутри интерактивного элемента
  const isInsideInteractive = e.target.closest(interactiveSelectors);
  
  // Также проверяем иконки и SVG, но только если они внутри кнопок или ссылок
  const isIconInButton = (e.target.tagName === 'I' || e.target.tagName === 'SVG' || e.target.closest('i, svg')) && 
                         e.target.closest('button, a, .btn');
  
  if (isDirectlyInteractive || isInsideInteractive || isIconInButton) {
    // Если клик был по интерактивному элементу, не переключаем чекбокс
    return;
  }
  
  // Находим чекбокс в этой строке
  const checkbox = row.querySelector('.row-checkbox');
  if (!checkbox) return;
  
  // Предотвращаем двойное срабатывание - проверяем, не был ли это клик по чекбоксу
  if (e.target === checkbox || checkbox.contains(e.target)) {
    return;
  }
  
  // Переключаем состояние чекбокса
  const wasChecked = checkbox.checked;
  checkbox.checked = !wasChecked;
  
  // Обновляем состояние напрямую, без dispatchEvent, чтобы избежать двойного срабатывания
  selectedAllFiltered = false;
  toggleRowSelection(parseInt(checkbox.value), checkbox.checked);
  updateRowSelectedClass(row, checkbox.checked);
  
  // Обновляем счетчик и состояние всех кнопок (включая "Сбросить все")
  updateSelectedCount();
  
  // Обновляем состояние чекбокса "Выбрать все"
  const selectAllCheckbox = document.getElementById('selectAll');
  if (selectAllCheckbox) {
    const allCheckboxes = document.querySelectorAll('.row-checkbox');
    const checkedCheckboxes = document.querySelectorAll('.row-checkbox:checked');
    selectAllCheckbox.checked = allCheckboxes.length > 0 && allCheckboxes.length === checkedCheckboxes.length;
  }
});

// Bulk edit: open modal
const bulkFieldSelect = document.getElementById('bulkFieldSelect');
const bulkGlobalWarning = document.getElementById('bulkGlobalWarning');
const bulkGlobalFieldLabel = bulkGlobalWarning ? bulkGlobalWarning.querySelector('.bulk-global-field') : null;
const bulkGlobalCountLabel = bulkGlobalWarning ? bulkGlobalWarning.querySelector('.bulk-global-count') : null;
const bulkGlobalConfirm = document.getElementById('bulkGlobalConfirm');
const bulkFieldModalEl = document.getElementById('bulkFieldModal');
const bulkEditBtn = document.getElementById('bulkEditFieldBtn');
const applyBulkFieldBtn = document.getElementById('applyBulkFieldBtn');

function shouldWarnGlobalBulk() {
  return selectedAllFiltered && ACTIVE_FILTERS_COUNT === 0;
}

function updateBulkWarningState() {
  if (!bulkGlobalWarning) return;
  const needWarning = shouldWarnGlobalBulk();
  if (!needWarning) {
    bulkGlobalWarning.style.display = 'none';
    if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
    if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = false;
    return;
  }
  bulkGlobalWarning.style.display = '';
  if (bulkGlobalFieldLabel && bulkFieldSelect) {
    const optionText = bulkFieldSelect.options[bulkFieldSelect.selectedIndex]?.textContent?.trim() || 'поле';
    bulkGlobalFieldLabel.textContent = optionText;
  }
  if (bulkGlobalCountLabel) {
    bulkGlobalCountLabel.textContent = filteredTotalLive.toLocaleString('ru-RU');
  }
  if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
  if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = true;
}

if (bulkEditBtn && bulkFieldModalEl) {
  bulkEditBtn.addEventListener('click', function() {
    if (!selectedAllFiltered && selectedIds.size === 0) return;
    const modal = bootstrap.Modal.getOrCreateInstance(bulkFieldModalEl);
    // Сбрасываем введённое значение перед открытием
    const input = document.getElementById('bulkFieldValue');
    if (input) input.value = '';
    updateBulkWarningState();
    modal.show();
  });
}

if (bulkGlobalConfirm) {
  bulkGlobalConfirm.addEventListener('change', () => {
    if (!applyBulkFieldBtn) return;
    if (!shouldWarnGlobalBulk()) {
      applyBulkFieldBtn.disabled = false;
      return;
    }
    applyBulkFieldBtn.disabled = !bulkGlobalConfirm.checked;
  });
}

if (bulkFieldModalEl) {
  bulkFieldModalEl.addEventListener('hidden.bs.modal', () => {
    if (bulkGlobalConfirm) bulkGlobalConfirm.checked = false;
    if (applyBulkFieldBtn) applyBulkFieldBtn.disabled = false;
  });
}

if (bulkFieldSelect) {
  bulkFieldSelect.addEventListener('change', () => {
    if (shouldWarnGlobalBulk()) {
      updateBulkWarningState();
    }
  });
}

// Универсальная кнопка "Сбросить все" - очищает выбранные строки и/или фильтры
const clearAllSelectedBtn = document.getElementById('clearAllSelectedBtn');
if (clearAllSelectedBtn) {
  clearAllSelectedBtn.addEventListener('click', function() {
    const hasSelection = selectedAllFiltered || selectedIds.size > 0;
    const hasActiveFilters = document.querySelectorAll('.filter-chip').length > 0;
    
    // Если есть и выбранные строки, и фильтры - сбрасываем оба
    // Если есть только фильтры - сбрасываем фильтры (перезагрузка страницы)
    // Если есть только строки - сбрасываем строки (без перезагрузки)
    
    if (hasActiveFilters) {
      // Если есть фильтры, всегда сбрасываем их (это требует перезагрузки страницы)
      // Также сбрасываем строки перед перезагрузкой, если они были выбраны
      if (hasSelection) {
        selectedIds.clear();
        selectedAllFiltered = false;
        saveSelectedIds();
      }
      // Перенаправляем на страницу без параметров фильтров
      const baseUrl = window.location.pathname;
      window.location.href = baseUrl;
      return; // Прерываем выполнение, так как происходит перезагрузка страницы
    } else if (hasSelection) {
      // Если есть только выбранные строки - сбрасываем их без перезагрузки
      // Очищаем все выбранные ID
      selectedIds.clear();
      selectedAllFiltered = false;
      
      // Снимаем галочки со всех чекбоксов
      document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.checked = false;
        // Убираем визуальное выделение строки
        const row = cb.closest('tr[data-id]');
        if (row) {
          updateRowSelectedClass(row, false);
        }
      });
      
      // Сбрасываем чекбокс "Выбрать все"
      const selectAllCheckbox = document.getElementById('selectAll');
      if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
      }
      
      // Сохраняем изменения
      saveSelectedIds();
      
      // Обновляем состояние всех кнопок
      const exportBtns = document.querySelectorAll('#exportSelectedCsv, #exportSelectedTxt, #deleteSelected, #changeStatusSelected, #bulkEditFieldBtn');
      exportBtns.forEach(btn => btn.disabled = true);
      
      // Обновляем счетчик и состояние кнопок
      updateSelectedCount();
    }
  });
}

// ===== Массовый перенос аккаунтов (V3.0) =====
const transferBtn = document.getElementById('transferAccountsBtn');
if (transferBtn) {
  transferBtn.addEventListener('click', function() {
    // Открываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('transferAccountsModal'));
    modal.show();
  });
}

const applyTransferBtn = document.getElementById('applyTransferBtn');
if (applyTransferBtn) {
  applyTransferBtn.addEventListener('click', async function() {
    // Получаем значения из формы
    const text = (document.getElementById('transferText')?.value || '').trim();
    const statusSelect = (document.getElementById('transferStatusSelect')?.value || '').trim();
    const statusCustom = (document.getElementById('transferStatusCustom')?.value || '').trim();
    const status = statusCustom || statusSelect;
    const enableLike = document.getElementById('transferEnableLike')?.checked ?? false;
    
    // Валидация полей
    if (!text) { 
      showToast('Вставьте текст с ID аккаунтов', 'error'); 
      return; 
    }
    
    if (!status) { 
      showToast('Укажите новый статус', 'error'); 
      return; 
    }
    
    // Проверка размера перед отправкой
    const lines = text.split('\n').filter(l => l.trim() !== '');
    const sizeInBytes = new Blob([text]).size;
    const maxSize = 20 * 1024 * 1024; // 20MB
    const maxLines = 50000;
    const recommendedLines = 2000;
    
    if (sizeInBytes > maxSize) {
      showToast(`⚠️ Слишком большой текст (${(sizeInBytes/1024/1024).toFixed(1)}MB). Максимум 20MB`, 'error');
      return;
    }
    
    if (lines.length > maxLines) {
      showToast(`⚠️ Слишком много строк (${lines.length.toLocaleString()}). Максимум ${maxLines.toLocaleString()}`, 'error');
      return;
    }
    
    // Предупреждение для больших объёмов
    if (lines.length > recommendedLines) {
      const confirmMsg = `⚠️ Вы вставили ${lines.length.toLocaleString()} строк.\n\n` +
        `Рекомендуется обрабатывать не более ${recommendedLines.toLocaleString()} строк за раз.\n` +
        `При большом объёме обработка может занять 30-60 секунд.\n\n` +
        `Продолжить?`;
      
      if (!confirm(confirmMsg)) {
        return;
      }
    }
    
    try {
      // Показываем информативный индикатор загрузки
      if (typeof showPageLoader === 'function') {
        showPageLoader();
      }
      
      // Добавляем информационное сообщение для больших объемов
      const loadingInfoEl = document.createElement('div');
      loadingInfoEl.id = 'massTransferLoadingInfo';
      loadingInfoEl.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10001;background:#fff;padding:30px;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.3);text-align:center;min-width:350px;';
      loadingInfoEl.innerHTML = `
        <div style="font-size:48px;margin-bottom:15px;">⏳</div>
        <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:10px;">Обработка массового переноса</div>
        <div style="font-size:14px;color:#666;margin-bottom:15px;">Обрабатывается ${lines.length.toLocaleString()} строк...</div>
        <div style="font-size:12px;color:#999;">Пожалуйста, подождите. Это может занять некоторое время.</div>
        <div id="transferTimer" style="font-size:13px;color:#0d6efd;margin-top:15px;font-weight:500;">Прошло: 0 сек</div>
      `;
      document.body.appendChild(loadingInfoEl);
      
      // Запускаем таймер для отображения времени
      const startTime = Date.now();
      const timerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        const timerEl = document.getElementById('transferTimer');
        if (timerEl) {
          timerEl.textContent = `Прошло: ${elapsed} сек`;
        }
      }, 1000);
      
      // Формируем тело запроса
      const body = { 
        text, 
        status, 
        csrf: '<?= e($csrfToken) ?>',
        options: {
          enable_exact: true,
          enable_numeric: true,
          enable_like: enableLike
        }
      };
      
      // Отправляем запрос на новый API endpoint с увеличенным таймаутом
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 300000); // 5 минут таймаут
      
      const res = await fetch('mass_transfer.php', { 
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }, 
        body: JSON.stringify(body),
        signal: controller.signal
      });
      
      clearTimeout(timeoutId);
      clearInterval(timerInterval);
      
      console.log('📥 MASS TRANSFER: Ответ получен', {
        status: res.status,
        statusText: res.statusText,
        ok: res.ok,
        contentType: res.headers.get('content-type')
      });
      
      if (!res.ok) {
        // Пытаемся прочитать детали ошибки из JSON ответа
        let errorMessage = `HTTP ${res.status}: ${res.statusText}`;
        const contentType = res.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
          try {
            const errorData = await res.json();
            console.error('❌ MASS TRANSFER: Ошибка (JSON):', errorData);
            errorMessage = errorData.error || errorMessage;
          } catch (e) {
            console.error('❌ MASS TRANSFER: Ошибка парсинга JSON ошибки:', e);
          }
        } else {
          const errorText = await res.text().catch(() => '');
          console.error('❌ MASS TRANSFER: Ошибка (текст):', errorText.substring(0, 500));
          errorMessage = errorText || errorMessage;
        }
        throw new Error(errorMessage);
      }
      
      const contentType = res.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        const textResponse = await res.text().catch(() => '');
        console.error('❌ MASS TRANSFER: Ответ не JSON:', textResponse.substring(0, 500));
        throw new Error('Сервер вернул некорректный ответ. Ожидается JSON.');
      }
      
      const json = await res.json();
      console.log('✅ MASS TRANSFER: JSON получен', json);
      
      if (!json.success) {
        console.error('❌ MASS TRANSFER: Импорт не успешен', json);
        throw new Error(json.error || 'Неизвестная ошибка');
      }
      
      // Выводим детальную статистику в консоль
      console.log('Обновлено записей:', json.affected);
      console.log('Статистика:');
      console.table({
        'Распознано токенов (ID аккаунтов)': json.statistics?.parsed_tokens || 0,
        'Распознано числовых ID': json.statistics?.parsed_numeric || 0,
        'Всего строк обработано': json.statistics?.total_lines || 0,
        'Нераспознанных строк': json.statistics?.unparsed_lines || 0,
        'Найдено по id_soc_account (точно)': json.statistics?.matched_exact_id_soc || 0,
        'Найдено по social_url (LIKE)': json.statistics?.matched_like_url || 0,
        'Найдено по cookies (LIKE)': json.statistics?.matched_like_cookies || 0,
        'Всего найдено': json.statistics?.total_found || 0
      });
      console.log('Новый статус:', json.status);
      console.groupEnd();
      
      // Показываем успешное уведомление
      const stats = json.statistics || {};
      const message = `✅ Успешно обновлено: ${json.affected} записей\n` +
        `📊 Найдено: ${stats.total_found || 0} из ${(stats.parsed_tokens || 0) + (stats.parsed_numeric || 0)} распознанных ID`;
      
      showToast(message, 'success');
      
      // Очищаем форму
      document.getElementById('transferText').value = '';
      document.getElementById('transferStatusSelect').value = '';
      document.getElementById('transferStatusCustom').value = '';
      document.getElementById('transferEnableLike').checked = false;
      
      // Закрываем модальное окно
      const modalEl = document.getElementById('transferAccountsModal');
      if (modalEl) {
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
      }
      
      // Перезагружаем страницу для обновления данных
      setTimeout(() => window.location.reload(), 1500);
      
    } catch (e) {
      console.error('❌ Ошибка массового переноса:', e);
      
      // Проверяем, не был ли это таймаут
      if (e.name === 'AbortError') {
        showToast('⏱️ Превышено время ожидания. Попробуйте разбить данные на меньшие части (по 1000 строк).', 'error');
      } else {
        showToast('Ошибка массового переноса: ' + e.message, 'error');
      }
    } finally {
      // Скрываем индикатор загрузки
      if (typeof hidePageLoader === 'function') hidePageLoader();
      
      // Удаляем информационное окно
      const loadingInfo = document.getElementById('massTransferLoadingInfo');
      if (loadingInfo) loadingInfo.remove();
      
      // Очищаем таймер если он ещё работает
      if (typeof timerInterval !== 'undefined') clearInterval(timerInterval);
      if (typeof timeoutId !== 'undefined') clearTimeout(timeoutId);
    }
  });
}

// Bulk edit: apply
if (applyBulkFieldBtn) {
  applyBulkFieldBtn.addEventListener('click', async function() {
    const field = (document.getElementById('bulkFieldSelect')?.value || '').trim();
    const value = (document.getElementById('bulkFieldValue')?.value || '').trim();
    if (!field) { showToast('Выберите поле', 'error'); return; }
    const scope = selectedAllFiltered 
      ? (ACTIVE_FILTERS_COUNT === 0 ? 'all' : 'filtered') 
      : 'selected';
    if (scope === 'all' && bulkGlobalConfirm && !bulkGlobalConfirm.checked) {
      showToast('Подтвердите глобальное изменение всех записей', 'error');
      return;
    }
    try {
      let body;
      if (selectedAllFiltered) {
        const params = new URLSearchParams(window.location.search);
        body = { field, value, ids: [], select: 'all', query: params.toString(), csrf: '<?= e($csrfToken) ?>', scope };
      } else {
        body = { field, value, ids: Array.from(selectedIds), csrf: '<?= e($csrfToken) ?>', scope };
      }
      const res = await fetch('bulk_update_field.php', { 
        method: 'POST', 
        headers: { 
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }, 
        body: JSON.stringify(body) 
      });
      if (!res.ok) {
        const text = await res.text();
        throw new Error(text || 'HTTP error');
      }
      const json = await res.json();
      if (!json.success) throw new Error(json.error || 'bulk update failed');
      showToast(`Изменено записей: ${json.affected ?? 0}`, 'success');
      const modal = bootstrap.Modal.getInstance(bulkFieldModalEl);
      if (modal) modal.hide();
      await refreshDashboardData();
    } catch (e) { 
      console.error('Bulk edit error:', e);
      showToast('Ошибка массового изменения: ' + (e.message || e), 'error'); 
    }
  });
}

(function(){
  document.addEventListener('DOMContentLoaded', function(){
    // Отключено для повышения плавности (убираем перерисовки на mousemove)
  });
})();

window.addEventListener('load', () => {
  // Инициализация счётчиков и первичная синхронизация
  updateSelectedOnPageCounter && updateSelectedOnPageCounter();
  
  // Скрываем прелоадер после загрузки страницы
  // Не удаляем элемент, а просто скрываем его
  const pageLoader = document.getElementById('pageLoader');
  if (pageLoader) {
    // Скрываем прелоадер немедленно, не ждем асинхронных операций
    pageLoader.classList.add('hidden');
    // НЕ удаляем элемент - он может понадобиться для обновлений таблицы
  }
});

// ===== Прилипающий горизонтальный скроллбар (новая реализация) =====
// Код перемещен в assets/js/sticky-scrollbar.js
</script>
<script src="assets/js/sticky-scrollbar.js?v=<?= time() ?>"></script>
<script src="assets/js/table-module.js?v=<?= time() ?>"></script>
<script src="assets/js/toast.js?v=<?= time() ?>"></script>
<script src="assets/js/filters-modern.js?v=<?= time() ?>"></script>
<script src="assets/js/dashboard.js?v=<?= time() ?>"></script>
<script src="assets/js/validation.js?v=<?= time() ?>"></script>
<script src="assets/js/quick-search.js?v=<?= time() ?>"></script>
<script src="assets/js/saved-filters.js?v=<?= time() ?>"></script>
<script src="assets/js/favorites.js?v=<?= time() ?>"></script>
</body>
</html>


