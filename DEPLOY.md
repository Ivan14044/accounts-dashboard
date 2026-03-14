# Деплой на хостинг по FTP

При пуше в ветки `feature/dashboard-load-optimization` или `main` GitHub Actions запускает workflow **Deploy to FTP** и заливает файлы на хостинг.

## Однократная настройка

1. Откройте репозиторий на GitHub → **Settings** → **Secrets and variables** → **Actions**.
2. Добавьте три секрета (**New repository secret**):
   - **FTP_SERVER** — адрес FTP-сервера (например, `if592995.ftp.tools`).
   - **FTP_USERNAME** — логин FTP (например, `if592995_6505`).
   - **FTP_PASSWORD** — пароль от FTP.
3. Сохраните.

Путь на сервере задаётся в `.github/workflows/deploy.yml` в переменной `FTP_SERVER_DIR` (по умолчанию `/home/if592995/panel.account-factory.site/www/`). При необходимости измените его в файле workflow.

## Как это работает

- При `git push` в `main` или `feature/dashboard-load-optimization` запускается **Deploy to FTP**.
- Файлы из репозитория синхронизируются в каталог на сервере.
- Папки и файлы из списка `exclude` не заливаются (`.git`, `.github`, `node_modules`, `.env` и др.).

Ручной запуск: **Actions** → **Deploy to FTP** → **Run workflow**.
