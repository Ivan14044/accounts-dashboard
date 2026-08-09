<?php
/**
 * Тест манифеста и сборки бандлов CSS/JS (includes/AssetBundles.php + tools/build_assets.php).
 *
 * Запуск:  php tests/test_asset_bundles.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД. Проверяем инварианты, поломка которых НЕ видна в консоли браузера
 * и потому опасна — бандл «работает», но ведёт себя не так, как набор файлов:
 *
 *  1. Все файлы манифеста существуют (иначе бандл молча соберётся короче).
 *  2. Шаблоны не подключают бандлируемые ассеты в обход манифеста — иначе список
 *     разъедется, как уже разъехалась схема accounts по трём файлам.
 *  3. Ни в одном JS-исходнике нет директивы 'use strict' на верхнем уровне файла.
 *     В отдельном <script> она действует только на свой файл; в конкатенации первая
 *     такая директива включила бы strict-режим ВСЕМУ бандлу, а во втором и далее
 *     файлах, наоборот, превратилась бы в бесполезное строковое выражение.
 *     Проект пишет 'use strict' внутри IIFE — этот тест стережёт, чтобы так и осталось.
 *  4. CSS `@import` допустим только в самом начале ПЕРВОГО файла бандла: по спецификации
 *     @import обязан идти до любых правил, иначе браузер его игнорирует. Добавь кто-нибудь
 *     @import в core-dark.css — шрифт молча перестал бы грузиться только в проде.
 *  5. Сборщик пишет ровно конкатенацию исходников в порядке манифеста и ничего не теряет.
 *  6. Fallback: если бандл не собран, хелпер отдаёт исходные файлы в том же порядке;
 *     если собран — один тег с тем же ASSETS_VERSION.
 */

// Warning/Notice тоже считаем провалом: на проде error_reporting=E_ALL.
set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$failures = 0;
$passed   = 0;

$ROOT = dirname(__DIR__);

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

/** Рекурсивно удаляет каталог с файлами. */
function rmrf($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rmrf($path) : unlink($path);
    }
    rmdir($dir);
}

echo "\n=== Бандлы ассетов: манифест, инварианты, сборка ===\n\n";

$bundles = AssetBundles::all();

// ── 1. Файлы манифеста существуют ────────────────────────────────────────────
check('манифест не пуст', function () use ($bundles) {
    return array($bundles !== array(), 'AssetBundles::all() вернул пустой список');
});

foreach ($bundles as $bundle => $files) {
    check("[$bundle] все " . count($files) . " файлов существуют", function () use ($ROOT, $files) {
        $missing = array();
        foreach ($files as $rel) {
            if (!is_file($ROOT . '/' . $rel)) {
                $missing[] = $rel;
            }
        }
        return array($missing === array(), 'нет файлов: ' . implode(', ', $missing));
    });

    check("[$bundle] нет дублей в списке", function () use ($files) {
        $dupes = array_keys(array_filter(array_count_values($files), function ($n) {
            return $n > 1;
        }));
        return array($dupes === array(), 'повторяются: ' . implode(', ', $dupes));
    });
}

// ── 2. Шаблоны не подключают бандлируемые ассеты в обход манифеста ────────────
// Какой шаблон какие бандлы подключает. favorites.php и trash.php берут только CSS:
// своих JS у них по три штуки, бандлить там нечего, а dashboard.sync.js им не нужен
// (поэтому toast.js/theme-toggle.js там подключаются поштучно — это не нарушение).
$templateBundles = array(
    'templates/dashboard.php'                        => array('core.css', 'dashboard.sync.js', 'dashboard.defer.js'),
    'templates/favorites.php'                        => array('core.css'),
    'templates/trash.php'                            => array('core.css'),
    'templates/partials/dashboard/init-script.php'   => array('dashboard.sync.js', 'dashboard.defer.js'),
    'templates/partials/dashboard/config-script.php' => array('dashboard.sync.js', 'dashboard.defer.js'),
);

foreach ($templateBundles as $tpl => $used) {
    check("[$tpl] не хардкодит ассеты своих бандлов", function () use ($ROOT, $tpl, $used, $bundles) {
        $src = file_get_contents($ROOT . '/' . $tpl);
        if ($src === false) {
            return array(false, 'файл не читается');
        }
        $bundled = array();
        foreach ($used as $bundle) {
            foreach ($bundles[$bundle] as $rel) {
                $bundled[$rel] = true;
            }
        }
        // Ищем любые src=/href= на assets/js/ и assets/css/ и сверяем с манифестом.
        preg_match_all('~(?:src|href)="(assets/(?:js|css)/[^"?]+)~', $src, $m);
        $hard = array();
        foreach ($m[1] as $path) {
            if (isset($bundled[$path])) {
                $hard[] = $path;
            }
        }
        $hard = array_values(array_unique($hard));
        return array($hard === array(), 'подключены мимо AssetBundles: ' . implode(', ', $hard));
    });
}

