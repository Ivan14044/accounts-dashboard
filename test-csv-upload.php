<?php
/**
 * Тестовый скрипт для проверки валидации CSV
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/CsvParser.php';
require_once __DIR__ . '/includes/Logger.php';

requireAuth();

// Путь к тестовому файлу
$testFile = 'C:/Users/Knysh/OneDrive/Desktop/account_template_2026_01_14_account_template_2026_01_14_csv_1.csv';

if (!file_exists($testFile)) {
    die('Файл не найден: ' . $testFile);
}

echo "<h1>Тест валидации CSV</h1>";
echo "<pre>";

echo "=== ИНФОРМАЦИЯ О ФАЙЛЕ ===\n";
echo "Путь: $testFile\n";
echo "Размер: " . filesize($testFile) . " байт (" . round(filesize($testFile) / 1024 / 1024, 2) . " МБ)\n";
echo "Существует: " . (file_exists($testFile) ? 'Да' : 'Нет') . "\n";
echo "\n";

try {
    echo "=== ПАРСИНГ CSV ===\n";
    $parser = new CsvParser();
    $data = $parser->parse($testFile);
    
    echo "Строк распарсено: " . count($data) . "\n";
    echo "\n";
    
    echo "=== ПЕРВАЯ СТРОКА ===\n";
    print_r($data[0]);
    echo "\n";
    
    echo "=== ПОЛЯ ПЕРВОЙ СТРОКИ ===\n";
    if (!empty($data[0])) {
        $fields = array_keys($data[0]);
        echo "Количество полей: " . count($fields) . "\n";
        echo "Поля: " . implode(', ', $fields) . "\n";
        echo "\n";
        
        echo "=== ПРОВЕРКА ОБЯЗАТЕЛЬНЫХ ПОЛЕЙ ===\n";
        $requiredFields = ['login', 'status'];
        foreach ($requiredFields as $field) {
            $exists = array_key_exists($field, $data[0]);
            $value = $data[0][$field] ?? 'НЕТ';
            echo "Поле '$field': " . ($exists ? "✅ Есть" : "❌ Отсутствует") . " | Значение: '$value'\n";
        }
    }
    
    echo "\n=== УСПЕХ! ===\n";
    echo "CSV файл валиден и корректно парсится на сервере.\n";
    
} catch (Exception $e) {
    echo "\n=== ОШИБКА! ===\n";
    echo "Сообщение: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

echo "</pre>";
