<?php
/**
 * Скачивание шаблона CSV для добавления аккаунтов
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';

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

// Формируем заголовки CSV (только английские названия для системы)
$headers = [];
foreach ($templateFields as $field) {
    $headers[] = $field;
}

// Устанавливаем заголовки для скачивания файла
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="account_template_' . date('Y-m-d') . '.csv"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// Выводим BOM для корректного отображения кириллицы в Excel
echo "\xEF\xBB\xBF";

// Создаем поток вывода
$output = fopen('php://output', 'w');

// Записываем заголовки (английские названия для системы)
fputcsv($output, $headers, ';');

// Добавляем пример строки (для наглядности)
$exampleRow = array_fill(0, count($headers), '');
fputcsv($output, $exampleRow, ';');

fclose($output);
exit;
