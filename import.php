<?php
/**
 * Импорт аккаунтов из CSV/JSON файлов
 * Поддерживает валидацию, предпросмотр и массовую вставку
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/Config.php';

// Проверяем авторизацию
requireAuth();
checkSessionTimeout();

$service = new AccountsService();
$meta = $service->getColumnMetadata();
$allColumns = $meta['all'];
$columnTitles = $meta['columns'];
$numericColumns = $meta['numeric'] ?? [];

/**
 * Определение типа параметра для bind_param на основе метаданных колонки
 * 
 * @param string $field Имя поля
 * @param mixed $value Значение
 * @param array $allColumns Массив всех колонок
 * @param array $numericColumns Массив числовых колонок
 * @return string Тип параметра ('i', 'd', 's')
 */
function getParamType($field, $value, $allColumns, $numericColumns) {
    if (!in_array($field, $allColumns, true)) {
        return 's';
    }
    
    if (in_array($field, $numericColumns, true)) {
        if (is_numeric($value)) {
            return (strpos((string)$value, '.') !== false) ? 'd' : 'i';
        }
    }
    
    return 's';
}

// Обработка загрузки файла
$importResult = null;
$errors = [];
$previewData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    try {
        $file = $_FILES['import_file'];
        $format = $_POST['format'] ?? 'csv';
        $duplicateAction = $_POST['duplicate_action'] ?? 'skip'; // skip, update, error
        
        // Валидация файла
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Ошибка загрузки файла: ' . $file['error']);
        }
        
        if ($file['size'] > Config::MAX_REQUEST_SIZE) {
            throw new Exception('Файл слишком большой. Максимальный размер: ' . (Config::MAX_REQUEST_SIZE / 1024 / 1024) . ' MB');
        }
        
        // Парсим данные в зависимости от формата
        $data = [];
        if ($format === 'json') {
            // Проверяем размер файла
            if ($file['size'] > 20 * 1024 * 1024) { // 20MB
                throw new Exception('JSON файл слишком большой (' . round($file['size'] / 1024 / 1024, 1) . 'MB). Максимум 20MB. Используйте CSV для больших файлов.');
            }
            
            $content = file_get_contents($file['tmp_name']);
            if ($content === false) {
                throw new Exception('Не удалось прочитать файл');
            }
            
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Ошибка парсинга JSON: ' . json_last_error_msg());
            }
            
            // Если это не массив массивов, оборачиваем
            if (!empty($data) && !isset($data[0])) {
                $data = [$data];
            }
        } else {
            // CSV парсинг - используем потоковую обработку через fgetcsv
            // Передаем путь к файлу вместо содержимого для экономии памяти
            $data = parseCSV($file['tmp_name']);
        }
        
        if (empty($data)) {
            throw new Exception('Файл не содержит данных');
        }
        
        // Если это предпросмотр
        if (isset($_POST['preview']) && $_POST['preview'] === '1') {
            $previewData = array_slice($data, 0, 10); // Первые 10 записей для предпросмотра
        } else {
            // Импорт данных
            $importResult = importAccounts($data, $duplicateAction, $service, $allColumns);
        }
        
    } catch (Exception $e) {
        Logger::error('Import error', ['message' => $e->getMessage()]);
        $errors[] = $e->getMessage();
    }
}

/**
 * Парсинг CSV файла с использованием fgetcsv для корректной обработки переносов строк в полях
 * 
 * @param string $filePath Путь к временному файлу
 * @return array Массив данных
 */
function parseCSV($filePath) {
    // Используем потоковую обработку через fopen/fgetcsv вместо загрузки всего файла в память
    $handle = @fopen($filePath, 'r');
    if ($handle === false) {
        throw new Exception('Не удалось открыть файл для чтения');
    }
    
    // Определяем разделитель по первой строке
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        return [];
    }
    
    $delimiter = strpos($firstLine, ';') !== false ? ';' : ',';
    
    // Возвращаемся к началу файла
    rewind($handle);
    
    // Читаем заголовки
    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false || empty($headers)) {
        fclose($handle);
        return [];
    }
    
    $headers = array_map('trim', $headers);
    
    // Нормализуем заголовки (убираем пробелы, приводим к нижнему регистру)
    $normalizedHeaders = [];
    foreach ($headers as $header) {
        $normalized = strtolower(trim($header));
        $normalized = str_replace([' ', '-'], '_', $normalized);
        $normalizedHeaders[] = $normalized;
    }
    
    $data = [];
    $lineNum = 0;
    $maxRows = 100000; // Защита от слишком больших файлов
    
    // Читаем данные построчно
    while (($values = fgetcsv($handle, 0, $delimiter)) !== false && $lineNum < $maxRows) {
        $lineNum++;
        
        // Пропускаем пустые строки
        if (empty(array_filter($values, function($v) { return trim($v) !== ''; }))) {
            continue;
        }
        
        // Если количество колонок не совпадает, пропускаем строку
        if (count($values) !== count($headers)) {
            continue;
        }
        
        $row = [];
        foreach ($normalizedHeaders as $index => $header) {
            $row[$header] = isset($values[$index]) ? trim($values[$index]) : '';
        }
        
        $data[] = $row;
    }
    
    fclose($handle);
    return $data;
}

