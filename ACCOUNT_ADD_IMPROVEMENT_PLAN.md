# План улучшения функционала добавления аккаунтов

**Версия:** 1.0  
**Дата:** 2026-02-10  
**Статус:** В планировании

---

## Краткое резюме проблем

### 🔴 Критические (требуют немедленного исправления)

1. **Неинформативный CSV шаблон** — пользователи не понимают, что заполнять
2. **Нет предварительной валидации** — ошибки обнаруживаются только после загрузки
3. **Откат всех строк при одной ошибке** — потеря данных при batch import
4. **Жёстко заданное поведение для дубликатов** — нет выбора (skip/update/error)

### ⚠️ Технические недостатки

5. **N+1 проблема при проверке дубликатов** — 1000 SELECT запросов вместо 1
6. **Отсутствие batch INSERT** — 1000 вставок вместо 10 batch
7. **Парсер CSV не вынесен в отдельный класс** — дублирование кода
8. **Избыточное логирование** — раздутые логи, замедление

### 🎨 UX проблемы

9. **Нет предпросмотра CSV** — пользователь не видит данные до импорта
10. **Нет прогресс-бара** — непонятно, сколько времени осталось
11. **Ошибки не группируются** — трудно понять, что исправить

---

## Детальный план (по фазам)

### Фаза 1: Быстрые исправления (Неделя 1-2)

#### ✅ Задача 1.1: Улучшить CSV шаблон
**Срок:** 1 день  
**Приоритет:** 🔴 Критический  
**Файлы:** `download_account_template.php`

**Что сделать:**
1. Добавить комментарий с инструкцией в первую строку CSV
2. Добавить пример заполненной строки (2-я строка)
3. Отметить обязательные поля звёздочкой (`login*`, `status*`)
4. Добавить список допустимых значений для `status`

**Код:**
```php
// download_account_template.php
$headers = ['login*', 'status*', 'email', 'password', ...];

// Добавляем комментарий
echo "# Обязательные поля: login*, status*\n";
echo "# Допустимые статусы: active, banned, suspended, deleted, test\n";
echo "# Пример см. в строке 4\n";

// Заголовки
fputcsv($output, $headers, ';');

// Пример строки
$exampleRow = [
    'example_user',
    'active',
    'user@example.com',
    'MyPass123',
    // ...
];
fputcsv($output, $exampleRow, ';');

// Пустая строка для заполнения
$emptyRow = array_fill(0, count($headers), '');
fputcsv($output, $emptyRow, ';');
```

**Тестирование:**
- Скачать шаблон → проверить наличие комментариев
- Заполнить по примеру → загрузить → импорт успешен

---

#### ✅ Задача 1.2: Исправить batch import (индивидуальные транзакции)
**Срок:** 2 дня  
**Приоритет:** 🔴 Критический  
**Файлы:** `includes/AccountsRepository.php`

**Проблема:**
Сейчас одна транзакция для всех строк → ошибка в одной строке откатывает ВСЕ.

**Решение:**
Каждая строка в своей транзакции.

