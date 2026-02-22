<?php
/**
 * Скрипт для создания таблицы избранных аккаунтов
 * Автоматически создаёт таблицу, если её нет
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Logger.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройка избранного</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .success {
            color: #28a745;
            background: #d4edda;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .error {
            color: #dc3545;
            background: #f8d7da;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .info {
            color: #004085;
            background: #d1ecf1;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .step {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Настройка функционала избранного</h1>
        
        <?php
        try {
            require_once __DIR__ . '/includes/Database.php';
            $mysqli = Database::getInstance()->getConnection();

            if (!$mysqli) {
                throw new Exception('Не удалось подключиться к базе данных');
            }
            
            echo '<div class="step">';
            echo '<h3>Шаг 1: Проверка подключения к БД</h3>';
            echo '<p>✓ Подключение к базе данных установлено</p>';
            echo '</div>';
            
            // Проверяем существование таблицы
            echo '<div class="step">';
            echo '<h3>Шаг 2: Проверка существования таблицы</h3>';
            
            $checkTable = $mysqli->query("SHOW TABLES LIKE 'account_favorites'");
            $tableExists = $checkTable && $checkTable->num_rows > 0;
            
            if ($tableExists) {
                echo '<div class="info">';
                echo '<p>✓ Таблица <code>account_favorites</code> уже существует</p>';
                echo '</div>';
                
                // Показываем структуру таблицы
                $result = $mysqli->query("DESCRIBE account_favorites");
                if ($result) {
                    echo '<h4>Структура таблицы:</h4>';
                    echo '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; border-collapse: collapse;">';
                    echo '<tr><th>Поле</th><th>Тип</th><th>Null</th><th>Ключ</th><th>По умолчанию</th></tr>';
                    while ($row = $result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($row['Field']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['Type']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['Null']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['Key']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['Default'] ?? 'NULL') . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                }
            } else {
                echo '<div class="info">';
                echo '<p>⚠ Таблица <code>account_favorites</code> не найдена. Создаём...</p>';
                echo '</div>';
                
                // Создаём таблицу
                echo '<div class="step">';
                echo '<h3>Шаг 3: Создание таблицы</h3>';
                
                $createTableSQL = "
                CREATE TABLE IF NOT EXISTS `account_favorites` (
                    `user_id` VARCHAR(255) NOT NULL,
                    `account_id` INT NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`user_id`, `account_id`),
                    INDEX `idx_user_id` (`user_id`),
                    INDEX `idx_account_id` (`account_id`),
                    INDEX `idx_user_created` (`user_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ";
                
                if ($mysqli->query($createTableSQL)) {
                    echo '<div class="success">';
                    echo '<p>✓ Таблица <code>account_favorites</code> успешно создана!</p>';
                    echo '</div>';
                } else {
                    throw new Exception('Ошибка создания таблицы: ' . $mysqli->error);
                }
                echo '</div>';
                
                // Проверяем наличие внешнего ключа (может не работать, если таблица accounts не имеет нужной структуры)
                echo '<div class="step">';
                echo '<h3>Шаг 4: Проверка индексов</h3>';
                
                $indexes = $mysqli->query("SHOW INDEXES FROM account_favorites");
                if ($indexes) {
                    echo '<p>✓ Индексы созданы:</p>';
                    echo '<ul>';
                    while ($idx = $indexes->fetch_assoc()) {
                        if ($idx['Key_name'] !== 'PRIMARY') {
                            echo '<li><code>' . htmlspecialchars($idx['Key_name']) . '</code> на поле <code>' . htmlspecialchars($idx['Column_name']) . '</code></li>';
                        }
                    }
                    echo '</ul>';
                }
                echo '</div>';
            }
            
            // Проверяем наличие внешнего ключа (опционально)
            echo '<div class="step">';
            echo '<h3>Шаг 5: Проверка внешних ключей</h3>';
            
            $foreignKeys = $mysqli->query("
                SELECT 
                    CONSTRAINT_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'account_favorites'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            if ($foreignKeys && $foreignKeys->num_rows > 0) {
                echo '<p>✓ Внешние ключи настроены:</p>';
                echo '<ul>';
                while ($fk = $foreignKeys->fetch_assoc()) {
                    echo '<li><code>' . htmlspecialchars($fk['COLUMN_NAME']) . '</code> → <code>' . 
                         htmlspecialchars($fk['REFERENCED_TABLE_NAME']) . '.' . 
                         htmlspecialchars($fk['REFERENCED_COLUMN_NAME']) . '</code></li>';
                }
                echo '</ul>';
            } else {
                echo '<div class="info">';
                echo '<p>ℹ Внешние ключи не настроены (это нормально, если таблица accounts не имеет нужной структуры)</p>';
                echo '</div>';
            }
            echo '</div>';
            
            // Финальный статус
            echo '<div class="success">';
            echo '<h3>✅ Настройка завершена!</h3>';
            echo '<p>Таблица <code>account_favorites</code> готова к использованию.</p>';
            echo '<p><a href="index.php">← Вернуться к дашборду</a></p>';
            echo '</div>';
            
            Logger::info('Favorites table setup completed', ['table_exists' => $tableExists]);
            
        } catch (Exception $e) {
            echo '<div class="error">';
            echo '<h3>❌ Ошибка</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
            
            Logger::error('Favorites table setup failed', ['message' => $e->getMessage()]);
        }
        ?>
    </div>
</body>
</html>


