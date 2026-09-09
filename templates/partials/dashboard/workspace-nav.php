<?php
$workspacePage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$workspaceTableQuery = !empty($currentTable) && $currentTable !== 'accounts' ? '?table=' . urlencode($currentTable) : '';
?>
<nav class="workspace-nav" aria-label="Разделы панели">
  <?php foreach (['index.php' => 'Аккаунты', 'favorites.php' => 'Избранное', 'trash.php' => 'Корзина'] as $workspacePath => $workspaceLabel): ?>
  <a href="<?= $workspacePath . $workspaceTableQuery ?>"<?= $workspacePage === $workspacePath ? ' aria-current="page"' : '' ?>><?= $workspaceLabel ?></a>
  <?php endforeach; ?>
</nav>
