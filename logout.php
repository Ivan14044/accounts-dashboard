<?php
/**
 * logout.php — выход из системы
 */

require_once __DIR__ . '/auth.php';

// Не логаутим неавторизованных
if (!isAuthenticated()) {
    header('Location: login.php');
    exit;
}

// CSRF токен ТОЛЬКО из POST (GET уязвим к CSRF через ссылки/img)
$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($token)) {
    http_response_code(400);
    header('Location: login.php?message=csrf_error');
    exit;
}

// Выходим из системы
logout();

// Перенаправляем на страницу входа
header('Location: login.php?message=logout');
exit;
?>
