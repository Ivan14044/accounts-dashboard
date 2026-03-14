<?php
/**
 * COLGROUP + THEAD для таблицы аккаунтов
 */
$activeSort = $sort ?? 'id';
$activeDir = $dir ?? 'asc';
?>
<colgroup>
  <col class="ac-col ac-col--checkbox" style="width: var(--col-checkbox);">
  <?php foreach ($ALL_COLUMNS as $key => $label): ?>
    <col class="ac-col ac-col--<?= e($key) ?>" data-column="<?= e($key) ?>">
    <?php if ($key === 'id'): ?>
      <!-- Колонка «Избранное» идёт сразу после ID, порядок совпадает с thead -->
      <col class="ac-col ac-col--favorite" style="width: var(--col-favorite);" data-column="favorite">
    <?php endif; ?>
  <?php endforeach; ?>
  <col class="ac-col ac-col--actions" style="width: var(--col-actions);">
</colgroup>
<thead>
  <tr>
    <th scope="col" class="ac-cell ac-cell--checkbox" data-column="checkbox">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="selectAll" aria-label="Выделить все на странице">
      </div>
    </th>
    <?php foreach ($ALL_COLUMNS as $key => $label): 
      $isActive = $activeSort === $key;
      $ariaSort = $isActive ? ($activeDir === 'asc' ? 'ascending' : 'descending') : 'none';
    ?>
      <th scope="col"
          class="ac-cell" 
          data-col="<?= e($key) ?>"
          data-column="<?= e($key) ?>"
          aria-sort="<?= $ariaSort ?>">
        <a href="<?= sort_link($key) ?>"
           class="ac-cell__sort"
           data-sort-link
           data-sort-column="<?= e($key) ?>">
          <span><?= e($label) ?></span>
          <span class="sort-indicator" aria-hidden="true">
            <?= dir_arrow($sort, $dir, $key) ?>
          </span>
        </a>
      </th>
      <?php if ($key === 'id'): ?>
        <th scope="col" class="ac-cell ac-cell--favorite" data-column="favorite" aria-label="Избранное">
          <i class="fas fa-star"></i>
        </th>
      <?php endif; ?>
    <?php endforeach; ?>
    <th scope="col" class="ac-cell ac-cell--actions text-end" data-column="actions">
      Действия
    </th>
  </tr>
</thead>
