<?php
/**
 * Сервис аккаунтов: единая точка для всех операций с таблицей.
 *
 * Делегирует доступ к БД в {@see AccountsRepository}, статистику — в
 * {@see StatisticsService}, аудит — в {@see AuditLogger}. Сам ничего в БД
 * напрямую не пишет (за исключением batch-INSERT в `account_history` для bulk-импорта).
 *
 * Из-за размера реализация раскидана по трейтам в `includes/services/`:
 *   - {@see AccountsServiceFiltersTrait} — createFilterFromRequest, buildOrderBy
 *   - {@see AccountsServiceQueryTrait}   — getAccounts/Count/ById + validation reads
 *   - {@see AccountsServiceStatsTrait}   — делегирующие методы статистики
 *   - {@see AccountsServiceWriteTrait}   — update/delete/restore с audit log
 *   - {@see AccountsServiceCreateTrait}  — createAccount/createAccountsBulk с audit log
 *
 * Публичный API класса (имена и сигнатуры методов) не меняется — трейты просто
 * физически разносят реализацию по файлам.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/FilterBuilder.php';
require_once __DIR__ . '/ColumnMetadata.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/AccountsRepository.php';
require_once __DIR__ . '/StatisticsService.php';
require_once __DIR__ . '/services/AccountsServiceFiltersTrait.php';
require_once __DIR__ . '/services/AccountsServiceQueryTrait.php';
require_once __DIR__ . '/services/AccountsServiceStatsTrait.php';
require_once __DIR__ . '/services/AccountsServiceWriteTrait.php';
require_once __DIR__ . '/services/AccountsServiceCreateTrait.php';

class AccountsService {
    use AccountsServiceFiltersTrait;
    use AccountsServiceQueryTrait;
    use AccountsServiceStatsTrait;
    use AccountsServiceWriteTrait;
    use AccountsServiceCreateTrait;

    private $db;
    private $table;
    private $metadata;
    private $repository;
    private $statistics;

    public function __construct(string $table = 'accounts') {
        $this->table = $table;
        $this->db = Database::getInstance();
        $mysqli = $this->db->getConnection();
        $this->metadata = ColumnMetadata::getInstance($mysqli, $this->table);
        if ($this->table === 'accounts') {
            $this->db->ensureIndexes();
        }

        $this->repository = new AccountsRepository($this->table);
        $this->statistics = new StatisticsService($this->table);
    }

    public function getTableName(): string {
        return $this->table;
    }

    /**
     * Список колонок, хранящихся как строка, но используемых как число (для FilterBuilder — без TRIM/CAST).
     * Единый источник для createFilterFromRequest и для мест, где FilterBuilder создаётся вручную (api/index.php и т.д.).
     */
    public static function getNumericLikeColumns(): array {
        return [
            'limit_rk', 'scenario_pharma', 'quantity_friends', 'quantity_fp',
            'quantity_bm', 'quantity_photo', 'year_created',
            'birth_day', 'birth_month', 'birth_year'
        ];
    }

    /**
     * Получение метаданных колонок
     */
    public function getColumnMetadata(): array {
        return [
            'columns' => $this->metadata->getColumnTitles(),
            'all' => $this->metadata->getAllColumns(),
            'numeric' => $this->metadata->getNumericColumns(),
            'text' => $this->metadata->getTextColumns()
        ];
    }
}
