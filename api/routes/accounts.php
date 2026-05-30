<?php
/**
 * Маршруты ресурса `accounts`.
 *
 * Подключается из `api/index.php`. Использует переменные $router и $tableName
 * из родительского scope.
 *
 * Включённые endpoints:
 *   GET  /accounts/count
 *   POST /accounts
 *   POST /accounts/bulk
 *   POST /accounts/custom-card
 *   POST /accounts/validate/preview     — pre-flight COUNT для UI прогресса
 *   POST /accounts/validate/prepare     — извлечение FB ID из строк
 *   POST /accounts/validate/check       — параллельный check через NPPR
 *   GET  /accounts/validate/progress    — polling прогресса для UI
 *
 * @var ApiRouter $router
 * @var string    $tableName
 */
require_once __DIR__ . '/../../includes/AccountValidationService.php';
require_once __DIR__ . '/../../includes/JobProgress.php';

$router->get('/accounts/count', function() use ($tableName) {
    $service = new AccountsService($tableName);

    // Если передан параметр q, возвращаем также количество результатов
    if (!empty($_GET['q'])) {
        $filter = $service->createFilterFromRequest($_GET);
        $count = $service->getAccountsCount($filter);

        // Также возвращаем ограниченный список для quick search
        $limit = isset($_GET['limit']) ? Validator::validateId($_GET['limit'], true) : 10;
        if ($limit > 0 && $limit <= 50) {
            $rows = $service->getAccounts($filter, 'id', 'DESC', $limit, 0);
            json_success([
                'count' => $count,
                'rows' => $rows
            ]);
        } else {
            json_success(['count' => $count]);
        }
    } else {
        // Обычный подсчет
        $filter = $service->createFilterFromRequest($_GET);
        $count = $service->getAccountsCount($filter);
        json_success(['count' => $count]);
    }
});

$router->post('/accounts', function() use ($tableName) {
    $input = read_json_input(1048576); // 1MB максимум
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }

    // Валидация CSRF токена
    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CREATE ACCOUNT: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }

    // Валидация обязательных полей
    $login = trim((string)($input['login'] ?? ''));
    $status = trim((string)($input['status'] ?? ''));

    if (empty($login)) {
        throw new InvalidArgumentException('Login is required');
    }

    if (empty($status)) {
        throw new InvalidArgumentException('Status is required');
    }

    // Получаем сервис и метаданные для валидации полей
    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();

    // Подготавливаем данные для создания аккаунта
    // Исключаем служебные поля (csrf) и проверяем существование остальных
    $accountData = [];
    foreach ($input as $field => $value) {
        // Пропускаем служебные поля
        if (in_array($field, ['csrf'], true)) {
            continue;
        }

        // Пропускаем системные поля, которые не должны передаваться
        if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }

        // Проверяем существование поля в метаданных
        if (!in_array($field, $meta['all'], true)) {
            Logger::warning('CREATE ACCOUNT: Unknown field ignored', ['field' => $field]);
            continue;
        }

        // Валидируем поле
        try {
            $validatedField = Validator::validateField($field, $meta['all']);
            $accountData[$validatedField] = $value;
        } catch (InvalidArgumentException $e) {
            Logger::warning('CREATE ACCOUNT: Invalid field skipped', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            continue;
        }
    }

    // Убеждаемся, что обязательные поля присутствуют после фильтрации
    if (empty($accountData['login']) || trim((string)$accountData['login']) === '') {
        throw new InvalidArgumentException('Login is required');
    }

    if (empty($accountData['status']) || trim((string)$accountData['status']) === '') {
        throw new InvalidArgumentException('Status is required');
    }

    $id = $service->createAccount($accountData);

    json_success([
        'id' => $id,
        'message' => 'Account created successfully'
    ]);
});