**Код:**
```php
// AccountsRepository.php - createAccountsBulk()
public function createAccountsBulk(array $accountsData, string $duplicateAction = 'skip'): array {
    $conn = $this->db->getConnection();
    $created = 0;
    $skipped = 0;
    $errors = [];
    
    // Получаем все существующие логины ОДНИМ запросом
    $loginsToCheck = array_column($accountsData, 'login');
    $existingLogins = $this->getExistingLogins($loginsToCheck);
    
    foreach ($accountsData as $rowNum => $data) {
        // КАЖДАЯ СТРОКА В СВОЕЙ ТРАНЗАКЦИИ
        $conn->begin_transaction();
        
        try {
            // Валидация
            $loginValue = trim((string)($data['login'] ?? ''));
            $statusValue = trim((string)($data['status'] ?? ''));
            
            if (empty($loginValue)) {
                throw new InvalidArgumentException('Login is required');
            }
            if (empty($statusValue)) {
                throw new InvalidArgumentException('Status is required');
            }
            
            // Проверка дубликата (в массиве, не в БД!)
            if (in_array($loginValue, $existingLogins)) {
                if ($duplicateAction === 'skip') {
                    $skipped++;
                    $conn->rollback();
                    continue;
                } elseif ($duplicateAction === 'error') {
                    throw new InvalidArgumentException('Login already exists');
                }
            }
            
            // INSERT
            $sql = "INSERT INTO accounts (login, status, ...) VALUES (?, ?, ...)";
            $stmt = $conn->prepare($sql);
            // ... bind_param, execute ...
            
            $conn->commit(); // ← Коммитим ТОЛЬКО эту строку
            $created++;
            
        } catch (Exception $e) {
            $conn->rollback(); // ← Откатываем ТОЛЬКО эту строку
            $errors[] = [
                'row' => $rowNum + 1,
                'message' => $e->getMessage()
            ];
        }
    }
    
    return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
}

// Вспомогательный метод
private function getExistingLogins(array $logins): array {
    if (empty($logins)) return [];
    
    $conn = $this->db->getConnection();
    $placeholders = implode(',', array_fill(0, count($logins), '?'));
    $sql = "SELECT login FROM accounts WHERE login IN ($placeholders)";
    
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($logins));
    $stmt->bind_param($types, ...$logins);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[] = $row['login'];
    }
    $stmt->close();
    
    return $existing;
}
```

**Результат:**
- 1000 SELECT запросов → **1 SELECT**
- При ошибке в строке 500 откатывается только она, остальные 999 сохраняются

**Тестирование:**
- Импорт 100 строк, где 1 невалидна → 99 добавлены
- Импорт 100 строк с 10 дубликатами → 90 добавлены, 10 пропущены

---

#### ✅ Задача 1.3: Добавить выбор duplicate_action
**Срок:** 1 день  
**Приоритет:** ⚠️ Средний  
**Файлы:** `templates/partials/dashboard/modals/add-account-modal.php`

**Что сделать:**
Вместо `<input type="hidden" name="duplicate_action" value="skip">` добавить радио-кнопки.

**Код:**
```html
<!-- add-account-modal.php -->
<div class="mb-3">
  <label class="form-label fw-semibold">
    <i class="fas fa-copy me-2"></i>
    Действие при обнаружении дубликатов:
  </label>
  
  <div class="form-check">
    <input 
      class="form-check-input" 
      type="radio" 
      name="duplicate_action" 
      id="dupSkip" 
      value="skip" 
      checked
    >
    <label class="form-check-label" for="dupSkip">
      <strong>Пропустить</strong> — не добавлять аккаунты с существующим логином (рекомендуется)
    </label>
  </div>
  
  <div class="form-check">
    <input 
      class="form-check-input" 
      type="radio" 
      name="duplicate_action" 
      id="dupUpdate" 
      value="update"
    >
    <label class="form-check-label" for="dupUpdate">
      <strong>Обновить</strong> — заменить данные существующих аккаунтов
    </label>
  </div>
  
  <div class="form-check">
    <input 
      class="form-check-input" 
      type="radio" 
      name="duplicate_action" 
      id="dupError" 
      value="error"
    >
    <label class="form-check-label" for="dupError">
      <strong>Ошибка</strong> — остановить импорт при обнаружении дубликата
    </label>
  </div>
  
  <div class="form-text">
    Дубликаты определяются по полю <code>login</code>
  </div>
</div>
```

**Реализация `update` в backend:**
```php
// AccountsRepository.php - в createAccountsBulk()
if (in_array($loginValue, $existingLogins)) {
    if ($duplicateAction === 'skip') {
        $skipped++;
        $conn->rollback();
        continue;
    } elseif ($duplicateAction === 'update') {
        // UPDATE вместо INSERT
        $sql = "UPDATE accounts SET status = ?, email = ?, ... WHERE login = ?";
        $stmt = $conn->prepare($sql);
        // ... bind_param, execute ...
        $conn->commit();
        $created++; // Считаем как "обновлённую" запись
        continue;
    } elseif ($duplicateAction === 'error') {
        throw new InvalidArgumentException('Login already exists: ' . $loginValue);
    }
}
```

