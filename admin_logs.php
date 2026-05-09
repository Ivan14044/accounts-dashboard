<?php
/**
 * admin_logs.php — Журнал действий сотрудников (Audit Log для администратора)
 * Показывает ВСЕ движения аккаунтов: смену статусов, редактирование полей,
 * массовые операции, удаления — с фильтрацией и пагинацией.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/AuditLogger.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/Validator.php';

// Проверяем авторизацию
requireAuth();
checkSessionTimeout();

// Экранирование
function e($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Защита от CSV/formula injection при открытии в Excel.
 * Префиксуем апострофом любые значения, начинающиеся с опасных символов.
 */
function sanitizeCsvCell($value): string {
    $s = (string)($value ?? '');
    if ($s === '') return $s;
    $first = $s[0];
    if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
        || $first === "\t" || $first === "\r") {
        return "'" . $s;
    }
    return $s;
}

// ===== Экспорт CSV =====
// Требуем POST + CSRF — чтобы нельзя было выкачать весь audit log ссылкой CSRF.
if (
    (($_POST['export'] ?? '') === 'csv') &&
    (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
) {
    try {
        Validator::validateCsrfToken((string)($_POST['csrf'] ?? ''));
    } catch (Throwable $e) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die('CSRF validation failed');
    }

    // Переносим все нужные фильтры из POST в $_GET чтобы сохранить дальнейшую логику.
    foreach (['date_from','date_to','user','field','account_id','search','period'] as $k) {
        if (isset($_POST[$k])) { $_GET[$k] = $_POST[$k]; }
    }
    $_GET['export'] = 'csv';
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $mysqli = Database::getInstance()->getConnection();

    $exportWhere = [];
    $exportParams = [];
    $exportTypes = '';

    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $filterUser = $_GET['user'] ?? '';
    $filterField = $_GET['field'] ?? '';
    $filterAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
    $filterSearch = $_GET['search'] ?? '';

    if (!empty($dateFrom)) {
        $exportWhere[] = "h.changed_at >= ?";
        $exportParams[] = $dateFrom . ' 00:00:00';
        $exportTypes .= 's';
    }
    if (!empty($dateTo)) {
        $exportWhere[] = "h.changed_at <= ?";
        $exportParams[] = $dateTo . ' 23:59:59';
        $exportTypes .= 's';
    }
    if (!empty($filterUser)) {
        $exportWhere[] = "h.changed_by = ?";
        $exportParams[] = $filterUser;
        $exportTypes .= 's';
    }
    if (!empty($filterField)) {
        $exportWhere[] = "h.field_name = ?";
        $exportParams[] = $filterField;
        $exportTypes .= 's';
    }
    if ($filterAccountId > 0) {
        $exportWhere[] = "h.account_id = ?";
        $exportParams[] = $filterAccountId;
        $exportTypes .= 'i';
    }
    if (!empty($filterSearch)) {
        $exportWhere[] = "(h.old_value LIKE ? OR h.new_value LIKE ? OR h.changed_by LIKE ?)";
        $s = "%{$filterSearch}%";
        $exportParams[] = $s;
        $exportParams[] = $s;
        $exportParams[] = $s;
        $exportTypes .= 'sss';
    }

    $whereClause = !empty($exportWhere) ? 'WHERE ' . implode(' AND ', $exportWhere) : '';
    $sql = "SELECT h.* FROM account_history h $whereClause ORDER BY h.changed_at DESC LIMIT 10000";

    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        if (!empty($exportParams)) {
            $stmt->bind_param($exportTypes, ...$exportParams);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_H-i') . '.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, ['ID', 'ID аккаунта', 'Поле', 'Старое значение', 'Новое значение', 'Пользователь', 'Дата/Время', 'IP-адрес']);

        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                sanitizeCsvCell($row['id']),
                sanitizeCsvCell($row['account_id']),
                sanitizeCsvCell($row['field_name']),
                sanitizeCsvCell($row['old_value']),
                sanitizeCsvCell($row['new_value']),
                sanitizeCsvCell($row['changed_by']),
                sanitizeCsvCell($row['changed_at']),
                sanitizeCsvCell($row['ip_address']),
            ]);
        }

        fclose($output);
        $stmt->close();
        exit;
    }
}

// ===== API: получить уникальных пользователей =====
if (isset($_GET['api']) && $_GET['api'] === 'users') {
    $mysqli = Database::getInstance()->getConnection();
    $result = $mysqli->query("SELECT DISTINCT changed_by FROM account_history ORDER BY changed_by ASC");
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row['changed_by'];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($users);
    exit;
}

// ===== API: получить уникальные поля =====
if (isset($_GET['api']) && $_GET['api'] === 'fields') {
    $mysqli = Database::getInstance()->getConnection();
    $result = $mysqli->query("SELECT DISTINCT field_name FROM account_history ORDER BY field_name ASC");
    $fields = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $fields[] = $row['field_name'];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($fields);
    exit;
}

// ===== Основная логика =====
$mysqli = Database::getInstance()->getConnection();

