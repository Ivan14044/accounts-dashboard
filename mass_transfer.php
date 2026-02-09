<?php
/**
 * API для массового переноса аккаунтов в другой статус
 * 
 * Принимает JSON с текстом (содержащим ID) и новым статусом,
 * парсит ID, находит аккаунты в БД и обновляет их статус.
 * 
 * ЛОГИКА ПОИСКА:
 * 1. Сначала ищет точное совпадение в колонке "Id soc account"
 * 2. Если не найдено - ищет в "Соцсеть URL" по паттерну:
 *    https://www.facebook.com/profile.php?id=XXXXX
 * 
 * @version 4.0 - Упрощение функционала
 * @date 2025-11-11
 * 
 * Входные данные (JSON):
 * {
 *   "text": "строки с ID формата (10|61)XXXXXXXXXXX",
 *   "status": "новый_статус",
 *   "csrf": "токен"
 * }
 * 
 * Выходные данные (JSON):
 * {
 *   "success": true,
 *   "affected": 95,
 *   "statistics": {
 *     "parsed_ids": 100,           // Количество распознанных ID
 *     "total_lines": 105,          // Всего обработано строк
 *     "unparsed_lines": 5,         // Строк не удалось распознать
 *     "matched_by_id_soc": 90,     // Найдено по колонке "Id soc account"
 *     "matched_by_url": 5,         // Найдено по колонке "Соцсеть URL"
 *     "total_found": 95            // Всего найдено уникальных аккаунтов
 *   },
 *   "status": "новый_статус"
 * }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/MassTransferService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

// Устанавливаем заголовки JSON для всех ответов
header('Content-Type: application/json; charset=utf-8');

try {
    Logger::debug('MASS TRANSFER: Начало обработки запроса', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
    ]);
    
    // Проверка аутентификации
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api'); // Rate limiting для API
    
    // Чтение входных данных
    $rawInput = file_get_contents('php://input');
    Logger::debug('MASS TRANSFER: Получены входные данные', [
        'raw_input_length' => strlen($rawInput),
        'raw_input_preview' => substr($rawInput, 0, 200)
    ]);
    
    $input = json_decode($rawInput, true);
    $jsonError = json_last_error();
    
    Logger::debug('MASS TRANSFER: Парсинг JSON', [
        'json_error' => $jsonError !== JSON_ERROR_NONE ? json_last_error_msg() : 'none',
        'is_array' => is_array($input),
        'input_keys' => is_array($input) ? array_keys($input) : []
    ]);
    
    if (!is_array($input)) {
        $errorMsg = $jsonError !== JSON_ERROR_NONE 
            ? 'Неверный формат JSON: ' . json_last_error_msg()
            : 'Неверный формат запроса. Ожидается JSON объект.';
        Logger::error('MASS TRANSFER: Ошибка парсинга входных данных', [
            'json_error' => $jsonError !== JSON_ERROR_NONE ? json_last_error_msg() : 'none',
            'raw_input_preview' => substr($rawInput, 0, 500)
        ]);
        throw new Exception($errorMsg);
    }
    
    // Извлечение параметров
    $text = isset($input['text']) ? trim((string)$input['text']) : '';
    $status = isset($input['status']) ? trim((string)$input['status']) : '';
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';
    $options = isset($input['options']) && is_array($input['options']) ? $input['options'] : [];
    
    Logger::debug('MASS TRANSFER: Извлечены параметры', [
        'text_length' => strlen($text),
        'status' => $status,
        'csrf_present' => !empty($csrf),
        'csrf_length' => strlen($csrf),
        'options' => $options
    ]);
    
    // Валидация обязательных полей
    if ($text === '') {
        Logger::warning('MASS TRANSFER: Текст пустой');
        throw new Exception('Текст не может быть пустым');
    }
    
    if ($status === '') {
        Logger::warning('MASS TRANSFER: Статус пустой');
        throw new Exception('Статус не может быть пустым');
    }
    
    // Проверка CSRF токена
    if (!verifyCsrfToken($csrf)) {
        Logger::warning('MASS TRANSFER: CSRF валидация провалена', [
            'csrf' => substr($csrf, 0, 20) . '...',
            'session_csrf' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 20) . '...' : 'not_set'
        ]);
        throw new Exception('CSRF токен недействителен');
    }
    
    // Обработка массового переноса
    Logger::debug('MASS TRANSFER: Started', ['text_length' => strlen($text), 'status' => $status]);
    
    $service = new MassTransferService();
    $result = $service->processTransfer($text, $status, $options);
    
    Logger::info('MASS TRANSFER: Completed', [
        'affected' => $result['affected'] ?? 0,
        'parsed_ids' => $result['statistics']['parsed_ids'] ?? 0
    ]);
    
    // Возврат результата
    json_success($result);
    
} catch (Throwable $e) {
    Logger::error('MASS TRANSFER: Критическая ошибка', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    $httpCode = 400;
    if ($e instanceof InvalidArgumentException) {
        $httpCode = 400;
    } elseif (strpos($e->getMessage(), 'CSRF') !== false) {
        $httpCode = 403;
    } elseif (strpos($e->getMessage(), 'аутентификац') !== false || strpos($e->getMessage(), 'auth') !== false) {
        $httpCode = 401;
    }
    
    json_error($e->getMessage(), $httpCode);
}

