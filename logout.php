<?php
/**
 * logout.php — выход из системы
 */

require_once __DIR__ . '/auth.php';

// Выходим из системы
logout();

// Перенаправляем на страницу входа
header('Location: login.php?message=logout');
exit;
?>
