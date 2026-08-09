<?php
/**
 * Манифест бандлов CSS/JS и рендер тегов подключения.
 *
 * Зачем: страница дашборда подключала 35 своих JS-файлов и 8 CSS отдельными
 * запросами. Здесь лежит ЕДИНСТВЕННЫЙ список того, что и в каком порядке
 * подключается, — им пользуются и сборщик (tools/build_assets.php), и шаблоны.
 * Раньше порядок жил только в разметке двух шаблонов; расхождение такого списка
 * с реальностью в этом проекте уже случалось (схема accounts в трёх местах).
 *
 * Чего здесь осознанно НЕТ:
 *  - минификации. Конкатенация не меняет семантику, а regex-минификатор (такой был
 *    в удалённом build_assets.php) её меняет — он резал `;}` внутри строк и data-URI.
 *    Выигрыш от минификации поверх gzip мал, риск — нет;
 *  - вендорных файлов (bootstrap, fontawesome, nouislider). Они уже минифицированы,
 *    отдаются с другим кэшем, а nouislider вообще с CDN;
 *  - ленивой загрузки и code splitting. Это отдельная задача про поведение UI.
 *
 * Как работает подключение:
 *  - если бандл собран (assets/build/<имя>) — отдаётся один тег;
 *  - если нет — отдаются исходные файлы в порядке манифеста.
 * Благодаря этому локальный стенд работает без сборки, а прод не ломается, если
 * шаг сборки в деплое отвалился и бандл не доехал.
 *
 * ГРАБЛИ: собранный бандл на локалке не пересобирается сам. Поправил JS и не видишь
 * изменений — удали каталог assets/build (или запусти сборщик заново). В проде
 * этой проблемы нет: бандлы собираются в GitHub Actions из свежего кода на каждый
 * деплой, а ASSETS_VERSION там же меняется на SHA коммита.
 */
class AssetBundles
{
    /** Каталог со сборкой относительно корня проекта. */
    const BUILD_DIR = 'assets/build';

    /**
     * Что во что собирается. Порядок внутри списка = порядок выполнения,
     * менять его нельзя без понимания зависимостей (см. комментарии).
     *
     * Разделение sync/defer не косметическое: `defer`-скрипты выполняются ПОСЛЕ
     * всех обычных, после парсинга документа. Свалить их в один бандл — значит
     * поменять порядок выполнения. Поэтому бандла два, ровно по этой границе.
     */
    private static $bundles = array(

        // ── CSS. Каскад: порядок = приоритет, последний перекрывает предыдущие ──
        // Подключается на dashboard.php, favorites.php и trash.php — списки там
        // были идентичны, поэтому бандл общий.
        'core.css' => array(
            'assets/css/core-base.css',       // токены и типографика; в нём @import шрифта — обязан быть первым
            'assets/css/core-components.css',
            'assets/css/core-plugins.css',
            'assets/css/core-theme.css',
            'assets/css/core-tables.css',
            'assets/css/core-mobile.css',     // мобильные оверрайды — после базовых
            'assets/css/core-design-v2.css',  // слой полировки дизайна
            'assets/css/core-dark.css',       // тёмная тема — последней
        ),

        // ── JS без defer: выполняются по ходу парсинга, строго в этом порядке ──
        // Выполняются ПОСЛЕ инлайнов config-script.php и init-script.php, которые
        // объявляют window.__DASHBOARD_CONFIG__ и window.DashboardConfig.
        // constants.js читает DashboardConfig.activeFiltersCount на момент загрузки:
        // окажись бандл выше инлайна — ACTIVE_FILTERS_COUNT молча станет нулём.
        'dashboard.sync.js' => array(
            // core/* подняты в начало (было — после dashboard-init.js). Причина не
            // косметическая: `logger`, `domCache` и `batchUpdater` объявлены как
            // top-level `const`. В отдельных <script> имя, объявленное позже, просто
            // не существует раньше — работает `typeof logger !== 'undefined'`.
            // В конкатенации все top-level const/let/class всплывают в temporal dead
            // zone на весь бандл, и тот же `typeof` уже БРОСАЕТ
            // «Cannot access 'logger' before initialization». Проверено в браузере:
            // на первом варианте бандла страница падала с этой ошибкой, и падение
            // на верхнем уровне обрывало весь бандл целиком.
            // Порядок ниже = фактический порядок зависимостей; инвариант стережёт
            // tests/test_asset_bundles.php.
            'assets/js/core/logger.js',
            'assets/js/core/dom-cache.js',
            'assets/js/core/performance.js',
            'assets/js/modules/constants.js',                // ключи localStorage и константы — их читают все остальные
            'assets/js/modules/custom-cards.js',             // строго ДО dashboard-init.js: тот зовёт initializeCustomCards() через window
            'assets/js/modules/inline-edit.js',
            'assets/js/modules/columns-cards-settings.js',
            'assets/js/modules/auto-refresh.js',
            'assets/js/modules/touch-gestures.js',
            'assets/js/dashboard-init.js',
            'assets/js/modules/dashboard-refresh.js',
            'assets/js/pagination.js',
            'assets/js/modules/dashboard-selection.js',
            'assets/js/modules/dashboard-export.js',
            'assets/js/modules/dashboard-filters.js',
            'assets/js/modules/dashboard-stats.js',
            'assets/js/modules/dashboard-modals.js',
            'assets/js/modules/dashboard-validate.js',
            'assets/js/modules/dashboard-main.js',
            'assets/js/sticky-scrollbar.js',
            'assets/js/table-module.js',
            'assets/js/toast.js',
            'assets/js/modules/undo.js',
            'assets/js/modules/cell-actions.js',
            'assets/js/filters-modern.js',
            'assets/js/dashboard.js',
        ),

        // ── JS с defer: выполняются после парсинга, в порядке объявления ──
        // Внимание: nouislider с CDN тоже defer и объявлен раньше этого бандла,
        // поэтому он по-прежнему выполнится первым — так было и до бандлинга.
        'dashboard.defer.js' => array(
            'assets/js/modules/dashboard-upload.js',
            'assets/js/validation.js',
            'assets/js/quick-search.js',
            'assets/js/saved-filters.js',
            'assets/js/favorites.js',
            'assets/js/modules/cards-hide-sync.js',
            'assets/js/density-toggle.js',
            'assets/js/per-page.js',
            'assets/js/theme-toggle.js',
        ),
    );