$router->post('/accounts/bulk', function() use ($tableName) {
    $input = read_json_input(20 * 1024 * 1024); // 20MB максимум для bulk операций
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }

    // Валидация CSRF токена
    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CREATE ACCOUNTS BULK: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }

    // Получаем массив аккаунтов
    $accountsData = $input['accounts'] ?? [];
    if (!is_array($accountsData) || empty($accountsData)) {
        throw new InvalidArgumentException('Accounts array is required and must not be empty');
    }

    // Ограничение на количество аккаунтов за один запрос
    if (count($accountsData) > 10000) {
        throw new InvalidArgumentException('Maximum 10000 accounts per request allowed');
    }

    // Действие при дубликатах
    $duplicateAction = $input['duplicate_action'] ?? 'skip';
    if (!in_array($duplicateAction, ['skip', 'error'], true)) {
        $duplicateAction = 'skip';
    }

    // Получаем сервис и метаданные для валидации полей
    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();

    // Валидируем и нормализуем данные для каждого аккаунта
    $validatedAccounts = [];
    foreach ($accountsData as $idx => $accountData) {
        if (!is_array($accountData)) {
            continue; // Пропускаем невалидные записи
        }

        $validatedAccount = [];

        // Проверяем обязательные поля
        $login = trim((string)($accountData['login'] ?? ''));
        $status = trim((string)($accountData['status'] ?? ''));

        if (empty($login) || empty($status)) {
            continue; // Пропускаем записи без обязательных полей
        }

        $validatedAccount['login'] = $login;
        $validatedAccount['status'] = $status;

        // Валидируем остальные поля
        foreach ($accountData as $field => $value) {
            // Пропускаем служебные поля
            if (in_array($field, ['csrf', 'id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            // Проверяем существование поля в метаданных
            if (!in_array($field, $meta['all'], true)) {
                continue;
            }

            // Валидируем поле
            try {
                $validatedField = Validator::validateField($field, $meta['all']);
                $validatedAccount[$validatedField] = $value;
            } catch (InvalidArgumentException $e) {
                continue; // Пропускаем невалидные поля
            }
        }

        $validatedAccounts[] = $validatedAccount;
    }

    if (empty($validatedAccounts)) {
        throw new InvalidArgumentException('No valid accounts to create');
    }

    $result = $service->createAccountsBulk($validatedAccounts, $duplicateAction);

    json_success($result);
});

$router->post('/accounts/custom-card', function() use ($tableName) {
    // Проверка CSRF токена для POST запросов
    $input = read_json_input(1048576); // 1MB максимум
    $csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';

    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CUSTOM CARD API: CSRF validation failed');
        json_error('CSRF validation failed', 403);
        return;
    }

    $service = new AccountsService($tableName);

    // Получаем фильтры из POST запроса (JSON)
    $filters = $input;

    if (!$filters || !is_array($filters)) {
        // Если не JSON, пробуем GET параметры (для обратной совместимости)
        $filters = $_GET;
    }

    // Создаем фильтр из переданных параметров
    $filter = $service->createFilterFromRequest($filters);

    // Подсчитываем количество записей
    $count = $service->getAccountsCount($filter);

    json_success(['count' => $count]);
});

// ────────────────────────────────────────────────────────────
// Проверка аккаунтов на валидность (NPPR Services)
// ────────────────────────────────────────────────────────────

/**
 * POST /accounts/validate/preview
 * Pre-flight COUNT: возвращает только число записей в выбранном scope,
 * без выборки cookies/regex-парсинга. Нужен для мгновенной обратной связи
 * пользователю ("Будет проверено: 1234 аккаунта") до старта тяжёлого prepare.
 */
$router->post('/accounts/validate/preview', function() use ($tableName) {
    $input = read_json_input(1048576);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid input');

    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $scope = (string)($input['scope'] ?? 'selected');
    if (!in_array($scope, ['selected', 'page', 'filter'], true)) {
        throw new InvalidArgumentException('Invalid scope');
    }

    $ids   = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids'])) : [];
    $query = (string)($input['query'] ?? '');

    $service = new AccountsService($tableName);
    $total   = $service->getValidationCount($scope, $ids, $query);

    json_success(['total' => $total, 'scope' => $scope]);
});

/**
 * POST /accounts/validate/prepare
 * Подготовка списка: извлекает FB ID из записей, фильтрует пустые.
 */
