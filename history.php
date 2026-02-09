<?php
/**
 * Страница просмотра истории изменений аккаунта (Audit Log)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/AuditLogger.php';

// Проверяем авторизацию
requireAuth();
checkSessionTimeout();

$accountId = (int)get_param('id', '0');
if ($accountId <= 0) {
    http_response_code(400);
    exit('Invalid account ID');
}

$service = new AccountsService();
$account = $service->getAccountById($accountId);

if (!$account) {
    http_response_code(404);
    exit('Account not found');
}

$auditLogger = AuditLogger::getInstance();
$history = $auditLogger->getAccountHistory($accountId, 200);

?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>История изменений #<?= $accountId ?> - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/unified-theme.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        .history-item {
            border-left: 3px solid #0d6efd;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
        .history-item.old-value {
            background: #fff3cd;
        }
        .history-item.new-value {
            background: #d1e7dd;
        }
        .field-name {
            font-weight: 600;
            color: #0d6efd;
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
                <a href="view.php?id=<?= $accountId ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-arrow-left me-1"></i>
                    Назад к аккаунту
                </a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">
                    <i class="fas fa-history me-2"></i>
                    История изменений аккаунта #<?= $accountId ?>
                </h4>
            </div>
            <div class="card-body">
                <?php if (empty($history)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-history fa-3x mb-3"></i>
                        <p>История изменений пуста</p>
                    </div>
                <?php else: ?>
                    <div class="history-list">
                        <?php foreach ($history as $item): ?>
                            <div class="history-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="field-name"><?= htmlspecialchars($item['field_name']) ?></span>
                                        <small class="text-muted ms-2">
                                            <?= htmlspecialchars($item['changed_by']) ?>
                                        </small>
                                    </div>
                                    <small class="text-muted">
                                        <?= date('d.m.Y H:i:s', strtotime($item['changed_at'])) ?>
                                    </small>
                                </div>
                                
                                <?php if (!empty($item['old_value'])): ?>
                                    <div class="old-value p-2 rounded mb-2">
                                        <small class="text-muted d-block mb-1">Было:</small>
                                        <code><?= htmlspecialchars($item['old_value']) ?></code>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($item['new_value'])): ?>
                                    <div class="new-value p-2 rounded">
                                        <small class="text-muted d-block mb-1">Стало:</small>
                                        <code><?= htmlspecialchars($item['new_value']) ?></code>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($item['ip_address'])): ?>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-network-wired me-1"></i>
                                        IP: <?= htmlspecialchars($item['ip_address']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

