<?php
/**
 * Тест: пока пароль редактируется в ячейке, панель «глазик / карандаш /
 * копировать» не садится поверх кнопок сохранения.
 *
 * Запуск:  php tests/test_pw_editor_editing_flag.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Жалоба владельца (15.11.2026): правлю пароль прямо в таблице, хочу нажать
 * синюю галочку «сохранить» — а поверх неё глазик, карандаш и копирование,
 * нажать нельзя. Воспроизведено на стенде (плотность «средняя», настоящая мышь
 * над полем ввода): панель встаёт в ту же ячейку и лежит на галочке и крестике.
 *
 * Причина. Панель действий (modules/cell-actions.js) не садится в ячейку,
 * которая помечена `data-editing="true"`. Редактор обычных полей
 * (modules/inline-edit.js) эту метку ставит, а редактор пароля (ветка `.pw-edit`
 * в dashboard-init.js) — нет. Первое же движение мыши над полем ввода
 * возвращало панель на место — поверх кнопок. Вторая беда той же причины:
 * виртуализация таблицы сохраняет при перерисовке только строки с этой меткой,
 * так что правка пароля могла пропасть при автообновлении.
 *
 * Тест стережёт:
 *   1) редактор пароля ставит метку на обёртку, ячейку и строку;
 *   2) все выходы из редактора (сохранено / ошибка / сбой сети / отмена) идут
 *      через одну функцию, которая метку снимает, — никаких прямых
 *      `pwWrap.innerHTML = originalContent` мимо неё;
 *   3) панель не садится в ячейку, где открыто поле ввода, даже если какой-то
 *      будущий редактор забудет метку (страховка в cell-actions.js).
 *
 * Проверка по тексту кода: инструмента для имитации страницы в проекте нет
 * (новая зависимость — только по согласию владельца). Живое поведение проверено
 * в браузере на стенде.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = dirname(__DIR__);
$failures = 0;
$passed = 0;

function pweCheck($ok, $label)
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failures++;
        echo "  ✗ {$label}\n";
    }
}

/** Комментарии вырезаем, чтобы тест не ловил собственное описание бага в коде. */
function pweStripComments($code)
{
    $out = preg_replace('~/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s', '', $code);
    $out = $out === null ? null : preg_replace('~(^|[^:\\\\])//[^\n]*~m', '$1', $out);
    if ($out === null) {
        throw new RuntimeException('PCRE не справился (код ' . preg_last_error() . ')');
    }
    return $out;
}

$init = pweStripComments(file_get_contents($ROOT . '/assets/js/dashboard-init.js'));

// Ветка редактора пароля: от `var pwEditBtn` до первого `return;` после
// навешивания обработчика клавиш.
$start = strpos($init, "t.closest('.pw-edit')");
pweCheck($start !== false, 'в dashboard-init.js есть ветка редактора пароля');
$branch = '';
if ($start !== false) {
    $end = strpos($init, "input.addEventListener('keydown'", $start);
    $end = $end === false ? false : strpos($init, 'return;', $end);
    $branch = substr($init, $start, $end === false ? 6000 : $end - $start);
}

echo "Редактор пароля ставит метку «редактируется»:\n";
pweCheck(preg_match("~pwWrap\.setAttribute\(\s*'data-editing'\s*,\s*'true'\s*\)~", $branch) === 1,
    'на обёртку пароля');
pweCheck(preg_match("~closest\(\s*'td'\s*\)~", $branch) === 1
    && preg_match("~\w+\.setAttribute\(\s*'data-editing'\s*,\s*'true'\s*\)[\s\S]*\w+\.setAttribute\(\s*'data-editing'\s*,\s*'true'\s*\)[\s\S]*\w+\.setAttribute\(\s*'data-editing'\s*,\s*'true'\s*\)~", $branch) === 1,
    'на ячейку и строку (их бережёт виртуализация при перерисовке)');

echo "Все выходы из редактора снимают метку:\n";
pweCheck(substr_count($branch, 'pwWrap.innerHTML = originalContent') === 1,
    'содержимое ячейки возвращается ровно в одном месте (было пять прямых присваиваний)');
pweCheck(preg_match("~removeAttribute\(\s*'data-editing'\s*\)~", $branch) === 1,
    'это место снимает метку');

$cell = pweStripComments(file_get_contents($ROOT . '/assets/js/modules/cell-actions.js'));
$mo = strpos($cell, 'function onMouseOver');
$moBody = $mo === false ? '' : substr($cell, $mo, 1500);

echo "Страховка в панели действий:\n";
pweCheck(preg_match("~querySelector\(\s*'input,\s*textarea,\s*select'\s*\)~", $moBody) === 1,
    'панель не садится в ячейку, где открыто поле ввода');

echo "\n{$passed} прошло, {$failures} упало\n";
exit($failures > 0 ? 1 : 0);
