<?php
/**
 * Вспомогательные функции общего назначения
 */

/**
 * Подготовка SQL-запроса с параметрами
 * 
 * @param mysqli $db Соединение с БД
 * @param string $sql SQL-запрос
 * @param array $params Параметры для bind
 * @return mysqli_stmt
 */
if (!function_exists('qprep')) {
function qprep(mysqli $db, string $sql, array $params = []): mysqli_stmt {
    $stmt = $db->prepare($sql);
    if (!$stmt) { 
        http_response_code(500); 
        exit('SQL prepare error: ' . $db->error); 
    }
    
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    
    return $stmt;
}
}

/**
 * Безопасная HTML-экранизация
 * 
 * @param mixed $value Значение для экранирования
 * @return string
 */
if (!function_exists('e')) {
function e($value): string { 
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}
}

/**
 * Получение GET-параметра с дефолтным значением
 * 
 * @param string $key Ключ параметра
 * @param string $default Значение по умолчанию
 * @return string
 */
if (!function_exists('get_param')) {
function get_param(string $key, string $default = ''): string { 
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default; 
}
}

/**
 * Построение URL с изменением параметров
 * 
 * @param array $patch Параметры для изменения/добавления
 * @return string
 */
if (!function_exists('url_with')) {
function url_with(array $patch): string {
    $params = $_GET;
    
    foreach ($patch as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = (string)$value;
        }
    }
    
    $basePath = strtok($_SERVER['REQUEST_URI'], '?');
    
    if (empty($params)) {
        return $basePath;
    }
    
    return $basePath . '?' . http_build_query($params);
}
}

/**
 * Генерация ссылки для сортировки колонки
 * 
 * @param string $col Колонка для сортировки
 * @return string URL для сортировки
 */
if (!function_exists('sort_link')) {
function sort_link(string $col): string { 
    $current = get_param('sort', 'id'); 
    $dir = strtolower(get_param('dir', 'asc')) === 'asc' ? 'asc' : 'desc'; 
    $nextDir = ($current === $col && $dir === 'asc') ? 'desc' : 'asc'; 
    return e(url_with(['sort' => $col, 'dir' => $nextDir])); 
}
}

/**
 * Разбор тела запроса как JSON. Чистая функция — тестируется без сети и сервера
 * (tests/test_error_http_codes.php).
 *
 * Кидает InvalidArgumentException, а не Exception: кривое тело запроса — это
 * ошибка клиента, и ErrorHandler превращает её в HTTP 400, а не в 500.
 *
 * @param string $raw     Сырое тело запроса
 * @param int    $maxSize Максимальный размер в байтах
 * @return array|null Декодированный массив; null для пустого тела и для
 *                    валидного JSON, который не является массивом
 * @throws InvalidArgumentException При превышении размера или битом JSON
 */
if (!function_exists('decode_json_body')) {
function decode_json_body(string $raw, int $maxSize = 1048576): ?array {
    if ($raw === '') {
        return null;
    }

    if (strlen($raw) > $maxSize) {
        require_once __DIR__ . '/Logger.php';
        Logger::warning('JSON input size exceeded', [
            'size' => strlen($raw),
            'max_size' => $maxSize
        ]);
        throw new InvalidArgumentException('Input size exceeds maximum allowed size');
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Причину забираем ДО любого вызова Logger: внутри него json_encode
        // контекста сбрасывает json_last_error(), и в сообщение раньше улетало
        // бессмысленное «Invalid JSON format: No error».
        $reason = json_last_error_msg();

        require_once __DIR__ . '/Logger.php';
        Logger::warning('JSON decode error', ['error' => $reason]);

        throw new InvalidArgumentException('Invalid JSON format: ' . $reason);
    }

    return is_array($data) ? $data : null;
}
}

/**
 * Безопасное чтение JSON из php://input с ограничением размера
 *
 * @param int $maxSize Максимальный размер в байтах (по умолчанию 1MB)
 * @return array|null Декодированный JSON или null, если тела нет
 * @throws InvalidArgumentException При превышении размера или ошибке декодирования
 */
if (!function_exists('read_json_input')) {
function read_json_input(int $maxSize = 1048576): ?array {
    // Для GET запросов возвращаем null, так как php://input пустой
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        return null;
    }

    // Читаем на байт больше лимита, чтобы отличить «ровно лимит» от «больше лимита»
    $input = file_get_contents('php://input', false, null, 0, $maxSize + 1);
    if ($input === false) {
        return null;
    }

    return decode_json_body($input, $maxSize);
}
}

/**
 * Стрелка направления сортировки
 * 
 * @param string $currentSort Текущая колонка сортировки
 * @param string $dir Направление сортировки
 * @param string $thCol Колонка заголовка
 * @return string Символ стрелки или пустая строка
 */
if (!function_exists('dir_arrow')) {
function dir_arrow(string $currentSort, string $dir, string $thCol): string { 
    if ($currentSort !== $thCol) return ''; 
    return $dir === 'ASC' ? ' ▲' : ' ▼'; 
}
}

/**
 * JSON-ответ с корректными заголовками
 * 
 * @param array $data Данные для отправки
 * @param int $status HTTP-статус
 */
if (!function_exists('json_response')) {
function json_response(array $data, int $status = 200): void {
    // Используем ResponseHeaders если доступен
    if (class_exists('ResponseHeaders')) {
        ResponseHeaders::setJsonHeaders();
    } else {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    
    if ($status !== 200) {
        http_response_code($status);
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
}

/**
 * JSON-ответ об ошибке
 * 
 * @param string $message Сообщение об ошибке
 * @param int $status HTTP-статус
 */
if (!function_exists('json_error')) {
function json_error(string $message, int $status = 400): void {
    json_response(['success' => false, 'error' => $message], $status);
}
}

/**
 * JSON-ответ об успехе
 * 
 * @param array $data Дополнительные данные
 */
if (!function_exists('json_success')) {
function json_success(array $data = []): void {
    json_response(array_merge(['success' => true], $data));
}
}