**Тестирование:**
- `skip` → дубликаты пропускаются
- `update` → дубликаты обновляются
- `error` → импорт останавливается при первом дубликате

---

#### ✅ Задача 1.4: Централизовать конфигурацию
**Срок:** 1 день  
**Приоритет:** 🟡 Низкий  
**Файлы:** `includes/Config.php`, `dashboard-upload.js`

**Что сделать:**
1. Добавить константы в `Config.php`
2. Создать API endpoint для получения конфигурации
3. Использовать в JavaScript

**Код:**
```php
// includes/Config.php
class Config {
    // Импорт
    const MAX_IMPORT_FILE_SIZE = 20 * 1024 * 1024; // 20 MB
    const MAX_IMPORT_ROWS = 10000;
    const IMPORT_BATCH_SIZE = 100;
    const IMPORT_RATE_LIMIT_PER_MINUTE = 5;
    
    // Допустимые статусы
    const ALLOWED_STATUSES = ['active', 'banned', 'suspended', 'deleted', 'test'];
}
```

```php
// api/config.php
<?php
require_once __DIR__ . '/../includes/Config.php';

header('Content-Type: application/json');

echo json_encode([
    'MAX_IMPORT_FILE_SIZE' => Config::MAX_IMPORT_FILE_SIZE,
    'MAX_IMPORT_ROWS' => Config::MAX_IMPORT_ROWS,
    'ALLOWED_STATUSES' => Config::ALLOWED_STATUSES
]);
```

```javascript
// dashboard-upload.js
let config = null;

async function loadConfig() {
    const response = await fetch('/api/config.php');
    config = await response.json();
}

// В handleUpload
if (!config) await loadConfig();
const maxSize = config.MAX_IMPORT_FILE_SIZE;
```

---

### Фаза 2: Производительность (Неделя 3-5)

#### ✅ Задача 2.1: Рефакторинг CSV Parser
**Срок:** 2 дня  
**Приоритет:** ⚠️ Средний  
**Файлы:** Создать `includes/CsvParser.php`

**Что сделать:**
Вынести функцию `parseCSVForImport()` в отдельный класс.

**Код:**
```php
// includes/CsvParser.php
<?php
class CsvParser {
    private $delimiter = ';';
    private $maxRows = 10000;
    
    public function __construct(int $maxRows = 10000, string $delimiter = ';') {
        $this->maxRows = $maxRows;
        $this->delimiter = $delimiter;
    }
    
    public function parse(string $filePath): array {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception('Не удалось открыть файл для чтения');
        }
        
        // Определяем разделитель
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        
        $delimiter = strpos($firstLine, ';') !== false ? ';' : ',';
        rewind($handle);
        
        // Читаем заголовки
        $headers = fgetcsv($handle, 0, $delimiter);
        if (empty($headers)) {
            fclose($handle);
            return [];
        }
        
        $normalizedHeaders = $this->normalizeHeaders($headers);
        
        // Читаем данные
        $data = [];
        $lineNum = 0;
        
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false && $lineNum < $this->maxRows) {
            $lineNum++;
            
            if (empty(array_filter($values))) continue;
            if (count($values) !== count($headers)) continue;
            
            $row = [];
            foreach ($normalizedHeaders as $index => $header) {
                $row[$header] = isset($values[$index]) ? trim($values[$index]) : '';
            }
            
            $data[] = $row;
        }
        
        fclose($handle);
        return $data;
    }
    
    private function normalizeHeaders(array $headers): array {
        $normalized = [];
        foreach ($headers as $header) {
            $clean = mb_strtolower(trim($header), 'UTF-8');
            $clean = preg_replace('/^\xEF\xBB\xBF/', '', $clean);
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);
            $clean = str_replace([' ', '-', '—', '–'], '_', $clean);
            $clean = preg_replace('/_+/', '_', $clean);
            $clean = trim($clean, '_');
            $normalized[] = $clean;
        }
        return $normalized;
    }
}
```

**Использование:**
```php
// import_accounts.php
require_once __DIR__ . '/includes/CsvParser.php';

$parser = new CsvParser(Config::MAX_IMPORT_ROWS);
$data = $parser->parse($file['tmp_name']);
```

