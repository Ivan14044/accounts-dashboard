<?php
/**
 * Тест: список статусов — закреплённые, недавние, «только этот» и две
 * починенные поломки. Проверяются инварианты разметки и связей между
 * файлами; сама логика (поиск, разделы, хранение) — в
 * tests/status-filter.test.cjs, живое поведение — на стенде в браузере.
 *
 * Запуск:  php tests/test_status_filter_markup.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * ── Зачем (проверено на рабочей панели 22.09.2026, только чтение) ──
 *
 * 1. Кнопки «Все» и «Очистить» в списке ставили/снимали галочки и меняли
 *    надпись («Все выбраны» / «Все статусы»), но фильтр не применяли: ни
 *    одного запроса refresh.php, URL и таблица прежние. Галочки менялись
 *    присваиванием .checked, а оно событие change не порождает — применял
 *    фильтр только обработчик change в filters-modern.js.
 * 2. После обновления таблицы число у «Пустой статус» становилось 0 (было
 *    436): dashboard-refresh.js искал data.byStatus['__empty__'], а сервер
 *    кладёт пустой статус под ключ '' (StatisticsService склеивает NULL и '').
 * 3. Логика списка жила в двух местах: обработчики в dashboard-init.js и
 *    применение фильтра в filters-modern.js; подпись на кнопке считалась
 *    трижды (шаблон, dashboard-init.js, filters-modern.js) и расходилась.
 *    Теперь список ведёт один модуль assets/js/modules/status-filter.js.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/**
 * @param string   $name
 * @param callable $fn возвращает array(ok, сообщение при провале)
 */
function sfCheck($name, callable $fn)
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

/**
 * Прочитать файл проекта. Отсутствие файла — провал проверки, а не Warning.
 *
 * @param string $rel путь от корня
 * @return string
 */
function sfSource($rel)
{
    global $ROOT;
    $path = $ROOT . '/' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("нет файла $rel");
    }
    return (string)file_get_contents($path);
}

/**
 * Убрать из JS комментарии (строки оставляем: по ним ищем id и ключи).
 *
 * @param string $code
 * @return string
 */
function sfStripJsComments($code)
{
    $out = preg_replace('~/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s', '', $code);
    if ($out === null) {
        throw new RuntimeException('PCRE не справился с комментариями');
    }
    $out = preg_replace('~(^|[^:\\\\])//[^\n]*~m', '$1', $out);
    if ($out === null) {
        throw new RuntimeException('PCRE не справился с комментариями');
    }
    return $out;
}

/**
 * Тело функции `function <name>(...) { ... }` по подсчёту фигурных скобок.
 * Вызывать на коде без комментариев.
 *
 * @param string $code
 * @param string $name
 * @return string|null
 */
