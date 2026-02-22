<?php
/**
 * Скачивание шаблона CSV для добавления аккаунтов.
 * Генерирует только заголовки колонок и пустые строки для заполнения (без инструкций и примеров данных).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Config.php';

requireAuth();
checkSessionTimeout();

$service = new AccountsService();
$meta = $service->getColumnMetadata();
$allColumns = $meta['all'];

// Исключаем системные поля
$excludeFields = ['id', 'created_at', 'updated_at', 'deleted_at'];
$templateFields = array_diff($allColumns, $excludeFields);

// Сортируем поля, чтобы обязательные были первыми
$requiredFields = ['login', 'status'];
$otherFields = array_diff($templateFields, $requiredFields);
$templateFields = array_merge($requiredFields, $otherFields);

// Заголовки CSV: обязательные поля помечены звёздочкой
$headers = [];
foreach ($templateFields as $field) {
    $headers[] = in_array($field, $requiredFields) ? $field . '*' : $field;
}

// Заголовки для скачивания
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="account_template_' . date('Y-m-d') . '.csv"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// BOM для корректной кириллицы в Excel
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Только строка заголовков
fputcsv($output, $headers, ';');

// Пустые строки для заполнения пользователем
for ($i = 0; $i < 5; $i++) {
    fputcsv($output, array_fill(0, count($headers), ''), ';');
}

fclose($output);
exit;
