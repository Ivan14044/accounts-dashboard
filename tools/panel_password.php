<?php
/**
 * Пароль панели: получить хэш для секрета и записать файл на сайт.
 *
 * Запуск (только CLI):
 *   php tools/panel_password.php hash    # пароль со STDIN → bcrypt-хэш в STDOUT
 *   php tools/panel_password.php write   # хэш из env PANEL_PASSWORD_HASH → .panel_password.php
 *
 * Как сменить пароль панели (сменить = заодно выкинуть всех вошедших):
 *   1. printf '%s' 'НОВЫЙ_ПАРОЛЬ' | docker run --rm -i -v "$PWD":/app -w /app php:7.3-cli php tools/panel_password.php hash
 *   2. gh secret set PANEL_PASSWORD_HASH --body '<хэш из шага 1>'
 *   3. перезапустить деплой (Actions → Deploy to FTP → Run workflow).
 * Пароль идёт через STDIN, а не аргументом — чтобы не оседать в истории
 * оболочки и в списке процессов.
 *
 * Где `write` запускается на самом деле: шаг «Write panel password file» в
 * .github/workflows/deploy.yml, перед FTPS-загрузкой. Для локального стенда —
 * вручную тем же способом (файл в .gitignore).
 *
 * Код выхода: 0 — успех, 1 — нет хэша / хэш битый / файл не пишется.
 * Именно код 1 роняет деплой ДО заливки: иначе на прод уехал бы код, который
 * без файла с хэшем закрывает вход всем (см. includes/PanelPassword.php).
 */

if (PHP_SAPI !== 'cli') {
    // По HTTP файл не должен работать вообще. Каталог tools/ и так закрыт
    // в .htaccess, это вторая линия.
    http_response_code(403);
    exit("panel_password.php запускается только из CLI\n");
}

require_once dirname(__DIR__) . '/includes/PanelPassword.php';

$mode = $argv[1] ?? '';

if ($mode === 'hash') {
    $password = stream_get_contents(STDIN);
    // Перевод строки в конце — почти всегда след `echo`, а не часть пароля.
    $password = rtrim((string)$password, "\r\n");
    if ($password === '') {
        fwrite(STDERR, "Пароль не передан: подайте его на STDIN\n");
        exit(1);
    }
    echo password_hash($password, PASSWORD_DEFAULT), "\n";
    exit(0);
}

if ($mode === 'write') {
    $hash = trim((string)getenv('PANEL_PASSWORD_HASH'));
    if ($hash === '') {
        fwrite(STDERR, "PANEL_PASSWORD_HASH не задан — без пароля панели вход закрыт всем, деплой остановлен\n");
        exit(1);
    }
    if (!PanelPassword::isValidHash($hash)) {
        fwrite(STDERR, "PANEL_PASSWORD_HASH не похож на хэш password_hash() — в секрет положен сам пароль?\n");
        exit(1);
    }

    $file = PanelPassword::file();
    if (file_put_contents($file, PanelPassword::renderFile($hash), LOCK_EX) === false) {
        fwrite(STDERR, "Не удалось записать $file\n");
        exit(1);
    }

    // Сверка: файл читается обратно тем же кодом, что и на сайте.
    PanelPassword::setFile($file);
    if (PanelPassword::loadHash() !== $hash) {
        fwrite(STDERR, "Записанный файл читается не тем хэшем\n");
        exit(1);
    }

    echo PanelPassword::FILE_NAME . " записан (" . filesize($file) . " байт)\n";
    exit(0);
}

fwrite(STDERR, "Использование: php tools/panel_password.php hash|write\n");
exit(1);
