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
  <link href="assets/css/csv-preview.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/modern-header.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/sticky-scrollbar.css?v=<?= time() ?>" rel="stylesheet">
  <!-- Единая тема для всех элементов -->
  <link href="assets/css/unified-theme.css?v=<?= time() ?>" rel="stylesheet">
  <!-- Новая таблица -->
  <link href="assets/css/table-core.css?v=<?= time() ?>" rel="stylesheet">
  <link href="assets/css/table-theme.css?v=<?= time() ?>" rel="stylesheet">
  
  <!-- Синхронное скрытие карточек ДО загрузки DOM (модуль) -->
  <script src="assets/js/modules/cards-hide-sync.js?v=<?= time() ?>"></script>

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
  <?php include __DIR__ . '/partials/dashboard/header.php'; ?>

  <!-- Основной контент -->
  <main class="container-fluid">
    <!-- Статистические карточки -->
    <?php include __DIR__ . '/partials/dashboard/stats-cards.php'; ?>
    


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
  <?php include __DIR__ . '/partials/dashboard/filters.php'; ?>
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
<?php include __DIR__ . '/partials/dashboard/config-script.php'; ?>
<?php include __DIR__ . '/partials/dashboard/init-script.php'; ?>
<!-- Core модули для оптимизации производительности -->
<script src="assets/js/core/logger.js?v=<?= time() ?>"></script>
<script src="assets/js/core/dom-cache.js?v=<?= time() ?>"></script>
<script src="assets/js/core/performance.js?v=<?= time() ?>"></script>
<script src="assets/js/modules/dashboard-refresh.js?v=<?= time() ?>"></script>
<!-- Модули дашборда -->
<script src="assets/js/modules/dashboard-selection.js?v=<?= time() ?>"></script>
<script src="assets/js/modules/dashboard-filters.js?v=<?= time() ?>"></script>
<script src="assets/js/modules/dashboard-stats.js?v=<?= time() ?>"></script>
<script src="assets/js/modules/dashboard-modals.js?v=<?= time() ?>"></script>
<script src="assets/js/modules/dashboard-main.js?v=<?= time() ?>"></script>
<!-- Основные модули -->
<script src="assets/js/sticky-scrollbar.js?v=<?= time() ?>"></script>
<script src="assets/js/table-module.js?v=<?= time() ?>"></script>
<script src="assets/js/toast.js?v=<?= time() ?>"></script>
<script src="assets/js/filters-modern.js?v=<?= time() ?>"></script>
<script src="assets/js/modules/dashboard-upload.js?v=<?= time() ?>"></script>
<script src="assets/js/dashboard.js?v=<?= time() ?>"></script>
<script src="assets/js/validation.js?v=<?= time() ?>"></script>
<script src="assets/js/quick-search.js?v=<?= time() ?>"></script>
<script src="assets/js/saved-filters.js?v=<?= time() ?>"></script>
<script src="assets/js/favorites.js?v=<?= time() ?>"></script>
</body>
</html>


