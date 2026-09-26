<?php
$workspacePage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$workspaceTableQuery = !empty($currentTable) && $currentTable !== 'accounts' ? '?table=' . urlencode($currentTable) : '';
?>
<nav class="workspace-nav" aria-label="Разделы панели">
  <?php /* Значки видны только на телефоне, где эта навигация становится нижней
           панелью вкладок (assets/css/core-touch.css, раздел 5). На компьютере
           они скрыты, вид прежний. */ ?>
  <?php foreach (['index.php' => ['Аккаунты', 'fa-users'], 'favorites.php' => ['Избранное', 'fa-star'], 'trash.php' => ['Корзина', 'fa-trash-alt']] as $workspacePath => $workspaceItem): ?>
  <a href="<?= $workspacePath . $workspaceTableQuery ?>"<?= $workspacePage === $workspacePath ? ' aria-current="page"' : '' ?>><i class="fas <?= $workspaceItem[1] ?> workspace-nav__icon" aria-hidden="true"></i><span><?= $workspaceItem[0] ?></span></a>
  <?php endforeach; ?>
</nav>
