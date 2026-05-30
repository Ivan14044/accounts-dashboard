<?php
/**
 * Чтение аккаунтов из БД.
 *
 * Содержит SELECT-методы AccountsRepository — выборка списка/одной записи,
 * deferred join по id (чтобы не таскать тяжёлые TEXT-колонки при фильтрации),
 * усечённые SELECT для валидации.
 *
 * Подключается в {@see AccountsRepository} через `use`.
 */
trait AccountsRepoSelectTrait {
    /**
     * Получение списка аккаунтов с фильтрами и пагинацией
     *
     * @param FilterBuilder $filter Фильтр
     * @param string $orderBy SQL выражение для ORDER BY
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @param bool $includeDeleted Включать ли удалённые записи
     * @return array
     */
    public function getAccounts(FilterBuilder $filter, string $orderBy, int $limit, int $offset, bool $includeDeleted = false, ?array $columns = null): array {
        // $columns !== null — выбрать только эти колонки (+ id для INNER JOIN USING(id)).
        // Лёгкий экспорт: не тащим все колонки (вкл. тяжёлые cookies/full_cookies) на 1000 строк,
        // когда в файл идёт лишь часть. null = все колонки (прежнее поведение, дашборд/CSV).
        if ($columns !== null) {
            $meta = array_values(array_unique(array_merge(['id'], $columns)));
        } else {
            $meta = $this->metadata->getAllColumns();
        }

        $validCols = [];
        foreach ($meta as $col) {
            if (!$this->metadata->columnExists($col)) {
                Logger::warning("Column '$col' does not exist in table '{$this->table}', skipping");
                continue;
            }
            $validCols[] = '`' . $col . '`';
        }

        if (empty($validCols)) {
            $validCols = ['`id`', '`login`', '`status`'];
            Logger::error("No valid columns found, using default columns");
        }

        $selectCols = implode(', ', $validCols);
        $where = $filter->getWhereClause($includeDeleted);
        $params = $filter->getParams();

        // Deferred join: внутренний подзапрос выбирает только id — MySQL не читает тяжёлые
        // TEXT/BLOB колонки (cookies, full_cookies, token и т.д.) при фильтрации и сортировке.
        // Полные данные подтягиваются JOIN-ом только для финальных LIMIT строк.
        $innerSql = "SELECT id FROM {$this->table} $where ORDER BY $orderBy LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;

        $sql = "SELECT $selectCols FROM {$this->table} "
             . "INNER JOIN ($innerSql) AS _page USING(id) "
             . "ORDER BY $orderBy";

        $cacheKey = null;
        if (Config::FEATURE_STATS_CACHING && $limit <= 100) {
            $cacheKey = 'accounts_' . md5($sql . serialize($params));
        }

        return $this->db->prepare($sql, $params, $cacheKey);
    }

    /**
     * Подсчет количества записей с фильтрами
     *
     * @param FilterBuilder $filter Фильтр
     * @param bool $includeDeleted Включать ли удалённые записи
     * @return int
     */
    public function getAccountsCount(FilterBuilder $filter, bool $includeDeleted = false): int {
        $where = $filter->getWhereClause($includeDeleted);
        $params = $filter->getParams();
        $whereClause = str_replace('WHERE ', '', $where);

        return (int)$this->db->getCount($this->table, $whereClause, $params);
    }

    /**
     * Получение одной записи аккаунта по ID
     *
     * @param int $id ID аккаунта
     * @return array|null
     */
    public function getAccountById(int $id): ?array {
        if ($id <= 0) {
            return null;
        }
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare select statement');
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to fetch account by id');
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Получение записей для проверки валидности (id, login, id_soc_account, social_url, cookies)
     * Используется для prepare перед вызовом check.fb.tools.
     *
     * @param array $ids Массив ID записей
     * @return array
     */
    public function getAccountsByIdsForValidation(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        $ids = array_map('intval', array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }
        $selectCols = $this->buildValidationSelectColumns();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deletedCond = $this->metadata->columnExists('deleted_at') ? ' AND (deleted_at IS NULL OR deleted_at = 0)' : '';
        $sql = "SELECT $selectCols FROM {$this->table} WHERE id IN ($placeholders)" . $deletedCond;
        $types = str_repeat('i', count($ids));
        $stmt = $this->db->getConnection()->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare getAccountsByIdsForValidation');
        }
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * SELECT-выражения для validation-запросов.
     * cookies — LONGTEXT, на FB-аккаунтах это 5–10KB JSON. Нам нужен только c_user,
     * который всегда в первых ~4KB. Обрезаем в БД, чтобы не таскать мегабайты данных.
     */
    private function buildValidationSelectColumns(): string {
        $cols = ['`id`', '`login`'];
        if ($this->metadata->columnExists('id_soc_account')) {
            $cols[] = '`id_soc_account`';
        }
        if ($this->metadata->columnExists('social_url')) {
            $cols[] = '`social_url`';
        }
        if ($this->metadata->columnExists('cookies')) {
            $cols[] = 'SUBSTRING(`cookies`, 1, ' . (int)Config::VALIDATE_COOKIES_TRUNCATE . ') AS `cookies`';
        }
        return implode(', ', $cols);
    }

    /**
     * Получение записей по фильтру для проверки валидности (только id, login, id_soc_account, cookies)
     *
     * @param FilterBuilder $filter
     * @param string $orderBy
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAccountsByFilterForValidation(FilterBuilder $filter, string $orderBy, int $limit, int $offset): array {
        $selectCols = $this->buildValidationSelectColumns();
        $where = $filter->getWhereClause(false);
        $params = $filter->getParams();
        $innerSql = "SELECT id FROM {$this->table} $where ORDER BY $orderBy LIMIT ? OFFSET ?";
        $params[] = (int) $limit;
        $params[] = (int) $offset;
        $sql = "SELECT $selectCols FROM {$this->table} INNER JOIN ($innerSql) AS _page USING(id) ORDER BY $orderBy";
        return $this->db->prepare($sql, $params);
    }
}
