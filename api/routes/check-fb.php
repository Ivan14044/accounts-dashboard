<?php
/**
 * Маршрут проверки аккаунтов через NPPR Services (FB Checker).
 *
 * POST /accounts/check-fb
 *   body: { ids: [int...], csrf: '...' }
 *   →    { results: [{ id, fb_id, status, valid }], breakdown, total, balance }
 *
 * Это устаревший, но всё ещё работающий путь проверки. Современный поток
 * — POST /accounts/validate/{preview,prepare,check} (см. `accounts.php`).
 *
 * @var ApiRouter $router
 */
require_once __DIR__ . '/../../includes/FbCheckerService.php';
require_once __DIR__ . '/../../includes/FilterBuilder.php';

$router->post('/accounts/check-fb', function() {
    $input = read_json_input(2 * 1024 * 1024); // 2MB на массив id
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid input');
    }

    $csrf = (string)($input['csrf'] ?? '');
    if (!Validator::validateCsrfToken($csrf)) {
        Logger::warning('CHECK FB: CSRF validation failed');
        throw new InvalidArgumentException('CSRF validation failed');
    }

    $rawIds = isset($input['ids']) && is_array($input['ids']) ? $input['ids'] : [];
    $ids = [];
    foreach ($rawIds as $v) {
        $i = (int)$v;
        if ($i > 0) $ids[] = $i;
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        throw new InvalidArgumentException('ids[] is required');
    }
    if (count($ids) > Config::MAX_IDS_PER_REQUEST) {
        throw new InvalidArgumentException('Too many ids in one request');
    }

    $checker = new FbCheckerService();
    if (!$checker->isConfigured()) {
        Logger::error('CHECK FB: NPPR_TOKEN env var is not set');
        json_error('FB checker is not configured (NPPR_TOKEN missing)', 503);
        return;
    }

    // Берём строки из БД по выбранным ID — ОБЯЗАТЕЛЬНО из выбранной таблицы.
    // Без $tableName и AccountsService, и FilterBuilder молча берут 'accounts':
    // при работе со второй таблицей проверка читала данные (токен, cookies)
    // ЧУЖОГО аккаунта с тем же id и возвращала статусы, привязанные к id из
    // другой таблицы. Проверено пробником на двух таблицах 2026-08-10:
    //   запрошена accounts2, id=7001 → без таблицы приходил login из accounts.
    // Инвариант стережёт tests/test_api_routes_table_scope.php.
    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();
    $filter = new FilterBuilder(
        $meta['columns'],
        $meta['numeric'],
        AccountsService::getNumericLikeColumns(),
        $tableName
    );
    $filter->addIdsFilter($ids);
    $rows = $service->getAccounts($filter, 'id', 'ASC', count($ids), 0, false);

    if (empty($rows)) {
        json_success([
            'results'   => [],
            'breakdown' => ['active' => 0, 'banned' => 0, 'notFound' => 0, 'withoutToken' => 0, 'duplicate' => 0, 'error' => 0],
            'total'     => 0,
            'balance'   => null,
        ]);
        return;
    }

    // Параллельный массив: db_id ↔ NPPR-строка
    $dbIds = [];
    $lines = [];
    foreach ($rows as $row) {
        $dbIds[] = (int)$row['id'];
        $lines[] = $checker->buildAccountString($row);
    }

    $checkResult = $checker->checkLines($lines);

    $out = [];
    foreach ($dbIds as $i => $dbId) {
        $line   = $checkResult['results'][$i]['line']   ?? '';
        $status = $checkResult['results'][$i]['status'] ?? 'error';
        $out[] = [
            'id'     => $dbId,
            'fb_id'  => FbCheckerService::extractFbId($line),
            'status' => $status,
            'valid'  => $status === 'active',
        ];
    }

    Logger::info('FB checker run', [
        'requested' => count($ids),
        'checked'   => count($out),
        'breakdown' => $checkResult['breakdown'],
        'balance'   => $checkResult['balance'],
    ]);

    json_success([
        'results'   => $out,
        'breakdown' => $checkResult['breakdown'],
        'total'     => count($out),
        'balance'   => $checkResult['balance'],
    ]);
});
