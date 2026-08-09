<?php
/**
 * Сборщик бандлов CSS/JS: конкатенирует исходники по манифесту includes/AssetBundles.php.
 *
 * Запуск (только CLI):
 *   php tools/build_assets.php                 # собрать в assets/build
 *   php tools/build_assets.php --out=/tmp/x    # собрать в другой каталог (тесты)
 *   php tools/build_assets.php --clean         # удалить собранные бандлы и выйти
 *
 * Где запускается на самом деле: шаг «Build asset bundles» в .github/workflows/deploy.yml,
 * перед FTPS-загрузкой. На проде сборки нет и быть не может — там нет ни Node, ни
 * гарантии, что PHP сможет писать в assets/build при каждом запросе.
 *
 * Почему без минификации — см. шапку includes/AssetBundles.php.
 *
 * Код выхода: 0 — успех, 1 — ошибка (нет исходника, не пишется файл).
 */

if (PHP_SAPI !== 'cli') {
    // По HTTP файл не должен работать вообще: он пишет в файловую систему.
    // Каталог tools/ и так закрыт в .htaccess, это вторая линия.
    http_response_code(403);
    exit("build_assets.php запускается только из CLI\n");
}

require_once dirname(__DIR__) . '/includes/AssetBundles.php';

$root     = AssetBundles::rootDir();
$outDir   = $root . '/' . AssetBundles::BUILD_DIR;
$clean    = false;

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--out=') === 0) {
        $outDir = rtrim(substr($arg, 6), '/');
    } elseif ($arg === '--clean') {
        $clean = true;
    } else {
        fwrite(STDERR, "Неизвестный аргумент: $arg\n");
        exit(1);
    }
}

if ($clean) {
    foreach (array_keys(AssetBundles::all()) as $bundle) {
        $path = $outDir . '/' . $bundle;
        if (is_file($path) && !unlink($path)) {
            fwrite(STDERR, "Не удалось удалить $path\n");
            exit(1);
        }
        echo "удалён: $path\n";
    }
    exit(0);
}

if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Не удалось создать каталог $outDir\n");
    exit(1);
}

$totalIn = 0;
$totalOut = 0;

foreach (AssetBundles::all() as $bundle => $files) {
    $content = '';
    foreach ($files as $rel) {
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) {
            // Молча пропустить нельзя: бандл соберётся короче, и страница потеряет
            // модуль без единой ошибки в консоли.
            fwrite(STDERR, "ОШИБКА: нет исходника $rel (бандл $bundle)\n");
            exit(1);
        }
        $code = file_get_contents($abs);
        if ($code === false) {
            fwrite(STDERR, "ОШИБКА: не читается $rel\n");
            exit(1);
        }
        $totalIn += strlen($code);
        $content .= AssetBundles::chunkFor($rel, $code);
    }

    $target = $outDir . '/' . $bundle;
    if (file_put_contents($target, $content) === false) {
        fwrite(STDERR, "ОШИБКА: не записывается $target\n");
        exit(1);
    }
    $totalOut += strlen($content);
    printf("%-22s %2d файлов → %7d байт  (%s)\n", $bundle, count($files), strlen($content), $target);
}

printf("\nИтого: %d байт исходников → %d байт бандлов\n", $totalIn, $totalOut);
exit(0);
