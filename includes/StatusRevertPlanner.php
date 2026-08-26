<?php
/**
 * Планировщик отката массовой смены статуса.
 *
 * За что отвечает: по строкам из account_history + текущему состоянию аккаунтов
 * решает, какие записи можно безопасно вернуть к прежнему статусу.
 *
 * Чего здесь осознанно НЕТ: доступа к БД. Класс чистый — на вход приходит уже
 * выбранный набор строк, на выход идёт план. Так его можно прогнать тестами
 * без стенда (см. tests/test_status_revert_planner.php), а вся SQL-часть
 * живёт в вызывающей странице.
 *
 * Главный инвариант (тот же, что у {@see UndoService::revertGroup()}):
 * откатывается ТОЛЬКО строка, чей статус до сих пор равен тому, который
 * проставила откатываемая операция. Если после неё аккаунт перевели куда-то
 * ещё — это чужая, более свежая правка, и затирать её нельзя. Сравнение
 * строгое (===) намеренно: коллация MySQL регистронезависимая и на выборке
 * могла бы «склеить» perechek_new и PERECHEK_NEW, а это разные статусы.
 */
class StatusRevertPlanner {

    /**
     * Построение плана отката.
     *
     * @param array  $rows           Строки вида
     *                               {account_id, current_status, deleted_at}.
     *                               account_id может быть строкой (из mysqli).
     * @param string $expectedStatus Статус, который проставила откатываемая
     *                               операция. Совпадение с ним — пропуск в откат.
     * @param bool   $includeTrashed Откатывать ли аккаунты, лежащие в корзине.
     * @return array {
     *   revert:          int[] — id к обновлению (без дублей, в порядке появления),
     *   skipped_changed: int   — статус изменён после операции, не трогаем,
     *   skipped_trashed: int   — в корзине, а откат корзины не запрошен,
     *   duplicates:      int   — сколько повторов account_id схлопнуто
     * }
     */
    public static function plan(array $rows, string $expectedStatus, bool $includeTrashed): array {
        // 1) Схлопываем дубли: один аккаунт = один UPDATE. Дубль в истории
        //    возможен, если операция логировалась несколькими батчами.
        $unique     = [];
        $duplicates = 0;
        foreach ($rows as $row) {
            $id = isset($row['account_id']) ? (int)$row['account_id'] : 0;
            if ($id <= 0) {
                continue;
            }
            if (isset($unique[$id])) {
                $duplicates++;
                continue;
            }
            $unique[$id] = $row;
        }

        // 2) Классификация
        $revert         = [];
        $skippedChanged = 0;
        $skippedTrashed = 0;

        foreach ($unique as $id => $row) {
            $current = isset($row['current_status']) && $row['current_status'] !== null
                ? (string)$row['current_status']
                : '';

            // Поздняя правка важнее всего: такую строку не трогаем ни при каких флагах.
            if ($current !== $expectedStatus) {
                $skippedChanged++;
                continue;
            }

            if (!$includeTrashed && self::isTrashed($row)) {
                $skippedTrashed++;
                continue;
            }

            $revert[] = $id;
        }

        return [
            'revert'          => $revert,
            'skipped_changed' => $skippedChanged,
            'skipped_trashed' => $skippedTrashed,
            'duplicates'      => $duplicates,
        ];
    }

    /**
     * Лежит ли аккаунт в корзине.
     *
     * NULL, пустая строка и «нулевая» дата MySQL значат «не удалён»: колонка
     * deleted_at на разных установках заполнялась по-разному, а '0000-00-00'
     * прилетает со старых строк при отключённом NO_ZERO_DATE.
     *
     * @param array $row строка выборки
     * @return bool
     */
    private static function isTrashed(array $row): bool {
        if (!isset($row['deleted_at']) || $row['deleted_at'] === null) {
            return false;
        }
        $value = trim((string)$row['deleted_at']);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return false;
        }
        return true;
    }
}
