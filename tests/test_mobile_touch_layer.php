<?php
/**
 * Тест мобильного слоя: assets/css/core-touch.css + assets/js/mobile-touch.js.
 *
 * Запуск:  php tests/test_mobile_touch_layer.php
 * Код выхода: 0 — все проверки прошли, 1 — есть падения.
 *
 * Без сети и БД. Стережём то, что ломается молча — вёрстка на компьютере при
 * этом выглядит как обычно, а на телефоне снова «прыгает» или мельчит:
 *
 *  1. Каждая полноценная страница панели разрешает заходить под вырез экрана
 *     (viewport-fit=cover) и подключает core-touch.css — напрямую или через
 *     бандл core.css, где он обязан стоять ПОСЛЕДНИМ (иначе тёмная тема или
 *     слой дизайна перебьют размеры целей нажатия).
 *  2. Компьютер не трогаем: в core-touch.css каждое «44px» (размер под палец)
 *     живёт внутри @media (pointer: coarse) или @media (max-width …). Правило
 *     без такого условия поменяло бы кнопки и на мониторе.
 *  3. Нигде нет голого 100vh без запасного dvh рядом — на iOS 100vh больше
 *     видимой области, и блок «на весь экран» дёргается при скрытии адресной
 *     строки.
 *  4. У всех числовых полей есть inputmode — цифровая клавиатура телефона.
 *  5. Серверная карточка «Email + 2FA» рендерится скрытой: custom-cards.js
 *     всё равно удаляет её при запуске, а видимая она мелькала и сдвигала
 *     страницу (сдвиг вёрстки 0,07 на телефоне).
 *  6. Страницы с окнами подключают mobile-touch.js (жест «смахнуть вниз»).
 *  7. Нижняя панель выбранных строк включается классом has-selection — его
 *     ставят оба модуля выбора (дашборд и корзина).
 *  8. Автообновление журнала действий не перезагружает страницу.
 */

// Warning/Notice тоже считаем провалом: на проде error_reporting=E_ALL.
set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$failures = 0;
$passed   = 0;
$ROOT     = dirname(__DIR__);

require_once $ROOT . '/includes/AssetBundles.php';

/**
 * @param string   $name
 * @param callable $fn возвращает [ok, сообщение при провале]
 */
function check($name, callable $fn)
{
    global $failures, $passed;
    try {
        $res = $fn();
        $ok  = $res[0];
        $msg = isset($res[1]) ? $res[1] : '';
    } catch (Throwable $e) {
        $ok  = false;
        $msg = get_class($e) . ': ' . $e->getMessage();
    }
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name — $msg\n";
    }
}

/** @return string содержимое файла проекта */
function src($rel)
{
    global $ROOT;
    $s = file_get_contents($ROOT . '/' . $rel);
    if ($s === false) {
        throw new RuntimeException("не читается $rel");
    }
    return $s;
}

// Все страницы, которые отдают целый HTML-документ (проверено grep по <!DOCTYPE).
$pages = array(
    'login.php', 'loading.php', 'view.php', 'history.php', 'log.php',
    'admin_logs.php', 'admin_duplicates.php', 'bundles.php',
    'templates/dashboard.php', 'templates/favorites.php', 'templates/trash.php',
);
// Шаблоны, которые берут CSS из бандла core.css
$bundled = array('templates/dashboard.php', 'templates/favorites.php', 'templates/trash.php');

echo "── 1. Каждая страница подключает мобильный слой\n";

check('core-touch.css — последний файл бандла core.css', function () {
    $files = AssetBundles::files('core.css');
    $last  = end($files);
    return array($last === 'assets/css/core-touch.css', 'последний: ' . $last);
});

foreach ($pages as $p) {
    check("$p: viewport-fit=cover", function () use ($p) {
        return array(strpos(src($p), 'viewport-fit=cover') !== false, 'нет viewport-fit=cover в meta viewport');
    });
    check("$p: подключает core-touch.css", function () use ($p, $bundled) {
        $s = src($p);
        if (in_array($p, $bundled, true)) {
            return array(strpos($s, "AssetBundles::tags('core.css')") !== false, 'шаблон не подключает бандл core.css');
        }
        return array(strpos($s, 'assets/css/core-touch.css') !== false, 'нет <link> на core-touch.css');
    });
}

check('самостоятельные страницы подключают core-touch.css ПОСЛЕ своего <style>', function () use ($pages, $bundled) {
    $bad = array();
    foreach ($pages as $p) {
        if (in_array($p, $bundled, true)) {
            continue;
        }
        $s    = src($p);
        $link = strpos($s, 'assets/css/core-touch.css');
        $sty  = strrpos(substr($s, 0, strpos($s, '</head>')), '</style>');
        if ($sty !== false && $link < $sty) {
            $bad[] = $p;
        }
    }
    return array(!$bad, 'стиль страницы перебьёт слой: ' . implode(', ', $bad));
});

echo "── 2. Размеры под палец не трогают компьютер\n";

