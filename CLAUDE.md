# CLAUDE.md

Этот файл содержит указания для Claude Code (claude.ai/code) при работе с кодом в этом репозитории.

## Проект

Accounts Dashboard — веб-приложение на чистом PHP 7.4+ / MySQL для управления записями аккаунтов (CRUD, фильтрация, массовые операции, импорт/экспорт, корзина с мягким удалением, избранное, история изменений и проверка валидности аккаунтов). Без фреймворка, без Composer, без npm, без сборки кроме одного PHP-скрипта. Текст интерфейса и комментарии в коде преимущественно на русском.

## Команды

```bash
# Сборка/минификация фронтенд-ассетов (склеивает упорядоченные списки CSS/JS в assets/build/)
php build_assets.php

# Проверка синтаксиса файла — единственный доступный «линтер»; используйте для проверки правок
php -l export.php

# OpenSpec (workflow на основе спецификаций — см. раздел «OpenSpec» ниже)
openspec list                 # активные предложения изменений
openspec spec list --long     # существующие capability/спецификации
openspec validate <id> --strict
```

**Нет автоматических тестов** и **нет локального линтера** (отсутствуют `composer.json`, `package.json`, `phpunit.xml`). Не ищите `npm test` / `vendor/`. Изменения в бэкенде проверяйте через `php -l` плюс ручной прогон эндпоинта в браузере; изменения во фронтенде — открыв страницу после `php build_assets.php`. На уровне PR `php -l` по всем PHP-файлам прогоняется автоматически (`.github/workflows/lint.yml`).

Локальный запуск требует доступного экземпляра MySQL, учётные данные которого вводятся на `login.php` (см. заметку о конфигурации БД ниже) — приложение не может стартовать только из CLI, потому что у него нет данных для подключения к БД, пока не создана сессия логина.

## Архитектура

### Две схемы обработки запросов

1. **Отдельные процедурные эндпоинты** в корне репозитория — `export.php`, `import_accounts.php`, `mass_transfer.php`, `login.php`, `update_field.php`, `bulk_update_field.php`, `delete.php`, `restore.php`, `view.php`, `admin_*.php` и т.д. Каждый подключает (`require`) `config.php`, затем `auth.php` и обрабатывает одну операцию. Новые эндпоинты, изменяющие данные, делаются по этому образцу.
2. **Единый REST-роутер** в `api/index.php` для всего под `/api/*` (`accounts`, `accounts/bulk`, `accounts/validate/*`, `favorites`, `filters`, `settings`, `status/register`). Использует `includes/ApiRouter.php`; `getPath()` отрезает префикс `/api`, поэтому запрос к `/api/accounts/count` попадает на маршрут `/accounts/count`. Маршруты регистрируются как замыкания с middleware авторизации + rate-limit.

### config.php — это bootstrap (а не просто конфиг)

`config.php` — единая точка входа, которую подключает каждая страница. Он: настраивает логирование ошибок в `php_errors.log`, выставляет security-заголовки (`ResponseHeaders::setSecurityHeaders()`), стартует сессию, открывает подключение к БД в глобальную `$mysqli`, автоматически создаёт таблицу `accounts`, если её нет, определяет константы путей (`PROJECT_ROOT`, `INCLUDES_DIR`, `TEMPLATES_DIR`, `ASSETS_DIR`), определяет `ASSETS_VERSION`, поднимает ini-лимиты на загрузку/память и подключает (`require_once`) весь сервисный слой из `includes/`. (Он вобрал в себя бывший `bootstrap.php`.)

### Учётные данные БД берутся из сессии логина, а не из .env

Хост/пользователь/пароль/имя базы аккаунтов хранятся **только** в `$_SESSION['db_config']`, заполняемом из формы `login.php`. `config.php` сознательно отказывается от `.env`/переменных окружения для БД аккаунтов. Если в сессии нет конфигурации БД: XHR-запросы получают `401` JSON `{redirect: 'login.php'}`, обычные запросы редиректятся на `login.php`. (Упоминание `.env`/`config.local.php` для настроек БД в README устарело — доверяйте `config.php`.)

### Доступ к базе данных

Используйте синглтон: `Database::getInstance()->getConnection()` возвращает общий `mysqli` (он переиспользует глобальную `$mysqli`, созданную в `config.php`). **Никогда не используйте `global $mysqli` в новом коде.** `Database::prepare($sql, $params, $cacheKey)` выполняет подготовленный запрос и кэширует результаты SELECT. Все запросы — подготовленные. Учтите, что `sql_mode` намеренно не включает `STRICT_TRANS_TABLES` (иначе функциональный индекс по `CAST(login AS UNSIGNED)` бросает «Data truncated» для строковых логинов); целостность обеспечивается подготовленными запросами + PHP-валидацией.

### Несколько таблиц аккаунтов + ленивое создание таблиц

