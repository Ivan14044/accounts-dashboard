<?php
/**
 * Журнал запросов на выгрузку. Сохраняет только метаданные, без содержимого аккаунтов.
 * Запись создаётся до передачи данных клиенту; незавершённая запись означает
 * прерванный запрос, а не успешное скачивание файла браузером.
 */
class ExportAudit {
    private $db;
    private $id;
    private $rowCount = 0;
    private $statusCounts = [];
    private $finished = false;

    public function __construct(mysqli $db, string $table, string $format, string $stage, string $scope, array $columns = [], array $statuses = []) {
        $this->db = $db;
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table)) {
            throw new InvalidArgumentException('Invalid export table');
        }
        $sql = "CREATE TABLE IF NOT EXISTS `export_audit` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
            `table_name` VARCHAR(64) NOT NULL,
            `format` VARCHAR(12) NOT NULL,
            `stage` VARCHAR(16) NOT NULL,
            `scope` VARCHAR(16) NOT NULL,
            `columns_json` TEXT NOT NULL,
            `filter_status_json` TEXT NOT NULL,
            `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `status_counts_json` TEXT NOT NULL,
            `state` VARCHAR(16) NOT NULL DEFAULT 'started',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            INDEX `idx_export_created` (`created_at`, `id`),
            INDEX `idx_export_user` (`username`, `created_at`),
            INDEX `idx_export_ip` (`ip_address`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$db->query($sql)) {
            throw new RuntimeException('Cannot create export audit table: ' . $db->error);
        }

        $username = substr((string)($_SESSION['username'] ?? 'unknown'), 0, 255);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $columnsJson = json_encode(array_values($columns), JSON_UNESCAPED_UNICODE);
        $statusesJson = json_encode(array_values($statuses), JSON_UNESCAPED_UNICODE);
        $emptyCounts = '{}';
        $stmt = $db->prepare('INSERT INTO export_audit (username, ip_address, user_agent, table_name, format, stage, scope, columns_json, filter_status_json, status_counts_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Cannot prepare export audit: ' . $db->error);
        }
        $stmt->bind_param('ssssssssss', $username, $ip, $agent, $table, $format, $stage, $scope, $columnsJson, $statusesJson, $emptyCounts);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new RuntimeException('Cannot start export audit: ' . $db->error);
        }
        $this->id = (int)$db->insert_id;
    }

    public function addRow(array $row): void {
        $this->rowCount++;
        $status = isset($row['status']) ? (string)$row['status'] : '';
        $this->statusCounts[$status] = ($this->statusCounts[$status] ?? 0) + 1;
    }

    /** Для списка ID: статусы ещё не читались и не должны выдумываться. */
    public function addIdCount(int $count): void {
        $this->rowCount += max(0, $count);
    }

    public function complete(): void {
        $this->finish('completed');
    }

    public function fail(): void {
        $this->finish('failed');
    }

    private function finish(string $state): void {
        if ($this->finished || !$this->id) {
            return;
        }
        $counts = json_encode($this->statusCounts, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare('UPDATE export_audit SET row_count = ?, status_counts_json = ?, state = ?, finished_at = NOW() WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Cannot prepare export audit update: ' . $this->db->error);
        }
        $stmt->bind_param('issi', $this->rowCount, $counts, $state, $this->id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new RuntimeException('Cannot finish export audit: ' . $this->db->error);
        }
        $this->finished = true;
    }

    public function __destruct() {
        if (!$this->finished && $this->id) {
            try {
                $this->finish('interrupted');
            } catch (Throwable $e) {
                error_log('Export audit finalization failed: ' . $e->getMessage());
            }
        }
    }
}