// ── 3. 'use strict' не на верхнем уровне JS-файла ─────────────────────────────
foreach ($bundles as $bundle => $files) {
    if (substr($bundle, -3) !== '.js') {
        continue;
    }
    check("[$bundle] ни в одном исходнике нет 'use strict' на верхнем уровне", function () use ($ROOT, $files) {
        $bad = array();
        foreach ($files as $rel) {
            // Директива работает только как ПЕРВАЯ инструкция скрипта, т.е. до неё
            // могут быть только комментарии и пустые строки. Именно такой случай ловим.
            $code = file_get_contents($ROOT . '/' . $rel);
            $stripped = preg_replace('~/\*.*?\*/~s', '', $code);       // блочные комментарии
            $stripped = preg_replace('~^\s*//[^\n]*$~m', '', $stripped); // строчные комментарии
            if (preg_match('~^\s*([\'"])use strict\1~', $stripped)) {
                $bad[] = $rel;
            }
        }
        return array($bad === array(), "директива на верхнем уровне в: " . implode(', ', $bad));
    });
}

// ── 3b. Temporal dead zone: объявление раньше использования ──────────────────
// Самая коварная разница между «35 отдельных <script>» и одним бандлом.
// В отдельных скриптах имя, объявленное через top-level const/let/class в файле N,
// в файле N-1 просто НЕ СУЩЕСТВУЕТ — привычная защита `typeof x !== 'undefined'`
// честно возвращает 'undefined'. В конкатенации все такие объявления попадают в
// temporal dead zone на весь скрипт, и тот же `typeof` уже БРОСАЕТ
// «Cannot access 'x' before initialization», обрывая бандл целиком.
// Ровно так и падала первая версия бандла (logger и domCache грузились после
// dashboard-init.js). Тест ловит регрессию порядка.
//
// Известные ложные срабатывания: имя может встретиться раньше как ЛОКАЛЬНАЯ
// переменная внутри функции — такое затенение безопасно. Проверенные вручную
// случаи перечислены в $tdzAllowed с объяснением.
$tdzAllowed = array(
    // dashboard-init.js:613 — `var selectedIds = window.DashboardSelection.getSelectedIds();`
    // Это локальная переменная внутри функции, а не обращение к глобальной из
    // dashboard-selection.js. Затенение, TDZ не задевает.
    'selectedIds' => 'assets/js/dashboard-init.js',
);

/** Убирает комментарии и строковые литералы, чтобы имена искались только в коде. */
function stripJs($code, $label)
{
    // Развёрнутый шаблон блочного комментария вместо ленивого `.*?`: на файле в
    // 100 КБ ленивый вариант упирается в pcre.backtrack_limit и preg_replace ТИХО
    // отдаёт null. Первая версия этой проверки из-за этого «не нашла» ни одной
    // проблемы — сбой превратился в «всё хорошо». Поэтому ниже ещё и явная проверка.
    $steps = array(
        '~/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s' => '',
        '~(^|[^:\\\\])//[^\n]*~m'           => '$1',
        '~`(?:\\\\.|[^`\\\\])*`~s'          => '``',
        "~'(?:\\\\.|[^'\\\\\n])*'~"         => "''",
        '~"(?:\\\\.|[^"\\\\\n])*"~'         => '""',
    );
    foreach ($steps as $re => $repl) {
        $out = preg_replace($re, $repl, $code);
        if ($out === null) {
            throw new RuntimeException("PCRE не справился с $label (код " . preg_last_error() . ')');
        }
        $code = $out;
    }
    return $code;
}

// JIT PCRE на этих шаблонах упирается в стек (PREG_JIT_STACKLIMIT_ERROR) — отключаем.
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');

foreach ($bundles as $bundle => $files) {
    if (substr($bundle, -3) !== '.js') {
        continue;
    }
    check("[$bundle] top-level const/let/class объявлены раньше использования", function () use ($ROOT, $files, $tdzAllowed) {
        $src = array();
        foreach ($files as $rel) {
            $src[$rel] = stripJs(file_get_contents($ROOT . '/' . $rel), $rel);
        }
        $declaredAt = array(); // имя => индекс файла, где объявлено
        foreach ($files as $i => $rel) {
            if (preg_match_all('~^(?:const|let|class)\s+([A-Za-z_$][\w$]*)~m', $src[$rel], $m)) {
                foreach ($m[1] as $name) {
                    if (!isset($declaredAt[$name])) {
                        $declaredAt[$name] = $i;
                    }
                }
            }
        }
        $problems = array();
        foreach ($declaredAt as $name => $declIdx) {
            foreach ($files as $j => $rel) {
                if ($j >= $declIdx) {
                    break;
                }
                if (isset($tdzAllowed[$name]) && $tdzAllowed[$name] === $rel) {
                    continue;
                }
                // Голое имя: не свойство (.name) и не часть другого идентификатора.
                if (preg_match('~(?<![\w$.])' . preg_quote($name, '~') . '(?![\w$])~', $src[$rel])) {
                    $problems[] = "$name объявлен в {$files[$declIdx]}, но используется раньше в $rel";
                }
            }
        }
        return array($problems === array(), implode('; ', $problems));
    });
}