Одна база может содержать несколько таблиц аккаунтов. `includes/TableResolver.php` выбирает активную таблицу из `?table=xxx` (с валидацией, исключая системные таблицы `account_history`/`account_favorites`/`saved_filters`/`user_settings`) и отдаёт её имя в глобальную `$tableName`. Сервисы создаются под неё: `new AccountsService($tableName)`. Вспомогательные таблицы создаются при первом обращении через `ensureAccountFavoritesTable()` / `ensureUserSettingsTable()` / `ensureSavedFiltersTable()` в `api/index.php`. Файлы в `sql/` (миграции, индексы) применяются вручную (phpMyAdmin / MySQL CLI), не через раннер миграций.

### Сервисный слой (`includes/`)

`AccountsService` (+ `AccountsRepository`) — основной фасад; `FilterBuilder` переводит параметры запроса в условия WHERE; `ColumnMetadata` интроспектит колонки таблицы (с кэшем); `StatisticsService`, `MassTransferService`, `AuditLogger`, `AccountValidationService`, `CsvParser`, `Validator`, `RateLimiter`/`RateLimitMiddleware`, `SessionManager`, `JobProgress`. Настраиваемые параметры (размеры страниц, TTL кэшей, лимиты rate-limit, лимиты экспорта, настройки проверки аккаунтов и каноническая структура CSV-импорта) централизованы в виде констант в `includes/Config.php`.

### Фронтенд (без сборщика)

Исходные CSS/JS лежат в `assets/css/` и `assets/js/` (`core/`, `modules/`, отдельные скрипты). `php build_assets.php` склеивает **явно упорядоченные** списки файлов (порядок важен для каскада и зависимостей — см. порядок загрузки JS в `DEVELOPER_GUIDE.md`) и пишет минифицированные бандлы с хешем содержимого в `assets/build/`. Каркас страницы — `templates/dashboard.php` + `templates/partials/`. Вместо `console.log` используйте `logger.debug/info/warn/error` (JS `window.logger`).

### Соглашения по безопасности

Каждый POST/PUT/DELETE обязан нести CSRF-токен, проверяемый через `Validator::validateCsrfToken()`. Файловый rate-limit работает по каждому эндпоинту (`checkRateLimit('api')` и т.п.). Экранируйте вывод через `e()`. Чувствительные поля (`password`, `email_password`, `cookies`, `first_cookie`, `token`, `two_fa`) помечены в `Config::CSV_STRUCTURE` и не должны логироваться в открытом виде. Пользователи + bcrypt-хеши лежат в `users.json`; при первом запуске случайный пароль админа пишется в `FIRST_RUN_ADMIN_PASSWORD.txt`.

## Деплой и ветвление

`main` — единственный trunk и **единственная ветка, которая деплоит в прод**. `.github/workflows/deploy.yml` запускает FTPS-деплой на живой `panel.account-factory.site` при **каждом пуше/мерже в `main`** (стейджинга нет). Он переписывает `ASSETS_VERSION` в `config.php` на короткий SHA коммита (cache-buster для JS/CSS — никогда не ставьте `time()`), пишет `deployed-version.txt` и материализует секрет `NPPR_API_TOKEN` в `.nppr_token` (legacy-путь, см. ниже). Любой мерж в `main` считайте релизом в продакшен.

**Рабочий процесс.** Делайте задачи в короткоживущих ветках от свежего `main` с именами `feat/...`, `fix/...`, `chore/...` — пуш в такие ветки ничего не деплоит. Открывайте PR в `main`; мерж триггерит единственный прод-деплой. `main` защищён branch protection: прямой пуш запрещён, нужен зелёный CI-чек `lint` (`php -l`). После деплоя проверяйте `panel.account-factory.site/deployed-version.txt` — там SHA вашего мержа. Не пушьте старые ветки типа `feature/dashboard-load-optimization`: их версия `deploy.yml` могла деплоить и могла бы откатить прод.

## Проверка валидности аккаунтов (check.fb.tools)

`/api/accounts/validate/{preview,prepare,check,progress}` проверяют аккаунты Facebook через внешний bulk-API **`check.fb.tools`** (`Config::FB_TOOLS_URL`, без токена/авторизации). Длинные прогоны `check` сначала вызывают `session_write_close()` (чтобы не блокировать другие запросы) и сообщают инкрементальный прогресс через `JobProgress`, который опрашивает фронтенд. **Legacy:** старый путь на NPPR (`includes/FbCheckerService.php`, токен из `NPPR_API_TOKEN` / файла `.nppr_token`) помечен `@deprecated` как dead code и подлежит удалению отдельным PR.

## Workflow OpenSpec

`AGENTS.md` делегирует в `openspec/AGENTS.md`. Перед нетривиальной работой — новые возможности, ломающие изменения, сдвиги архитектуры или меняющая поведение работа над производительностью/безопасностью — создайте предложение изменения в `openspec/changes/<verb-led-id>/` (`proposal.md`, `tasks.md`, опционально `design.md` плюс delta-спецификации) и запустите `openspec validate <id> --strict`; не начинайте реализацию, пока предложение не одобрено. **Пропускайте** предложение для багфиксов, опечаток, комментариев, изменений конфигурации и тестов на существующее поведение. Файлы `.cursor/commands/openspec-*.md` повторяют этот процесс.
