<?php
/**
 * Тест чистой классификации AccountValidationService::classifyItems().
 *
 * Запуск:  php tests/test_validation_classify.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД — проверяем только логику valid / invalid / errored,
 * включая регрессию бага «при сбое API валидные аккаунты уезжают в invalid».
 */

require_once __DIR__ . '/../includes/AccountValidationService.php';

$failures = 0;
$passed   = 0;

/**
 * @param string $name
 * @param array  $items   как из prepareItems
 * @param array  $results [fb_id => bool]; отсутствие ключа = «неизвестно»
 * @param array  $expect  ['valid'=>[ids], 'invalid'=>[ids], 'errored'=>[ids]]
 */
function check(string $name, array $items, array $results, array $expect): void
{
    global $failures, $passed;

    $res = AccountValidationService::classifyItems($items, $results);

    $ids = function (array $bucket): array {
        $out = array_map(static fn($e) => (int)$e['id'], $bucket);
        sort($out);
        return $out;
    };

    $ok = true;
    foreach (['valid', 'invalid', 'errored'] as $b) {
        $got  = $ids($res[$b] ?? []);
        $want = $expect[$b] ?? [];
        sort($want);
        if ($got !== $want) {
            $ok = false;
            fwrite(STDERR, sprintf(
                "  ✗ %s — bucket '%s': ожидалось [%s], получено [%s]\n",
                $name, $b, implode(',', $want), implode(',', $got)
            ));
        }
    }

    if ($ok) { $passed++; echo "  ✓ $name\n"; }
    else     { $failures++; }
}

echo "Тест classifyItems():\n";

// 1. Один FB ID, активен → valid
check('один true → valid',
    [['id' => 1, 'login' => 'a', 'fb_ids' => ['100']]],
    ['100' => true],
    ['valid' => [1]]);

// 2. Один FB ID, точно невалиден → invalid
check('один false → invalid',
    [['id' => 2, 'login' => 'b', 'fb_ids' => ['200']]],
    ['200' => false],
    ['invalid' => [2]]);

// 3. Один FB ID, ответа нет (батч упал) → errored, НЕ invalid
check('один unknown → errored',
    [['id' => 3, 'login' => 'c', 'fb_ids' => ['300']]],
    [], // 300 отсутствует в результатах
    ['errored' => [3]]);

// 4. Несколько ID, один активен → valid (даже если другой false)
check('false + true → valid',
    [['id' => 4, 'login' => 'd', 'fb_ids' => ['401', '402']]],
    ['401' => false, '402' => true],
    ['valid' => [4]]);

// 5. Несколько ID, все невалидны → invalid
check('все false → invalid',
    [['id' => 5, 'login' => 'e', 'fb_ids' => ['501', '502']]],
    ['501' => false, '502' => false],
    ['invalid' => [5]]);

// 6. РЕГРЕССИЯ БАГА: один false + один не проверен → errored, НЕ invalid
check('false + unknown → errored (не invalid!)',
    [['id' => 6, 'login' => 'f', 'fb_ids' => ['601', '602']]],
    ['601' => false], // 602 отсутствует
    ['errored' => [6]]);

// 7. int FB ID в item против строкового ключа в results → должны совпасть
check('int id vs string key → совпадение',
    [['id' => 7, 'login' => 'g', 'fb_ids' => [700]]],
    ['700' => true],
    ['valid' => [7]]);

// 8. Нет FB ID → не попадает ни в один bucket (это skipped уровнем выше)
check('без fb_ids → нигде',
    [['id' => 8, 'login' => 'h', 'fb_ids' => []]],
    [],
    []);

// 9. Смешанная партия: valid + invalid + errored одновременно
check('смешанная партия',
    [
        ['id' => 11, 'login' => 'v', 'fb_ids' => ['1100']],          // valid
        ['id' => 12, 'login' => 'i', 'fb_ids' => ['1200']],          // invalid
        ['id' => 13, 'login' => 'u', 'fb_ids' => ['1300']],          // errored (unknown)
        ['id' => 14, 'login' => 'm', 'fb_ids' => ['1401', '1402']],  // errored (false+unknown)
    ],
    ['1100' => true, '1200' => false, '1401' => false],
    ['valid' => [11], 'invalid' => [12], 'errored' => [13, 14]]);

echo "\nИтог: {$passed} прошло, {$failures} упало\n";
exit($failures === 0 ? 0 : 1);
