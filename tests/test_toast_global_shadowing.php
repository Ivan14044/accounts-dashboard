<?php
/**
 * Тест: обращения к тостам идут через window.Toast, а не через голое имя Toast.
 *
 * Запуск:  php tests/test_toast_global_shadowing.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-08-09 живой проверкой отмены на стенде):
 * в assets/js/toast.js объявлены ДВЕ разные сущности с почти одним именем —
 *   class Toast { ... }      // top-level class = глобальная лексическая привязка
 *   window.Toast = new Toast();  // экземпляр с методами success/error/warning/info
 * Голое имя `Toast` в любом другом файле резолвится в КЛАСС и затеняет
 * window.Toast, потому что лексическая привязка приоритетнее свойства window.
 * У класса нет статических success/error/... — вызов падает с
 * «Toast.error is not a function».
 *
 * Что это ломало на самом деле: modules/undo.js после УСПЕШНОЙ отмены вызывал
 * `Toast.success(...)`, падал на этой строке, улетал в catch, там падал ещё раз на
 * `Toast.error(...)` — и до `refreshDashboardData()` дело не доходило. Отмена в БД
 * происходила, а пользователь не видел ни тоста, ни обновлённой таблицы.
 * От бандлинга это НЕ зависит: проверено в браузере и на бандле, и на отдельных
 * файлах — `Toast === window.Toast` там и там false.
 *
 * Тест смотрит только на реальный код: комментарии и строковые литералы вырезаются.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

// JIT PCRE упирается в стек на шаблонах строковых литералов — отключаем.
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/** Вырезает комментарии и строковые литералы. */
function stripJsCode($code, $label)
{
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
            // Молчаливый null от preg_replace однажды уже превратил сбой проверки
            // в «всё хорошо» — поэтому падаем громко.
            throw new RuntimeException("PCRE не справился с $label (код " . preg_last_error() . ')');
        }
        $code = $out;
    }
    return $code;
}

echo "\n=== Toast: обращение только через window.Toast ===\n\n";

$files = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/assets/js'));
foreach ($it as $f) {
    if ($f->isFile() && substr($f->getFilename(), -3) === '.js') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$bad = array();
foreach ($files as $abs) {
    $rel = ltrim(str_replace($ROOT, '', $abs), '/');
    // Сам toast.js объявляет класс и создаёт экземпляр — ему можно.
    if ($rel === 'assets/js/toast.js') {
        continue;
    }
    $code = stripJsCode(file_get_contents($abs), $rel);
    // Голое `Toast.` или `Toast[` — не как свойство (window.Toast) и не часть
    // другого идентификатора (SomeToast, ToastManager).
    if (preg_match_all('~(?<![\w$.])Toast\s*[\.\[]~', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $line = substr_count(substr($code, 0, $hit[1]), "\n") + 1;
            $bad[] = "$rel:$line";
        }
    }
}

if ($bad === array()) {
    $passed++;
    echo "  [OK]   ни один модуль не обращается к тостам по голому имени Toast\n";
} else {
    $failures++;
    echo "  [FAIL] голое имя Toast (это КЛАСС, а не экземпляр) в: " . implode(', ', $bad) . "\n";
}

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);