check('каждое 44px в core-touch.css — внутри @media (pointer: coarse) или (max-width)', function () {
    $css   = preg_replace('~/\*.*?\*/~s', '', src('assets/css/core-touch.css'));
    $stack = array();   // условия открытых блоков: строка @media или '' для обычного правила
    $head  = '';
    $bad   = array();
    $len   = strlen($css);
    for ($i = 0; $i < $len; $i++) {
        $c = $css[$i];
        if ($c === '{') {
            $stack[] = trim($head);
            $head    = '';
        } elseif ($c === '}') {
            array_pop($stack);
            $head = '';
        } elseif ($c === ';') {
            $decl = trim($head);
            $head = '';
            if (preg_match('~^(min-)?(height|width)\s*:\s*44px~', $decl)) {
                $guarded = false;
                foreach ($stack as $cond) {
                    if (strpos($cond, '@media') === 0 && (strpos($cond, 'pointer: coarse') !== false || strpos($cond, 'max-width') !== false)) {
                        $guarded = true;
                    }
                }
                if (!$guarded) {
                    $bad[] = $decl . ' в ' . implode(' > ', $stack);
                }
            }
        } else {
            $head .= $c;
        }
    }
    return array(!$bad, implode('; ', array_slice($bad, 0, 3)));
});

echo "── 3. 100vh только с запасным dvh\n";

$vhFiles = array_merge($pages, array('assets/css/core-base.css', 'assets/css/core-mobile.css', 'assets/css/core-touch.css'));
check('за каждым 100vh в той же декларации идёт dvh-вариант', function () use ($vhFiles) {
    $bad = array();
    foreach ($vhFiles as $f) {
        $lines = explode("\n", src($f));
        foreach ($lines as $n => $line) {
            if (!preg_match('~(^|[^\w-])(min-height|height|max-height)\s*:\s*[^;]*\b100vh\b~', $line, $m)) {
                continue;
            }
            $prop = $m[2];
            $next = isset($lines[$n + 1]) ? $lines[$n + 1] : '';
            $same = preg_match('~' . $prop . '\s*:[^;]*dvh~', $line) || preg_match('~' . $prop . '\s*:[^;]*dvh~', $next);
            if (!$same) {
                $bad[] = "$f:" . ($n + 1);
            }
        }
    }
    return array(!$bad, implode(', ', $bad));
});

echo "── 4. Цифровая клавиатура у числовых полей\n";

check('у каждого <input type="number"> есть inputmode', function () use ($ROOT) {
    $bad   = array();
    $files = array_merge(glob($ROOT . '/*.php'), glob($ROOT . '/templates/*.php'), glob($ROOT . '/templates/partials/*/*.php'), glob($ROOT . '/templates/partials/*/*/*.php'));
    foreach ($files as $f) {
        if (!preg_match_all('~<input\b[^>]*type="number"[^>]*>~s', file_get_contents($f), $m)) {
            continue;
        }
        foreach ($m[0] as $tag) {
            if (strpos($tag, 'inputmode=') === false) {
                $bad[] = basename($f);
            }
        }
    }
    return array(!$bad, 'без inputmode: ' . implode(', ', array_unique($bad)));
});

echo "── 5. Карточка «Email + 2FA» не мелькает\n";

check('серверная карточка custom:email_twofa рендерится с hidden', function () {
    $ok = (bool) preg_match('~<div class="stat-card[^"]*" data-card="custom:email_twofa"[^>]*\bhidden\b~', src('templates/partials/dashboard/stats-cards.php'));
    return array($ok, 'карточка видна до запуска custom-cards.js — снова будет прыжок при загрузке');
});
check('custom-cards.js по-прежнему удаляет все custom:* при запуске (на этом держится hidden)', function () {
    return array(strpos(src('assets/js/modules/custom-cards.js'), "row.querySelectorAll('[data-card^=\"custom:\"]').forEach(n => n.remove());") !== false,
        'логика изменилась — проверь, не должна ли карточка снова быть видимой');
});

echo "── 6. Жест «смахнуть окно вниз»\n";

check('mobile-touch.js в бандле дашборда', function () {
    return array(in_array('assets/js/mobile-touch.js', AssetBundles::files('dashboard.defer.js'), true), 'нет в dashboard.defer.js');
});
foreach (array('templates/trash.php', 'templates/favorites.php', 'view.php', 'admin_logs.php') as $p) {
    check("$p: подключает mobile-touch.js", function () use ($p) {
        return array(strpos(src($p), 'assets/js/mobile-touch.js') !== false, 'нет <script> на mobile-touch.js');
    });
}

echo "── 7. Панель выбранных строк\n";

foreach (array('assets/js/modules/dashboard-selection.js', 'assets/js/trash.js') as $f) {
    check("$f ставит body.has-selection", function () use ($f) {
        return array(strpos(src($f), "classList.toggle('has-selection'") !== false, 'класс не ставится — панель на телефоне не появится');
    });
}

echo "── 8. Журнал действий обновляется на месте\n";

check('admin_logs.php: автообновление без location.reload', function () {
    $s = src('admin_logs.php');
    $noReload = !preg_match('~setInterval\(\s*\(\)\s*=>\s*location\.reload~', $s);
    $inPlace  = strpos($s, 'setInterval(refreshLogsInPlace') !== false;
    return array($noReload && $inPlace, 'раз в 30 с снова перезагружается вся страница (сброс прокрутки и фокуса)');
});

echo "\nИтого: $passed прошло, $failures упало\n";
exit($failures > 0 ? 1 : 0);
