# Отчёт самопроверки (IMPROVEMENT_PLAN)

**Дата:** 2026-02-21

## Выполненные задачи

### Фаза 1
- [x] **Удалён test-csv-upload.php** — тестовый скрипт с хардкодным путём
- [x] **Деплой .optimization_applied** — документировано: `apply_indexes_safe.php` и `create_optimization_flag.php` создают флаг; при деплое выполнить один из них (см. QUERY_PERFORMANCE.md §8)
- [ ] Подключить бандл по умолчанию — отложено (бандл не включает все модули: dashboard-modals, dashboard-refresh и т.д.)
- [ ] first_cookie в инструкции — уже было сделано ранее

### Фаза 2 — Миграция API ✅
- [x] **favorites.js** — `api_favorites.php` → `/api/favorites` (GET, POST, DELETE)
- [x] **saved-filters.js** — `api_saved_filters.php` → `/api/filters` (GET, POST, DELETE)
- [x] **quick-search.js** — `api.php` → `/api/accounts/count` (с параметром q)
- [x] **init-script.php** — `api_user_settings.php` → `/api/settings`, `api_custom_card.php` → `/api/accounts/custom-card`, `api_register_status.php` → `/api/status/register`
- [x] **dashboard-stats.js** — `api_user_settings.php` → `/api/settings`
- [x] Во все POST-запросы добавлен CSRF-токен

### Фаза 4
- [x] **QUERY_PERFORMANCE.md** — уже содержит рекомендации для accountfactory (login как строка, батчи UPDATE)
- [x] **light=1** — уже используется в dashboard-refresh при частичных обновлениях

## Проверка работоспособности

| Компонент | Статус |
|-----------|--------|
| Избранное (favorites) | Миграция на /api/favorites |
| Сохранённые фильтры | Миграция на /api/filters |
| Быстрый поиск (Ctrl+K) | Миграция на /api/accounts/count |
| Скрытые карточки | Миграция на /api/settings (в init-script) |
| Кастомные карточки | Миграция на /api/settings, /api/accounts/custom-card, /api/status/register |
| Бандл пересобран | `php build_assets.php` — dashboard.min.js, dashboard.min.css |

## Не затронуто (legacy, не в критическом пути)

- **assets/js/features/hidden-cards.js**, **assets/js/features/custom-cards.js** — содержат старые URL, но **не подключаются** шаблоном dashboard; логика дублирована в init-script.php с новыми URL
- **Legacy api_*.php** — файлы оставлены для обратной совместимости; после проверки на проде можно удалить

## Рекомендации для следующего деплоя

1. Выполнить `php apply_indexes_safe.php` или открыть в браузере для создания `.optimization_applied`
2. Проверить: избранное, сохранённые фильтры, быстрый поиск, кастомные карточки, скрытие карточек
3. При ошибках 403 — проверить передачу CSRF-токена (window.DashboardConfig.csrfToken)
