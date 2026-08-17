# Деплой на хостинг по FTP

При пуше в `main` GitHub Actions запускает workflow **Deploy to FTP** и заливает файлы на хостинг. Мёрдж в `main` = деплой в прод.

## Однократная настройка

1. Откройте репозиторий на GitHub → **Settings** → **Secrets and variables** → **Actions**.
2. Добавьте три секрета (**New repository secret**):
   - **FTP_SERVER** — адрес FTP-сервера (например, `if592995.ftp.tools`).
   - **FTP_USERNAME** — логин FTP (например, `if592995_6505`).
   - **FTP_PASSWORD** — пароль от FTP.
3. Сохраните.

Каталог назначения **не задаётся в workflow**: там стоит `server_dir: ./`, а FTP-логин сам приземляется в домашний каталог сайта (`/home/if592995/panel.account-factory.site/www/`). Менять путь надо в настройках FTP-пользователя на хостинге; явный `server_dir` в `deploy.yml` нужен, только если домашний каталог не совпадает с каталогом сайта.

Переезд панели на другой домен описан в `docs/DOMAIN_MIGRATION.md`.

## Как это работает

- При `git push` в `main` запускается **Deploy to FTP**.
- Файлы из репозитория синхронизируются в каталог на сервере.
- Папки и файлы из списка `exclude` не заливаются (`.git`, `.github`, `node_modules`, `.env` и др.).

Ручной запуск: **Actions** → **Deploy to FTP** → **Run workflow**.
