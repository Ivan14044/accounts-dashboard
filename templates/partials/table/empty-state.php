<?php
/**
 * Строка «Ничего не найдено» — тело таблицы, когда выборка пустая.
 * Включается из partials/table/rows.php.
 *
 * colspan = число колонок данных + 3 служебные (checkbox, favorite, actions).
 * Порядок и состав служебных колонок задан в colgroup — см. partials/table/columns.php.
 * Партиал самодостаточен: $ALL_COLUMNS может быть не определён (прямой include, тест).
 */
$emptyStateColspan = (isset($ALL_COLUMNS) && is_array($ALL_COLUMNS) ? count($ALL_COLUMNS) : 0) + 3;
?>
<tr class="ac-row ac-row--empty">
  <td colspan="<?= $emptyStateColspan ?>" class="text-center text-muted py-5">
    <i class="fas fa-search fa-2x mb-3 text-muted"></i>
    <div>Ничего не найдено</div>
  </td>
</tr>