/**
 * Импорт аккаунтов в БД
 */
function importAccounts($data, $duplicateAction, $service, $allColumns) {
    global $mysqli;
    
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    
    // Начинаем транзакцию
    $mysqli->begin_transaction();
    
    try {
        foreach ($data as $rowNum => $row) {
            try {
                // Валидация и нормализация данных
                $accountData = [];
                foreach ($row as $key => $value) {
                    // Пропускаем несуществующие колонки
                    if (!in_array($key, $allColumns, true)) {
                        continue;
                    }
                    
                    // Пропускаем ID и системные поля
                    if (in_array($key, ['id', 'created_at', 'updated_at'], true)) {
                        continue;
                    }
                    
                    $accountData[$key] = $value;
                }
                
                if (empty($accountData)) {
                    $skipped++;
                    continue;
                }
                
                // Проверка дубликатов (по login или email)
                $duplicateId = null;
                if (!empty($accountData['login'])) {
                    $stmt = $mysqli->prepare("SELECT id FROM accounts WHERE login = ? LIMIT 1");
                    $stmt->bind_param('s', $accountData['login']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $duplicateId = (int)$row['id'];
                    }
                    $stmt->close();
                }
                
                if ($duplicateId) {
                    if ($duplicateAction === 'skip') {
                        $skipped++;
                        continue;
                    } elseif ($duplicateAction === 'error') {
                        throw new Exception("Дубликат найден (ID: $duplicateId)");
                    } elseif ($duplicateAction === 'update') {
                        // Обновляем существующую запись
                        $updateFields = [];
                        $updateValues = [];
                        $types = '';
                        
                        foreach ($accountData as $field => $value) {
                            $updateFields[] = "`$field` = ?";
                            $updateValues[] = $value;
                            $types .= getParamType($field, $value, $allColumns, $numericColumns);
                        }
                        
                        $sql = "UPDATE accounts SET " . implode(', ', $updateFields) . " WHERE id = ?";
                        $updateValues[] = $duplicateId;
                        $types .= 'i';
                        
                        $stmt = $mysqli->prepare($sql);
                        $stmt->bind_param($types, ...$updateValues);
                        $stmt->execute();
                        $stmt->close();
                        
                        $updated++;
                        continue;
                    }
                }
                
                // Вставка новой записи
                $fields = array_keys($accountData);
                $values = array_values($accountData);
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                
                $types = '';
                foreach ($fields as $idx => $field) {
                    $types .= getParamType($field, $values[$idx], $allColumns, $numericColumns);
                }
                
                $sql = "INSERT INTO accounts (`" . implode('`, `', $fields) . "`) VALUES ($placeholders)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param($types, ...$values);
                $stmt->execute();
                $stmt->close();
                
                $imported++;
                
            } catch (Exception $e) {
                $errors[] = "Строка " . ($rowNum + 1) . ": " . $e->getMessage();
                if ($duplicateAction === 'error') {
                    throw $e; // Прерываем при ошибке
                }
            }
        }
        
        // Коммитим транзакцию
        $mysqli->commit();
        
        Logger::info('Import completed', [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => count($errors)
        ]);
        
        return [
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Импорт аккаунтов - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        .import-container {
            max-width: 900px;
            margin: 2rem auto;
        }
        .preview-table {
            max-height: 400px;
            overflow-y: auto;
        }
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
        }
        .file-upload-area:hover {
            border-color: #0d6efd;
            background: #f8f9fa;
        }
        .file-upload-area.dragover {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-chart-line text-primary me-2"></i>
                Dashboard
            </a>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted">
                    <i class="fas fa-user me-1"></i>
                    <?= htmlspecialchars(getCurrentUser()) ?>
                </span>
                <a class="btn btn-sm btn-outline-secondary" href="index.php">
                    <i class="fas fa-arrow-left me-1"></i>
                    Назад
                </a>
            </div>
        </div>
    </nav>

    <main class="container import-container">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">
                    <i class="fas fa-file-import me-2"></i>
                    Импорт аккаунтов
                </h4>
            </div>
            <div class="card-body">
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>Ошибки:</h5>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if ($importResult && $importResult['success']): ?>
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle me-2"></i>Импорт завершён!</h5>
                        <ul class="mb-0">
                            <li>Импортировано: <strong><?= $importResult['imported'] ?></strong></li>
                            <?php if ($importResult['updated'] > 0): ?>
                                <li>Обновлено: <strong><?= $importResult['updated'] ?></strong></li>
                            <?php endif; ?>
                            <?php if ($importResult['skipped'] > 0): ?>
                                <li>Пропущено: <strong><?= $importResult['skipped'] ?></strong></li>
                            <?php endif; ?>
                            <?php if (!empty($importResult['errors'])): ?>
                                <li>Ошибок: <strong><?= count($importResult['errors']) ?></strong></li>
                            <?php endif; ?>
                        </ul>
                        <div class="mt-3">
                            <a href="index.php" class="btn btn-primary">Вернуться к списку</a>
                        </div>
                    </div>
                <?php elseif ($previewData): ?>
                    <div class="alert alert-info">
                        <h5><i class="fas fa-eye me-2"></i>Предпросмотр (первые 10 записей)</h5>
                        <p>Всего записей в файле: <strong><?= count($previewData) ?></strong></p>
                    </div>
                    
                    <div class="table-responsive preview-table">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <?php 
                                    $previewColumns = [];
                                    if (!empty($previewData)) {
                                        $previewColumns = array_keys($previewData[0]);
                                        foreach ($previewColumns as $col): 
                                    ?>
                                        <th><?= htmlspecialchars($col) ?></th>
                                    <?php 
                                        endforeach;
                                    }
                                    ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData as $row): ?>
                                    <tr>
                                        <?php foreach ($previewColumns as $col): ?>
                                            <td><?= htmlspecialchars($row[$col] ?? '') ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="mt-3">
                        <input type="hidden" name="format" value="<?= htmlspecialchars($_POST['format'] ?? 'csv') ?>">
                        <input type="hidden" name="duplicate_action" value="<?= htmlspecialchars($_POST['duplicate_action'] ?? 'skip') ?>">
                        <input type="hidden" name="import_file_name" value="<?= htmlspecialchars($_FILES['import_file']['name'] ?? '') ?>">
                        <input type="hidden" name="import_file_tmp" value="<?= htmlspecialchars($_FILES['import_file']['tmp_name'] ?? '') ?>">
                        
                        <div class="d-flex gap-2">
                            <button type="submit" name="import" value="1" class="btn btn-success">
                                <i class="fas fa-check me-2"></i>
                                Подтвердить импорт
                            </button>
                            <a href="import.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>
                                Отмена
                            </a>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" id="importForm">
                        <div class="mb-4">
                            <label class="form-label">Формат файла</label>
                            <select name="format" class="form-select" required>
                                <option value="csv">CSV (разделитель: точка с запятой или запятая)</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Действие при дубликатах</label>
                            <select name="duplicate_action" class="form-select" required>
                                <option value="skip">Пропустить дубликаты</option>
                                <option value="update">Обновить существующие</option>
                                <option value="error">Остановить при дубликате</option>
                            </select>
                            <small class="form-text text-muted">
                                Дубликаты определяются по полю "login"
                            </small>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Файл для импорта</label>
                            <div class="file-upload-area" id="fileUploadArea">
                                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                <p class="mb-2">
                                    <strong>Перетащите файл сюда</strong> или
                                    <label for="import_file" class="btn btn-primary btn-sm ms-2">
                                        <i class="fas fa-folder-open me-1"></i>
                                        Выбрать файл
                                    </label>
                                </p>
                                <input 
                                    type="file" 
                                    name="import_file" 
                                    id="import_file" 
                                    class="d-none" 
                                    accept=".csv,.json,.txt"
                                    required
                                >
                                <small class="text-muted d-block">
                                    Максимальный размер: <?= (Config::MAX_REQUEST_SIZE / 1024 / 1024) ?> MB
                                </small>
                                <div id="fileName" class="mt-2 text-primary fw-bold" style="display: none;"></div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" name="preview" value="1" class="btn btn-outline-info">
                                <i class="fas fa-eye me-2"></i>
                                Предпросмотр
                            </button>
                            <button type="submit" name="import" value="1" class="btn btn-success">
                                <i class="fas fa-upload me-2"></i>
                                Импортировать
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>
                                Отмена
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
                
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">Информация о форматах</h5>
            </div>
            <div class="card-body">
                <h6>CSV формат:</h6>
                <ul>
                    <li>Первая строка должна содержать заголовки колонок</li>
                    <li>Разделитель: точка с запятой (;) или запятая (,)</li>
                    <li>Кодировка: UTF-8</li>
                    <li>Пример: <code>login,email,password,status</code></li>
                </ul>
                
                <h6 class="mt-3">JSON формат:</h6>
                <ul>
                    <li>Массив объектов или один объект</li>
                    <li>Ключи должны соответствовать названиям колонок в БД</li>
                    <li>Пример: <code>[{"login":"user1","email":"user1@example.com"}]</code></li>
                </ul>
                
                <h6 class="mt-3">Доступные колонки:</h6>
                <div class="small text-muted">
                    <?= implode(', ', array_slice($allColumns, 0, 20)) ?>
                    <?php if (count($allColumns) > 20): ?>
                        ... и ещё <?= count($allColumns) - 20 ?> колонок
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Drag & Drop для загрузки файла
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('import_file');
        const fileName = document.getElementById('fileName');
        
        fileUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUploadArea.classList.add('dragover');
        });
        
        fileUploadArea.addEventListener('dragleave', () => {
            fileUploadArea.classList.remove('dragover');
        });
        
        fileUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                updateFileName();
            }
        });
        
        fileInput.addEventListener('change', updateFileName);
        
        function updateFileName() {
            if (fileInput.files.length > 0) {
                fileName.textContent = 'Выбран: ' + fileInput.files[0].name;
                fileName.style.display = 'block';
            } else {
                fileName.style.display = 'none';
            }
        }
    </script>
</body>
</html>

