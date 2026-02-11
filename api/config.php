<?php
/**
 * API endpoint для получения конфигурации системы
 * Предоставляет клиентской части доступ к настройкам импорта и другим константам
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/Config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600'); // Кэшируем на 1 час

try {
    requireAuth();
    checkSessionTimeout();
    
    // Формируем конфигурацию для клиента
    $config = [
        // Импорт
        'MAX_IMPORT_FILE_SIZE' => Config::MAX_IMPORT_FILE_SIZE,
        'MAX_IMPORT_ROWS' => Config::MAX_IMPORT_ROWS,
        'IMPORT_BATCH_SIZE' => Config::IMPORT_BATCH_SIZE,
        
        // Пагинация
        'DEFAULT_PAGE_SIZE' => Config::DEFAULT_PAGE_SIZE,
        'MAX_PAGE_SIZE' => Config::MAX_PAGE_SIZE,
        'MIN_PAGE_SIZE' => Config::MIN_PAGE_SIZE,
        
        // Экспорт
        'MAX_EXPORT_RECORDS' => Config::MAX_EXPORT_RECORDS,
        
        // Форматы
        'DATE_FORMAT' => 'Y-m-d H:i:s',
        'TIMEZONE' => date_default_timezone_get()
    ];
    
    echo json_encode([
        'success' => true,
        'config' => $config
    ]);
    
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ]);
}
