<?php
/**
 * Футер таблицы с пагинацией и summary
 */
$__basePath = '';
$__commonQs = $_GET;
unset($__commonQs['page']);
$__commonQs = $__commonQs ?: [];
?>
<footer class="dashboard-table__footer">
  <div class="dashboard-table__footer-info text-muted small">
    Найдено: <span id="foundTotal"><?= number_format($filteredTotal) ?></span>
    • Стр. <span id="pageNum"><?= (int)max(1, (int)get_param('page', 1)) ?></span>
    из <span id="pagesCount"><?= (int)$pages ?></span>
    • Показывается: <span id="showingCount"><?= count($rows) ?></span>
    <span id="virtualizationHint" class="ms-2 d-none">
      <i class="fas fa-info-circle text-info" title="Виртуализация активна"></i>
      <span id="virtualizationStats">Видно <span id="visibleRowsCount">0</span> из <span id="totalRowsOnPage">0</span> строк</span>
    </span>
  </div>
  <div class="dashboard-table__footer-nav">
    <div class="dashboard-table__footer-select d-flex align-items-center gap-2">
      <label class="form-label mb-0 small" for="pageJumpInput">Перейти на стр.:</label>
      <input type="number" class="form-control form-control-sm dashboard-table__footer-page-input" id="pageJumpInput" min="1" max="<?= (int)$pages ?>" value="<?= (int)$page ?>" placeholder="№" aria-label="Номер страницы">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="pageJumpBtn" aria-label="Перейти на введённую страницу">Перейти</button>
    </div>
    <?php if ($pages > 1): ?>
    <nav aria-label="Навигация по страницам" class="dashboard-table__pagination">
      <ul class="pagination m-0">
        <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $__basePath . '?' . http_build_query(array_merge($__commonQs, ['page'=>1])) ?>" aria-label="Первая">
            <i class="fas fa-angle-double-left"></i>
          </a>
        </li>
        <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $__basePath . '?' . http_build_query(array_merge($__commonQs, ['page'=>(int)$prev])) ?>" aria-label="Предыдущая">
            <i class="fas fa-angle-left"></i>
          </a>
        </li>
        <?php if ($startPage > 1): ?>
          <li class="page-item">
            <a class="page-link" href="<?= $__basePath . '?' . http_build_query(array_merge($__commonQs, ['page'=>1])) ?>">1</a>
          </li>
          <?php if ($startPage > 2): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($pageNumbers as $pnum): ?>
          <?php if ($pnum == $page): ?>
            <li class="page-item active" aria-current="page">
              <span class="page-link"><?= (int)$pnum ?></span>
            </li>
          <?php else: ?>
            <li class="page-item">
              <a class="page-link" href="<?= $__basePath . '?' . http_build_query(array_merge($__commonQs, ['page'=>(int)$pnum])) ?>"><?= (int)$pnum ?></a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($endPage < $pages): ?>
          <?php if ($endPage < $pages - 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
          <li class="page-item">
            <a class="page-link" href="<?= $__basePath . '?' . http_build_query(array_merge($__commonQs, ['page'=>(int)$pages])) ?>"><?= (int)$pages ?></a>
          </li>
        <?php endif; ?>
        <li class="page-item <?= $page == $pages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $__basePath . '?' . http_build_query(array_merge($__commonQs, ['page'=>(int)$next])) ?>" aria-label="Следующая">
            <i class="fas fa-angle-right"></i>
          </a>
        </li>
        <li class="page-item <?= $page == $pages ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $__basePath . '?' . http_build_query(array_merge($__commonQs, ['page'=>(int)$pages])) ?>" aria-label="Последняя">
            <i class="fas fa-angle-double-right"></i>
          </a>
        </li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
</footer>
