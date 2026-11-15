<?php
/**
 * Запросы по списку id — частями не длиннее Config::ID_LIST_CHUNK.
 *
 * Зачем. Интерфейс шлёт выбранные строки пачками по 1000, и запрос вида
 * `UPDATE accounts ... WHERE id IN (<1000 id>) AND deleted_at IS NULL` на
 * проде перебирает ВСЮ таблицу (~190 тыс. строк, 45–67 с), хотя пачки
 * в 835–950 id проходят за доли секунды. Причина — оптимизатор MySQL:
 * когда ему не хватает памяти на разбор длинного списка
 * (range_optimizer_max_mem_size), он молча отказывается от поиска по номеру.
 * Поднять лимит своей сессии (Database::__construct, 03.09.2026) на проде
 * не помогло — в журнале панели удаления по 1000 шли по 50 с и после этого.
 * Короткий список память не исчерпывает при любой настройке сервера.
 * Стережёт tests/integration_id_chunks_mysql.php.
 *
 * Чего здесь НЕТ: запросов «по фильтру» — у них нет списка id.
 *
 * Подключается в {@see AccountsRepository} через `use`.
 */
trait AccountsRepoIdChunksTrait {
    /**
     * Выполнить изменяющий запрос для всех id частями, атомарно.
     *
     * Части идут в одной транзакции: если упадёт третья (например, строка
     * занята внешней программой дольше innodb_lock_wait_timeout), откатятся
     * и первые две — как было, когда запрос был один. Транзакция открывается
     * только при двух и более частях. Вызывающий код НЕ должен сам держать
     * открытую транзакцию: begin_transaction() её бы молча закоммитил.
     *
     * @param string $sqlTemplate Запрос с маркером {IDS} на месте списка id
     * @param string $headTypes   Типы параметров, идущих в запросе ДО списка
     * @param array  $headParams  Сами эти параметры
     * @param int[]  $ids         Id (уже приведённые к int)
     * @param string $errorText   Текст исключения при сбое
     * @return int Сумма affected_rows по всем частям
     * @throws Exception Если подготовка или выполнение части не удались
     */
    private function executeForIdChunks(string $sqlTemplate, string $headTypes, array $headParams, array $ids, string $errorText): int {
        $chunks = array_chunk(array_values($ids), Config::ID_LIST_CHUNK);
        $conn = $this->db->getConnection();
        $useTransaction = count($chunks) > 1;

        if ($useTransaction) {
            $conn->begin_transaction();
        }
        try {
            $affected = 0;
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $conn->prepare(str_replace('{IDS}', $placeholders, $sqlTemplate));
                if (!$stmt) {
                    throw new Exception($errorText . ' (prepare)');
                }
                $params = array_merge($headParams, $chunk);
                $stmt->bind_param($headTypes . str_repeat('i', count($chunk)), ...$params);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new Exception($errorText);
                }
                $affected += max(0, $stmt->affected_rows);
                $stmt->close();
            }
            if ($useTransaction) {
                $conn->commit();
            }
            return $affected;
        } catch (Throwable $e) {
            if ($useTransaction) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    /**
     * Выбрать id частями: `SELECT id ... WHERE id IN ({IDS}) ...`.
     *
     * @param string $sqlTemplate Запрос с маркером {IDS}; первая колонка — id
     * @param int[]  $ids         Id (уже приведённые к int)
     * @param string $errorText   Текст исключения при сбое
     * @return int[] Найденные id (порядок — как вернула база)
     * @throws Exception Если подготовка или выполнение части не удались
     */
    private function selectIdsInChunks(string $sqlTemplate, array $ids, string $errorText): array {
        $found = [];
        $conn = $this->db->getConnection();
        foreach (array_chunk(array_values($ids), Config::ID_LIST_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $conn->prepare(str_replace('{IDS}', $placeholders, $sqlTemplate));
            if (!$stmt) {
                throw new Exception($errorText . ' (prepare)');
            }
            $stmt->bind_param(str_repeat('i', count($chunk)), ...$chunk);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception($errorText);
            }
            $result = $stmt->get_result();
            while ($row = $result->fetch_row()) {
                $found[] = (int)$row[0];
            }
            $stmt->close();
        }
        return $found;
    }
}