    /**
     * Все бандлы манифеста.
     *
     * @return array<string, string[]> имя бандла => пути исходников от корня проекта
     */
    public static function all()
    {
        return self::$bundles;
    }

    /**
     * Список исходников одного бандла.
     *
     * @param string $bundle имя бандла (ключ манифеста)
     * @return string[]
     * @throws InvalidArgumentException если бандла нет в манифесте
     */
    public static function files($bundle)
    {
        if (!isset(self::$bundles[$bundle])) {
            throw new InvalidArgumentException("Неизвестный бандл: $bundle");
        }
        return self::$bundles[$bundle];
    }

    /** Корень проекта (каталог, в котором лежит index.php). */
    public static function rootDir()
    {
        return dirname(__DIR__);
    }

    /**
     * Кусок бандла, соответствующий одному исходнику.
     *
     * Разделитель важен: файл может заканчиваться строчным комментарием без перевода
     * строки — тогда следующий файл целиком уехал бы в комментарий. Точка с запятой
     * между файлами — пустая инструкция, она безопасна и на верхнем уровне JS, и в CSS
     * её нет (для CSS отдаём только перевод строки).
     *
     * @param string $relPath путь исходника от корня проекта
     * @param string $code    содержимое исходника
     * @return string
     */
    public static function chunkFor($relPath, $code)
    {
        $isCss = substr($relPath, -4) === '.css';
        $head  = "/* ==== $relPath ==== */\n";
        $tail  = $isCss ? "\n" : "\n;\n";
        return $head . $code . $tail;
    }

    /**
     * Есть ли собранный бандл на диске.
     *
     * @param string      $bundle
     * @param string|null $buildDir абсолютный путь к каталогу сборки (для тестов)
     * @return bool
     */
    public static function isBuilt($bundle, $buildDir = null)
    {
        self::files($bundle); // валидация имени
        return is_file(self::buildPath($bundle, $buildDir));
    }

    /**
     * Абсолютный путь к собранному бандлу.
     *
     * @param string      $bundle
     * @param string|null $buildDir
     * @return string
     */
    public static function buildPath($bundle, $buildDir = null)
    {
        $dir = $buildDir !== null ? rtrim($buildDir, '/') : self::rootDir() . '/' . self::BUILD_DIR;
        return $dir . '/' . $bundle;
    }

    /**
     * HTML-теги подключения бандла: один тег, если бандл собран, иначе — все исходники.
     *
     * @param string      $bundle   имя бандла
     * @param string      $attrs    доп. атрибуты тега, например ' defer' (только для JS)
     * @param string|null $buildDir абсолютный путь к каталогу сборки (для тестов)
     * @return string HTML, каждый тег с переводом строки
     * @throws InvalidArgumentException если бандла нет в манифесте
     */
    public static function tags($bundle, $attrs = '', $buildDir = null)
    {
        $files = self::files($bundle);
        $paths = self::isBuilt($bundle, $buildDir)
            ? array(self::BUILD_DIR . '/' . $bundle)
            : $files;

        $html = '';
        foreach ($paths as $path) {
            $html .= self::tagFor($path, $attrs) . "\n";
        }
        return $html;
    }

    /**
     * href собранного бандла для <link rel="preload">, либо запасной путь,
     * если бандл не собран.
     *
     * @param string      $bundle
     * @param string      $fallbackFile путь исходника от корня проекта
     * @param string|null $buildDir
     * @return string путь с ?v=ASSETS_VERSION
     */
    public static function preloadHref($bundle, $fallbackFile, $buildDir = null)
    {
        $path = self::isBuilt($bundle, $buildDir)
            ? self::BUILD_DIR . '/' . $bundle
            : $fallbackFile;
        return $path . '?v=' . self::version();
    }

    /** Один тег подключения для .js или .css. */
    private static function tagFor($path, $attrs)
    {
        $src = htmlspecialchars($path . '?v=' . self::version(), ENT_QUOTES, 'UTF-8');
        if (substr($path, -4) === '.css') {
            return '<link href="' . $src . '" rel="stylesheet">';
        }
        return '<script src="' . $src . '"' . $attrs . '></script>';
    }

    /**
     * Cache-buster. В проде ASSETS_VERSION подменяется на короткий SHA коммита
     * шагом деплоя; вне прода константы может не быть — тогда time(), как и в
     * остальных шаблонах.
     */
    private static function version()
    {
        return defined('ASSETS_VERSION') ? ASSETS_VERSION : time();
    }
}
