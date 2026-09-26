<?php
/**
 * Тест точного отбора почт под чистку (EmailPurgePlanner).
 *
 * Падает на наивной логике «строка содержит домен» и проходит на строгом
 * сравнении по домену адреса. Самодостаточен: без БД, без сети.
 */

// Warning/Notice = падение, как на проде (error_reporting=E_ALL).
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/EmailPurgePlanner.php';

$failed = 0;
function check($cond, $msg) {
    global $failed;
    if ($cond) {
        echo "  ok: $msg\n";
    } else {
        echo "  FAIL: $msg\n";
        $failed++;
    }
}

$domains = EmailPurgePlanner::normalizeDomains([
    'scalomail.com', ' Cholecystolimail.com ', 'APOMMAIL.COM', 'scalomail.com',
]);
check(count($domains) === 3, 'normalizeDomains убирает регистр/пробелы/дубли (3 из 4)');

// ── Совпадает: адрес именно на этом домене ──
check(EmailPurgePlanner::matches('john@scalomail.com', $domains), 'обычный адрес на домене');
check(EmailPurgePlanner::matches('  John@Scalomail.COM  ', $domains), 'регистр и пробелы адреса');
check(EmailPurgePlanner::matches('a@apommail.com', $domains), 'второй домен списка');

// ── НЕ совпадает: ловушки, на которых наивный поиск снёс бы чужой ящик ──
check(!EmailPurgePlanner::matches('x@sub.scalomail.com', $domains), 'поддомен НЕ трогаем');
check(!EmailPurgePlanner::matches('scalomail.com@gmail.com', $domains), 'домен в ИМЕНИ ящика НЕ трогаем');
check(!EmailPurgePlanner::matches('user@notscalomail.com', $domains), 'домен как суффикс чужого НЕ трогаем');
check(!EmailPurgePlanner::matches('user@scalomail.company.com', $domains), 'домен как префикс чужого НЕ трогаем');
check(!EmailPurgePlanner::matches('', $domains), 'пустой адрес');
check(!EmailPurgePlanner::matches(null, $domains), 'null-адрес');
check(!EmailPurgePlanner::matches('no-at-sign', $domains), 'адрес без @');
check(!EmailPurgePlanner::matches('user@othermail.com', $domains), 'адрес на постороннем домене');

// ── emailDomain: берём часть после ПОСЛЕДНЕЙ @ ──
check(EmailPurgePlanner::emailDomain('weird@name@scalomail.com') === 'scalomail.com', 'две @ — домен по последней');
check(EmailPurgePlanner::emailDomain('nodomain') === null, 'без @ → null');

// ── chunk: пачки по первичному ключу ──
$chunks = EmailPurgePlanner::chunk([1, 2, 3, 4, 5], 2);
check(count($chunks) === 3 && $chunks[2] === [5], 'chunk бьёт [1..5] по 2 в три пачки');
check(EmailPurgePlanner::chunk([], 500) === [], 'пустой список → нет пачек');

if ($failed > 0) {
    echo "\nПРОВАЛЕНО тестов: $failed\n";
    exit(1);
}
echo "\nВсе проверки прошли.\n";
exit(0);