function sfFunctionBody($code, $name)
{
    if (!preg_match('~function\s+' . preg_quote($name, '~') . '\s*\([^)]*\)\s*\{~', $code, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]) - 1;
    $depth = 0;
    for ($i = $start, $len = strlen($code); $i < $len; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

echo "\n=== Список статусов: закреплённые, недавние, «только этот» ===\n\n";

// ── Подпись на кнопке: сервер и браузер считают её одинаково ────────────────
require_once $ROOT . '/includes/Utils.php';

sfCheck('status_filter_label(): один статус — его имя, иначе число', function () {
    if (!function_exists('status_filter_label')) {
        return array(false, 'нет функции status_filter_label() в includes/Utils.php');
    }
    // Те же случаи, что в tests/status-filter.test.cjs для labelFor().
    $cases = array(
        array(array(), false, 'Все статусы'),
        array(array('perechek_new'), false, 'perechek_new'),
        array(array(), true, 'Пустой статус'),
        array(array('king', 'captcha'), false, 'Выбрано: 2'),
        array(array('king'), true, 'Выбрано: 2'),
    );
    foreach ($cases as $c) {
        $got = status_filter_label($c[0], $c[1]);
        if ($got !== $c[2]) {
            return array(false, 'для ' . json_encode($c[0], JSON_UNESCAPED_UNICODE) . ($c[1] ? '+пустой' : '')
                . " ждали «{$c[2]}», получили «{$got}»");
        }
    }
    return array(true);
});

// ── Разметка списка ─────────────────────────────────────────────────────────
$tplRel = 'templates/partials/dashboard/filters.php';

sfCheck('подпись кнопки в шаблоне берётся из status_filter_label()', function () use ($tplRel) {
    $tpl = sfSource($tplRel);
    return array(strpos($tpl, 'status_filter_label(') !== false,
        "в $tplRel подпись #statusDropdownLabel считается вручную");
});

sfCheck('у каждого статуса есть кнопка-булавка с понятной подписью', function () use ($tplRel) {
    $tpl = sfSource($tplRel);
    if (!preg_match('~foreach\s*\(\s*\$statuses\s+as\s+(?:\$\w+\s*=>\s*)?\$st\s*\)\s*:(.*?)endforeach;~s', $tpl, $m)) {
        return array(false, "не нашёл цикл по \$statuses в $tplRel");
    }
    $row = $m[1];
    $need = array(
        'class="status-pin-btn"'            => 'кнопка status-pin-btn',
        'type="button"'                     => 'type="button" (иначе кнопка отправит форму)',
        'aria-pressed="false"'              => 'aria-pressed для экранного диктора',
        'aria-label="Закрепить статус <?= e($st) ?>"' => 'aria-label с именем статуса',
        'class="status-name"'               => 'имя статуса в span.status-name (по нему ищет поиск)',
        'data-status-value="<?= e($st) ?>"' => 'data-status-value у строки',
    );
    foreach ($need as $needle => $what) {
        if (strpos($row, $needle) === false) {
            return array(false, "в строке статуса нет: $what");
        }
    }
    return array(true);
});

sfCheck('в списке есть разделы «Закреплённые», «Недавние», «Все статусы» и живой регион', function () use ($tplRel) {
    $tpl = sfSource($tplRel);
    foreach (array('pinned', 'recent', 'all') as $section) {
        if (strpos($tpl, 'data-status-section="' . $section . '"') === false) {
            return array(false, "нет раздела data-status-section=\"$section\"");
        }
    }
    if (!preg_match('~id="statusPinLive"[^>]*aria-live="polite"|aria-live="polite"[^>]*id="statusPinLive"~', $tpl)) {
        return array(false, 'нет #statusPinLive с aria-live="polite" — диктор не узнает о закреплении');
    }
    return array(true);
});

// ── Один владелец у списка ──────────────────────────────────────────────────
sfCheck('dashboard-init.js больше не ведёт список статусов', function () {
    $code = sfStripJsComments(sfSource('assets/js/dashboard-init.js'));
    foreach (array('markFiltersAsChanged', 'selectAllStatusesBtn', 'clearAllStatusesBtn', "'statusSearch'", 'applyStatusFilter') as $needle) {
        if (strpos($code, $needle) !== false) {
            return array(false, "в dashboard-init.js осталось «{$needle}» — у списка должен быть один владелец");
        }
    }
    return array(true);
});

$moduleRel = 'assets/js/modules/status-filter.js';

sfCheck('«Все» и «Очистить» применяют фильтр, а не только ставят галочки', function () use ($moduleRel) {
    $code = sfStripJsComments(sfSource($moduleRel));
    $body = sfFunctionBody($code, 'setAllStatuses');
    if ($body === null) {
        return array(false, "в $moduleRel нет функции setAllStatuses()");
    }
    if (!preg_match('~\bapplyNow\s*\(~', $body)) {
        return array(false, 'setAllStatuses() меняет галочки, но не зовёт applyNow() — фильтр не применится');
    }
    $apply = sfFunctionBody($code, 'applyNow');
    if ($apply === null || strpos($apply, 'applyFormFiltersWithoutReload') === false) {
        return array(false, 'applyNow() должна применять фильтр через applyFormFiltersWithoutReload');
    }
    foreach (array('selectAllStatusesBtn', 'clearAllStatusesBtn') as $id) {
        // id может стоять и в getElementById('…'), и в селекторе closest('#…').
        if (!preg_match("~'#?" . $id . "'~", $code)) {
            return array(false, "модуль не подписан на кнопку #$id");
        }
    }
    return array(true);
});

sfCheck('ключ хранения — из constants.js, своей копии в модуле нет', function () use ($moduleRel) {
    $constants = sfSource('assets/js/modules/constants.js');
    if (!preg_match("~window\.LS_KEY_STATUS_QUICK\s*=\s*'[^']+'~", $constants)) {
        return array(false, 'в constants.js нет window.LS_KEY_STATUS_QUICK');
    }
    $code = sfStripJsComments(sfSource($moduleRel));
    if (strpos($code, 'LS_KEY_STATUS_QUICK') === false) {
        return array(false, 'модуль не читает window.LS_KEY_STATUS_QUICK');
    }
    if (preg_match("~'dashboard_status_quick~", $code)) {
        return array(false, 'модуль держит свою копию ключа — разъедется с constants.js');
    }
    return array(true);
});

// ── Счётчики и недавние после обновления таблицы ────────────────────────────
sfCheck('dashboard-refresh.js отдаёт счётчики списка модулю (там исправлен «Пустой статус»)', function () {
    $code = sfStripJsComments(sfSource('assets/js/modules/dashboard-refresh.js'));
    if (strpos($code, "'.status-count'") !== false) {
        return array(false, 'в dashboard-refresh.js свой цикл по .status-count — там и был ноль у «Пустой статус»');
    }
    if (!preg_match('~DashboardStatusFilter\.updateCounts\s*\(~', $code)) {
        return array(false, 'dashboard-refresh.js не зовёт DashboardStatusFilter.updateCounts()');
    }
    if (strpos($code, 'dashboard:data-applied') !== false) {
        return array(false, 'событие dashboard:data-applied дублирует подписку onAfterRefresh');
    }
    // Недавние пишутся через уже существующую подписку onAfterRefresh (второй
    // механизм «данные обновились» не заводим): подписчик должен узнать, легли
    // ли данные на экран и с какими параметрами ушёл запрос.
    if (!preg_match('~notifyAfterRefresh\s*\(\s*\{[^}]*\bapplied\b[^}]*\bsearch\b~', $code)) {
        return array(false, 'notifyAfterRefresh() не передаёт {applied, search} — недавние не отличат успех от отмены');
    }
    if (!preg_match('~applyDataToDom\s*\(\s*\)\s*;\s*applied\s*=\s*true~', $code)) {
        return array(false, 'applied должен ставиться сразу после applyDataToDom(), а не раньше');
    }
    return array(true);
});

sfCheck('недавние пишутся через DashboardRefresh.onAfterRefresh и только для легших на экран данных', function () use ($moduleRel) {
    $code = sfStripJsComments(sfSource($moduleRel));
    if (!preg_match('~DashboardRefresh\.onAfterRefresh\s*\(\s*function\s*\(\s*info\s*\)\s*\{[^}]*info\.applied~', $code)) {
        return array(false, 'модуль должен подписаться на onAfterRefresh и проверять info.applied');
    }
    if (strpos($code, 'dashboard:data-applied') !== false) {
        return array(false, 'второй механизм «данные обновились» (событие dashboard:data-applied) — дубль подписки');
    }
    return array(true);
});

sfCheck('filters-modern.js не считает подпись кнопки сам', function () {
    $code = sfStripJsComments(sfSource('assets/js/filters-modern.js'));
    if (strpos($code, "'Выбрано: '") !== false) {
        return array(false, "в filters-modern.js своя подпись «Выбрано: N» — разойдётся с модулем");
    }
    if (!preg_match('~DashboardStatusFilter\.updateLabel\s*\(~', $code)) {
        return array(false, 'syncFormFromUrl() должна звать DashboardStatusFilter.updateLabel()');
    }
    return array(true);
});

// ── Подключение ─────────────────────────────────────────────────────────────
sfCheck('модуль подключён в dashboard.sync.js раньше dashboard-main.js', function () use ($ROOT, $moduleRel) {
    require_once $ROOT . '/includes/AssetBundles.php';
    $files = AssetBundles::files('dashboard.sync.js');
    $mod  = array_search($moduleRel, $files, true);
    $main = array_search('assets/js/modules/dashboard-main.js', $files, true);
    if ($mod === false) {
        return array(false, "$moduleRel нет в манифесте dashboard.sync.js");
    }
    return array($main !== false && $mod < $main, 'модуль должен загружаться раньше dashboard-main.js, который его запускает');
});

sfCheck('dashboard-main.js запускает модуль', function () {
    $code = sfStripJsComments(sfSource('assets/js/modules/dashboard-main.js'));
    return array((bool)preg_match('~DashboardStatusFilter\.init\s*\(~', $code), 'нет вызова DashboardStatusFilter.init()');
});

echo "\nИтого: пройдено $passed, упало $failures\n";
exit($failures > 0 ? 1 : 0);
