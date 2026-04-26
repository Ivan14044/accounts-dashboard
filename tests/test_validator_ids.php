<?php
/**
 * Тесты для Validator::validateIds()
 * Проверяют корректную работу с массивами ID разных размеров.
 *
 * Запуск: php tests/test_validator_ids.php
 */

// Минимальный бутстрап — Logger нужен для Validator
require_once __DIR__ . '/../includes/Logger.php';
require_once __DIR__ . '/../includes/Validator.php';

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $failMsg = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failed++;
        echo "  [FAIL] $name" . ($failMsg ? " — $failMsg" : '') . "\n";
    }
}

function assert_throws(string $name, callable $fn, string $expectedMsg = '') {
    global $passed, $failed;
    try {
        $fn();
        $failed++;
        echo "  [FAIL] $name — ожидалось исключение, но ничего не брошено\n";
    } catch (InvalidArgumentException $e) {
        if ($expectedMsg && strpos($e->getMessage(), $expectedMsg) === false) {
            $failed++;
            echo "  [FAIL] $name — неверное сообщение: '{$e->getMessage()}', ожидалось '$expectedMsg'\n";
        } else {
            $passed++;
            echo "  [OK]   $name\n";
        }
    }
}

echo "\n=== Validator::validateIds — Базовые тесты ===\n\n";

// 1. Пустой массив
assert_throws('Пустой массив бросает исключение', function () {
    Validator::validateIds([]);
}, 'IDs are required');

// 2. Один ID
$result = Validator::validateIds([42]);
assert_test('Один ID → [42]', $result === [42]);

// 3. Дубликаты
$result = Validator::validateIds([1, 2, 2, 3, 3, 3]);
assert_test('Дубликаты удаляются', count($result) === 3);

// 4. Строковые ID
$result = Validator::validateIds(['1', '2', '3']);
assert_test('Строковые ID конвертируются', $result === [1, 2, 3]);

// 5. CSV-строка
$result = Validator::validateIds('10, 20, 30');
assert_test('CSV строка парсится', $result === [10, 20, 30]);

// 6. Невалидные ID пропускаются
$result = Validator::validateIds([1, -5, 'abc', 0, 7]);
assert_test('Невалидные ID пропускаются', $result === [1, 7]);

// 7. Все ID невалидные
assert_throws('Все невалидные → исключение', function () {
    Validator::validateIds([-1, 0, 'abc']);
}, 'No valid IDs found');

echo "\n=== Validator::validateIds — Лимиты ===\n\n";

// 8. Ровно 1000 ID (старый лимит) — должно работать
$ids1000 = range(1, 1000);
$result = Validator::validateIds($ids1000);
assert_test('1000 ID — без ошибки', count($result) === 1000);

// 9. 1001 ID — должно работать (лимит теперь 50000)
$ids1001 = range(1, 1001);
$result = Validator::validateIds($ids1001);
assert_test('1001 ID — без ошибки (новый лимит 50000)', count($result) === 1001);

// 10. 2550 ID — типичный кейс из бага на скриншоте
$ids2550 = range(1, 2550);
$result = Validator::validateIds($ids2550);
assert_test('2550 ID — кейс из бага', count($result) === 2550);

// 11. 5000 ID
$ids5000 = range(1, 5000);
$result = Validator::validateIds($ids5000);
assert_test('5000 ID — работает', count($result) === 5000);

// 12. 10000 ID
$ids10k = range(1, 10000);
$result = Validator::validateIds($ids10k);
assert_test('10000 ID — работает', count($result) === 10000);

// 13. Ровно 50000 ID — граница нового лимита
$ids50k = range(1, 50000);
$result = Validator::validateIds($ids50k);
assert_test('50000 ID — граница лимита', count($result) === 50000);

// 14. 50001 ID — превышение лимита
assert_throws('50001 ID → исключение', function () {
    Validator::validateIds(range(1, 50001));
}, 'Maximum 50000 IDs allowed');

// 15. Кастомный лимит (обратная совместимость)
assert_throws('Кастомный лимит 100 — 101 ID → исключение', function () {
    Validator::validateIds(range(1, 101), 100);
}, 'Maximum 100 IDs allowed');

$result = Validator::validateIds(range(1, 100), 100);
assert_test('Кастомный лимит 100 — ровно 100 OK', count($result) === 100);

echo "\n=== Validator::validateIds — Производительность ===\n\n";

// 16. Скорость на 50000 ID
$start = microtime(true);
$ids50k = range(1, 50000);
Validator::validateIds($ids50k);
$elapsed = (microtime(true) - $start) * 1000;
assert_test("50000 ID за " . round($elapsed, 1) . "ms (< 500ms)", $elapsed < 500);

echo "\n" . str_repeat('─', 50) . "\n";
echo "Результат: $passed пройдено, $failed провалено\n";
echo str_repeat('─', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
