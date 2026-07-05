<?php
/**
 * Тест чистой классификации UndoService::planUndo().
 *
 * Запуск:  php tests/test_undo_plan.php
 * Код выхода: 0 — все тесты прошли, 1 — есть падения.
 *
 * Без сети и БД — проверяем только план отката:
 *  - группировка обычных полей по (field, old, new);
 *  - чувствительные поля ([СКРЫТО]) не откатываются;
 *  - deleted_at: удаление → restore, hard delete ('DELETED') → unsupported,
 *    restore-событие → unsupported;
 *  - NULL old_value трактуется как ''.
 */

require_once __DIR__ . '/../includes/UndoService.php';

$failures = 0;
$passed   = 0;

/**
 * @param string $name
 * @param array  $rows   строки account_history {account_id, field_name, old_value, new_value}
 * @param array  $expect ['reverts'=>[['field','old','new','ids'],...], 'restores'=>[ids],
 *                        'skipped_sensitive'=>n, 'unsupported'=>n]
 */
function check(string $name, array $rows, array $expect): void
{
    global $failures, $passed;

    $plan = UndoService::planUndo($rows);
    $ok = true;

    // Нормализуем reverts для сравнения без зависимости от порядка групп
    $normalize = static function (array $groups): array {
        $out = [];
        foreach ($groups as $g) {
            $ids = array_map('intval', $g['ids']);
            sort($ids);
            $out[$g['field'] . '|' . $g['old'] . '|' . $g['new']] = $ids;
        }
        ksort($out);
        return $out;
    };

    $gotReverts  = $normalize($plan['reverts']);
    $wantReverts = $normalize($expect['reverts'] ?? []);
    if ($gotReverts !== $wantReverts) {
        $ok = false;
        fwrite(STDERR, sprintf(
            "  ✗ %s — reverts: ожидалось %s, получено %s\n",
            $name, json_encode($wantReverts, JSON_UNESCAPED_UNICODE), json_encode($gotReverts, JSON_UNESCAPED_UNICODE)
        ));
    }

    $gotRestores  = $plan['restores'];
    $wantRestores = $expect['restores'] ?? [];
    sort($gotRestores);
    sort($wantRestores);
    if ($gotRestores !== $wantRestores) {
        $ok = false;
        fwrite(STDERR, sprintf(
            "  ✗ %s — restores: ожидалось [%s], получено [%s]\n",
            $name, implode(',', $wantRestores), implode(',', $gotRestores)
        ));
    }

    foreach (['skipped_sensitive', 'unsupported'] as $counter) {
        $got  = $plan[$counter];
        $want = $expect[$counter] ?? 0;
        if ($got !== $want) {
            $ok = false;
            fwrite(STDERR, sprintf(
                "  ✗ %s — %s: ожидалось %d, получено %d\n",
                $name, $counter, $want, $got
            ));
        }
    }

    if ($ok) { $passed++; echo "  ✓ $name\n"; }
    else     { $failures++; }
}

function row(int $id, string $field, ?string $old, ?string $new): array
{
    return ['account_id' => $id, 'field_name' => $field, 'old_value' => $old, 'new_value' => $new];
}

echo "Тест planUndo():\n";

// 1. Массовая смена статуса: одинаковый old → одна группа
check('bulk-статус с одинаковым old → одна группа',
    [
        row(1, 'status', 'new', 'sold'),
        row(2, 'status', 'new', 'sold'),
        row(3, 'status', 'new', 'sold'),
    ],
    ['reverts' => [['field' => 'status', 'old' => 'new', 'new' => 'sold', 'ids' => [1, 2, 3]]]]);

// 2. Разные старые значения → разные группы (каждой строке вернётся её значение)
check('разные old → разные группы',
    [
        row(1, 'status', 'new', 'sold'),
        row(2, 'status', 'hold', 'sold'),
    ],
    ['reverts' => [
        ['field' => 'status', 'old' => 'new', 'new' => 'sold', 'ids' => [1]],
        ['field' => 'status', 'old' => 'hold', 'new' => 'sold', 'ids' => [2]],
    ]]);

// 3. Одинаковый old, но разный new → тоже разные группы (guard по new при откате)
check('разные new → разные группы',
    [
        row(1, 'status', 'new', 'sold'),
        row(2, 'status', 'new', 'trash'),
    ],
    ['reverts' => [
        ['field' => 'status', 'old' => 'new', 'new' => 'sold', 'ids' => [1]],
        ['field' => 'status', 'old' => 'new', 'new' => 'trash', 'ids' => [2]],
    ]]);

// 4. Чувствительное поле по имени → пропуск
check('sensitive-поле (password) → skipped_sensitive',
    [
        row(1, 'password', '[СКРЫТО]', '[СКРЫТО]'),
        row(2, 'status', 'new', 'sold'),
    ],
    [
        'reverts' => [['field' => 'status', 'old' => 'new', 'new' => 'sold', 'ids' => [2]]],
        'skipped_sensitive' => 1,
    ]);

// 5. Скрытое значение в обычном поле → пропуск (защита от порчи данных маркером)
check('значение [СКРЫТО] → skipped_sensitive',
    [row(1, 'note', '[СКРЫТО]', 'x')],
    ['skipped_sensitive' => 1]);

// 6. Удаление в корзину → restore
check('deleted_at set → restore',
    [
        row(1, 'deleted_at', '', '2026-07-04 10:00:00'),
        row(2, 'deleted_at', null, '2026-07-04 10:00:00'),
    ],
    ['restores' => [1, 2]]);

// 7. Hard delete (таблица без soft delete) → отменить невозможно
check('hard delete (DELETED) → unsupported',
    [row(1, 'deleted_at', '', 'DELETED')],
    ['unsupported' => 1]);

// 8. Restore-событие (deleted_at → '') — такие действия не отменяем
check('restore-событие → unsupported',
    [row(1, 'deleted_at', '2026-07-04 10:00:00', '')],
    ['unsupported' => 1]);

// 9. NULL old_value == '' (семантика valueToString)
check('NULL old == пустая строка',
    [
        row(1, 'note', null, 'text'),
        row(2, 'note', '', 'text'),
    ],
    ['reverts' => [['field' => 'note', 'old' => '', 'new' => 'text', 'ids' => [1, 2]]]]);

// 10. Смешанное действие: правки + удаления + sensitive
check('смешанное действие',
    [
        row(1, 'status', 'new', 'sold'),
        row(2, 'deleted_at', '', '2026-07-04 12:00:00'),
        row(3, 'cookies', '[СКРЫТО]', '[СКРЫТО]'),
        row(4, 'deleted_at', '', 'DELETED'),
    ],
    [
        'reverts' => [['field' => 'status', 'old' => 'new', 'new' => 'sold', 'ids' => [1]]],
        'restores' => [2],
        'skipped_sensitive' => 1,
        'unsupported' => 1,
    ]);

// 11. Пустая история → пустой план
check('пустая история', [], []);

echo "\nИтог: passed={$passed}, failed={$failures}\n";
exit($failures > 0 ? 1 : 0);
