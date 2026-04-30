<?php
/**
 * Скрипт для объединения и минификации CSS и JS файлов
 * Запускать: php build_assets.php
 */

// Проверяем авторизацию (если запускается через веб)
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
    requireAuth();
}

// Создаем директорию для собранных файлов
$buildDir = __DIR__ . '/assets/build';
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0755, true);
}

// Порядок загрузки CSS файлов (важно для каскадности)
$cssFiles = [
    'assets/css/core-base.css',
    'assets/css/core-components.css',
    'assets/css/core-plugins.css',
    'assets/css/core-theme.css',
    'assets/css/core-tables.css',
    'assets/css/core-mobile.css', // ВАЖНО: должен идти последним — содержит mobile-overrides
];

// Порядок загрузки JS файлов
$jsFiles = [
    'assets/js/validation.js',
    'assets/js/toast.js',
    'assets/js/sticky-scrollbar.js',
    'assets/js/table-module.js',
    'assets/js/filters-modern.js',
    'assets/js/saved-filters.js',
    'assets/js/quick-search.js',
    'assets/js/favorites.js',
    'assets/js/trash.js',
    'assets/js/dashboard.js',
];

/**
 * Минификация CSS
 */
function minifyCSS($css) {
    // Удаляем комментарии
    $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
    // Удаляем лишние пробелы
    $css = preg_replace('/\s+/', ' ', $css);
    // Удаляем пробелы вокруг специальных символов
    $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
    // Удаляем пробелы в конце строк
    $css = str_replace(['; ', ' }', '{ ', ' }'], [';', '}', '{', '}'], $css);
    // Удаляем последнюю точку с запятой в блоке
    $css = preg_replace('/;}/', '}', $css);
    return trim($css);
}

/**
 * Минификация JavaScript
 */
function minifyJS($js) {
    // Удаляем однострочные комментарии (но не в строках)
    $js = preg_replace('/(?<!["\'])\/\/.*$/m', '', $js);
    // Удаляем многострочные комментарии
    $js = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $js);
    // Удаляем лишние пробелы и переносы строк
    $js = preg_replace('/\s+/', ' ', $js);
    // Удаляем пробелы вокруг операторов
    $js = preg_replace('/\s*([=+\-*\/%<>!&|?:;{}()\[\],])\s*/', '$1', $js);
    // Удаляем пробелы в конце строк
    $js = trim($js);
    return $js;
}

/**
 * Объединение файлов
 */
function combineFiles($files, $type = 'css') {
    $combined = '';
    $errors = [];
    
    foreach ($files as $file) {
        if (!file_exists($file)) {
            $errors[] = "Файл не найден: $file";
            continue;
        }
        
        $content = file_get_contents($file);
        if ($content === false) {
            $errors[] = "Не удалось прочитать: $file";
            continue;
        }
        
        // Добавляем комментарий с именем файла для отладки
        $combined .= "\n/* === $file === */\n";
        $combined .= $content . "\n";
    }
    
    if (!empty($errors)) {
        echo "⚠️  Предупреждения:\n";
        foreach ($errors as $error) {
            echo "   $error\n";
        }
    }
    
    return $combined;
}

// Объединяем CSS
echo "📦 Объединение CSS файлов...\n";
$cssCombined = combineFiles($cssFiles, 'css');
$cssMinified = minifyCSS($cssCombined);
file_put_contents($buildDir . '/dashboard.min.css', $cssMinified);
echo "✅ Создан: assets/build/dashboard.min.css (" . number_format(strlen($cssMinified)) . " байт)\n";

// Сохраняем также не минифицированную версию для отладки
file_put_contents($buildDir . '/dashboard.css', $cssCombined);
echo "✅ Создан: assets/build/dashboard.css (" . number_format(strlen($cssCombined)) . " байт)\n";

// Объединяем JS
echo "\n📦 Объединение JS файлов...\n";
$jsCombined = combineFiles($jsFiles, 'js');
$jsMinified = minifyJS($jsCombined);
file_put_contents($buildDir . '/dashboard.min.js', $jsMinified);
echo "✅ Создан: assets/build/dashboard.min.js (" . number_format(strlen($jsMinified)) . " байт)\n";

// Сохраняем также не минифицированную версию для отладки
file_put_contents($buildDir . '/dashboard.js', $jsCombined);
echo "✅ Создан: assets/build/dashboard.js (" . number_format(strlen($jsCombined)) . " байт)\n";

// Создаем версию на основе хеша содержимого для кэширования
$cssHash = substr(md5($cssMinified), 0, 8);
$jsHash = substr(md5($jsMinified), 0, 8);

// Копируем с хешем в имени
copy($buildDir . '/dashboard.min.css', $buildDir . "/dashboard.{$cssHash}.min.css");
copy($buildDir . '/dashboard.min.js', $buildDir . "/dashboard.{$jsHash}.min.js");

echo "\n🎉 Готово! Файлы созданы в assets/build/\n";
echo "   CSS версия: dashboard.{$cssHash}.min.css\n";
echo "   JS версия: dashboard.{$jsHash}.min.js\n";
echo "\n💡 Для использования обновите templates/dashboard.php\n";

