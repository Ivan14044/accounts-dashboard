<?php
/**
 * Дебаг страница для диагностики проблем
 */
// Загружаем config.php ПЕРЕД bootstrap.php, чтобы инициализировать подключение к БД
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';

// Загружаем DashboardController (не включен в bootstrap.php)
require_once __DIR__ . '/includes/DashboardController.php';

// Проверяем авторизацию
requireAuth();

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', '1');

$service = new AccountsService();
$controller = new DashboardController($service);

// Получаем данные
$dashboardData = $controller->prepareDashboardData();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Debug Dashboard</title>
    <style>
        body {
            font-family: monospace;
            padding: 20px;
            background: #f5f5f5;
        }
        .section {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .section h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 5px;
        }
        .var-name {
            font-weight: bold;
            color: #007bff;
        }
        .var-value {
            color: #28a745;
            margin-left: 10px;
        }
        .error {
            color: #dc3545;
            background: #f8d7da;
            padding: 10px;
            border-radius: 3px;
            margin: 5px 0;
        }
        .success {
            color: #155724;
            background: #d4edda;
            padding: 10px;
            border-radius: 3px;
            margin: 5px 0;
        }
        pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }
        .check {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin-right: 10px;
        }
        .check.ok {
            background: #28a745;
        }
        .check.fail {
            background: #dc3545;
        }
    </style>
</head>
<body>
    <h1>🔍 Debug Dashboard</h1>
    
    <div class="section">
        <h2>🔌 Проверка подключения к БД</h2>
        <?php
        global $mysqli;
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            echo '<div class="success">✅ Подключение к БД установлено</div>';
            echo '<p>Host: ' . htmlspecialchars($mysqli->host_info) . '</p>';
            echo '<p>Database: ' . htmlspecialchars($mysqli->query("SELECT DATABASE()")->fetch_row()[0] ?? 'N/A') . '</p>';
        } else {
            echo '<div class="error">❌ Подключение к БД НЕ установлено</div>';
            echo '<p>Переменная $mysqli не определена или не является экземпляром mysqli</p>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>✅ Проверка переменных</h2>
        <?php
        $requiredVars = [
            'rows', 'meta', 'ALL_COLUMNS', 'NUMERIC_COLS', 'statuses',
            'stats', 'page', 'pages', 'perPage', 'filterParams', 'csrfToken'
        ];
        
        foreach ($requiredVars as $var) {
            $exists = isset($dashboardData[$var]);
            $value = $exists ? $dashboardData[$var] : 'NOT SET';
            $type = $exists ? gettype($dashboardData[$var]) : 'N/A';
            $count = is_array($value) ? count($value) : (is_string($value) ? strlen($value) : 'N/A');
            
            echo '<div>';
            echo '<span class="check ' . ($exists ? 'ok' : 'fail') . '"></span>';
            echo '<span class="var-name">$' . $var . '</span>';
            echo '<span class="var-value">[' . $type . ']';
            if (is_array($count)) {
                echo ' count: ' . $count;
            } elseif (is_string($count) && $count !== 'N/A') {
                echo ' length: ' . $count;
            }
            echo '</span>';
            if (!$exists) {
                echo ' <span class="error">⚠️ ОТСУТСТВУЕТ</span>';
            }
            echo '</div>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>📊 Статистика</h2>
        <?php
        if (isset($dashboardData['stats'])) {
            $stats = $dashboardData['stats'];
            echo '<div class="success">✅ Статистика получена</div>';
            echo '<pre>' . print_r($stats, true) . '</pre>';
        } else {
            echo '<div class="error">❌ Статистика не получена</div>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>📋 Данные таблицы</h2>
        <?php
        if (isset($dashboardData['rows'])) {
            $rows = $dashboardData['rows'];
            echo '<div class="success">✅ Получено записей: ' . count($rows) . '</div>';
            if (count($rows) > 0) {
                echo '<pre>' . print_r(array_slice($rows, 0, 2), true) . '</pre>';
                echo '<p><em>Показаны первые 2 записи из ' . count($rows) . '</em></p>';
            }
        } else {
            echo '<div class="error">❌ Данные таблицы не получены</div>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>🔧 Метаданные колонок</h2>
        <?php
        if (isset($dashboardData['meta'])) {
            $meta = $dashboardData['meta'];
            echo '<div class="success">✅ Метаданные получены</div>';
            echo '<p>Всего колонок: ' . (isset($meta['all']) ? count($meta['all']) : 0) . '</p>';
            echo '<p>Числовых колонок: ' . (isset($meta['numeric']) ? count($meta['numeric']) : 0) . '</p>';
            echo '<pre>' . print_r($meta, true) . '</pre>';
        } else {
            echo '<div class="error">❌ Метаданные не получены</div>';
        }
        ?>
    </div>
    
    <div class="section">
        <h2>🌐 JavaScript проверка</h2>
        <div id="jsCheck">
            <p>Проверка JavaScript...</p>
        </div>
        <script>
            (function() {
                const checkDiv = document.getElementById('jsCheck');
                const checks = [];
                
                // Проверка DOM
                checks.push({
                    name: 'DOM загружен',
                    result: document.readyState === 'complete' || document.readyState === 'interactive'
                });
                
                // Проверка элементов
                const statsLoading = document.getElementById('statsLoading');
                checks.push({
                    name: 'Элемент statsLoading существует',
                    result: !!statsLoading
                });
                
                if (statsLoading) {
                    checks.push({
                        name: 'statsLoading имеет класс show',
                        result: statsLoading.classList.contains('show')
                    });
                    checks.push({
                        name: 'statsLoading display стиль',
                        result: window.getComputedStyle(statsLoading).display
                    });
                    checks.push({
                        name: 'statsLoading opacity',
                        result: window.getComputedStyle(statsLoading).opacity
                    });
                }
                
                // Выводим результаты
                let html = '<table style="width: 100%; border-collapse: collapse;">';
                html += '<tr><th style="text-align: left; padding: 5px; border-bottom: 1px solid #ddd;">Проверка</th><th style="text-align: left; padding: 5px; border-bottom: 1px solid #ddd;">Результат</th></tr>';
                checks.forEach(check => {
                    const status = check.result === true || check.result === false ? 
                        (check.result ? '✅' : '❌') : 
                        'ℹ️';
                    const value = typeof check.result === 'string' ? check.result : (check.result ? 'true' : 'false');
                    html += `<tr><td style="padding: 5px; border-bottom: 1px solid #eee;">${check.name}</td><td style="padding: 5px; border-bottom: 1px solid #eee;">${status} ${value}</td></tr>`;
                });
                html += '</table>';
                checkDiv.innerHTML = html;
                
                // Логируем в консоль
                console.log('=== DEBUG PAGE JS CHECK ===');
                checks.forEach(check => {
                    console.log(`${check.name}:`, check.result);
                });
            })();
        </script>
    </div>
    
    <div class="section">
        <h2>📝 Все переменные (JSON)</h2>
        <pre><?php
        // Убираем большие данные для читаемости
        $debugData = $dashboardData;
        if (isset($debugData['rows']) && count($debugData['rows']) > 0) {
            $debugData['rows'] = ['... ' . count($debugData['rows']) . ' rows ...'];
        }
        echo json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        ?></pre>
    </div>
    
    <div class="section">
        <h2>🔗 Ссылки</h2>
        <p><a href="index.php">← Вернуться на главную</a></p>
        <p><a href="index.php?debug=1">Главная с debug параметром</a></p>
    </div>
</body>
</html>

