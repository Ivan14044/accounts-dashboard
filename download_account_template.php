<?php
/**
 * Скачивание шаблона CSV для добавления аккаунтов
 * Генерирует CSV файл с заголовками, инструкциями и примером заполнения
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

// Формируем заголовки CSV с отметкой обязательных полей
$headers = [];
foreach ($templateFields as $field) {
    // Добавляем звёздочку к обязательным полям
    $headers[] = in_array($field, $requiredFields) ? $field . '*' : $field;
}

// Формируем пример строки с реалистичными данными
$exampleRow = [];
foreach ($templateFields as $field) {
    switch ($field) {
        case 'login':
            $exampleRow[] = 'example_user_' . date('Ymd');
            break;
        case 'status':
            $exampleRow[] = 'active';
            break;
        case 'email':
            $exampleRow[] = 'user@example.com';
            break;
        case 'password':
            $exampleRow[] = 'MySecurePass123';
            break;
        case 'social_url':
            $exampleRow[] = 'https://vk.com/id123456';
            break;
        case 'cookies':
            $exampleRow[] = 'session_id=abc123xyz; token=def456';
            break;
        case 'notes':
            $exampleRow[] = 'Test account - example';
            break;
        case 'pharma':
            $exampleRow[] = '100';
            break;
        case 'scenario':
            $exampleRow[] = 'standard';
            break;
        case 'limit_rk':
            $exampleRow[] = '5000';
            break;
        case 'currency':
            $exampleRow[] = 'USD';
            break;
        case 'geo':
            $exampleRow[] = 'US';
            break;
        case 'friends':
            $exampleRow[] = '250';
            break;
        default:
            // Для остальных полей - пустое значение
            $exampleRow[] = '';
            break;
    }
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

// Записываем инструкции как комментарии
fputcsv($output, ['# ИНСТРУКЦИЯ ПО ЗАПОЛНЕНИЮ CSV ФАЙЛА'], ';');
fputcsv($output, ['# 1. Обязательные поля помечены звёздочкой (*) - их нужно заполнить для всех строк'], ';');
fputcsv($output, ['# 2. Поле status - обязательное, может содержать любое значение (например: active, banned, custom_status, test, и т.д.)'], ';');
fputcsv($output, ['# 3. Формат email: user@example.com'], ';');
fputcsv($output, ['# 4. Формат social_url: https://vk.com/id123 или https://facebook.com/username'], ';');
fputcsv($output, ['# 5. Числовые поля (pharma, limit_rk, friends): только целые числа'], ';');
fputcsv($output, ['# 6. Максимальный размер файла: ' . (Config::MAX_IMPORT_FILE_SIZE / 1024 / 1024) . ' MB'], ';');
fputcsv($output, ['# 7. Максимальное количество строк: ' . Config::MAX_IMPORT_ROWS], ';');
fputcsv($output, ['# 8. Пример правильно заполненной строки см. в строке 10'], ';');
fputcsv($output, ['# 9. Строки с комментариями (#) будут пропущены при импорте'], ';');
fputcsv($output, ['#'], ';');

// Записываем заголовки с отметкой обязательных полей
fputcsv($output, $headers, ';');

// Записываем пример строки
fputcsv($output, $exampleRow, ';');

// Добавляем несколько пустых строк для заполнения пользователем
for ($i = 0; $i < 5; $i++) {
    $emptyRow = array_fill(0, count($headers), '');
    fputcsv($output, $emptyRow, ';');
}

fclose($output);
exit;
