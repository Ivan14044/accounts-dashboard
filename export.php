<?php
// export.php — экспорт CSV/TXT (с поддержкой выбранных записей)
// Увеличиваем лимиты для экспорта больших объемов данных
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300'); // 5 минут
set_time_limit(300);

// Устанавливаем кодировку UTF-8 для корректной обработки данных
mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');
mb_http_output('UTF-8');

// Включаем обработку ошибок в самом начале
error_reporting(E_ALL);
ini_set('display_errors', 0); // Не показываем ошибки пользователю, только логируем

// ОТКЛЮЧАЕМ БУФЕРИЗАЦИЮ В САМОМ НАЧАЛЕ, ДО ЗАГРУЗКИ ЗАВИСИМОСТЕЙ
// Это критично для корректной работы заголовков Content-Disposition
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('output_buffering', 0);
// Очищаем все существующие буферы
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { 
        @ob_end_clean(); 
    }
}

// Загружаем Logger первым, чтобы можно было логировать ошибки
require_once __DIR__ . '/includes/Logger.php';

/**
 * Write a CSV row using RFC 4180 rules (no backslash escape).
 * On PHP 7.4+ delegates to fputcsv with escape=''.
 * On PHP < 7.4 writes manually: fields are quoted when they contain
 * the delimiter, a double-quote, or a newline; quotes are escaped as "".
 */
function writeCsvRow($handle, array $fields, string $delimiter = ';') {
    if (PHP_VERSION_ID >= 70400) {
        return fputcsv($handle, $fields, $delimiter, '"', '');
    }
    $out = [];
    foreach ($fields as $field) {
        $field = (string)$field;
        if (strpos($field, '"') !== false || strpos($field, $delimiter) !== false
            || strpos($field, "\n") !== false || strpos($field, "\r") !== false) {
            $out[] = '"' . str_replace('"', '""', $field) . '"';
        } else {
            $out[] = $field;
        }
    }
    return fwrite($handle, implode($delimiter, $out) . "\n");
}

// Обработчик фатальных ошибок
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (class_exists('Logger')) {
            Logger::error('EXPORT: Fatal error', [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);
        }
    }
});

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/includes/Utils.php';
    require_once __DIR__ . '/includes/RequestHandler.php';
    require_once __DIR__ . '/includes/AccountsService.php';
    require_once __DIR__ . '/includes/Config.php';
    require_once __DIR__ . '/includes/RateLimitMiddleware.php';
    require_once __DIR__ . '/includes/Validator.php';
} catch (Exception $e) {
    Logger::error('EXPORT: Failed to load dependencies', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    die('Export failed: Failed to load dependencies');
} catch (Throwable $e) {
    Logger::error('EXPORT: Fatal error loading dependencies', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    die('Export failed: Fatal error');
}

// Проверяем авторизацию
try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('export'); // Более строгий лимит для экспорта
} catch (Exception $e) {
    Logger::error('EXPORT: Auth/rate limit error', ['error' => $e->getMessage()]);
    http_response_code(403);
    die('Export failed: Authentication error');
}

// Требуем POST + валидный CSRF-токен.
// Это блокирует CSRF-эксфильтрацию данных через <img src=export.php?...>.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Logger::warning('EXPORT: Rejecting non-POST request', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=utf-8');
    die('Export requires POST with CSRF token');
}
$csrfToken = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
try {
    Validator::validateCsrfToken((string)$csrfToken);
} catch (Throwable $csrfErr) {
    Logger::warning('EXPORT: CSRF validation failed');
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('Export failed: CSRF validation failed');
}

// Буферизация уже отключена в начале файла
// Дополнительно убеждаемся, что буферы чисты
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { 
        @ob_end_clean(); 
    }
}

Logger::info('EXPORT: ===== EXPORT STARTED =====', [
    'format' => $_GET['format'] ?? 'csv',
    'select_all' => $_GET['select'] ?? 'no',
    'ids' => $_GET['ids'] ?? 'none',
    'cols' => $_GET['cols'] ?? 'none',
    'sort' => $_GET['sort'] ?? 'none',
    'dir' => $_GET['dir'] ?? 'none',
    'all_params' => $_GET,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'output_buffering' => ini_get('output_buffering')
]);