// ── 4. CSS @import — только в начале первого файла бандла ─────────────────────
foreach ($bundles as $bundle => $files) {
    if (substr($bundle, -4) !== '.css') {
        continue;
    }
    check("[$bundle] @import только в начале первого файла", function () use ($ROOT, $files) {
        $bad = array();
        foreach ($files as $i => $rel) {
            $code = file_get_contents($ROOT . '/' . $rel);
            if (strpos($code, '@import') === false) {
                continue;
            }
            if ($i !== 0) {
                $bad[] = "$rel (не первый файл бандла)";
                continue;
            }
            // В первом файле: до @import не должно быть ни одного правила.
            $before = substr($code, 0, strpos($code, '@import'));
            $before = preg_replace('~/\*.*?\*/~s', '', $before);
            if (trim($before) !== '') {
                $bad[] = "$rel (перед @import есть код)";
            }
        }
        return array($bad === array(), implode('; ', $bad));
    });
}

// ── 5. Сборщик пишет конкатенацию исходников ─────────────────────────────────
$tmp = sys_get_temp_dir() . '/asset-bundles-test-' . getmypid();
rmrf($tmp);

check('tools/build_assets.php отрабатывает с кодом 0', function () use ($ROOT, $tmp) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/tools/build_assets.php')
        . ' --out=' . escapeshellarg($tmp) . ' 2>&1';
    exec($cmd, $out, $code);
    return array($code === 0, "код выхода $code, вывод: " . implode(' | ', $out));
});

foreach ($bundles as $bundle => $files) {
    check("[$bundle] собранный файл = конкатенация исходников", function () use ($ROOT, $tmp, $bundle, $files) {
        $built = $tmp . '/' . $bundle;
        if (!is_file($built)) {
            return array(false, "бандл не создан: $built");
        }
        $got = file_get_contents($built);
        $expected = '';
        foreach ($files as $rel) {
            $expected .= AssetBundles::chunkFor($rel, file_get_contents($ROOT . '/' . $rel));
        }
        if ($got !== $expected) {
            return array(false, 'содержимое не совпало с ожидаемой конкатенацией ('
                . strlen($got) . ' против ' . strlen($expected) . ' байт)');
        }
        return array(true, '');
    });

    check("[$bundle] в бандле есть кусок каждого исходника", function () use ($ROOT, $tmp, $bundle, $files) {
        $got = file_get_contents($tmp . '/' . $bundle);
        $missing = array();
        foreach ($files as $rel) {
            // Берём непустой отрезок из середины файла — устойчивее, чем первая строка
            // (шапки-комментарии у файлов похожи).
            $src = file_get_contents($ROOT . '/' . $rel);
            $probe = substr($src, (int)(strlen($src) / 2), 60);
            if ($probe !== '' && strpos($got, $probe) === false) {
                $missing[] = $rel;
            }
        }
        return array($missing === array(), 'не найдены в бандле: ' . implode(', ', $missing));
    });
}

// ── 6. Хелпер: fallback и режим бандла ───────────────────────────────────────
if (!defined('ASSETS_VERSION')) {
    define('ASSETS_VERSION', 'testver');
}

check('без собранного бандла хелпер отдаёт исходники в порядке манифеста', function () use ($bundles) {
    $bundle = 'dashboard.sync.js';
    $files  = $bundles[$bundle];
    $html   = AssetBundles::tags($bundle, '', '/nonexistent-build-dir');
    $pos    = -1;
    foreach ($files as $rel) {
        $at = strpos($html, $rel . '?v=');
        if ($at === false) {
            return array(false, "в fallback нет $rel");
        }
        if ($at < $pos) {
            return array(false, "порядок нарушен на $rel");
        }
        $pos = $at;
    }
    $count = substr_count($html, '<script src=');
    return array($count === count($files), "тегов $count, ожидали " . count($files));
});

check('с собранным бандлом хелпер отдаёт один тег с ASSETS_VERSION', function () use ($tmp) {
    $html = AssetBundles::tags('dashboard.sync.js', ' defer', $tmp);
    if (substr_count($html, '<script src=') !== 1) {
        return array(false, "ожидали 1 тег, получили " . substr_count($html, '<script src='));
    }
    if (strpos($html, 'assets/build/dashboard.sync.js?v=' . ASSETS_VERSION) === false) {
        return array(false, "нет пути к бандлу с версией: $html");
    }
    if (strpos($html, ' defer') === false) {
        return array(false, 'потерялся атрибут defer');
    }
    return array(true, '');
});

check('CSS-бандл рендерится тегом <link rel="stylesheet">', function () use ($tmp) {
    $html = AssetBundles::tags('core.css', '', $tmp);
    return array(
        substr_count($html, '<link ') === 1 && strpos($html, 'rel="stylesheet"') !== false,
        "получили: $html"
    );
});

check('неизвестный бандл — исключение, а не тихая пустая строка', function () use ($tmp) {
    try {
        AssetBundles::tags('нет-такого.js', '', $tmp);
    } catch (InvalidArgumentException $e) {
        return array(true, '');
    }
    return array(false, 'исключения не было');
});

rmrf($tmp);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);