// Быстрые периоды
$period = $_GET['period'] ?? '';
switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d');
        break;
    case 'yesterday':
        $dateFrom = date('Y-m-d', strtotime('-1 day'));
        $dateTo = date('Y-m-d', strtotime('-1 day'));
        break;
    case '3days':
        $dateFrom = date('Y-m-d', strtotime('-3 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'month':
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'all':
        $dateFrom = '';
        $dateTo = '';
        break;
    default:
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        break;
}

$filterUser = $_GET['user'] ?? '';
$filterField = $_GET['field'] ?? '';
$filterAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$filterSearch = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(20, min(200, (int)($_GET['per_page'] ?? 50)));

// Собираем WHERE
$whereConditions = [];
$params = [];
$types = '';

if (!empty($dateFrom)) {
    $whereConditions[] = "h.changed_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}

if (!empty($dateTo)) {
    $whereConditions[] = "h.changed_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}

if (!empty($filterUser)) {
    $whereConditions[] = "h.changed_by = ?";
    $params[] = $filterUser;
    $types .= 's';
}

if (!empty($filterField)) {
    $whereConditions[] = "h.field_name = ?";
    $params[] = $filterField;
    $types .= 's';
}

if ($filterAccountId > 0) {
    $whereConditions[] = "h.account_id = ?";
    $params[] = $filterAccountId;
    $types .= 'i';
}

if (!empty($filterSearch)) {
    $whereConditions[] = "(h.old_value LIKE ? OR h.new_value LIKE ? OR h.changed_by LIKE ?)";
    $s = "%{$filterSearch}%";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $types .= 'sss';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Подсчёт общего количества
$countSql = "SELECT COUNT(*) as total FROM account_history h $whereClause";
$countStmt = $mysqli->prepare($countSql);
$totalCount = 0;
if ($countStmt) {
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $totalCount = $countStmt->get_result()->fetch_assoc()['total'] ?? 0;
    $countStmt->close();
}

// Пагинация
$totalPages = max(1, ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Получаем записи
$sql = "SELECT h.* FROM account_history h $whereClause ORDER BY h.changed_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$logs = [];
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
}

// Статистика: действия по пользователям за период
$statsUsers = [];
$statsSql = "SELECT changed_by, COUNT(*) as cnt FROM account_history h $whereClause GROUP BY changed_by ORDER BY cnt DESC LIMIT 20";
$statsParams = array_slice($params, 0, -2);
$statsTypes = substr($types, 0, -2);

$statsStmt = $mysqli->prepare($statsSql);
if ($statsStmt) {
    if (!empty($statsParams)) {
        $statsStmt->bind_param($statsTypes, ...$statsParams);
    }
    $statsStmt->execute();
    $statsResult = $statsStmt->get_result();
    while ($row = $statsResult->fetch_assoc()) {
        $statsUsers[] = $row;
    }
    $statsStmt->close();
}

// Статистика: действия по полям за период
$statsFields = [];
$fieldsSql = "SELECT field_name, COUNT(*) as cnt FROM account_history h $whereClause GROUP BY field_name ORDER BY cnt DESC LIMIT 20";
$fieldsStmt = $mysqli->prepare($fieldsSql);
if ($fieldsStmt) {
    if (!empty($statsParams)) {
        $fieldsStmt->bind_param($statsTypes, ...$statsParams);
    }
    $fieldsStmt->execute();
    $fieldsResult = $fieldsStmt->get_result();
    while ($row = $fieldsResult->fetch_assoc()) {
        $statsFields[] = $row;
    }
    $fieldsStmt->close();
}

// Статистика: действия за сегодня
$todayCount = 0;
$todayStmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM account_history WHERE changed_at >= ?");
if ($todayStmt) {
    $todayDate = date('Y-m-d') . ' 00:00:00';
    $todayStmt->bind_param('s', $todayDate);
    $todayStmt->execute();
    $todayCount = $todayStmt->get_result()->fetch_assoc()['cnt'] ?? 0;
    $todayStmt->close();
}

// Получаем списки для фильтров
$allUsers = [];
$usersResult = $mysqli->query("SELECT DISTINCT changed_by FROM account_history ORDER BY changed_by ASC");
if ($usersResult) {
    while ($row = $usersResult->fetch_assoc()) {
        $allUsers[] = $row['changed_by'];
    }
}

$allFields = [];
$fieldsResult2 = $mysqli->query("SELECT DISTINCT field_name FROM account_history ORDER BY field_name ASC");
if ($fieldsResult2) {
    while ($row = $fieldsResult2->fetch_assoc()) {
        $allFields[] = $row['field_name'];
    }
}

// Хелпер для читабельного названия поля
function fieldLabel(string $field): string {
    $labels = [
        'status' => 'Статус',
        'email' => 'Email',
        'email_password' => 'Пароль почты',
        'password' => 'Пароль',
        'login' => 'Логин',
        'cookies' => 'Cookies',
        'first_cookie' => 'First Cookie',
        'token' => 'Токен',
        'two_fa' => '2FA',
        'notes' => 'Заметки',
        'description' => 'Описание',
        'proxy' => 'Прокси',
        'user_agent' => 'User Agent',
        'id_soc_account' => 'ID соц. аккаунта',
        'api_key' => 'API ключ',
        'name' => 'Имя',
        'phone' => 'Телефон',
        'birthday' => 'Дата рождения',
        'gender' => 'Пол',
        'country' => 'Страна',
    ];
    return $labels[strtolower($field)] ?? $field;
}

// Хелпер для иконки действия
function fieldIcon(string $field): string {
    $icons = [
        'status' => 'fa-exchange-alt',
        'email' => 'fa-envelope',
        'email_password' => 'fa-key',
        'password' => 'fa-lock',
        'login' => 'fa-user',
        'cookies' => 'fa-cookie-bite',
        'first_cookie' => 'fa-cookie',
        'token' => 'fa-shield-alt',
        'two_fa' => 'fa-mobile-alt',
        'notes' => 'fa-sticky-note',
        'description' => 'fa-align-left',
        'proxy' => 'fa-globe',
        'user_agent' => 'fa-desktop',
        'id_soc_account' => 'fa-id-badge',
        'name' => 'fa-signature',
        'phone' => 'fa-phone',
    ];
    return $icons[strtolower($field)] ?? 'fa-edit';
}

// Хелпер для цвета действия
function actionColor(string $field): string {
    $colors = [
        'status' => '#0d6efd',
        'email' => '#6f42c1',
        'password' => '#dc3545',
        'email_password' => '#dc3545',
        'login' => '#198754',
        'cookies' => '#fd7e14',
        'token' => '#dc3545',
        'notes' => '#20c997',
        'description' => '#20c997',
        'proxy' => '#6610f2',
        'two_fa' => '#e83e8c',
        'name' => '#17a2b8',
        'phone' => '#17a2b8',
    ];
    return $colors[strtolower($field)] ?? '#6c757d';
}

// Функция для построения URL с текущими фильтрами
function buildUrl(array $overrides = []): string {
    $params = [
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'user' => $_GET['user'] ?? '',
        'field' => $_GET['field'] ?? '',
        'account_id' => $_GET['account_id'] ?? '',
        'search' => $_GET['search'] ?? '',
        'page' => $_GET['page'] ?? 1,
        'per_page' => $_GET['per_page'] ?? 50,
        'period' => $_GET['period'] ?? '',
    ];
    $params = array_merge($params, $overrides);
    $params = array_filter($params, function($v) { return $v !== '' && $v !== 0 && $v !== '0'; });
    return 'admin_logs.php?' . http_build_query($params);
}

// Определяем активный период для кнопок
function activePeriod(): string {
    return $_GET['period'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Журнал действий — Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/core-theme.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
    <link href="assets/css/core-mobile.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">
    <link href="assets/css/core-design-v2.css?v=<?= defined('ASSETS_VERSION') ? ASSETS_VERSION : time() ?>" rel="stylesheet">

    <style>
        :root {
            --log-bg: #ffffff;
            --log-border: #e5e7eb;
            --log-hover: #f8f9fa;
            --log-stripe: #f8fafc;
        }

        body { background: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

        /* === Шапка === */
        .page-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: #fff;
            padding: 28px 32px;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.12);
        }
        .page-header h1 { font-size: 1.6rem; font-weight: 700; margin: 0; }
        .page-header .subtitle { font-size: 0.9rem; opacity: 0.7; margin-top: 4px; }

        /* === Карточки статистики === */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--log-bg);
            border: 1px solid var(--log-border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .stat-card .stat-icon { font-size: 1.4rem; margin-bottom: 8px; }
        .stat-card .stat-number { font-size: 1.8rem; font-weight: 700; color: #1e293b; }
        .stat-card .stat-label { font-size: 0.8rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }

        /* === Быстрые периоды === */
        .period-buttons { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
        .period-btn {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 500;
            border: 1px solid var(--log-border);
            background: var(--log-bg);
            color: #475569;
            text-decoration: none;
            transition: all 0.15s;
        }
        .period-btn:hover { background: #eff6ff; border-color: #3b82f6; color: #1e40af; }
        .period-btn.active { background: #1e293b; border-color: #1e293b; color: #fff; }

        /* === Фильтры === */
        .filters-card {
            background: var(--log-bg);
            border: 1px solid var(--log-border);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }
        .filters-card .form-label {
            font-size: 0.8rem; font-weight: 600; color: #475569;
            text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 6px;
        }
        .filters-card .form-control,
        .filters-card .form-select { border-radius: 8px; border-color: #d1d5db; font-size: 0.9rem; }
        .filters-card .form-control:focus,
        .filters-card .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }

        /* === Таблица логов === */
        .logs-card {
            background: var(--log-bg);
            border: 1px solid var(--log-border);
            border-radius: 12px;
            overflow: hidden;
        }
        .logs-card .card-header-custom {
            background: #f8fafc;
            padding: 16px 24px;
            border-bottom: 1px solid var(--log-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .log-table { width: 100%; border-collapse: collapse; }
        .log-table thead th {
            background: #f1f5f9; padding: 12px 16px;
            font-size: 0.78rem; font-weight: 600; color: #475569;
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid var(--log-border);
            white-space: nowrap; position: sticky; top: 0; z-index: 10;
        }
        .log-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.1s;
            cursor: pointer;
        }
        .log-table tbody tr:hover { background: #eef2ff; }
        .log-table tbody tr:nth-child(even) { background: var(--log-stripe); }
        .log-table tbody tr:nth-child(even):hover { background: #eef2ff; }
        .log-table td { padding: 12px 16px; font-size: 0.88rem; vertical-align: middle; }

        /* === Бейдж поля === */
        .field-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 20px;
            font-size: 0.78rem; font-weight: 600;
            color: #fff; white-space: nowrap;
        }
        .field-badge i { font-size: 0.7rem; }

        /* === Значения === */
        .value-cell { max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
        .value-old {
            background: #fef2f2; color: #991b1b;
            padding: 3px 8px; border-radius: 6px;
            font-size: 0.82rem; font-family: monospace;
            display: inline-block; max-width: 200px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .value-new {
            background: #f0fdf4; color: #166534;
            padding: 3px 8px; border-radius: 6px;
            font-size: 0.82rem; font-family: monospace;
            display: inline-block; max-width: 200px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        /* === Пользователь === */
        .user-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; background: #eff6ff;
            border: 1px solid #bfdbfe; border-radius: 20px;
            font-size: 0.82rem; font-weight: 500; color: #1e40af; white-space: nowrap;
        }
        .user-badge .user-dot { width: 8px; height: 8px; border-radius: 50%; background: #3b82f6; }

        /* === Аккаунт === */
        .account-link {
            display: inline-flex; align-items: center; gap: 4px;
            font-weight: 600; color: #1e293b; text-decoration: none;
            padding: 3px 8px; border-radius: 6px;
            transition: all 0.15s;
        }
        .account-link:hover { background: #e0e7ff; color: #3730a3; }
        .account-link i { font-size: 0.7rem; opacity: 0.5; }

        /* === Кнопки действий === */
        .action-buttons { display: flex; gap: 4px; }
        .action-btn {
            width: 32px; height: 32px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; border: 1px solid #e2e8f0;
            background: #fff; color: #64748b;
            font-size: 0.8rem; transition: all 0.15s;
            text-decoration: none;
        }
        .action-btn:hover { border-color: #3b82f6; color: #3b82f6; background: #eff6ff; }
        .action-btn.view-btn:hover { border-color: #059669; color: #059669; background: #ecfdf5; }
        .action-btn.history-btn:hover { border-color: #7c3aed; color: #7c3aed; background: #f5f3ff; }
        .action-btn.filter-btn:hover { border-color: #d97706; color: #d97706; background: #fffbeb; }

        /* === IP === */
        .ip-badge { font-size: 0.78rem; color: #94a3b8; font-family: monospace; }

        /* === Пагинация === */
        .pagination-wrapper {
            padding: 16px 24px; background: #f8fafc;
            border-top: 1px solid var(--log-border);
            display: flex; justify-content: space-between; align-items: center;
        }
        .pagination .page-link {
            border-radius: 8px; margin: 0 2px;
            font-size: 0.85rem; border: 1px solid #d1d5db; color: #475569;
        }
        .pagination .page-item.active .page-link { background: #1e293b; border-color: #1e293b; }

        /* === Стата по пользователям === */
        .user-stats-bar { display: flex; gap: 8px; flex-wrap: wrap; }
        .user-stat-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; background: var(--log-bg);
            border: 1px solid var(--log-border); border-radius: 20px;
            font-size: 0.82rem; cursor: pointer; transition: all 0.15s;
        }
        .user-stat-chip:hover { background: #eff6ff; border-color: #3b82f6; }
        .user-stat-chip.active-chip { background: #1e293b; border-color: #1e293b; color: #fff; }
        .user-stat-chip .chip-count {
            background: #e2e8f0; padding: 1px 8px;
            border-radius: 10px; font-weight: 600; font-size: 0.78rem;
        }
        .user-stat-chip.active-chip .chip-count { background: rgba(255,255,255,0.2); color: #fff; }

        /* === Scrollable table === */
        .table-wrapper { max-height: 65vh; overflow-y: auto; }

        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 3rem; margin-bottom: 16px; }

        .value-tooltip { cursor: help; }

        /* === Модальное окно деталей === */
        .detail-modal .modal-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .detail-modal .detail-row {
            display: flex; gap: 12px; margin-bottom: 12px;
            padding: 12px; background: #f8fafc; border-radius: 8px;
        }
        .detail-modal .detail-label {
            font-size: 0.8rem; font-weight: 600; color: #64748b;
            text-transform: uppercase; min-width: 120px;
        }
        .detail-modal .detail-value {
            font-size: 0.9rem; color: #1e293b;
            word-break: break-all; white-space: pre-wrap; flex: 1;
        }
        .detail-modal .value-block {
            padding: 12px 16px; border-radius: 8px;
            font-family: monospace; font-size: 0.85rem;
            word-break: break-all; white-space: pre-wrap;
            max-height: 200px; overflow-y: auto;
        }
        .detail-modal .value-block.old { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .detail-modal .value-block.new { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        /* === Автообновление === */
        .auto-refresh-indicator {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 12px; border-radius: 20px;
            font-size: 0.78rem; font-weight: 500;
        }
        .auto-refresh-indicator.active { background: #dcfce7; color: #166534; }
        .auto-refresh-indicator.inactive { background: #f1f5f9; color: #94a3b8; }
        .pulse-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #22c55e; animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* === Мобильная адаптация === */
        @media (max-width: 768px) {
            .page-header { padding: 20px; }
            .filters-card { padding: 16px; }
            .log-table td, .log-table thead th { padding: 8px 10px; font-size: 0.8rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .action-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4" style="max-width: 1600px;">

        <!-- Шапка -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h1><i class="fas fa-shield-alt me-2"></i>Журнал действий сотрудников</h1>
                    <div class="subtitle">Полный аудит всех операций с аккаунтами</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button onclick="toggleAutoRefresh()" class="btn btn-sm btn-outline-light" id="autoRefreshBtn" title="Автообновление каждые 30 сек">
                        <i class="fas fa-sync-alt me-1"></i> Авто
                    </button>
                    <form method="post" action="admin_logs.php" class="d-inline">
                        <input type="hidden" name="export" value="csv">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="date_from" value="<?= e($dateFrom) ?>">
                        <input type="hidden" name="date_to" value="<?= e($dateTo) ?>">
                        <input type="hidden" name="user" value="<?= e($filterUser) ?>">
                        <input type="hidden" name="field" value="<?= e($filterField) ?>">
                        <input type="hidden" name="account_id" value="<?= e($filterAccountId > 0 ? $filterAccountId : '') ?>">
                        <input type="hidden" name="search" value="<?= e($filterSearch) ?>">
                        <input type="hidden" name="period" value="<?= e($period) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-light">
                            <i class="fas fa-file-csv me-1"></i> CSV
                        </button>
                    </form>
                    <a href="log.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-server me-1"></i> Системные логи
                    </a>
                    <a href="index.php" class="btn btn-sm btn-outline-light">
                        <i class="fas fa-arrow-left me-1"></i> Дашборд
                    </a>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="color: #3b82f6;"><i class="fas fa-list-alt"></i></div>
                <div class="stat-number"><?= number_format($totalCount) ?></div>
                <div class="stat-label">По фильтрам</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #22c55e;"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-number"><?= number_format($todayCount) ?></div>
                <div class="stat-label">Сегодня</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #8b5cf6;"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?= count($statsUsers) ?></div>
                <div class="stat-label">Сотрудников</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #f59e0b;"><i class="fas fa-tags"></i></div>
                <div class="stat-number"><?= count($statsFields) ?></div>
                <div class="stat-label">Типов действий</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #64748b;"><i class="fas fa-copy"></i></div>
                <div class="stat-number"><?= $totalPages ?></div>
                <div class="stat-label">Страниц</div>
            </div>
        </div>

        <!-- Быстрые периоды -->
        <div class="period-buttons">
            <a href="<?= e(buildUrl(['period' => 'today', 'page' => 1, 'date_from' => '', 'date_to' => ''])) ?>"
               class="period-btn <?= $period === 'today' ? 'active' : '' ?>">
                <i class="fas fa-calendar-day me-1"></i> Сегодня
            </a>
            <a href="<?= e(buildUrl(['period' => 'yesterday', 'page' => 1, 'date_from' => '', 'date_to' => ''])) ?>"
               class="period-btn <?= $period === 'yesterday' ? 'active' : '' ?>">
                Вчера
            </a>
            <a href="<?= e(buildUrl(['period' => '3days', 'page' => 1, 'date_from' => '', 'date_to' => ''])) ?>"
               class="period-btn <?= $period === '3days' ? 'active' : '' ?>">
                3 дня
            </a>
            <a href="<?= e(buildUrl(['period' => 'week', 'page' => 1, 'date_from' => '', 'date_to' => ''])) ?>"
               class="period-btn <?= $period === 'week' || $period === '' ? 'active' : '' ?>">
                Неделя
            </a>
            <a href="<?= e(buildUrl(['period' => 'month', 'page' => 1, 'date_from' => '', 'date_to' => ''])) ?>"
               class="period-btn <?= $period === 'month' ? 'active' : '' ?>">
                Месяц
            </a>
            <a href="<?= e(buildUrl(['period' => 'all', 'page' => 1, 'date_from' => '', 'date_to' => ''])) ?>"
               class="period-btn <?= $period === 'all' ? 'active' : '' ?>">
                Все время
            </a>
        </div>

        <!-- Активность по пользователям -->
        <?php if (!empty($statsUsers)): ?>
        <div class="filters-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <strong style="font-size: 0.85rem; color: #475569;">
                    <i class="fas fa-users me-1"></i> Активность по сотрудникам
                </strong>
                <?php if (!empty($filterUser)): ?>
                    <a href="<?= e(buildUrl(['user' => '', 'page' => 1])) ?>" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-times me-1"></i> Сбросить фильтр
                    </a>
                <?php endif; ?>
            </div>
            <div class="user-stats-bar">
                <?php foreach ($statsUsers as $su): ?>
                    <a href="<?= e(buildUrl(['user' => $su['changed_by'], 'page' => 1])) ?>"
                       class="user-stat-chip text-decoration-none <?= $filterUser === $su['changed_by'] ? 'active-chip' : '' ?>">
                        <i class="fas fa-user" style="font-size: 0.7rem;"></i>
                        <?= e($su['changed_by']) ?>
                        <span class="chip-count"><?= number_format($su['cnt']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Фильтры -->
        <div class="filters-card">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Дата от</label>
                    <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Дата до</label>
                    <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Сотрудник</label>
                    <select name="user" class="form-select">
                        <option value="">Все</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= e($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Тип действия</label>
                    <select name="field" class="form-select">
                        <option value="">Все поля</option>
                        <?php foreach ($allFields as $f): ?>
                            <option value="<?= e($f) ?>" <?= $filterField === $f ? 'selected' : '' ?>><?= e(fieldLabel($f)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 col-sm-6">
                    <label class="form-label">ID акк.</label>
                    <input type="number" name="account_id" class="form-control"
                           placeholder="#" value="<?= $filterAccountId > 0 ? $filterAccountId : '' ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Поиск</label>
                    <input type="text" name="search" class="form-control"
                           placeholder="Поиск в значениях..." value="<?= e($filterSearch) ?>">
                </div>
                <div class="col-md-1 col-sm-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                    <a href="admin_logs.php" class="btn btn-outline-secondary" title="Сбросить фильтры">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Таблица логов -->
        <div class="logs-card">
            <div class="card-header-custom">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <strong>Записи</strong>
                        <span class="text-muted ms-2" style="font-size: 0.85rem;">
                            <?= number_format(min(($page - 1) * $perPage + 1, $totalCount)) ?>–<?= number_format(min($page * $perPage, $totalCount)) ?>
                            из <?= number_format($totalCount) ?>
                        </span>
                    </div>
                    <span class="auto-refresh-indicator inactive" id="autoRefreshStatus">
                        <span class="pulse-dot" style="display:none" id="pulseDot"></span>
                        <span id="autoRefreshText">Авто: выкл</span>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <select onchange="location.href=this.value" class="form-select form-select-sm" style="width: auto;">
                        <?php foreach ([20, 50, 100, 200] as $pp): ?>
                            <option value="<?= e(buildUrl(['per_page' => $pp, 'page' => 1])) ?>"
                                    <?= $perPage === $pp ? 'selected' : '' ?>>
                                <?= $pp ?> / стр
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary" title="Обновить">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h5>Записи не найдены</h5>
                    <p>Попробуйте изменить фильтры или расширить диапазон дат</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th style="width: 55px;">#</th>
                                <th>Дата / Время</th>
                                <th>Сотрудник</th>
                                <th>Аккаунт</th>
                                <th>Действие</th>
                                <th>Было</th>
                                <th>Стало</th>
                                <th>IP</th>
                                <th style="width: 120px;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $idx => $log): ?>
                                <tr onclick="showDetail(<?= $idx ?>)" data-log-idx="<?= $idx ?>">
                                    <td class="text-muted" style="font-size: 0.78rem;"><?= e($log['id']) ?></td>
                                    <td style="white-space: nowrap;">
                                        <div style="font-weight: 500;"><?= date('d.m.Y', strtotime($log['changed_at'])) ?></div>
                                        <div style="font-size: 0.78rem; color: #94a3b8;"><?= date('H:i:s', strtotime($log['changed_at'])) ?></div>
                                    </td>
                                    <td>
                                        <a href="<?= e(buildUrl(['user' => $log['changed_by'], 'page' => 1])) ?>"
                                           class="user-badge text-decoration-none" onclick="event.stopPropagation()">
                                            <span class="user-dot"></span>
                                            <?= e($log['changed_by']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="view.php?id=<?= (int)$log['account_id'] ?>"
                                           class="account-link" onclick="event.stopPropagation()" title="Открыть аккаунт #<?= (int)$log['account_id'] ?>">
                                            #<?= e($log['account_id']) ?>
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="field-badge" style="background: <?= e(actionColor($log['field_name'])) ?>">
                                            <i class="fas <?= e(fieldIcon($log['field_name'])) ?>"></i>
                                            <?= e(fieldLabel($log['field_name'])) ?>
                                        </span>
                                    </td>
                                    <td class="value-cell">
                                        <?php if (!empty($log['old_value'])): ?>
                                            <span class="value-old value-tooltip" title="<?= e($log['old_value']) ?>">
                                                <?= e(mb_strimwidth($log['old_value'], 0, 40, '...')) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="value-cell">
                                        <?php if (!empty($log['new_value'])): ?>
                                            <span class="value-new value-tooltip" title="<?= e($log['new_value']) ?>">
                                                <?= e(mb_strimwidth($log['new_value'], 0, 40, '...')) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['ip_address'])): ?>
                                            <span class="ip-badge"><?= e($log['ip_address']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?= (int)$log['account_id'] ?>"
                                               class="action-btn view-btn" title="Открыть аккаунт">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="history.php?id=<?= (int)$log['account_id'] ?>"
                                               class="action-btn history-btn" title="История аккаунта">
                                                <i class="fas fa-history"></i>
                                            </a>
                                            <a href="<?= e(buildUrl(['account_id' => $log['account_id'], 'page' => 1])) ?>"
                                               class="action-btn filter-btn" title="Все действия с этим аккаунтом">
                                                <i class="fas fa-filter"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Пагинация -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="text-muted" style="font-size: 0.85rem;">
                        Страница <?= $page ?> из <?= $totalPages ?>
                    </div>
                    <nav>
                        <ul class="pagination mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(buildUrl(['page' => 1])) ?>"><i class="fas fa-angle-double-left"></i></a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(buildUrl(['page' => $page - 1])) ?>"><i class="fas fa-angle-left"></i></a>
                                </li>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 3);
                            $endPage = min($totalPages, $page + 3);
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(buildUrl(['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(buildUrl(['page' => $page + 1])) ?>"><i class="fas fa-angle-right"></i></a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(buildUrl(['page' => $totalPages])) ?>"><i class="fas fa-angle-double-right"></i></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Статистика по типам действий -->
        <?php if (!empty($statsFields)): ?>
        <div class="filters-card mt-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <strong style="font-size: 0.85rem; color: #475569;">
                    <i class="fas fa-chart-bar me-1"></i> Статистика по типам изменений
                </strong>
                <?php if (!empty($filterField)): ?>
                    <a href="<?= e(buildUrl(['field' => '', 'page' => 1])) ?>" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-times me-1"></i> Сбросить
                    </a>
                <?php endif; ?>
            </div>
            <div class="mt-2">
                <?php
                $maxFieldCount = max(array_column($statsFields, 'cnt'));
                foreach ($statsFields as $sf):
                    $percent = $maxFieldCount > 0 ? ($sf['cnt'] / $maxFieldCount * 100) : 0;
                    $isActive = $filterField === $sf['field_name'];
                ?>
                <a href="<?= e(buildUrl(['field' => $sf['field_name'], 'page' => 1])) ?>"
                   class="d-flex align-items-center gap-3 mb-2 text-decoration-none"
                   style="<?= $isActive ? 'opacity: 1; background: #eff6ff; padding: 4px 8px; border-radius: 8px;' : 'opacity: 0.9;' ?>">
                    <div style="width: 140px;">
                        <span class="field-badge" style="background: <?= e(actionColor($sf['field_name'])) ?>; font-size: 0.75rem;">
                            <i class="fas <?= e(fieldIcon($sf['field_name'])) ?>"></i>
                            <?= e(fieldLabel($sf['field_name'])) ?>
                        </span>
                    </div>
                    <div class="flex-grow-1">
                        <div style="background: #e2e8f0; border-radius: 4px; height: 22px; overflow: hidden;">
                            <div style="background: <?= e(actionColor($sf['field_name'])) ?>; width: <?= $percent ?>%; height: 100%; border-radius: 4px; transition: width 0.3s;"></div>
                        </div>
                    </div>
                    <div style="width: 60px; text-align: right; font-weight: 600; font-size: 0.85rem; color: #1e293b;">
                        <?= number_format($sf['cnt']) ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Модальное окно деталей записи -->
    <div class="modal fade detail-modal" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2 text-primary"></i>
                        <span id="modalTitle">Детали записи</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody"></div>
                <div class="modal-footer" style="background: #f8fafc; border-top: 1px solid #e2e8f0;">
                    <div id="modalActions" class="d-flex gap-2"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Данные логов для модального окна
        const logsData = <?= json_encode(array_values($logs), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

        const fieldLabels = <?= json_encode([
            'status' => 'Статус', 'email' => 'Email', 'email_password' => 'Пароль почты',
            'password' => 'Пароль', 'login' => 'Логин', 'cookies' => 'Cookies',
            'first_cookie' => 'First Cookie', 'token' => 'Токен', 'two_fa' => '2FA',
            'notes' => 'Заметки', 'description' => 'Описание', 'proxy' => 'Прокси',
            'user_agent' => 'User Agent', 'id_soc_account' => 'ID соц. аккаунта', 'api_key' => 'API ключ',
            'name' => 'Имя', 'phone' => 'Телефон',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        function esc(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function getFieldLabel(field) {
            return fieldLabels[field.toLowerCase()] || field;
        }

        function formatDate(dateStr) {
            const d = new Date(dateStr);
            return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU');
        }

        // Модальное окно деталей
        function showDetail(idx) {
            const log = logsData[idx];
            if (!log) return;

            document.getElementById('modalTitle').textContent =
                'Запись #' + log.id + ' — Аккаунт #' + log.account_id;

            let body = '';

            body += '<div class="detail-row"><div class="detail-label">ID записи</div><div class="detail-value">' + esc(String(log.id)) + '</div></div>';
            body += '<div class="detail-row"><div class="detail-label">Дата/Время</div><div class="detail-value">' + formatDate(log.changed_at) + '</div></div>';
            body += '<div class="detail-row"><div class="detail-label">Сотрудник</div><div class="detail-value"><strong>' + esc(log.changed_by) + '</strong></div></div>';
            body += '<div class="detail-row"><div class="detail-label">Аккаунт</div><div class="detail-value"><a href="view.php?id=' + log.account_id + '" class="fw-bold">#' + esc(String(log.account_id)) + ' — открыть аккаунт</a></div></div>';
            body += '<div class="detail-row"><div class="detail-label">Поле</div><div class="detail-value"><strong>' + esc(getFieldLabel(log.field_name)) + '</strong> <small class="text-muted">(' + esc(log.field_name) + ')</small></div></div>';

            if (log.ip_address) {
                body += '<div class="detail-row"><div class="detail-label">IP-адрес</div><div class="detail-value"><code>' + esc(log.ip_address) + '</code></div></div>';
            }

            body += '<hr style="margin: 16px 0; border-color: #e2e8f0;">';
            body += '<div class="row g-3">';

            body += '<div class="col-md-6">';
            body += '<div class="mb-2" style="font-size: 0.82rem; font-weight: 600; color: #991b1b;"><i class="fas fa-arrow-left me-1"></i> Было:</div>';
            body += '<div class="value-block old">' + (log.old_value ? esc(log.old_value) : '<span style="color: #94a3b8;">(пусто)</span>') + '</div>';
            body += '</div>';

            body += '<div class="col-md-6">';
            body += '<div class="mb-2" style="font-size: 0.82rem; font-weight: 600; color: #166534;"><i class="fas fa-arrow-right me-1"></i> Стало:</div>';
            body += '<div class="value-block new">' + (log.new_value ? esc(log.new_value) : '<span style="color: #94a3b8;">(пусто)</span>') + '</div>';
            body += '</div>';

            body += '</div>';

            document.getElementById('modalBody').innerHTML = body;

            // Кнопки действий
            let actions = '';
            actions += '<a href="view.php?id=' + log.account_id + '" class="btn btn-primary btn-sm"><i class="fas fa-eye me-1"></i> Открыть аккаунт</a>';
            actions += '<a href="history.php?id=' + log.account_id + '" class="btn btn-outline-secondary btn-sm"><i class="fas fa-history me-1"></i> Вся история аккаунта</a>';
            actions += '<a href="' + buildFilterUrl(log.account_id) + '" class="btn btn-outline-warning btn-sm"><i class="fas fa-filter me-1"></i> Все действия с этим аккаунтом</a>';
            actions += '<button type="button" class="btn btn-outline-secondary btn-sm ms-auto" data-bs-dismiss="modal">Закрыть</button>';

            document.getElementById('modalActions').innerHTML = actions;

            new bootstrap.Modal(document.getElementById('detailModal')).show();
        }

        function buildFilterUrl(accountId) {
            const url = new URL(window.location.href);
            url.searchParams.set('account_id', accountId);
            url.searchParams.set('page', '1');
            return url.toString();
        }

        // Тултипы
        document.querySelectorAll('.value-tooltip').forEach(el => {
            new bootstrap.Tooltip(el, { placement: 'top', trigger: 'hover' });
        });

        // Автообновление
        let autoRefreshInterval = null;
        function toggleAutoRefresh() {
            const btn = document.getElementById('autoRefreshBtn');
            const status = document.getElementById('autoRefreshStatus');
            const pulse = document.getElementById('pulseDot');
            const text = document.getElementById('autoRefreshText');

            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                btn.classList.remove('active');
                btn.style.background = '';
                btn.style.borderColor = '';
                status.className = 'auto-refresh-indicator inactive';
                pulse.style.display = 'none';
                text.textContent = 'Авто: выкл';
            } else {
                autoRefreshInterval = setInterval(() => location.reload(), 30000);
                btn.style.background = 'rgba(34,197,94,0.3)';
                btn.style.borderColor = '#22c55e';
                status.className = 'auto-refresh-indicator active';
                pulse.style.display = 'block';
                text.textContent = 'Обновление каждые 30 сек';
            }
        }

        // Горячие клавиши
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;

            const currentPage = <?= $page ?>;
            const totalPages = <?= $totalPages ?>;

            if (e.key === 'ArrowLeft' && currentPage > 1) {
                location.href = '<?= buildUrl(['page' => max(1, $page - 1)]) ?>';
            }
            if (e.key === 'ArrowRight' && currentPage < totalPages) {
                location.href = '<?= buildUrl(['page' => min($totalPages, $page + 1)]) ?>';
            }
            if (e.key === 'r' && !e.ctrlKey && !e.metaKey) {
                location.reload();
            }
        });
    </script>
</body>
</html>
