<?php
/**
 * Однократное создание флага оптимизации (.optimization_applied).
 * Запустите один раз (в браузере или CLI: php create_optimization_flag.php),
 * чтобы при каждой перезагрузке страницы НЕ выполнялись auto_setup и проверка
 * таблицы в INFORMATION_SCHEMA — иначе каждая загрузка будет долгой.
 *
 * Требуется только если флаг по какой-то причине не создался (нет прав на запись,
 * read-only окружение и т.п.).
 */

$projectRoot = __DIR__;
$flagFile = $projectRoot . '/.optimization_applied';
$tempDir = sys_get_temp_dir();

$content = date('Y-m-d H:i:s') . "\nFlag created by create_optimization_flag.php\n";

$createdInProject = false;
$createdInTemp = false;

if (@file_put_contents($flagFile, $content) !== false) {
    $createdInProject = true;
}

// Путь к флагу в temp (как в config.php) — без подключения config, чтобы скрипт работал и без БД/логина
$fallbackPath = $tempDir . '/dashboard_opt_' . md5($projectRoot) . '.applied';

if (!$createdInProject && @file_put_contents($fallbackPath, $content) !== false) {
    $createdInTemp = true;
}

$alreadyExists = file_exists($flagFile) || file_exists($fallbackPath);

if (PHP_SAPI === 'cli') {
    if ($alreadyExists && !$createdInProject && !$createdInTemp) {
        echo "Флаг уже существует (страница не должна тормозить при перезагрузке).\n";
        echo "  В проекте: " . (file_exists($flagFile) ? $flagFile : 'нет') . "\n";
        echo "  В temp:    " . (file_exists($fallbackPath) ? $fallbackPath : 'нет') . "\n";
    } elseif ($createdInProject) {
        echo "OK: Флаг создан в корне проекта: .optimization_applied\n";
        echo "Перезагрузка страницы больше не будет запускать auto_setup и проверку таблицы.\n";
    } elseif ($createdInTemp) {
        echo "OK: Флаг создан в temp: $fallbackPath\n";
        echo "Перезагрузка страницы больше не будет запускать тяжёлые проверки.\n";
    } else {
        echo "Ошибка: не удалось создать флаг (нет прав на запись?).\n";
        echo "Создайте вручную пустой файл в корне проекта: .optimization_applied\n";
        echo "  touch .optimization_applied\n";
        exit(1);
    }
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Флаг оптимизации</title></head><body>';
    echo '<h1>Флаг оптимизации дашборда</h1>';
    if ($alreadyExists && !$createdInProject && !$createdInTemp) {
        echo '<p>Флаг уже существует — при перезагрузке тяжёлые проверки не выполняются.</p>';
    } elseif ($createdInProject) {
        echo '<p><strong>Готово.</strong> Флаг создан в корне проекта (<code>.optimization_applied</code>).</p>';
        echo '<p>Теперь перезагрузка страницы не будет запускать auto_setup и проверку таблицы.</p>';
    } elseif ($createdInTemp) {
        echo '<p><strong>Готово.</strong> Флаг создан в каталоге temp (нет прав на запись в проект).</p>';
        echo '<p>Перезагрузка страницы больше не будет тормозить.</p>';
    } else {
        echo '<p><strong>Ошибка:</strong> не удалось создать флаг (нет прав на запись?).</p>';
        echo '<p>Создайте вручную пустой файл в корне проекта: <code>.optimization_applied</code></p>';
    }
    echo '<p><a href="index.php">Открыть дашборд</a></p>';
    echo '</body></html>';
}
