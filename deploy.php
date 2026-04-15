<?php
/**
 * Скрипт автоматической загрузки проекта на хостинг по FTP
 */

// Проверяем авторизацию — скрипт доступен только для авторизованных пользователей
require_once __DIR__ . '/auth.php';
requireAuth();

// Данные подключения из переменных окружения (задаются в .env)
$ftp_server = getenv('FTP_SERVER') ?: '';
$ftp_user   = getenv('FTP_USER') ?: '';
$ftp_pass   = getenv('FTP_PASS') ?: '';
$remote_dir = getenv('FTP_REMOTE_DIR') ?: '/';

if (empty($ftp_server) || empty($ftp_user) || empty($ftp_pass)) {
    die('Ошибка: FTP-креды не заданы. Установите FTP_SERVER, FTP_USER, FTP_PASS в переменных окружения или .env файле.');
}

// Список файлов и папок для исключения
$exclude = [
    '.git',
    '.cursor',
    'logs',
    'deploy.php',
    '.env',
    'agent-tools',
    '-w'
];

// Увеличиваем время выполнения скрипта
set_time_limit(0);

echo "Подключение к $ftp_server...\n";
$conn_id = ftp_connect($ftp_server) or die("Не удалось подключиться к $ftp_server");

if (@ftp_login($conn_id, $ftp_user, $ftp_pass)) {
    echo "Авторизация успешна под именем $ftp_user\n";
    ftp_pasv($conn_id, true); // Включаем пассивный режим

    echo "Начало загрузки файлов...\n";
    uploadDirectory($conn_id, ".", $remote_dir, $exclude);

    echo "\nЗагрузка завершена успешно!\n";
} else {
    echo "Ошибка авторизации. Проверьте логин и пароль.\n";
}

ftp_close($conn_id);

/**
 * Рекурсивная функция загрузки папок
 */
function uploadDirectory($conn_id, $local_dir, $remote_dir, $exclude) {
    $handle = opendir($local_dir);
    if (!$handle) return;

    while (false !== ($file = readdir($handle))) {
        if ($file === "." || $file === ".." || in_array($file, $exclude)) continue;

        $local_path = $local_dir . DIRECTORY_SEPARATOR . $file;
        $remote_path = ($remote_dir === "/" ? "" : $remote_dir) . "/" . $file;

        if (is_dir($local_path)) {
            // Пытаемся создать папку на сервере
            if (!@ftp_chdir($conn_id, $remote_path)) {
                if (@ftp_mkdir($conn_id, $remote_path)) {
                    echo "Создана папка: $remote_path\n";
                }
            }
            // Рекурсивно заходим внутрь
            uploadDirectory($conn_id, $local_path, $remote_path, $exclude);
        } else {
            // Загружаем файл
            if (@ftp_put($conn_id, $remote_path, $local_path, FTP_BINARY)) {
                echo "Загружен: $remote_path\n";
            } else {
                echo "ОШИБКА загрузки: $remote_path\n";
            }
        }
    }
    closedir($handle);
}
