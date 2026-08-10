<?php
/**
 * Тест: виртуализация таблицы не копит обработчики scroll/resize.
 *
 * Запуск:  php tests/test_virtualization_listeners.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (замерено в браузере 2026-08-10).
 *
 * assets/js/modules/dashboard-refresh.js зовёт tableModule.updateRows() при
 * КАЖДОМ обновлении таблицы: смена фильтра, сортировка, пагинация, автообновление
 * раз в 30 секунд. При per_page > 50 это уходит в enableFromData(), которая
 * вешала scroll на #tableWrap и resize на window безусловно. Снимал их только
 * disable(), которого перед повторным включением никто не звал.
 *
 * Замер в браузере (per_page=100, обёрнут addEventListener на реальных объектах):
 *   после 1 обновления — 1 scroll, 1 resize
 *   после 4 обновлений — 4 scroll, 4 resize, снятых ноль
 * То есть +2 слушателя каждые полминуты бессрочно, и каждый на каждом кадре
 * скролла дёргает updateVisibleRows(). Это ровно та «дёрганность» таблицы, с
 * которой начинался разбор.
 *
 * Инвариант: любой путь, который вешает эти слушатели, обязан сперва снять
 * прошлые. Проверяем это по коду — счётчик слушателей из PHP не измерить,
 * живой замер сделан в браузере и приложен к PR.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/**
 * Фиксирует результат проверки.
 *
 * @param string $name Что проверяли
 * @param bool $ok Прошло ли
 * @param string $detail Подробности при провале
 * @return void
 */
function vlCheck($name, $ok, $detail = '')
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Тело метода класса из JS с вырезанными комментариями.
 *
 * @param string $code Исходник без комментариев
 * @param string $name Имя метода
 * @return string Тело в фигурных скобках или ''
 */
function vlMethodBody($code, $name)
{
    if (!preg_match('~(?<![\w$.])' . preg_quote($name, '~') . '\s*\([^)]*\)\s*\{~', $code, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $open = strpos($code, '{', $m[0][1]);
    $depth = 0;
    for ($i = $open, $n = strlen($code); $i < $n; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }
    }
    return '';
}

echo "\n=== Виртуализация: слушатели не копятся ===\n\n";

$src = file_get_contents($ROOT . '/assets/js/table-module.js');
// Комментарии вырезаем, иначе проверки поймают их текст, а не код.
$code = preg_replace('~/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s', '', $src);
$code = preg_replace('~(^|[^:\\\\])//[^\n]*~m', '$1', $code);

vlCheck('исходник виртуализации прочитан', strlen($code) > 1000);

// Общий помощник снятия обязан существовать: два независимых снятия рядом —
// это будущее расхождение, ровно как было с условием автоочистки корзины.
vlCheck(
    'есть общий метод снятия слушателей detachScrollListeners()',
    strpos($code, 'detachScrollListeners') !== false,
    'снятие должно быть одно на все пути включения'
);

foreach (array('enable', 'enableFromData') as $method) {
    $body = vlMethodBody($code, $method);
    vlCheck("метод $method() найден", $body !== '');
    if ($body === '') {
        continue;
    }

    $attachAt = strpos($body, 'addEventListener');
    $detachAt = strpos($body, 'detachScrollListeners');

    vlCheck(
        "$method() вешает слушатели (иначе тест бессмысленен)",
        $attachAt !== false,
        'addEventListener не найден — метод переписали?'
    );
    vlCheck(
        "$method() снимает прошлые слушатели ДО того, как навесить новые",
        $detachAt !== false && $attachAt !== false && $detachAt < $attachAt,
        $detachAt === false
            ? 'снятия нет вовсе — слушатели будут копиться при каждом обновлении таблицы'
            : 'снятие стоит ПОСЛЕ привязки, то есть снимает только что навешенное'
    );
}

// disable() должен пользоваться тем же помощником, а не своей копией
$disable = vlMethodBody($code, 'disable');
vlCheck('метод disable() найден', $disable !== '');
vlCheck(
    'disable() снимает слушатели тем же общим методом',
    strpos($disable, 'detachScrollListeners') !== false,
    'своя копия снятия рядом с общей — будущее расхождение'
);
vlCheck(
    'нигде не осталось второй копии removeEventListener для scroll',
    substr_count($code, "removeEventListener('scroll'") <= 1,
    'снятие scroll написано более одного раза'
);

/*
 * Отдельная проверка на грабли, в которые я едва не уехал при этой же правке.
 *
 * Вынося общий метод, легко подменить его собственное тело вызовом самого себя —
 * получится бесконечная рекурсия. Синтаксис при этом валиден, `node --check`
 * молчит, и падение случится только в браузере при первом же обновлении таблицы.
 */
$detach = vlMethodBody($code, 'detachScrollListeners');
vlCheck('метод detachScrollListeners() найден', $detach !== '');
vlCheck(
    'detachScrollListeners() не вызывает сам себя',
    strpos($detach, 'detachScrollListeners') === false,
    'рекурсия: метод подменён вызовом самого себя, это стек оверфлоу в браузере'
);
vlCheck(
    'detachScrollListeners() действительно снимает оба слушателя',
    strpos($detach, "removeEventListener('scroll'") !== false
        && strpos($detach, "removeEventListener('resize'") !== false,
    'тело пустое или снимает не всё'
);

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);
