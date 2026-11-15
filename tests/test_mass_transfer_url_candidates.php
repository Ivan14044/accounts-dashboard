<?php
/**
 * Массовый перенос: медленный поиск по social_url не гоняется по «мусорным»
 * числам из строк, где аккаунт уже найден точным поиском.
 *
 * Зачем. Строка, которую вставляют в окно переноса, обычно выглядит как
 * `логин|пароль|https://www.facebook.com/profile.php?id=…|куки…` и даёт 3+
 * кандидата (логин, id из ссылки, длинные числа из кук). Аккаунт находится
 * точным поиском по одному из них, а остальные кандидаты раньше считались
 * «ненайденными» и уходили в поиск `social_url LIKE '%…%'` — полный проход по
 * таблице на каждые 50 штук. На проде 21.10.2026 перенос 2101 аккаунта
 * (6479 кандидатов) шёл 253 с; на стенде (185 000 строк) 300 строк такого
 * вида — 18,4 с, из них ~12 с — 12 проходов LIKE, которые не нашли НИЧЕГО.
 *
 * Правило: в поиск по social_url идут только кандидаты из строк, в которых
 * точный поиск не нашёл ни одного аккаунта. Строки, где не нашлось ничего,
 * ищутся по ссылке как раньше.
 *
 * Без БД: parseText() и selectUrlSearchCandidates() — чистые.
 */
require_once __DIR__ . '/../includes/MassTransferService.php';

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$failures = 0;
function check($name, $cond, $detail = '') {
    global $failures;
    if ($cond) {
        echo "  ✓ $name\n";
    } else {
        $failures++;
        echo "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "Массовый перенос: кандидаты для поиска по ссылке\n";

// Конструктор ходит в базу — для чистых методов он не нужен
$svc = (new ReflectionClass('MassTransferService'))->newInstanceWithoutConstructor();

$text = implode("\n", [
    // 1: найдётся по id из ссылки; логин и число из кук — мусор
    '4915212806074|pw1|https://www.facebook.com/profile.php?id=100012345678901|1699999999999123',
    // 2: ничего не найдётся точно — должна пойти в поиск по ссылке целиком
    '4915000000002|pw2|https://www.facebook.com/profile.php?id=100099999999999|1688888888888123',
    // 3: найдётся по логину
    '4915333333333|pw3|1677777777777123',
    // 4: дубль кандидата из строки 1 в строке, где больше ничего не нашлось
    'https://www.facebook.com/1699999999999123',
]);

$parsed = $svc->parseText($text);
check('parseText отдаёт кандидатов по строкам', isset($parsed['line_tokens']) && count($parsed['line_tokens']) === 4,
    isset($parsed['line_tokens']) ? 'строк: ' . count($parsed['line_tokens']) : 'нет line_tokens');
check('общий список кандидатов не изменился', in_array('100012345678901', $parsed['ids'], true)
    && in_array('4915212806074', $parsed['ids'], true) && in_array('1699999999999123', $parsed['ids'], true));

// Точный поиск нашёл строки 1 (по id) и 3 (по логину)
$matched = ['100012345678901' => true, '4915333333333' => true];
$notFound = array_values(array_filter($parsed['ids'], function ($id) use ($matched) {
    return !isset($matched[$id]);
}));

$candidates = MassTransferService::selectUrlSearchCandidates($notFound, $matched, $parsed['line_tokens']);
sort($candidates);

check('мусор из строки, где аккаунт уже найден, в поиск по ссылке не идёт',
    !in_array('4915212806074', $candidates, true), json_encode($candidates));
check('мусор из строки, найденной по логину, тоже не идёт',
    !in_array('1677777777777123', $candidates, true), json_encode($candidates));
check('строка без единого совпадения ищется по ссылке целиком',
    in_array('100099999999999', $candidates, true) && in_array('4915000000002', $candidates, true)
    && in_array('1688888888888123', $candidates, true), json_encode($candidates));
check('кандидат, который встречается и в найденной, и в ненайденной строке, ищется',
    in_array('1699999999999123', $candidates, true), json_encode($candidates));

// Без разбивки по строкам (старый вызов) — поведение прежнее: ищутся все ненайденные
$legacy = MassTransferService::selectUrlSearchCandidates($notFound, $matched, null);
check('без разбивки по строкам ищутся все ненайденные, как раньше', $legacy === $notFound);

// Совпадение из базы может отличаться регистром (коллация без учёта регистра)
$parsedHex = $svc->parseText("61571235444abc|x|100055555555555");
$candHex = MassTransferService::selectUrlSearchCandidates(['100055555555555'], ['61571235444ABC' => true], $parsedHex['line_tokens']);
check('совпадение без учёта регистра тоже считается найденной строкой', $candHex === [], json_encode($candHex));

// Пустые входы
check('пустой список ненайденных → пусто', MassTransferService::selectUrlSearchCandidates([], $matched, $parsed['line_tokens']) === []);

echo $failures ? "\n$failures failed\n" : "\nOK\n";
exit($failures ? 1 : 0);