// Получаем параметры
try {
    // IDs могут приходить через GET (малые списки) или POST (большие списки >2000 символов)
    $ids = get_param('ids');
    if ($ids === '' && isset($_POST['ids'])) {
        $ids = trim((string)$_POST['ids']);
    }
    $selectAll = get_param('select') === 'all' || (isset($_POST['select']) && $_POST['select'] === 'all');
    $format = strtolower(get_param('format', isset($_POST['format']) ? $_POST['format'] : 'csv'));
    $colsParam = get_param('cols') ?: (isset($_POST['cols']) ? trim((string)$_POST['cols']) : '');
    
    Logger::info('EXPORT: Parameters parsed', [
        'ids' => $ids,
        'ids_length' => strlen($ids ?? ''),
        'selectAll' => $selectAll,
        'format' => $format,
        'colsParam' => $colsParam,
        'colsParam_length' => strlen($colsParam ?? '')
    ]);
} catch (Exception $e) {
    Logger::error('EXPORT: Failed to parse parameters', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    die('Export failed: Parameter parsing error');
}

// Инициализируем сервис
try {
    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();
    $allCols = $meta['all'];
    $numericMeta = $meta['numeric'];
    
    Logger::info('EXPORT: Service initialized', [
        'columns_count' => count($allCols ?? []),
        'numeric_count' => count($numericMeta ?? [])
    ]);
} catch (Exception $e) {
    Logger::error('EXPORT: Failed to initialize service', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    die('Export failed: Service initialization error');
}

// Определяем параметры запроса
$queryParams = [];
$orderBy = 'id ASC';
$idArray = [];

// Если экспортируем конкретные ID
if ($ids !== '' && !$selectAll) {
    $idArray = array_filter(array_map('intval', explode(',', $ids)));
    Logger::info('EXPORT: Processing specific IDs', [
        'ids_count' => count($idArray),
        'ids_sample' => array_slice($idArray, 0, 10) // Первые 10 для примера
    ]);
    if (!empty($idArray)) {
        $queryParams['ids'] = $idArray;
        $orderBy = "FIELD(id, " . implode(',', $idArray) . ")";
    }
} else {
    // Используем фильтры из запроса (поддержка GET и POST)
    $queryParams = array_merge($_GET, $_POST);
    
    // Нормализуем параметр status для правильной обработки
    // Если передан status[] как массив, преобразуем в status
    if (isset($queryParams['status[]']) && is_array($queryParams['status[]'])) {
        $queryParams['status'] = $queryParams['status[]'];
        unset($queryParams['status[]']);
    }
    // Если status передан как массив через status[0], status[1] и т.д., собираем в массив
    if (isset($queryParams['status']) && is_array($queryParams['status'])) {
        // Уже массив, оставляем как есть
        $queryParams['status'] = array_filter($queryParams['status']);
    }
    
    Logger::info('EXPORT: Query params normalized', [
        'status_is_array' => isset($queryParams['status']) && is_array($queryParams['status']),
        'status_count' => isset($queryParams['status']) && is_array($queryParams['status']) ? count($queryParams['status']) : 0
    ]);
    
    // Сортировка (используем централизованную логику из RequestHandler)
    try {
        $sortParams = RequestHandler::getSortParams($allCols);
        $sort = $sortParams['sort'];
        $dir = $sortParams['dir'];
        
        // Используем метод buildOrderBy из AccountsService для устранения дублирования
        $orderBy = $service->buildOrderBy($sort, $dir);
    } catch (Exception $e) {
        Logger::error('EXPORT: Failed to get sort params', [
            'error' => $e->getMessage()
        ]);
        // Используем значения по умолчанию
        $sort = 'id';
        $dir = 'ASC';
        $orderBy = $service->buildOrderBy($sort, $dir);
    }
}

// ЗАЩИТА: Требуем хотя бы один параметр для экспорта
$hasStatus = !empty($_GET['status']) || !empty($_GET['status[]']);
$hasQuery = !empty($_GET['q']);
// Проверяем ВСЕ возможные фильтры, не только status и q
$otherFilterKeys = ['status_marketplace', 'currency', 'geo', 'status_rk', 'has_email', 'has_2fa',
    'has_token', 'has_avatar', 'has_cover', 'has_password', 'has_fp', 'fully_filled', 'favorites',
    'limit_rk_from', 'limit_rk_to', 'scenario_pharma_from', 'scenario_pharma_to',
    'quantity_friends_from', 'quantity_friends_to', 'bm_status', 'year_created_from', 'year_created_to',
    'empty_status'];
$hasOtherFilters = false;
foreach ($otherFilterKeys as $fk) {
    if (!empty($_GET[$fk])) { $hasOtherFilters = true; break; }
}
if (empty($idArray) && !$selectAll && !$hasQuery && !$hasStatus && !$hasOtherFilters) {
    Logger::warning('EXPORT: Blocked - no filters provided', [
        'has_status' => $hasStatus,
        'has_query' => $hasQuery,
        'selectAll' => $selectAll,
        'ids_count' => count($idArray)
    ]);
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    die("ERROR: Export requires at least one filter parameter.\n\n"
        . "Security: Cannot export all records without filters.\n"
        . "Please specify one of:\n"
        . "  - q=search_term (search filter)\n"
        . "  - status=value (status filter)\n"
        . "  - ids=1,2,3 (specific IDs)\n"
        . "  - select=all (for explicit bulk export)\n");
}

// Получаем данные через AccountsService (единообразная логика)
$totalRows = 0;
$filter = null;
try {
    Logger::info('EXPORT: Creating filter', [
        'queryParams_keys' => array_keys($queryParams),
        'queryParams_status' => isset($queryParams['status']) ? (is_array($queryParams['status']) ? $queryParams['status'] : $queryParams['status']) : 'not_set',
        'queryParams_status_type' => gettype($queryParams['status'] ?? null),
        'selectAll' => $selectAll
    ]);
    
    // Создаём фильтр через сервис
    $filter = $service->createFilterFromRequest($queryParams);
    
    Logger::info('EXPORT: Filter created', [
        'filter_class' => get_class($filter),
        'conditions_count' => method_exists($filter, 'getConditionsCount') ? $filter->getConditionsCount() : 'unknown'
    ]);
    
    // Для конкретных ID используем их количество напрямую
    if (!empty($idArray) && !$selectAll) {
        $totalRows = count($idArray);
        Logger::info('EXPORT: Using IDs count', ['count' => $totalRows]);
    } else {
        // Подсчитываем количество строк
        Logger::info('EXPORT: Counting accounts', ['selectAll' => $selectAll]);
        try {
            $totalRows = $service->getAccountsCount($filter);
            Logger::info('EXPORT: Accounts count retrieved', ['count' => $totalRows]);
        } catch (Exception $countError) {
            Logger::error('EXPORT: Failed to get accounts count', [
                'error' => $countError->getMessage(),
                'trace' => $countError->getTraceAsString()
            ]);
            throw $countError;
        }
    }
    
    // Ограничиваем максимальное количество записей для экспорта
    $maxRecords = Config::MAX_EXPORT_RECORDS;
    
    // ПРОВЕРКА: Кастомный лимит от пользователя
    $userLimit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    if ($userLimit !== null && $userLimit !== false && $userLimit > 0) {
        $totalRows = min($totalRows, $userLimit);
        Logger::info('EXPORT: Applying user-specified limit', [
            'total' => $totalRows,
            'limit' => $userLimit
        ]);
    }
    
    if ($totalRows > $maxRecords) {
        Logger::warning('EXPORT: Too many records, limiting', [
            'total' => $totalRows,
            'limit' => $maxRecords
        ]);
        $totalRows = $maxRecords;
    }
    
    // Проверяем, что totalRows больше 0
    if ($totalRows <= 0) {
        Logger::warning('EXPORT: No records to export', [
            'total_rows' => $totalRows,
            'selectAll' => $selectAll,
            'has_ids' => !empty($idArray)
        ]);
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        die('No records found to export');
    }
    
    Logger::info('EXPORT: Starting export', [
        'total_rows' => $totalRows, 
        'format' => $format,
        'has_ids' => !empty($idArray),
        'ids_count' => count($idArray ?? []),
        'selectAll' => $selectAll,
        'filter_class' => get_class($filter),
        'useStreaming' => ($totalRows > 1000) || $selectAll
    ]);

} catch (Exception $e) {
    Logger::error('EXPORT: Failed to prepare export', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    // Чистим буферы перед выводом ошибки
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('Export failed: ' . htmlspecialchars($e->getMessage()));
} catch (Throwable $e) {
    Logger::error('EXPORT: Fatal error preparing export', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    // Чистим буферы перед выводом ошибки
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('Export failed: ' . htmlspecialchars($e->getMessage()));
}

// Устанавливаем заголовки с правильным именем файла ДО начала вывода данных
$obLevel = function_exists('ob_get_level') ? ob_get_level() : 0;
Logger::info('EXPORT: Setting headers', [
    'format' => $format,
    'ob_level' => $obLevel,
    'headers_sent' => headers_sent(),
    'total_rows' => $totalRows
]);

// Устанавливаем заголовки только если они еще не отправлены
if (!headers_sent()) {
    if ($format === 'txt') {
        // Чистим буферы перед началом вывода (на всякий случай)
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
        }
        header('Content-Type: text/plain; charset=utf-8');
        $dateStr = date('Y-m-d_H-i-s');
        $filename = "accounts_{$totalRows}_{$dateStr}.txt";
        // Используем правильное экранирование имени файла
        $filenameSafe = rawurlencode($filename);
        header("Content-Disposition: attachment; filename=\"{$filename}\"; filename*=UTF-8''{$filenameSafe}");
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        Logger::info('EXPORT: TXT headers set', ['filename' => $filename]);
    } else {
        // Чистим буферы перед началом вывода (на всякий случай)
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
        }
        header('Content-Type: text/csv; charset=utf-8');
        $csvDateStr = date('Y-m-d_H-i-s');
        $csvFilename = "accounts_{$totalRows}_{$csvDateStr}.csv";
        $csvFilenameSafe = rawurlencode($csvFilename);
        header("Content-Disposition: attachment; filename=\"{$csvFilename}\"; filename*=UTF-8''{$csvFilenameSafe}");
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        // Добавляем BOM для корректного отображения UTF-8 в Excel
        echo "\xEF\xBB\xBF";
        Logger::info('EXPORT: CSV headers set');
    }
} else {
    // Если заголовки уже отправлены, это критическая ошибка
    $sentFile = '';
    $sentLine = 0;
    headers_sent($sentFile, $sentLine);
    Logger::error('EXPORT: Headers already sent, cannot set download headers', [
        'headers_sent_file' => $sentFile ?: 'unknown',
        'headers_sent_line' => $sentLine ?: 'unknown'
    ]);
    http_response_code(500);
    die('Export failed: Headers already sent');
}

// Обертываем весь процесс экспорта в try-catch
try {
if ($format === 'txt') {
    Logger::info('EXPORT: Starting TXT export processing');
    
    // Экспорт в TXT: поддержка pipe-delimited для выбранных колонок
    $output = fopen('php://output', 'w');
    if (!$output) {
        Logger::error('EXPORT: Failed to open output stream');
        die('Failed to open output stream');
    }
    
    // Добавляем BOM для UTF-8, чтобы редакторы правильно определяли кодировку
    fwrite($output, "\xEF\xBB\xBF");
    
    $EOL = "\r\n"; // Корректные переводы строк для Windows-редакторов

    // Список допустимых ключей полей (все колонки из базы)
    $allKeys = $allCols;

    $selectedCols = [];
    if ($colsParam !== '') {
        foreach (explode(',', $colsParam) as $c) {
            $c = trim($c);
            if (in_array($c, $allKeys, true)) { $selectedCols[] = $c; }
        }
        // Убедимся, что есть хотя бы одна колонка
        $selectedCols = array_values(array_unique($selectedCols));
    }
    
    // Если колонки не указаны или пустые, используем значения по умолчанию
    if (empty($selectedCols)) {
        $selectedCols = ['id','login','email','status'];
    }

    // Функция санитизации ячейки
    $sanitizeCell = function($v) {
        if ($v === null) return '';
        $s = (string)$v;
        // Убеждаемся что строка в UTF-8
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'auto');
        }
        // Уберём переводы строк/трубы, чтобы не ломать формат
        $s = str_replace(["\r","\n","|"], [' ',' ',' '], $s);
        // Удаляем управляющие символы, но сохраняем UTF-8
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = trim($s);
        // Защита от CSV/formula injection при открытии в Excel
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) {
            $s = "'" . $s;
        }
        return $s;
    };

    // Определяем стратегию обработки
    $sort = get_param('sort', 'id');
    $dir = get_param('dir', 'ASC');
    $exportedCount = 0;
    
    // Если выбраны конкретные ID и их немного - загружаем все сразу
    // Если много записей или selectAll - используем потоковую обработку
    $useStreaming = ($totalRows > 1000) || $selectAll;
    
    Logger::info('EXPORT: Processing strategy determined', [
        'useStreaming' => $useStreaming,
        'totalRows' => $totalRows,
        'selectAll' => $selectAll,
        'hasIds' => !empty($idArray),
        'selectedColsCount' => count($selectedCols)
    ]);
    
    if ($selectedCols) {
        // Pipe-delimited без шапки, значения по выбранным колонкам
        if (!$useStreaming && !empty($idArray)) {
            // Для конкретных ID загружаем все сразу (их обычно немного)
            Logger::info('EXPORT: Loading all IDs at once', [
                'ids_count' => count($idArray),
                'total_rows' => $totalRows
            ]);
            try {
                $accounts = $service->getAccounts($filter, $sort, $dir, $totalRows, 0);
                Logger::info('EXPORT: Accounts loaded', [
                    'accounts_count' => count($accounts ?? []),
                    'selected_cols_count' => count($selectedCols)
                ]);
                
                foreach ($accounts as $row) {
                    $line = [];
                    foreach ($selectedCols as $key) {
                        $line[] = $sanitizeCell($row[$key] ?? '');
                    }
                    fwrite($output, implode('|', $line) . $EOL);
                    $exportedCount++;
                }
                
                unset($accounts);
            } catch (Exception $e) {
                Logger::error('EXPORT: Error processing accounts', ['error' => $e->getMessage()]);
                fclose($output);
                die('Export error: ' . htmlspecialchars($e->getMessage()));
            }
        } else {
            // Потоковая обработка для больших объемов
            $batchSize = 1000; // Обрабатываем по 1000 записей за раз
            Logger::info('EXPORT: Using streaming mode', [
                'total_rows' => $totalRows,
                'batch_size' => $batchSize,
                'estimated_batches' => ceil($totalRows / $batchSize),
                'selectAll' => $selectAll
            ]);
            
            $sort = get_param('sort', 'id');
            $dir = get_param('dir', 'ASC');
            Logger::info('EXPORT: Streaming parameters', [
                'sort' => $sort,
                'dir' => $dir,
                'filter_class' => get_class($filter)
            ]);
            
            for ($offset = 0; $offset < $totalRows; $offset += $batchSize) {
                $currentLimit = min($batchSize, $totalRows - $offset);
                
                try {
                    // Получаем порцию данных
                    Logger::info('EXPORT: Fetching batch', [
                        'offset' => $offset,
                        'limit' => $currentLimit,
                        'batch_num' => floor($offset / $batchSize) + 1,
                        'total_batches' => ceil($totalRows / $batchSize)
                    ]);
                    
                    $accounts = $service->getAccounts($filter, $sort, $dir, $currentLimit, $offset);
                    
                    Logger::info('EXPORT: Batch fetched', [
                        'accounts_in_batch' => count($accounts ?? []),
                        'offset' => $offset,
                        'expected' => $currentLimit
                    ]);
                    
                    if (empty($accounts)) {
                        Logger::info('EXPORT: No more data, stopping', ['offset' => $offset]);
                        break; // Больше нет данных
                    }
                    
                    // Обрабатываем и выводим порцию
                    foreach ($accounts as $row) {
                        $line = [];
                        foreach ($selectedCols as $key) {
                            $cellValue = $row[$key] ?? '';
                            // Убеждаемся, что данные в UTF-8
                            $sanitized = $sanitizeCell($cellValue);
                            $line[] = mb_convert_encoding($sanitized, 'UTF-8', 'UTF-8');
                        }
                        fwrite($output, implode('|', $line) . $EOL);
                        $exportedCount++;
                    }
                    
                    // Очищаем память и отправляем данные браузеру
                    unset($accounts);
                    if (function_exists('ob_get_level') && ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                    
                    // Логируем прогресс каждые 1000 записей для лучшей видимости
                    if ($exportedCount % 1000 === 0) {
                        Logger::info('EXPORT: Progress', [
                            'exported' => $exportedCount, 
                            'total' => $totalRows,
                            'percent' => round(($exportedCount / $totalRows) * 100, 2)
                        ]);
                    }
                } catch (Exception $e) {
                    Logger::error('EXPORT: Error processing batch', [
                        'offset' => $offset,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    if (isset($output) && $output) {
                        fclose($output);
                    }
                    die('Export error: ' . htmlspecialchars($e->getMessage()));
                }
            }
            
            Logger::info('EXPORT: Streaming completed', [
                'exported_count' => $exportedCount,
                'expected_count' => $totalRows
            ]);
        }
    } else {
        // Легаси-формат: многострочный блок по записи
        fwrite($output, "EXPORT ACCOUNTS" . $EOL);
        fwrite($output, "Generated: " . date('Y-m-d H:i:s') . $EOL);
        fwrite($output, "Total records: " . $totalRows . $EOL);
        fwrite($output, str_repeat("=", 50) . $EOL);
        
        if (!$useStreaming && !empty($idArray)) {
            // Для конкретных ID загружаем все сразу
            try {
                $accounts = $service->getAccounts($filter, $sort, $dir, $totalRows, 0);
                
                foreach ($accounts as $row) {
                    fwrite($output, "ACCOUNT #{$row['id']}" . $EOL);
                    fwrite($output, str_repeat("-", 30) . $EOL);
                    foreach ($row as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $label = ucfirst(str_replace('_', ' ', $key));
                            // Обеспечиваем корректную UTF-8 кодировку
                            $cleanValue = (string)$value;
                            if (!mb_check_encoding($cleanValue, 'UTF-8')) {
                                $cleanValue = mb_convert_encoding($cleanValue, 'UTF-8', 'auto');
                            }
                            // Удаляем управляющие символы
                            $cleanValue = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleanValue);
                            fwrite($output, "$label: $cleanValue" . $EOL);
                        }
                    }
                    fwrite($output, $EOL);
                    $exportedCount++;
                }
                
                unset($accounts);
            } catch (Exception $e) {
                Logger::error('EXPORT: Error processing accounts', ['error' => $e->getMessage()]);
                fclose($output);
                die('Export error: ' . htmlspecialchars($e->getMessage()));
            }
        } else {
            // Потоковая обработка для больших объемов
            $batchSize = 1000;
            
            for ($offset = 0; $offset < $totalRows; $offset += $batchSize) {
                $currentLimit = min($batchSize, $totalRows - $offset);
                
                try {
                    // Получаем порцию данных
                    $accounts = $service->getAccounts($filter, $sort, $dir, $currentLimit, $offset);
                    
                    if (empty($accounts)) {
                        break; // Больше нет данных
                    }
                    
                    // Обрабатываем и выводим порцию
                    foreach ($accounts as $row) {
                        fwrite($output, "ACCOUNT #{$row['id']}" . $EOL);
                        fwrite($output, str_repeat("-", 30) . $EOL);
                        foreach ($row as $key => $value) {
                            if ($value !== null && $value !== '') {
                                $label = ucfirst(str_replace('_', ' ', $key));
                                // Обеспечиваем корректную UTF-8 кодировку
                                $cleanValue = (string)$value;
                                if (!mb_check_encoding($cleanValue, 'UTF-8')) {
                                    $cleanValue = mb_convert_encoding($cleanValue, 'UTF-8', 'auto');
                                }
                                // Удаляем управляющие символы
                                $cleanValue = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleanValue);
                                fwrite($output, "$label: $cleanValue" . $EOL);
                            }
                        }
                        fwrite($output, $EOL);
                        $exportedCount++;
                    }
                    
                    // Очищаем память и отправляем данные браузеру
                    unset($accounts);
                    if (function_exists('ob_get_level') && ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                } catch (Exception $e) {
                    Logger::error('EXPORT: Error processing batch', [
                        'offset' => $offset,
                        'error' => $e->getMessage()
                    ]);
                    fclose($output);
                    die('Export error: ' . htmlspecialchars($e->getMessage()));
                }
            }
        }
    }
    
    if (isset($output) && $output) {
        fclose($output);
    }
    
    // Проверяем, что что-то было экспортировано
    if ($exportedCount === 0) {
        Logger::warning('EXPORT: No records exported', [
            'expected_count' => $totalRows,
            'selectAll' => $selectAll,
            'useStreaming' => $useStreaming
        ]);
    }
    
    Logger::info('EXPORT: ===== TXT EXPORT COMPLETED =====', [
        'exported_count' => $exportedCount,
        'expected_count' => $totalRows,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true)
    ]);
} else {
    // Экспорт в CSV
    $output = fopen('php://output', 'w');
    
    // Заголовки и соответствие полей (динамически из базы)
    $knownTitles = [
      'id' => 'ID', 'login' => 'Login', 'email' => 'Email', 'first_name' => 'First Name',
      'last_name' => 'Last Name', 'status' => 'Status', 'password' => 'Password',
      'email_password' => 'Email Password', 'birth_day' => 'Birth Day', 'birth_month' => 'Birth Month',
      'birth_year' => 'Birth Year', 'social_url' => 'Social URL', 'ads_id' => 'Ads ID',
      'user_agent' => 'User Agent', 'two_fa' => '2FA', 'token' => 'Token', 'cookies' => 'Cookies',
      'extra_info_1' => 'Extra Info 1', 'extra_info_2' => 'Extra Info 2', 'extra_info_3' => 'Extra Info 3',
      'extra_info_4' => 'Extra Info 4', 'created_at' => 'Created At', 'updated_at' => 'Updated At'
    ];
    $columns = [];
    foreach ($allCols as $col) {
        $columns[$col] = $knownTitles[$col] ?? ucfirst(str_replace('_', ' ', $col));
    }
    // Используем точку с запятой как разделитель для Excel
    writeCsvRow($output, array_values($columns), ';');

    // Санитизация для CSV-инъекций и UTF-8
    $sanitize = function($v) {
        if ($v === null) return '';
        $v = (string)$v;
        
        // Обеспечиваем корректную UTF-8 кодировку
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'auto');
        }
        
        // Удаляем управляющие символы
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
        
        // Защита от CSV-инъекций
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
            return "'" . $v;
        }
        
        return trim($v);
    };
    
    // Потоковая обработка данных порциями
    $batchSize = 1000; // Обрабатываем по 1000 записей за раз
    $sort = get_param('sort', 'id');
    $dir = get_param('dir', 'ASC');
    $exportedCount = 0;
    
    for ($offset = 0; $offset < $totalRows; $offset += $batchSize) {
        $currentLimit = min($batchSize, $totalRows - $offset);
        
        // Получаем порцию данных
        $accounts = $service->getAccounts($filter, $sort, $dir, $currentLimit, $offset);
        
        if (empty($accounts)) {
            break; // Больше нет данных
        }
        
        // Обрабатываем и выводим порцию
        foreach ($accounts as $row) {
            $line = [];
            foreach ($columns as $key => $_) {
                $cellValue = $row[$key] ?? '';
                // Убеждаемся, что данные в UTF-8
                $sanitized = $sanitize($cellValue);
                $line[] = mb_convert_encoding($sanitized, 'UTF-8', 'UTF-8');
            }
            // Используем точку с запятой как разделитель для Excel
            writeCsvRow($output, $line, ';');
            $exportedCount++;
        }
        
        // Очищаем память и отправляем данные браузеру
        unset($accounts);
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
        
        // Логируем прогресс каждые 5000 записей
        if ($exportedCount % 5000 === 0) {
            Logger::debug('EXPORT: Progress', ['exported' => $exportedCount, 'total' => $totalRows]);
        }
    }
    
    if (isset($output) && $output) {
        fclose($output);
    }
    
    Logger::info('EXPORT: ===== CSV EXPORT COMPLETED =====', [
        'exported_count' => $exportedCount,
        'expected_count' => $totalRows,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true)
    ]);
}

// Завершаем выполнение явно после успешного экспорта
exit(0);

} catch (Exception $e) {
    Logger::error('EXPORT: Fatal error during export', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    if (isset($output) && $output) {
        @fclose($output);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die("\nExport failed: " . htmlspecialchars($e->getMessage()));
} catch (Throwable $e) {
    Logger::error('EXPORT: Fatal throwable during export', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    if (isset($output) && $output) {
        @fclose($output);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die("\nExport failed: " . htmlspecialchars($e->getMessage()));
}
?>
