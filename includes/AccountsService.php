<?php
/**
 * Сервис для работы с аккаунтами
 * Обеспечивает единую точку доступа ко всем операциям с аккаунтами
 * Делегирует работу с БД в AccountsRepository и статистику в StatisticsService
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/FilterBuilder.php';
require_once __DIR__ . '/ColumnMetadata.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/AccountsRepository.php';
require_once __DIR__ . '/StatisticsService.php';

class AccountsService {
    private $db;
    private $table = 'accounts';
    private $metadata;
    private $repository;
    private $statistics;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $mysqli = $this->db->getConnection();
        $this->metadata = ColumnMetadata::getInstance($mysqli);
        $this->db->ensureIndexes();
        
        // Инициализируем репозиторий и сервис статистики
        $this->repository = new AccountsRepository();
        $this->statistics = new StatisticsService();
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
    
    /**
     * Создание фильтра из GET-параметров
     */
    public function createFilterFromRequest(array $params): FilterBuilder {
        $meta = $this->getColumnMetadata();
        $filter = new FilterBuilder($meta['columns'], $meta['numeric']);
        
        // Фильтр по конкретным ID (приоритетный для экспорта выбранных записей)
        if (!empty($params['ids']) && is_array($params['ids'])) {
            $filter->addIdsFilter($params['ids']);
        }
        
        // Общий поиск
        if (!empty($params['q'])) {
            $filter->addSearchFilter($params['q']);
        }
        
        // Статусы (множественный выбор)
        $statusArray = [];
        if (isset($params['status'])) {
            if (is_array($params['status'])) {
                $statusArray = $params['status'];
            } elseif (is_string($params['status']) && $params['status'] !== '') {
                $statusArray = explode(',', $params['status']);
            }
        }
        $emptyStatus = !empty($params['empty_status']);
        $filter->addStatusFilter($statusArray, $emptyStatus);
        
        // Статус marketplace (с поддержкой пустых значений)
        if (!empty($params['status_marketplace'])) {
            if ($params['status_marketplace'] === '__empty__') {
                $filter->addEmptyFilter('status_marketplace');
            } else {
                $filter->addEqualFilter('status_marketplace', $params['status_marketplace']);
            }
        }
        
        // Фильтр Currency (с поддержкой пустых значений)
        if (!empty($params['currency'])) {
            if ($params['currency'] === '__empty__') {
                $filter->addEmptyFilter('currency');
            } else {
                $filter->addEqualFilter('currency', $params['currency']);
            }
        }
        
        // Фильтр Geo (с поддержкой пустых значений)
        if (!empty($params['geo'])) {
            if ($params['geo'] === '__empty__') {
                $filter->addEmptyFilter('geo');
            } else {
                $filter->addEqualFilter('geo', $params['geo']);
            }
        }
        
        // Фильтр Status RK (с поддержкой пустых значений)
        if (!empty($params['status_rk'])) {
            if ($params['status_rk'] === '__empty__') {
                $filter->addEmptyFilter('status_rk');
            } else {
                $filter->addEqualFilter('status_rk', $params['status_rk']);
            }
        }
        
        // Фильтр Limit RK (диапазон)
        if ($this->metadata->columnExists('limit_rk')) {
            $filter->addRangeFilter('limit_rk',
                $params['limit_rk_from'] ?? null,
                $params['limit_rk_to'] ?? null
            );
        }
        
        // Булевы фильтры "не пустое"
        $filter->addNotEmptyFilter('email', !empty($params['has_email']));
        $filter->addNotEmptyFilter('two_fa', !empty($params['has_two_fa']));
        $filter->addNotEmptyFilter('token', !empty($params['has_token']));
        $filter->addNotEmptyFilter('avatar', !empty($params['has_avatar']));
        $filter->addNotEmptyFilter('cover', !empty($params['has_cover']));
        $filter->addNotEmptyFilter('password', !empty($params['has_password']));
        
        // Фильтр "Fan Page" (quantity_fp > 0)
        $filter->addGreaterThanZeroFilter('quantity_fp', !empty($params['has_fan_page']));
        
        // Фильтр "полностью заполненные"
        $filter->addFullyFilledFilter(!empty($params['full_filled']));
        
        // Фильтр "только избранные"
        if (!empty($params['favorites_only'])) {
            // Получаем ID пользователя из сессии
            $userId = $_SESSION['username'] ?? null;
            if ($userId) {
                $filter->addFavoritesFilter($userId, true);
            }
        }
        
        // Числовые диапазоны
        if ($this->metadata->columnExists('scenario_pharma')) {
            $filter->addRangeFilter('scenario_pharma', 
                $params['pharma_from'] ?? null, 
                $params['pharma_to'] ?? null
            );
        }
        
        if ($this->metadata->columnExists('quantity_friends')) {
            $filter->addRangeFilter('quantity_friends',
                $params['friends_from'] ?? null,
                $params['friends_to'] ?? null
            );
        }
        
        // Год создания
        $filter->addYearCreatedFilter(
            $params['year_created_from'] ?? null,
            $params['year_created_to'] ?? null
        );
        
        return $filter;
    }
    
    /**
     * Создание фильтра из массива параметров (для кастомных карточек)
     * Аналогично createFilterFromRequest, но принимает массив напрямую
     */
    public function createFilterFromArray(array $params): FilterBuilder {
        // Используем ту же логику, что и createFilterFromRequest
        return $this->createFilterFromRequest($params);
    }
    
    /**
     * Построение ORDER BY выражения с правильной обработкой NULL значений
     * Централизованная логика для устранения дублирования
     * 
     * @param string $sort Название колонки для сортировки
     * @param string $dir Направление сортировки (ASC/DESC)
     * @return string SQL выражение для ORDER BY
     */
    public function buildOrderBy(string $sort, string $dir = 'ASC'): string {
        $meta = $this->getColumnMetadata();
        
        // Валидация сортировки
        if (!in_array($sort, $meta['all'], true)) {
            $sort = 'id';
        }
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        
        // Сортировка по id — всегда простая, чтобы использовать индекс (избегаем filesort на 90k+ строк).
        // Сложное выражение CASE/TRIM/CAST не использует индекс и даёт 30+ сек в slow log.
        if ($sort === 'id') {
            return "`id` $dir";
        }
        
        // Список колонок, которые должны сортироваться как числа (даже если хранятся как строки)
        $numericLikeColumns = [
            'limit_rk', 'scenario_pharma', 'quantity_friends', 'quantity_fp', 
            'quantity_bm', 'quantity_photo', 'year_created', 
            'birth_day', 'birth_month', 'birth_year'
        ];
        
        $isNumeric = in_array($sort, $meta['numeric'], true) 
            || in_array($sort, $numericLikeColumns, true);
        
        if ($isNumeric) {
            // Для числовых полей: улучшенная обработка пустых значений и нечисловых данных
            // Используем COALESCE и NULLIF для корректной обработки пустых строк
            // TRIM убирает пробелы, CAST конвертирует в число
            $numericExpr = "CAST(COALESCE(NULLIF(TRIM(`$sort`), ''), '0') AS UNSIGNED)";
            
            if ($dir === 'ASC') {
                // NULL и пустые значения идут в конец при ASC
                return "CASE 
                            WHEN `$sort` IS NULL OR TRIM(`$sort`) = '' THEN 1 
                            ELSE 0 
                        END,
                        $numericExpr ASC";
            } else {
                // NULL и пустые значения идут в начало при DESC
                return "CASE 
                            WHEN `$sort` IS NULL OR TRIM(`$sort`) = '' THEN 1 
                            ELSE 0 
                        END DESC,
                        $numericExpr DESC";
            }
        } else {
            // Для текстовых полей: NULL и пустые значения идут в конец при ASC, в начало при DESC
            if ($dir === 'ASC') {
                return "(`$sort` IS NULL OR `$sort` = ''), `$sort` ASC";
            } else {
                return "(`$sort` IS NULL OR `$sort` = '') DESC, `$sort` DESC";
            }
        }
    }
    
    /**
     * Получение списка аккаунтов с фильтрами и пагинацией
     * Делегирует работу в AccountsRepository
     * 
     * @param FilterBuilder $filter Фильтр
     * @param string $sort Колонка для сортировки
     * @param string $dir Направление сортировки
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @param bool|null $includeDeleted Включать ли удалённые записи (для корзины)
     */
    public function getAccounts(FilterBuilder $filter, string $sort = 'id', string $dir = 'ASC', int $limit = 100, int $offset = 0, $includeDeleted = false): array {
        $meta = $this->getColumnMetadata();
        
        // Валидация сортировки
        if (!in_array($sort, $meta['all'], true)) {
            $sort = 'id';
        }
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
        
        // Используем централизованную логику построения ORDER BY
        $orderBy = $this->buildOrderBy($sort, $dir);
        
        // Приводим к bool, если передан null
        if ($includeDeleted === null) {
            $includeDeleted = false;
        }
        $includeDeleted = (bool)$includeDeleted;
        
        // Делегируем в репозиторий
        return $this->repository->getAccounts($filter, $orderBy, $limit, $offset, $includeDeleted);
    }
    
    /**
     * Подсчет количества записей с фильтрами
     * Делегирует работу в AccountsRepository
     * 
     * @param FilterBuilder $filter Фильтр
     * @param bool|null $includeDeleted Включать ли удалённые записи
     */
    public function getAccountsCount(FilterBuilder $filter, $includeDeleted = false): int {
        // Приводим к bool, если передан null
        if ($includeDeleted === null) {
            $includeDeleted = false;
        }
        $includeDeleted = (bool)$includeDeleted;
        
        // Делегируем в репозиторий
        return $this->repository->getAccountsCount($filter, $includeDeleted);
    }
    
    /**
     * Получение статистики (общая и по статусам)
     * Делегирует работу в StatisticsService
     */
    public function getStatistics(FilterBuilder $filter = null): array {
        return $this->statistics->getStatistics($filter);
    }
    
    /**
     * Получение всех уникальных значений фильтров одним запросом
     * Делегирует работу в StatisticsService
     * 
     * @return array Ассоциативный массив с ключами: status, status_marketplace, currency, geo, status_rk
     */
    public function getUniqueFilterValues(): array {
        return $this->statistics->getUniqueFilterValues();
    }
    
    /**
     * Получение списка уникальных статусов
     * Делегирует работу в StatisticsService
     */
    public function getUniqueStatuses(): array {
        return $this->statistics->getUniqueStatuses();
    }
    
    /**
     * Получение списка уникальных статусов marketplace с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueMarketplaceStatuses(): array {
        return $this->statistics->getUniqueMarketplaceStatuses();
    }
    
    /**
     * Получение количества записей с пустым статусом marketplace
     * Делегирует работу в StatisticsService
     */
    public function getEmptyMarketplaceStatusCount(): int {
        return $this->statistics->getEmptyMarketplaceStatusCount();
    }
    
    /**
     * Получение списка уникальных валют (Currency) с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueCurrencies(): array {
        return $this->statistics->getUniqueCurrencies();
    }
    
    /**
     * Получение количества записей с пустой валютой
     * Делегирует работу в StatisticsService
     */
    public function getEmptyCurrencyCount(): int {
        return $this->statistics->getEmptyCurrencyCount();
    }
    
    /**
     * Получение списка уникальных значений geo с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueGeos(): array {
        return $this->statistics->getUniqueGeos();
    }
    
    /**
     * Получение количества записей с пустым geo
     * Делегирует работу в StatisticsService
     */
    public function getEmptyGeoCount(): int {
        return $this->statistics->getEmptyGeoCount();
    }
    
    /**
     * Получение списка уникальных значений status_rk с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueStatusRk(): array {
        return $this->statistics->getUniqueStatusRk();
    }
    
    /**
     * Получение количества записей с пустым status_rk
     * Делегирует работу в StatisticsService
     */
    public function getEmptyStatusRkCount(): int {
        return $this->statistics->getEmptyStatusRkCount();
    }
    
    /**
     * Обновление статуса для выбранных аккаунтов
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function updateStatus(array $ids, string $status): int {
        // Логируем изменения в audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $auditLogger->logBulkChange($ids, 'status', null, $status);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }
        
        // Делегируем в репозиторий
        return $this->repository->updateStatus($ids, $status);
    }
    
    /**
     * Обновление статуса для всех записей по фильтру
     * Делегирует работу в AccountsRepository
     */
    public function updateStatusByFilter(FilterBuilder $filter, string $status): int {
        return $this->repository->updateStatusByFilter($filter, $status);
    }
    
    /**
     * Обновление одного поля для одной записи
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function updateField(int $id, string $field, $value): int {
        // Получаем старое значение для audit log
        $oldValue = null;
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $oldAccount = $this->getAccountById($id);
                $oldValue = $oldAccount[$field] ?? null;
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }
        
        // Делегируем в репозиторий
        $affectedRows = $this->repository->updateField($id, $field, $value);
        
        // Логируем изменение в audit log
        if ($affectedRows > 0 && isset($auditLogger) && $auditLogger->isEnabled()) {
            try {
                $auditLogger->logChange($id, $field, $oldValue, $value);
            } catch (Exception $e) {
                // Игнорируем ошибки audit log
            }
        }
        
        return $affectedRows;
    }
    
    /**
     * Массовое обновление поля
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function bulkUpdateField(array $ids, string $field, $value): int {
        // Логируем изменения в audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                $auditLogger->logBulkChange($ids, $field, null, $value);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }
        
        // Делегируем в репозиторий
        return $this->repository->bulkUpdateField($ids, $field, $value);
    }
    
    /**
     * Удаление аккаунтов по ID (Soft Delete - в корзину)
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function deleteAccounts(array $ids): int {
        // Проверяем, поддерживается ли Soft Delete для логирования
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');
        
        // Делегируем в репозиторий
        $affectedRows = $this->repository->deleteAccounts($ids);
        
        // Логируем удаление в audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                foreach ($ids as $accountId) {
                    $auditLogger->logChange($accountId, 'deleted_at', null, $supportsSoftDelete ? date('Y-m-d H:i:s') : 'DELETED');
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }
        
        return $affectedRows;
    }
    
    /**
     * Удаление аккаунтов по фильтру (Soft Delete - в корзину)
     * Делегирует работу в AccountsRepository с логированием в audit log
     */
    public function deleteAccountsByFilter(FilterBuilder $filter): int {
        // Проверяем, поддерживается ли Soft Delete для логирования
        $supportsSoftDelete = $this->metadata->columnExists('deleted_at');
        
        // Делегируем в репозиторий
        $affectedRows = $this->repository->deleteAccountsByFilter($filter);
        
        // Логируем удаление в audit log
        if ($affectedRows > 0 && $supportsSoftDelete) {
            try {
                $auditLogger = AuditLogger::getInstance();
                if ($auditLogger->isEnabled()) {
                    // Получаем ID удалённых аккаунтов для логирования (недавно удалённые)
                    $deletedQuery = "SELECT id FROM {$this->table} WHERE deleted_at IS NOT NULL AND deleted_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 100";
                    $deletedResult = $this->db->getConnection()->query($deletedQuery);
                    if ($deletedResult) {
                        while ($row = $deletedResult->fetch_assoc()) {
                            $auditLogger->logChange($row['id'], 'deleted_at', null, date('Y-m-d H:i:s'));
                        }
                        $deletedResult->close();
                    }
                }
            } catch (Exception $e) {
                // Игнорируем ошибки audit log
            }
        }
        
        return $affectedRows;
    }

    /**
     * Восстановление аккаунтов из корзины (Soft Delete)
     * Делегирует работу в AccountsRepository с логированием в audit log
     * 
     * @param array $ids Массив ID аккаунтов для восстановления
     * @return int Количество восстановленных аккаунтов
     */
    public function restoreAccounts(array $ids): int {
        // Делегируем в репозиторий
        $affectedRows = $this->repository->restoreAccounts($ids);
        
        // Логируем восстановление в audit log
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled() && $affectedRows > 0) {
                // Получаем ID восстановленных аккаунтов для логирования (используем prepared statement)
                $validIds = array_filter(array_map('intval', $ids));
                if (!empty($validIds)) {
                    $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
                    $restoredQuery = "SELECT id FROM {$this->table} WHERE id IN ($placeholders) AND deleted_at IS NULL LIMIT 100";
                    $restoredStmt = $this->db->getConnection()->prepare($restoredQuery);
                    if ($restoredStmt) {
                        $types = str_repeat('i', count($validIds));
                        $restoredStmt->bind_param($types, ...$validIds);
                        $restoredStmt->execute();
                        $restoredResult = $restoredStmt->get_result();
                        if ($restoredResult) {
                            while ($row = $restoredResult->fetch_assoc()) {
                                $auditLogger->logChange($row['id'], 'deleted_at', date('Y-m-d H:i:s'), null);
                            }
                        }
                        $restoredStmt->close();
                    }
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log
        }
        
        return $affectedRows;
    }
    
    /**
     * Массовое обновление произвольного поля по фильтру
     * Делегирует работу в AccountsRepository
     */
    public function updateFieldByFilter(FilterBuilder $filter, string $field, $value): int {
        return $this->repository->updateFieldByFilter($filter, $field, $value);
    }

    /**
     * Массовое обновление поля по всей таблице (использовать только после явного подтверждения)
     * Делегирует работу в AccountsRepository
     */
    public function updateFieldForAll(string $field, $value): int {
        return $this->repository->updateFieldForAll($field, $value);
    }

    /**
     * Получение одной записи аккаунта по ID
     * Делегирует работу в AccountsRepository
     */
    public function getAccountById(int $id): ?array {
        return $this->repository->getAccountById($id);
    }
    
    /**
     * Создание нового аккаунта
     * Делегирует работу в AccountsRepository с логированием в audit log
     * 
     * @param array $data Массив данных аккаунта
     * @return array Массив с данными созданного аккаунта и его ID
     * @throws InvalidArgumentException При ошибках валидации
     * @throws Exception При ошибках БД
     */
    public function createAccount(array $data): array {
        // Валидация обязательных полей на уровне сервиса
        if (empty($data['login']) || trim((string)$data['login']) === '') {
            throw new InvalidArgumentException('Login is required');
        }
        
        if (empty($data['status']) || trim((string)$data['status']) === '') {
            throw new InvalidArgumentException('Status is required');
        }
        
        // Проверяем, что все поля существуют в метаданных
        $meta = $this->getColumnMetadata();
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $meta['all'], true) && !in_array($field, ['csrf'], true)) {
                // Пропускаем служебные поля (csrf) и предупреждаем о неизвестных
                if ($field !== 'csrf') {
                    Logger::warning("Unknown field in createAccount data", ['field' => $field]);
                }
            }
        }
        
        // Делегируем создание в репозиторий
        $newId = $this->repository->createAccount($data);
        
        // Получаем созданный аккаунт
        $newAccount = $this->getAccountById($newId);
        
        if (!$newAccount) {
            throw new Exception('Failed to retrieve created account');
        }
        
        // Логируем создание через AuditLogger
        try {
            $auditLogger = AuditLogger::getInstance();
            if ($auditLogger->isEnabled()) {
                // Логируем создание каждого заполненного поля как изменение (old_value = null, new_value = значение)
                foreach ($data as $field => $value) {
                    // Пропускаем служебные поля
                    if (in_array($field, ['csrf'], true)) {
                        continue;
                    }
                    
                    // Пропускаем пустые значения для сокращения логов
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    
                    // Проверяем, что поле существует
                    if (!in_array($field, $meta['all'], true)) {
                        continue;
                    }
                    
                    try {
                        $auditLogger->logChange($newId, $field, null, $value);
                    } catch (Exception $e) {
                        // Игнорируем ошибки audit log для отдельных полей
                        Logger::warning("Failed to log field creation in audit log", [
                            'field' => $field,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Логируем общее событие создания аккаунта
                Logger::info('Account created', [
                    'id' => $newId,
                    'login' => $data['login'],
                    'created_by' => $_SESSION['username'] ?? 'unknown'
                ]);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки audit log, но логируем их
            Logger::warning("Audit logging failed for account creation", [
                'account_id' => $newId,
                'error' => $e->getMessage()
            ]);
        }
        
        return [
            'id' => $newId,
            'account' => $newAccount
        ];
    }
    
    /**
     * Массовое создание аккаунтов
     * Делегирует работу в AccountsRepository с логированием в audit log
     * 
     * @param array $accountsData Массив массивов данных аккаунтов
     * @param string $duplicateAction Действие при дубликате: 'skip', 'error'
     * @return array Статистика создания с деталями
     * @throws InvalidArgumentException При ошибках валидации
     * @throws Exception При ошибках БД
     */
    public function createAccountsBulk(array $accountsData, string $duplicateAction = 'skip'): array {
        if (empty($accountsData)) {
            throw new InvalidArgumentException('Accounts data is required');
        }
        
        // Валидация структуры данных
        if (!is_array($accountsData)) {
            throw new InvalidArgumentException('Accounts data must be an array');
        }
        
        // Проверяем, что каждый элемент - массив
        foreach ($accountsData as $idx => $accountData) {
            if (!is_array($accountData)) {
                throw new InvalidArgumentException("Account data at index $idx must be an array");
            }
        }
        
        // Делегируем создание в репозиторий
        $result = $this->repository->createAccountsBulk($accountsData, $duplicateAction);
        
        // Логируем массовое создание (без получения каждого аккаунта для производительности)
        try {
            Logger::info('Bulk accounts created', [
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'errors_count' => count($result['errors']),
                'created_ids_count' => count($result['created_ids'] ?? []),
                'created_by' => $_SESSION['username'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
        
        return $result;
    }
}


