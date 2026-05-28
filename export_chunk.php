<?php
// export_chunk.php — постраничная выгрузка для chunked TXT-скачивания (см. assets/js/modules/dashboard-export.js).
// Возвращает JSON. Два режима:
//   mode=idlist — упорядоченный список id по текущему фильтру (+ total). Лёгкий запрос (только id).
//   mode=rows   — pipe-delimited строки TXT для переданного среза ids по выбранным колонкам.
//
// Зачем: большой экспорт (тысячи строк с тяжёлыми колонками) одним запросом упирается в ~120s
// таймаут веб-сервера/прокси на шаринге и обрывается. Здесь браузер собирает файл из множества
// мелких быстрых запросов — каждый укладывается в лимит, а итог не ограничен временем.

ini_set('memory_limit', '512M');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/Logger.php';

/**
 * Отдать JSON-ошибку и завершить выполнение.
 * $detail — реальное сообщение об ошибке. Endpoint только для авторизованных (не публичный),
 * поэтому детали отдаём клиенту для диагностики.
 */
function chunk_fail(int $code, string $msg, string $detail = ''): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    $out = ['ok' => false, 'error' => $msg];
    if ($detail !== '') { $out['detail'] = $detail; }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// Перехват фатальных ошибок (в т.ч. вне try/catch) — вернуть JSON с причиной вместо голого 500.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (class_exists('Logger')) {
            Logger::error('EXPORT_CHUNK: fatal', ['message' => $e['message'], 'file' => $e['file'], 'line' => $e['line']]);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'     => false,
            'error'  => 'fatal',
            'detail' => $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/includes/Utils.php';
    require_once __DIR__ . '/includes/RequestHandler.php';
    require_once __DIR__ . '/includes/AccountsService.php';
    require_once __DIR__ . '/includes/Config.php';
    require_once __DIR__ . '/includes/RateLimitMiddleware.php';
    require_once __DIR__ . '/includes/Validator.php';
} catch (Throwable $e) {
    Logger::error('EXPORT_CHUNK: bootstrap failed', ['error' => $e->getMessage()]);
    chunk_fail(500, 'bootstrap failed', $e->getMessage());
}

// Авторизация. Используем мягкий лимит 'api' (100/мин), а не строгий 'export' (10/мин):
// chunked-выгрузка делает много мелких запросов подряд.
try {
    requireAuth();
    checkSessionTimeout();
    checkRateLimit('api');
} catch (Throwable $e) {
    chunk_fail(403, 'auth', $e->getMessage());
}

// Требуем POST + валидный CSRF (как export.php) — это authenticated bulk-read.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    chunk_fail(405, 'POST required');
}
$csrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
try {
    Validator::validateCsrfToken((string)$csrf);
} catch (Throwable $e) {
    chunk_fail(403, 'csrf');
}

// Имя таблицы приходит из TableResolver (config.php). Жёсткая проверка перед интерполяцией в SQL.
if (!isset($tableName) || !preg_match('/^[a-zA-Z0-9_]+$/', (string)$tableName)) {
    chunk_fail(500, 'bad table');
}

$mode = isset($_POST['mode']) ? (string)$_POST['mode'] : 'rows';
$sort = isset($_POST['sort']) ? (string)$_POST['sort'] : 'id';
$dir  = isset($_POST['dir'])  ? (string)$_POST['dir']  : 'ASC';

try {
    $service = new AccountsService($tableName);
    $meta = $service->getColumnMetadata();
    $allCols = $meta['all'];
} catch (Throwable $e) {
    Logger::error('EXPORT_CHUNK: service init failed', ['error' => $e->getMessage()]);
    chunk_fail(500, 'service init', $e->getMessage());
}

// ── Режим idlist: вернуть упорядоченный список id по фильтру ───────────────────
if ($mode === 'idlist') {
    try {
        // Сборка фильтра как в export.php (поддержка status[] из формы).
        $params = $_POST;
        if (isset($params['status[]']) && is_array($params['status[]'])) {
            $params['status'] = $params['status[]'];
            unset($params['status[]']);
        }
        if (isset($params['status']) && is_array($params['status'])) {
            $params['status'] = array_filter($params['status']);
        }

        $filter   = $service->createFilterFromRequest($params);
        $orderBy  = $service->buildOrderBy($sort, $dir);
        $where    = $filter->getWhereClause(false); // исключает soft-deleted
        $sqlParams = $filter->getParams();

        $sql = "SELECT id FROM `{$tableName}` {$where} ORDER BY {$orderBy} LIMIT ?";
        $sqlParams[] = (int)Config::MAX_EXPORT_RECORDS;

        $rows = Database::getInstance()->prepare($sql, $sqlParams);
        $ids = array_map(static function ($r) { return (int)$r['id']; }, $rows);

        echo json_encode(['ok' => true, 'ids' => $ids, 'total' => count($ids)], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        Logger::error('EXPORT_CHUNK: idlist failed', ['error' => $e->getMessage()]);
        chunk_fail(500, 'idlist', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// ── Режим rows: pipe-delimited строки TXT для переданного среза ids ────────────
try {
    $idsRaw = isset($_POST['ids']) ? (string)$_POST['ids'] : '';
    $idArray = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
    if (empty($idArray)) {
        echo json_encode(['ok' => true, 'text' => '', 'count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Защита от слишком большого среза за один запрос.
    if (count($idArray) > 5000) {
        $idArray = array_slice($idArray, 0, 5000);
    }

    // Выбранные колонки (как в export.php): валидируем против реальных колонок.
    $colsParam = isset($_POST['cols']) ? (string)$_POST['cols'] : '';
    $selectedCols = [];
    if ($colsParam !== '') {
        foreach (explode(',', $colsParam) as $c) {
            $c = trim($c);
            if (in_array($c, $allCols, true)) { $selectedCols[] = $c; }
        }
        $selectedCols = array_values(array_unique($selectedCols));
    }
    if (empty($selectedCols)) {
        $selectedCols = ['id', 'login', 'email', 'status'];
    }

    // Санитизация ячейки — идентична export.php, чтобы файл был такой же.
    $sanitizeCell = function ($v) {
        if ($v === null) return '';
        $s = (string)$v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'auto');
        }
        $s = str_replace(["\r", "\n", "|"], [' ', ' ', ' '], $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = trim($s);
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) {
            $s = "'" . $s;
        }
        return $s;
    };

    // Тянем именно этот срез ids (быстро, по PK). Порядок — по тому же sort/dir,
    // что и idlist, поэтому строки в файле идут в том же порядке, что список.
    $filter = $service->createFilterFromRequest(['ids' => $idArray]);
    $accounts = $service->getAccounts($filter, $sort, $dir, count($idArray), 0);

    $EOL = "\r\n";
    $buf = '';
    foreach ($accounts as $row) {
        $line = [];
        foreach ($selectedCols as $key) {
            $line[] = $sanitizeCell($row[$key] ?? '');
        }
        $buf .= implode('|', $line) . $EOL;
    }

    echo json_encode(['ok' => true, 'text' => $buf, 'count' => count($accounts)], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    Logger::error('EXPORT_CHUNK: rows failed', ['error' => $e->getMessage()]);
    chunk_fail(500, 'rows', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