$router->post('/accounts/validate/prepare', function() use ($tableName) {
    $input = read_json_input(1048576);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid input');

    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $scope = (string)($input['scope'] ?? 'selected');
    if (!in_array($scope, ['selected', 'page', 'filter'], true)) {
        throw new InvalidArgumentException('Invalid scope');
    }

    $ids    = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids'])) : [];
    $query  = (string)($input['query'] ?? '');
    $limit  = min(max((int)($input['limit'] ?? Config::VALIDATE_PREPARE_LIMIT), 1), Config::VALIDATE_PREPARE_LIMIT);
    $offset = max(0, (int)($input['offset'] ?? 0));

    $tStart = microtime(true);
    $service  = new AccountsService($tableName);
    $data     = $service->getAccountsForValidation($scope, $ids, $query, $limit, $offset);
    $tSql     = microtime(true);
    $prepared = AccountValidationService::prepareItems($data['rows']);
    $tEnd     = microtime(true);

    $rowCount = count($data['rows']);
    $nextOffset = $offset + $rowCount;
    $sqlMs    = (int)(($tSql - $tStart) * 1000);
    $extractMs = (int)(($tEnd - $tSql) * 1000);
    $totalMs  = $sqlMs + $extractMs;

    Logger::debug('validate/prepare timing', [
        'scope'      => $scope,
        'rows'       => $rowCount,
        'items'      => count($prepared['items']),
        'skipped'    => count($prepared['skipped']),
        'sql_ms'     => $sqlMs,
        'extract_ms' => $extractMs,
        'total_ms'   => $totalMs,
    ]);
    if ($totalMs > 3000) {
        Logger::warning('validate/prepare slow', [
            'scope' => $scope, 'rows' => $rowCount,
            'sql_ms' => $sqlMs, 'extract_ms' => $extractMs,
        ]);
    }

    json_success([
        'items'       => $prepared['items'],
        'skipped'     => $prepared['skipped'],
        'total'       => $data['total'],
        'has_more'    => $scope === 'filter' && $nextOffset < $data['total'],
        'next_offset' => $nextOffset,
    ]);
});

/**
 * POST /accounts/validate/check
 * Проверка батча через NPPR fbchecker (curl_multi, параллельно внутри запроса).
 * Сессия закрывается до начала — чтобы не блокировать другие запросы.
 *
 * Если передан job_id — после каждого sub-batch NPPR пишем инкрементальный
 * прогресс в JobProgress. Фронт читает его через polling /progress, что даёт
 * движение % ВНУТРИ одного /check (без этого UI стоит на 0% по 5–15 сек).
 */
$router->post('/accounts/validate/check', function() use ($tableName) {
    $input = read_json_input(1048576);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid input');

    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
    if (count($items) > Config::VALIDATE_CHECK_MAX_ITEMS) {
        throw new InvalidArgumentException('Too many items, max ' . Config::VALIDATE_CHECK_MAX_ITEMS);
    }

    $jobId = (string)($input['job_id'] ?? '');
    if ($jobId !== '' && !JobProgress::isValidId($jobId)) {
        $jobId = ''; // невалидный — игнорируем, но не падаем
    }

    // Cleanup старых job-файлов: на shared нет cron, делаем оппортунистически.
    // Дешёвая операция (glob + filemtime), 1 раз на /check некритично.
    if ($jobId !== '') {
        JobProgress::cleanup();
    }

    // Отпускаем сессию — длинная операция не должна блокировать UI
    session_write_close();
    set_time_limit(120);

    $tStart = microtime(true);
    $result = AccountValidationService::checkItems($items, $jobId !== '' ? $jobId : null);
    $totalMs = (int)((microtime(true) - $tStart) * 1000);

    Logger::debug('validate/check timing', [
        'items'    => count($items),
        'valid'    => count($result['valid']   ?? []),
        'invalid'  => count($result['invalid'] ?? []),
        'skipped'  => count($result['skipped'] ?? []),
        'total_ms' => $totalMs,
        'job_id'   => $jobId,
    ]);
    if ($totalMs > 15000) {
        Logger::warning('validate/check slow', [
            'items' => count($items), 'total_ms' => $totalMs,
        ]);
    }

    json_success($result);
});

/**
 * GET /accounts/validate/progress?job_id=X
 * Возвращает текущий прогресс задачи валидации. Фронт делает polling
 * каждые 1.5 сек чтобы UI двигался во время /check.
 */
$router->get('/accounts/validate/progress', function() {
    $jobId = (string)($_GET['job_id'] ?? '');
    if (!JobProgress::isValidId($jobId)) {
        throw new InvalidArgumentException('Invalid job_id');
    }

    // Polling может прилететь до того как сервер начал писать — это не ошибка
    $data = JobProgress::read($jobId);
    if ($data === null) {
        json_success(['exists' => false, 'checked' => 0]);
        return;
    }

    json_success(array_merge(['exists' => true], $data));
});
