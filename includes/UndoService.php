<?php
/**
 * Отмена последнего действия пользователя (undo).
 *
 * Работает поверх журнала user_actions и строк account_history с action_id:
 * последнее не отменённое действие пользователя откатывается к old_value.
 *
 * Ключевые гарантии:
 *  - откатываются только строки, чьё текущее значение совпадает с new_value
 *    действия (оптимистичная проверка) — более поздние правки не затираются;
 *  - чувствительные поля ([СКРЫТО]) не откатываются;
 *  - откат сам логируется как действие типа 'undo' (полный аудит), но
 *    отменить откат нельзя — «последним действием» считается последнее не-undo;
 *  - повторный откат того же действия блокируется атомарно (undone_at).
 *
 * Чистая классификация строк истории вынесена в static planUndo() —
 * тестируется без БД (см. tests/test_undo_plan.php).
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Logger.php';

class UndoService {
    /** Типы действий, которые можно отменить */
    const UNDOABLE_TYPES = ['update_status', 'update_field', 'bulk_update_field', 'delete', 'mass_transfer'];

    /** Маркер скрытого значения в account_history */
    const HIDDEN_VALUE = '[СКРЫТО]';

    /** Размер чанка для UPDATE при откате */
    const CHUNK_SIZE = 500;

    /** @var mysqli */
    private $mysqli;

    public function __construct() {
        $this->mysqli = Database::getInstance()->getConnection();
    }

    /**
     * Последнее отменяемое действие пользователя (для панели «↩ Последнее действие»).
     *
     * @return array|null {id, action_type, table_name, description, affected_count, created_at}
     */
    public function getLastUndoableAction(string $username): ?array {
        $placeholders = implode(',', array_fill(0, count(self::UNDOABLE_TYPES), '?'));
        $sql = "SELECT id, action_type, table_name, description, affected_count, created_at
                FROM user_actions
                WHERE username = ? AND undone_at IS NULL AND affected_count > 0
                  AND action_type IN ($placeholders)
                ORDER BY id DESC
                LIMIT 1";
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $types = 's' . str_repeat('s', count(self::UNDOABLE_TYPES));
        $stmt->bind_param($types, $username, ...self::UNDOABLE_TYPES);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['affected_count'] = (int)$row['affected_count'];
        return $row;
    }

    /**
     * Откат действия. Действие должно принадлежать пользователю и быть не отменённым.
     *
     * @return array {reverted, skipped_conflict, skipped_sensitive, unsupported, action}
     * @throws Exception если действие не найдено / уже отменено / таблица недоступна
     */
    public function undoAction(int $actionId, string $username): array {
        $action = $this->fetchAction($actionId, $username);
        if (!$action) {
            throw new Exception('Действие не найдено или принадлежит другому пользователю');
        }
        if ($action['undone_at'] !== null) {
            throw new Exception('Это действие уже отменено');
        }
        if (!in_array($action['action_type'], self::UNDOABLE_TYPES, true)) {
            throw new Exception('Действие этого типа нельзя отменить');
        }

        $table = $action['table_name'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !Database::getInstance()->tableExists($table)) {
            throw new Exception('Таблица действия недоступна: ' . $table);
        }
        $columns = $this->fetchColumns($table);

        $this->mysqli->begin_transaction();
        try {
            // Атомарная пометка «отменено» — защита от двойного клика / двух вкладок
            $stmt = $this->mysqli->prepare(
                "UPDATE user_actions SET undone_at = NOW() WHERE id = ? AND username = ? AND undone_at IS NULL"
            );
            if (!$stmt) {
                throw new Exception('Не удалось заблокировать действие');
            }
            $stmt->bind_param('is', $actionId, $username);
            $stmt->execute();
            $locked = $stmt->affected_rows;
            $stmt->close();
            if ($locked === 0) {
                throw new Exception('Это действие уже отменено');
            }

            $historyRows = $this->fetchHistoryRows($actionId);
            $plan = self::planUndo($historyRows);

            $report = [
                'reverted'          => 0,
                'skipped_conflict'  => 0,
                'skipped_sensitive' => $plan['skipped_sensitive'],
                'unsupported'       => $plan['unsupported'],
            ];

            // Откат — тоже действие: полный аудит, но сам он не отменяем (тип undo)
            $auditLogger = AuditLogger::getInstance();
            $undoActionId = null;
            if ($auditLogger->isEnabled()) {
                $undoActionId = $auditLogger->beginAction('undo', $table,
                    'Отмена: ' . $action['description']);
            }

            foreach ($plan['reverts'] as $group) {
                if (!isset($columns[$group['field']])) {
                    $report['unsupported'] += count($group['ids']);
                    continue;
                }
                $res = $this->revertGroup($table, $group, $columns[$group['field']]['nullable'], $auditLogger);
                $report['reverted'] += $res['reverted'];
                $report['skipped_conflict'] += $res['skipped'];
            }

            if (!empty($plan['restores'])) {
                if (isset($columns['deleted_at'])) {
                    $res = $this->restoreGroup($table, $plan['restores'], $auditLogger);
                    $report['reverted'] += $res['reverted'];
                    $report['skipped_conflict'] += $res['skipped'];
                } else {
                    $report['unsupported'] += count($plan['restores']);
                }
            }

            if ($auditLogger->isEnabled()) {
                $auditLogger->finishAction($report['reverted']);
            }

            // Связываем оригинал с действием-откатом
            if ($undoActionId !== null && $report['reverted'] > 0) {
                $stmt = $this->mysqli->prepare("UPDATE user_actions SET undo_action_id = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $undoActionId, $actionId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $this->mysqli->commit();

            Logger::info('UNDO: действие отменено', [
                'action_id' => $actionId,
                'user'      => $username,
                'report'    => $report,
            ]);

            $report['action'] = [
                'id'          => (int)$action['id'],
                'description' => $action['description'],
                'table_name'  => $table,
            ];
            return $report;
        } catch (Throwable $e) {
            $this->mysqli->rollback();
            throw ($e instanceof Exception) ? $e : new Exception($e->getMessage());
        }
    }

    /**
     * Чистая классификация строк истории для отката (без БД — тестируемо).
     *
     * @param array $historyRows строки {account_id, field_name, old_value, new_value}
     * @return array {
     *   reverts: [{field, old, new, ids[]}, ...],  — обычные поля, ключ группы (field, old, new)
     *   restores: [account_id, ...],               — отмена soft delete (deleted_at → NULL)
     *   skipped_sensitive: int,                    — [СКРЫТО]: откат невозможен
     *   unsupported: int                           — hard delete и прочее необратимое
     * }
     */
    public static function planUndo(array $historyRows): array {
        $sensitive = AuditLogger::sensitiveFields();
        $reverts = [];
        $restores = [];
        $skippedSensitive = 0;
        $unsupported = 0;

        foreach ($historyRows as $row) {
            $field = (string)$row['field_name'];
            $old   = $row['old_value'] === null ? '' : (string)$row['old_value'];
            $new   = $row['new_value'] === null ? '' : (string)$row['new_value'];
            $accountId = (int)$row['account_id'];

            if ($old === self::HIDDEN_VALUE || $new === self::HIDDEN_VALUE
                || in_array(strtolower($field), $sensitive, true)) {
                $skippedSensitive++;
                continue;
            }

            if ($field === 'deleted_at') {
                if ($new === 'DELETED') {
                    // Таблица без soft delete: строка физически удалена, вернуть нечего
                    $unsupported++;
                } elseif ($old === '' && $new !== '') {
                    // Удаление в корзину → откат = восстановление
                    $restores[] = $accountId;
                } else {
                    // Например, строка restore-события — такие действия не отменяем
                    $unsupported++;
                }
                continue;
            }

            $key = $field . "\x00" . $old . "\x00" . $new;
            if (!isset($reverts[$key])) {
                $reverts[$key] = ['field' => $field, 'old' => $old, 'new' => $new, 'ids' => []];
            }
            $reverts[$key]['ids'][] = $accountId;
        }

        return [
            'reverts'           => array_values($reverts),
            'restores'          => $restores,
            'skipped_sensitive' => $skippedSensitive,
            'unsupported'       => $unsupported,
        ];
    }

    // ---------------------------------------------------------------
    // internals
    // ---------------------------------------------------------------

    /**
     * Откат одной группы (field, old, new): возвращаем old только тем строкам,
     * где значение всё ещё равно new (поздние правки не трогаем).
     *
     * @return array {reverted:int, skipped:int}
     */
    private function revertGroup(string $table, array $group, bool $nullable, AuditLogger $auditLogger): array {
        $field = $group['field'];
        // Имя поля пришло из account_history — валидируем как везде
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            return ['reverted' => 0, 'skipped' => count($group['ids'])];
        }

        $reverted = 0;
        $skipped = 0;

        foreach (array_chunk($group['ids'], self::CHUNK_SIZE) as $chunk) {
            // Блокируем строки и сравниваем текущее значение с new_value в PHP —
            // та же нормализация, что у AuditLogger::valueToString (NULL → '')
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->mysqli->prepare(
                "SELECT id, `$field` AS val FROM `$table` WHERE id IN ($placeholders) FOR UPDATE"
            );
            if (!$stmt) {
                $skipped += count($chunk);
                continue;
            }
            $types = str_repeat('i', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $result = $stmt->get_result();

            $matched = [];
            $foundCount = 0;
            while ($row = $result->fetch_assoc()) {
                $foundCount++;
                $current = $row['val'] === null ? '' : (string)$row['val'];
                if ($current === $group['new']) {
                    $matched[] = (int)$row['id'];
                }
            }
            $stmt->close();

            // Не найденные (hard delete) и изменённые позже — пропуск
            $skipped += count($chunk) - count($matched);

            if (empty($matched)) {
                continue;
            }

            // Аудит отката — до UPDATE, чтобы зафиксировать текущие значения как old
            if ($auditLogger->isEnabled()) {
                try {
                    $auditLogger->logBulkChange($matched, $field, null, $group['old'] === '' ? null : $group['old'], null, $table);
                } catch (Exception $e) {}
            }

            // '' в истории означает NULL или пустую строку; для nullable-колонок
            // возвращаем NULL (исходная семантика valueToString(null) === '')
            $setNull = ($group['old'] === '' && $nullable);
            $inPlaceholders = implode(',', array_fill(0, count($matched), '?'));
            if ($setNull) {
                $sql = "UPDATE `$table` SET `$field` = NULL WHERE id IN ($inPlaceholders)";
                $stmt = $this->mysqli->prepare($sql);
                if ($stmt) {
                    $mTypes = str_repeat('i', count($matched));
                    $stmt->bind_param($mTypes, ...$matched);
                }
            } else {
                $sql = "UPDATE `$table` SET `$field` = ? WHERE id IN ($inPlaceholders)";
                $stmt = $this->mysqli->prepare($sql);
                if ($stmt) {
                    $mTypes = 's' . str_repeat('i', count($matched));
                    $stmt->bind_param($mTypes, $group['old'], ...$matched);
                }
            }
            if (!$stmt) {
                $skipped += count($matched);
                continue;
            }
            $stmt->execute();
            $reverted += max(0, $stmt->affected_rows);
            $stmt->close();
        }

        return ['reverted' => $reverted, 'skipped' => $skipped];
    }

    /**
     * Отмена удаления в корзину: deleted_at → NULL для строк, всё ещё лежащих в корзине.
     *
     * @return array {reverted:int, skipped:int}
     */
    private function restoreGroup(string $table, array $ids, AuditLogger $auditLogger): array {
        $reverted = 0;
        $skipped = 0;

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            // Аудит: logBulkChange сам читает текущие deleted_at и пропускает
            // уже восстановленные строки (old '' == new '')
            if ($auditLogger->isEnabled()) {
                try {
                    $auditLogger->logBulkChange($chunk, 'deleted_at', null, null, null, $table);
                } catch (Exception $e) {}
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->mysqli->prepare(
                "UPDATE `$table` SET deleted_at = NULL WHERE id IN ($placeholders) AND deleted_at IS NOT NULL"
            );
            if (!$stmt) {
                $skipped += count($chunk);
                continue;
            }
            $types = str_repeat('i', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $affected = max(0, $stmt->affected_rows);
            $stmt->close();

            $reverted += $affected;
            $skipped += count($chunk) - $affected;
        }

        return ['reverted' => $reverted, 'skipped' => $skipped];
    }

    private function fetchAction(int $actionId, string $username): ?array {
        $stmt = $this->mysqli->prepare(
            "SELECT id, username, action_type, table_name, description, affected_count, created_at, undone_at
             FROM user_actions WHERE id = ? AND username = ?"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $actionId, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    /** @return array строки {account_id, field_name, old_value, new_value} */
    private function fetchHistoryRows(int $actionId): array {
        $stmt = $this->mysqli->prepare(
            "SELECT account_id, field_name, old_value, new_value FROM account_history WHERE action_id = ? ORDER BY id"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $actionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /** @return array колонка → {nullable: bool} */
    private function fetchColumns(string $table): array {
        $columns = [];
        $result = $this->mysqli->query("SHOW COLUMNS FROM `$table`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[$row['Field']] = ['nullable' => (strtoupper((string)$row['Null']) === 'YES')];
            }
            $result->close();
        }
        return $columns;
    }
}