---

#### ✅ Задача 2.2: Batch INSERT (опционально, если нужна максимальная скорость)
**Срок:** 4 дня  
**Приоритет:** 🟡 Низкий (только если критична скорость)  
**Файлы:** `includes/AccountsRepository.php`

**Что сделать:**
Вставлять по 100 строк за раз вместо 1.

**Код:**
```php
// AccountsRepository.php
private function batchInsert(array $accountsData, int $batchSize = 100): array {
    $conn = $this->db->getConnection();
    $created = 0;
    $errors = [];
    
    $chunks = array_chunk($accountsData, $batchSize);
    
    foreach ($chunks as $chunkNum => $chunk) {
        $conn->begin_transaction();
        
        try {
            // Формируем VALUES (?, ?), (?, ?), ...
            $valuePlaceholders = [];
            $params = [];
            $types = '';
            
            foreach ($chunk as $data) {
                $valuePlaceholders[] = '(?, ?, ?, ...)'; // По количеству полей
                $params[] = $data['login'];
                $params[] = $data['status'];
                $params[] = $data['email'] ?? null;
                // ... другие поля
                $types .= 'sss...'; // Типы для каждого поля
            }
            
            $sql = "INSERT INTO accounts (login, status, email, ...) VALUES " . implode(',', $valuePlaceholders);
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            $created += $stmt->affected_rows;
            $stmt->close();
            $conn->commit();
            
        } catch (Exception $e) {
            $conn->rollback();
            // Если batch failed, попробовать вставить по одной строке
            foreach ($chunk as $data) {
                try {
                    $this->createAccount($data);
                    $created++;
                } catch (Exception $singleError) {
                    $errors[] = ['message' => $singleError->getMessage()];
                }
            }
        }
    }
    
    return ['created' => $created, 'errors' => $errors];
}
```

**Результат:**
1000 INSERT → 10 batch INSERT (по 100 строк).

---

### Фаза 3: UX улучшения (Неделя 6-8)

#### ✅ Задача 3.1: Клиентская валидация CSV
**Срок:** 3 дня  
**Приоритет:** ⚠️ Средний  
**Файлы:** `dashboard-upload.js`

**Что сделать:**
Валидировать CSV на клиенте перед отправкой.

**Код:**
```javascript
// dashboard-upload.js
async function validateCsvFile(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        
        reader.onload = (e) => {
            const text = e.target.result;
            const lines = text.split('\n').filter(line => line.trim() && !line.startsWith('#'));
            
            if (lines.length < 2) {
                reject('Файл пустой или содержит только заголовки');
                return;
            }
            
            const delimiter = text.includes(';') ? ';' : ',';
            const headers = lines[0].split(delimiter).map(h => h.trim().toLowerCase());
            
            // Проверяем обязательные поля
            if (!headers.includes('login') && !headers.includes('login*')) {
                reject('В файле отсутствует обязательное поле: login');
                return;
            }
            if (!headers.includes('status') && !headers.includes('status*')) {
                reject('В файле отсутствует обязательное поле: status');
                return;
            }
            
            // Проверяем первые 10 строк
            const errors = [];
            const loginIdx = headers.findIndex(h => h === 'login' || h === 'login*');
            const statusIdx = headers.findIndex(h => h === 'status' || h === 'status*');
            
            for (let i = 1; i < Math.min(11, lines.length); i++) {
                const values = lines[i].split(delimiter);
                
                if (!values[loginIdx] || !values[loginIdx].trim()) {
                    errors.push(`Строка ${i + 1}: отсутствует login`);
                }
                if (!values[statusIdx] || !values[statusIdx].trim()) {
                    errors.push(`Строка ${i + 1}: отсутствует status`);
                }
            }
            
            if (errors.length > 0) {
                reject('Обнаружены ошибки в первых 10 строках:\n' + errors.join('\n'));
                return;
            }
            
            resolve(true);
        };
        
        reader.onerror = () => reject('Ошибка чтения файла');
        reader.readAsText(file);
    });
}

// В handleUpload перед fetch
try {
    await validateCsvFile(file);
} catch (validationError) {
    if (errorsDiv) {
        errorsDiv.textContent = validationError;
        errorsDiv.classList.remove('d-none');
    }
    return;
}
```

