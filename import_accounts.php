<?php
/**
 * Импорт аккаунтов для добавления (упрощенная версия через AJAX)
 * Использует AccountsService::createAccountsBulk для массового создания
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/Validator.php';
require_once __DIR__ . '/includes/ErrorHandler.php';
require_once __DIR__ . '/includes/CsvParser.php';

// Устанавливаем заголовки JSON для всех ответов API
header('Content-Type: application/json; charset=utf-8');

// Логирование для отладки
Logger::debug('IMPORT ACCOUNTS: Начало обработки запроса', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'post_keys' => array_keys($_POST),
    'files_keys' => isset($_FILES) ? array_keys($_FILES) : [],
    'has_import_file' => isset($_FILES['import_file'])
]);

try {
    Logger::debug('IMPORT ACCOUNTS: Проверка авторизации...');
    requireAuth();
    checkSessionTimeout();
    Logger::debug('IMPORT ACCOUNTS: Авторизация успешна');
    
    // Rate limiting через файловый RateLimiter (работает без APCu)
    $userId = $_SESSION['username'] ?? 'anonymous';
    $limiter = new RateLimiter();
    $importKeyMinute = 'import_minute_' . md5($userId);
    $importKeyHour = 'import_hour_' . md5($userId);

    if (!$limiter->checkLimit($importKeyMinute, Config::IMPORT_RATE_LIMIT_PER_MINUTE, 60)) {
        Logger::warning('IMPORT ACCOUNTS: Превышен минутный лимит', ['user' => $userId]);
        throw new InvalidArgumentException('Превышен лимит импортов (' . Config::IMPORT_RATE_LIMIT_PER_MINUTE . ' в минуту). Подождите и попробуйте снова.');
    }
    if (!$limiter->checkLimit($importKeyHour, Config::IMPORT_RATE_LIMIT_PER_HOUR, 3600)) {
        Logger::warning('IMPORT ACCOUNTS: Превышен часовой лимит', ['user' => $userId]);
        throw new InvalidArgumentException('Превышен лимит импортов (' . Config::IMPORT_RATE_LIMIT_PER_HOUR . ' в час). Попробуйте через час.');
    }
    Logger::debug('IMPORT ACCOUNTS: Rate limit проверен', ['user' => $userId]);
    
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Logger::warning('IMPORT ACCOUNTS: Неверный метод запроса', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
        throw new InvalidArgumentException('Only POST method allowed');
    }
    
    // Проверка CSRF
    $csrf = $_POST['csrf'] ?? '';
    Logger::debug('IMPORT ACCOUNTS: Проверка CSRF токена', ['csrf_present' => !empty($csrf), 'csrf_length' => strlen($csrf)]);
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('IMPORT ACCOUNTS: CSRF validation failed', ['csrf' => substr($csrf, 0, 20) . '...']);
        throw new InvalidArgumentException('CSRF validation failed');
    }
    Logger::debug('IMPORT ACCOUNTS: CSRF токен валиден');
    
    // Проверка загрузки файла
    Logger::debug('IMPORT ACCOUNTS: Проверка загрузки файла', [
        'has_import_file' => isset($_FILES['import_file']),
        'file_error' => $_FILES['import_file']['error'] ?? 'not_set'
    ]);
    
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Ошибка загрузки файла';
        if (isset($_FILES['import_file']['error'])) {
            switch ($_FILES['import_file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMsg = 'Файл слишком большой';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMsg = 'Файл загружен частично';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMsg = 'Файл не был загружен';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errorMsg = 'Временная папка отсутствует';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errorMsg = 'Ошибка записи файла';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errorMsg = 'Загрузка файла остановлена расширением';
                    break;
            }
        }
        Logger::error('IMPORT ACCOUNTS: Ошибка загрузки файла', ['error_code' => $_FILES['import_file']['error'] ?? 'unknown', 'error_msg' => $errorMsg]);
        throw new InvalidArgumentException($errorMsg);
    }
    
    $file = $_FILES['import_file'];
    $format = $_POST['format'] ?? 'csv';
    $duplicateAction = $_POST['duplicate_action'] ?? 'skip';
    
    // Проверка, что файл был загружен через HTTP POST (защита от LFI)
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        Logger::error('IMPORT ACCOUNTS: Файл не является загруженным через HTTP POST', ['tmp_name' => $file['tmp_name'] ?? 'n/a']);
        throw new InvalidArgumentException('Недействительный файл загрузки');
    }
    
    Logger::debug('IMPORT ACCOUNTS: Информация о файле', [
        'name' => $file['name'] ?? 'unknown',
        'size' => $file['size'] ?? 0,
        'type' => $file['type'] ?? 'unknown',
        'tmp_name' => isset($file['tmp_name']) ? 'set' : 'not_set',
        'format' => $format,
        'duplicate_action' => $duplicateAction
    ]);
    
    // Валидация размера файла
    if ($file['size'] > Config::MAX_REQUEST_SIZE) {
        Logger::warning('IMPORT ACCOUNTS: Файл слишком большой', [
            'file_size' => $file['size'],
            'max_size' => Config::MAX_REQUEST_SIZE
        ]);
        throw new InvalidArgumentException('Файл слишком большой. Максимальный размер: ' . (Config::MAX_REQUEST_SIZE / 1024 / 1024) . ' MB');
    }
    
    // Валидация формата
    if (!in_array($format, ['csv'], true)) {
        Logger::warning('IMPORT ACCOUNTS: Неподдерживаемый формат', ['format' => $format]);
        throw new InvalidArgumentException('Поддерживается только формат CSV');
    }
    
    Logger::debug('IMPORT ACCOUNTS: Инициализация сервиса...');
    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();
    $allColumns = $meta['all'];
    Logger::debug('IMPORT ACCOUNTS: Метаданные колонок получены', ['columns_count' => count($allColumns)]);
    
    // РЕФАКТОРИНГ: Используем класс CsvParser вместо функции
    // Старая функция parseCSVForImport() удалена, логика вынесена в CsvParser
    
    Logger::debug('IMPORT ACCOUNTS: Начало парсинга CSV файла', [
        'tmp_name' => $file['tmp_name'] ?? 'not_set',
        'file_exists' => file_exists($file['tmp_name'] ?? ''),
        'file_size' => filesize($file['tmp_name'] ?? '') ?: 0,
        'php_version' => PHP_VERSION
    ]);

    $parser = new CsvParser(Config::MAX_IMPORT_ROWS);
    $data = $parser->parse($file['tmp_name']);
    
    // УДАЛЕНО: ~120 строк кода функции parseCSVForImport()
    // Теперь используется CsvParser из includes/CsvParser.php (см. выше)
    
    // Старый код parseCSVForImport() был заменён на класс CsvParser
    // Это даёт преимущества:
    // 1. Переиспользование кода (можно использовать в других местах)
    // 2. Легче тестировать
    // 3. Соответствует принципу Single Responsibility
    
    // CSV-парсинг выполнен через CsvParser (строка ~172)
    Logger::debug('IMPORT ACCOUNTS: CSV файл распарсен', [
        'rows_count' => count($data),
        'first_row' => !empty($data) ? array_keys($data[0] ?? []) : []
    ]);
    
    if (empty($data)) {
        Logger::warning('IMPORT ACCOUNTS: Файл не содержит данных после парсинга', [
            'php_version' => PHP_VERSION,
            'file_size' => $file['size'] ?? 0,
            'tmp_exists' => file_exists($file['tmp_name'] ?? ''),
            'tmp_size' => @filesize($file['tmp_name'] ?? '') ?: 0
        ]);
        throw new InvalidArgumentException(
            'Файл не содержит данных или имеет неверный формат (PHP ' . PHP_VERSION . ', size=' . ($file['size'] ?? 0) . ')'
        );
    }
    
    // Фильтруем данные - оставляем только существующие колонки
    Logger::debug('IMPORT ACCOUNTS: Начало фильтрации данных', [
        'total_rows' => count($data),
        'available_columns' => count($allColumns)
    ]);
    
    // ОДИН РАЗ создаём хеш-таблицу для O(1) поиска
    $columnMapping = [];
    foreach ($allColumns as $dbCol) {
        $columnMapping[mb_strtolower($dbCol, 'UTF-8')] = $dbCol;
    }
    
    Logger::debug('IMPORT ACCOUNTS: Маппинг колонок создан', [
        'total_columns' => count($columnMapping)
    ]);
    
    // Системные поля, которые нужно пропустить
    $systemFields = ['id', 'created_at', 'updated_at', 'deleted_at'];
    
    $filteredData = [];
    $unknownCols = []; // Без static!
    
    foreach ($data as $rowIndex => $row) {
        $filteredRow = [];
        
        foreach ($row as $key => $value) {
            $keyLower = mb_strtolower(trim($key), 'UTF-8');
            $foundKey = $columnMapping[$keyLower] ?? null;
            
            if ($foundKey && !in_array($foundKey, $systemFields, true)) {
                $filteredRow[$foundKey] = is_string($value) ? trim($value) : $value;
            } elseif (!$foundKey && !isset($unknownCols[$key])) {
                Logger::warning("IMPORT ACCOUNTS: Неизвестная колонка '{$key}'");
                $unknownCols[$key] = true;
            }
        }
        
        if (!empty($filteredRow)) {
            $filteredData[] = $filteredRow;
        }
    }
    
    Logger::debug('IMPORT ACCOUNTS: Фильтрация завершена', [
        'original_rows' => count($data),
        'filtered_rows' => count($filteredData),
        'unknown_columns' => array_keys($unknownCols)
    ]);
    
    if (empty($filteredData)) {
        Logger::warning('IMPORT ACCOUNTS: После фильтрации не осталось валидных данных', [
            'original_rows' => count($data),
            'all_columns' => $allColumns,
            'first_row_keys' => !empty($data) ? array_keys($data[0] ?? []) : []
        ]);
        throw new InvalidArgumentException('Файл не содержит валидных данных. Проверьте, что колонки соответствуют структуре шаблона.');
    }
    
    Logger::info('IMPORT ACCOUNTS: Начало массового создания аккаунтов', [
        'total_rows' => count($data),
        'filtered_rows' => count($filteredData),
        'duplicate_action' => $duplicateAction
    ]);
    
    // Используем bulk создание через сервис
    try {
        $result = $service->createAccountsBulk($filteredData, $duplicateAction);
        
        Logger::info('IMPORT ACCOUNTS: Массовое создание завершено', [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'errors_count' => count($result['errors']),
            'total' => count($filteredData)
        ]);
        
        if (!empty($result['errors'])) {
            Logger::warning('IMPORT ACCOUNTS: Обнаружены ошибки при создании', [
                'errors_sample' => array_slice($result['errors'], 0, 5)
            ]);
        }
        
        json_success([
            'message' => sprintf(
                'Создано: %d, Обновлено: %d, Пропущено: %d, Ошибок: %d',
                $result['created'],
                $result['updated'] ?? 0,
                $result['skipped'],
                count($result['errors'])
            ),
            'created' => $result['created'],
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'],
            'skipped_details' => $result['skipped_details'] ?? [], // НОВОЕ: Детали пропущенных
            'errors' => $result['errors'],
            'total' => count($filteredData)
        ]);
    } catch (Exception $bulkError) {
        Logger::error('IMPORT ACCOUNTS: Ошибка при массовом создании аккаунтов', [
            'message' => $bulkError->getMessage(),
            'trace' => $bulkError->getTraceAsString()
        ]);
        throw $bulkError;
    }
    
} catch (Throwable $e) {
    Logger::error('IMPORT ACCOUNTS: КРИТИЧЕСКАЯ ОШИБКА', [
        'exception_type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'post_data_keys' => array_keys($_POST),
        'files_info' => isset($_FILES['import_file']) ? [
            'name' => $_FILES['import_file']['name'] ?? 'unknown',
            'size' => $_FILES['import_file']['size'] ?? 0,
            'error' => $_FILES['import_file']['error'] ?? 'unknown'
        ] : 'no_file'
    ]);
    
    $httpCode = 500;
    if ($e instanceof InvalidArgumentException) {
        $httpCode = 400;
        Logger::debug('IMPORT ACCOUNTS: Определен HTTP код 400 (Bad Request)');
    } elseif (strpos($e->getMessage(), 'not authenticated') !== false || 
              strpos($e->getMessage(), 'Unauthorized') !== false) {
        $httpCode = 401;
        Logger::debug('IMPORT ACCOUNTS: Определен HTTP код 401 (Unauthorized)');
    } else {
        Logger::debug('IMPORT ACCOUNTS: Определен HTTP код 500 (Internal Server Error)');
    }
    
    ErrorHandler::handleError($e, 'Import Accounts', $httpCode);
}
