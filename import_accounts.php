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
    
    // Rate limiting для предотвращения злоупотреблений
    $userId = $_SESSION['user_id'] ?? 0;
    
    // Проверка минутного лимита
    $minuteKey = "import_rate_limit_minute_{$userId}";
    $minuteCount = function_exists('apcu_exists') && apcu_exists($minuteKey) ? apcu_fetch($minuteKey) : 0;
    
    if ($minuteCount >= Config::IMPORT_RATE_LIMIT_PER_MINUTE) {
        Logger::warning('IMPORT ACCOUNTS: Превышен минутный лимит', [
            'user_id' => $userId,
            'count' => $minuteCount,
            'limit' => Config::IMPORT_RATE_LIMIT_PER_MINUTE
        ]);
        throw new InvalidArgumentException('Превышен лимит импортов (5 в минуту). Пожалуйста, подождите и попробуйте снова.');
    }
    
    if (function_exists('apcu_store')) {
        apcu_store($minuteKey, $minuteCount + 1, 60); // TTL = 60 секунд
    }
    
    // Проверка часового лимита
    $hourKey = "import_rate_limit_hour_{$userId}";
    $hourCount = function_exists('apcu_exists') && apcu_exists($hourKey) ? apcu_fetch($hourKey) : 0;
    
    if ($hourCount >= Config::IMPORT_RATE_LIMIT_PER_HOUR) {
        Logger::warning('IMPORT ACCOUNTS: Превышен часовой лимит', [
            'user_id' => $userId,
            'count' => $hourCount,
            'limit' => Config::IMPORT_RATE_LIMIT_PER_HOUR
        ]);
        throw new InvalidArgumentException('Превышен лимит импортов (20 в час). Попробуйте через час.');
    }
    
    if (function_exists('apcu_store')) {
        apcu_store($hourKey, $hourCount + 1, 3600); // TTL = 3600 секунд (1 час)
    }
    
    Logger::debug('IMPORT ACCOUNTS: Rate limit проверен', [
        'user_id' => $userId,
        'minute_count' => $minuteCount + 1,
        'hour_count' => $hourCount + 1
    ]);
    
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
    $service = new AccountsService();
    $meta = $service->getColumnMetadata();
    $allColumns = $meta['all'];
    Logger::debug('IMPORT ACCOUNTS: Метаданные колонок получены', ['columns_count' => count($allColumns)]);
    
    // РЕФАКТОРИНГ: Используем класс CsvParser вместо функции
    // Старая функция parseCSVForImport() удалена, логика вынесена в CsvParser
    
    Logger::debug('IMPORT ACCOUNTS: Начало парсинга CSV файла', ['tmp_name' => $file['tmp_name'] ?? 'not_set']);
    
    $parser = new CsvParser(Config::MAX_IMPORT_ROWS);
    $data = $parser->parse($file['tmp_name']);
    
    // УДАЛЕНО: ~120 строк кода функции parseCSVForImport()
    // Теперь используется CsvParser из includes/CsvParser.php (см. выше)
    
    // Старый код parseCSVForImport() был заменён на класс CsvParser
    // Это даёт преимущества:
    // 1. Переиспользование кода (можно использовать в других местах)
    // 2. Легче тестировать
    // 3. Соответствует принципу Single Responsibility
    
    if (false) {
    // DEPRECATED: Старая функция parseCSVForImport удалена
    function parseCSVForImport_WILL_BE_REMOVED($filePath) {
        Logger::debug('PARSE CSV: Начало парсинга', ['file_path' => $filePath, 'file_exists' => file_exists($filePath)]);
        
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            Logger::error('PARSE CSV: Не удалось открыть файл', ['file_path' => $filePath]);
            throw new Exception('Не удалось открыть файл для чтения');
        }
        
        // Определяем разделитель
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            Logger::warning('PARSE CSV: Не удалось прочитать первую строку');
            fclose($handle);
            return [];
        }
        
        $delimiter = strpos($firstLine, ';') !== false ? ';' : ',';
        Logger::debug('PARSE CSV: Определен разделитель', ['delimiter' => $delimiter, 'first_line_preview' => substr($firstLine, 0, 100)]);
        rewind($handle);
        
        // Читаем заголовки
        $headers = fgetcsv($handle, 0, $delimiter);
        if ($headers === false || empty($headers)) {
            Logger::warning('PARSE CSV: Не удалось прочитать заголовки', ['headers' => $headers]);
            fclose($handle);
            return [];
        }
        
        $headers = array_map('trim', $headers);
        Logger::debug('PARSE CSV: Заголовки прочитаны', ['headers_count' => count($headers), 'headers' => $headers]);
        
        // Нормализуем заголовки (убираем пробелы, приводим к нижнему регистру)
        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $original = $header;
            $normalized = mb_strtolower(trim($header), 'UTF-8');
            
            // Удаляем BOM (\xEF\xBB\xBF) и непечатаемые ASCII символы
            $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized);
            $normalized = preg_replace('/[\x00-\x1F\x7F]/', '', $normalized);
            
            // Заменяем пробелы и различные типы тире на подчеркивания
            $normalized = str_replace([' ', '-', '—', '–'], '_', $normalized);
            // Убираем множественные подчеркивания
            $normalized = preg_replace('/_+/', '_', $normalized);
            // Убираем подчеркивания в начале и конце
            $normalized = trim($normalized, '_');
            
            Logger::debug("PARSE CSV: Нормализация заголовка #{$index}", [
                'original' => $original,
                'normalized' => $normalized
            ]);
            
            $normalizedHeaders[] = $normalized;
        }
        
        Logger::debug('PARSE CSV: Заголовки нормализованы', [
            'normalized_headers' => $normalizedHeaders,
            'original_headers' => $headers
        ]);
        
        $data = [];
        $lineNum = 0;
        $maxRows = 10000; // Защита от слишком больших файлов
        $skippedEmpty = 0;
        $skippedMismatch = 0;
        
        // Читаем данные построчно
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false && $lineNum < $maxRows) {
            $lineNum++;
            
            // Пропускаем пустые строки
            if (empty(array_filter($values, function($v) { return trim($v) !== ''; }))) {
                $skippedEmpty++;
                continue;
            }
            
            // Если количество колонок не совпадает, пропускаем строку
            if (count($values) !== count($headers)) {
                $skippedMismatch++;
                if ($lineNum <= 3) { // Логируем только первые 3 несоответствия
                    Logger::debug('PARSE CSV: Пропуск строки из-за несоответствия колонок', [
                        'line' => $lineNum,
                        'values_count' => count($values),
                        'headers_count' => count($headers)
                    ]);
                }
                continue;
            }
            
            $row = [];
            foreach ($normalizedHeaders as $index => $header) {
                $row[$header] = isset($values[$index]) ? trim($values[$index]) : '';
            }
            
            $data[] = $row;
            
            // Логируем первые 2 строки для отладки
            if (count($data) <= 2) {
                Logger::debug('PARSE CSV: Пример распарсенной строки', [
                    'row_number' => count($data),
                    'row_data' => $row
                ]);
            }
        }
        
        fclose($handle);
        
        Logger::info('PARSE CSV: Парсинг завершен', [
            'total_lines_read' => $lineNum,
            'rows_parsed' => count($data),
            'skipped_empty' => $skippedEmpty,
            'skipped_mismatch' => $skippedMismatch,
            'sample_keys' => !empty($data) ? array_keys($data[0] ?? []) : []
        ]);
        
        return $data;
    }
    } // Конец deprecated функции (if (false) {...})
    
    // Используется новый CsvParser (см. выше строка ~122)
    Logger::debug('IMPORT ACCOUNTS: CSV файл распарсен', [
        'rows_count' => count($data),
        'first_row' => !empty($data) ? array_keys($data[0] ?? []) : []
    ]);
    
    if (empty($data)) {
        Logger::warning('IMPORT ACCOUNTS: Файл не содержит данных после парсинга');
        throw new InvalidArgumentException('Файл не содержит данных или имеет неверный формат');
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