---

#### ✅ Задача 3.2: Предпросмотр CSV (опционально)
**Срок:** 5 дней  
**Приоритет:** 🟡 Низкий  
**Файлы:** `dashboard-upload.js`, `add-account-modal.php`

**Что сделать:**
Показать пользователю первые 10 строк из CSV перед импортом.

**UI:**
```html
<!-- add-account-modal.php -->
<div id="csvPreview" class="d-none mt-3">
  <h6>Предпросмотр (первые 10 строк):</h6>
  <div class="table-responsive">
    <table class="table table-sm table-bordered">
      <thead id="previewHeaders"></thead>
      <tbody id="previewBody"></tbody>
    </table>
  </div>
  <div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    Проверьте корректность данных. Строки с ошибками подсвечены красным.
  </div>
</div>
```

**JS:**
```javascript
function showCsvPreview(file) {
    const reader = new FileReader();
    reader.onload = (e) => {
        const text = e.target.result;
        const lines = text.split('\n').filter(l => l.trim() && !l.startsWith('#'));
        const delimiter = text.includes(';') ? ';' : ',';
        
        const headers = lines[0].split(delimiter);
        const headersRow = document.getElementById('previewHeaders');
        headersRow.innerHTML = '<tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr>';
        
        const body = document.getElementById('previewBody');
        body.innerHTML = '';
        
        const loginIdx = headers.findIndex(h => h.toLowerCase().includes('login'));
        const statusIdx = headers.findIndex(h => h.toLowerCase().includes('status'));
        
        for (let i = 1; i < Math.min(11, lines.length); i++) {
            const values = lines[i].split(delimiter);
            const row = document.createElement('tr');
            
            // Проверяем валидность
            const hasLogin = values[loginIdx] && values[loginIdx].trim();
            const hasStatus = values[statusIdx] && values[statusIdx].trim();
            
            if (!hasLogin || !hasStatus) {
                row.classList.add('table-danger');
            }
            
            row.innerHTML = values.map(v => `<td>${v || '<em class="text-muted">пусто</em>'}</td>`).join('');
            body.appendChild(row);
        }
        
        document.getElementById('csvPreview').classList.remove('d-none');
    };
    reader.readAsText(file);
}

// Вызываем при выборе файла
fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        showCsvPreview(e.target.files[0]);
    }
});
```

---

## Чеклист для проверки

### После Фазы 1 (быстрые исправления)
- [ ] CSV шаблон содержит комментарии и пример
- [ ] При ошибке в одной строке остальные добавляются
- [ ] Есть выбор действия при дубликатах (skip/update/error)
- [ ] Конфигурация вынесена в `Config.php`

### После Фазы 2 (производительность)
- [ ] CSV parser вынесен в отдельный класс
- [ ] Проверка дубликатов выполняется одним запросом
- [ ] (Опционально) Batch INSERT работает для больших файлов

### После Фазы 3 (UX)
- [ ] Есть клиентская валидация CSV перед загрузкой
- [ ] (Опционально) Показывается предпросмотр первых 10 строк
- [ ] (Опционально) Есть прогресс-бар при загрузке

---

## Метрики успеха

| Метрика | Было | Цель | Измерение |
|---------|------|------|-----------|
| Время импорта 1000 строк | 30 сек | <5 сек | Секундомер |
| Количество ошибок пользователей | ~50% | <10% | Логи ошибок |
| Понимание процесса | 30% | >80% | Опрос пользователей |
| Количество SQL запросов | 2000 | <50 | Профилирование |

---

**Следующие шаги:**
1. Ознакомиться с полным отчётом: `ACCOUNT_ADD_ANALYSIS_REPORT.md`
2. Приоритизировать задачи (рекомендуется начать с Фазы 1)
3. Создать GitHub Issues / JIRA задачи
4. Начать реализацию с Задачи 1.1 (самая простая)
