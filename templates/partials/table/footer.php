<?php
/**
 * Футер таблицы с пагинацией и summary
 * $page      — текущая страница (скорректированная сервером, не сырой URL-параметр)
 * $pages     — всего страниц
 * $prev      — номер предыдущей страницы
 * $next      — номер следующей страницы
 * $startPage, $endPage, $pageNumbers — окно кнопок
 * $filteredTotal, $rows, $perPage — данные таблицы
 */

// Формируем базовый набор query-параметров (без page) для href ссылок
$__qs = $_GET;
unset($__qs['page']);
$__qs = $__qs ?: [];

// Хелпер: строит href для конкретной страницы (function_exists — защита от повторного include)
if (!function_exists('pgHref')) {
    function pgHref(array $qs, int $p): string {
        return '?' . http_build_query(array_merge($qs, ['page' => $p]));
    }
}

// Допустимый набор значений per_page (на случай если контроллер не пробросил)
$__allowedPerPage = isset($allowedPerPage) && is_array($allowedPerPage) && $allowedPerPage
    ? $allowedPerPage
    : [25, 50, 100, 200];

// Базовые GET-параметры без page и per_page (для построения URL смены per_page)
$__ppQs = $_GET;
unset($__ppQs['page'], $__ppQs['per_page']);
$__ppQs = $__ppQs ?: [];
?>
<footer class="dashboard-table__footer">

  <div class="dashboard-table__footer-info text-muted small">
    Найдено: <span id="foundTotal"><?= number_format((int)$filteredTotal) ?></span>
    <?php if ((int)$pages > 1): ?>
      • Стр. <span id="pageNum"><?= (int)$page ?></span>
      из <span id="pagesCount"><?= (int)$pages ?></span>
    <?php endif; ?>
    <?php
      // Показываем "Показывается: N" только если оно != Найдено
      // (на 1 странице с pages=1 они равны → дублирование).
      $showingCount = count($rows);
      $showShowing  = (int)$pages > 1 || $showingCount !== (int)$filteredTotal;
    ?>
    <?php if ($showShowing): ?>
      • Показывается: <span id="showingCount"><?= $showingCount ?></span>
    <?php else: ?>
      <span id="showingCount" class="d-none"><?= $showingCount ?></span>
    <?php endif; ?>
    <span id="virtualizationHint" class="ms-2 d-none">
      <i class="fas fa-info-circle text-info" title="Виртуализация активна"></i>
      <span id="virtualizationStats">Видно <span id="visibleRowsCount">0</span> из <span id="totalRowsOnPage">0</span> строк</span>
    </span>
  </div>

  <!-- Все controls в одной правой группе: per-page → page jump → pagination buttons.
       Объединение нужно, чтобы footer был flex space-between на двух блоках
       (info ↔ controls) — без бесхозного per-page в центре. -->
  <div class="dashboard-table__footer-controls">

    <!-- Per-page селектор (URL-based: смена сбрасывает page → 1) -->
    <div class="dashboard-table__per-page" data-base-qs="<?= e(http_build_query($__ppQs)) ?>">
      <label class="form-label mb-0 small text-muted" for="perPageSelect" title="Строк на странице">
        <i class="fas fa-list-ol me-1" aria-hidden="true"></i>Строк:
      </label>
      <select class="form-select form-select-sm" id="perPageSelect" aria-label="Записей на странице">
        <?php foreach ($__allowedPerPage as $pp): ?>
          <option value="<?= (int)$pp ?>" <?= (int)$perPage === (int)$pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ((int)$pages > 1): ?>
    <span class="dashboard-table__footer-divider" aria-hidden="true"></span>

    <div class="dashboard-table__footer-nav">

    <!-- Поле быстрого перехода — только когда есть куда переходить -->
    <div class="dashboard-table__footer-select d-flex align-items-center gap-2">
      <label class="form-label mb-0 small" for="pageJumpInput" title="Перейти на страницу">Стр.:</label>
      <input
        type="number"
        class="form-control form-control-sm dashboard-table__footer-page-input"
        id="pageJumpInput"
        min="1"
        max="<?= (int)$pages ?>"
        value="<?= (int)$page ?>"
        placeholder="№"
        aria-label="Номер страницы"
      >
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        id="pageJumpBtn"
        aria-label="Перейти на введённую страницу"
      >Перейти</button>
    </div>

    <!-- Кнопки пагинации -->
    <nav aria-label="Навигация по страницам" class="dashboard-table__pagination" id="paginationNav">
      <ul class="pagination m-0">

        <!-- Первая страница -->
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link"
             href="<?= pgHref($__qs, 1) ?>"
             data-page="1"
             aria-label="Первая">
            <i class="fas fa-angle-double-left"></i>
          </a>
        </li>

        <!-- Предыдущая страница -->
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link"
             href="<?= pgHref($__qs, (int)$prev) ?>"
             data-page="<?= (int)$prev ?>"
             aria-label="Предыдущая">
            <i class="fas fa-angle-left"></i>
          </a>
        </li>

        <!-- Если окно не начинается с 1 — показываем «1 …» -->
        <?php if ($startPage > 1): ?>
          <li class="page-item">
            <a class="page-link" href="<?= pgHref($__qs, 1) ?>" data-page="1">1</a>
          </li>
          <?php if ($startPage > 2): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Окно номеров страниц -->
        <?php foreach ($pageNumbers as $pnum): ?>
          <?php if ((int)$pnum === (int)$page): ?>
            <li class="page-item active" aria-current="page">
              <span class="page-link"><?= (int)$pnum ?></span>
            </li>
          <?php else: ?>
            <li class="page-item">
              <a class="page-link"
                 href="<?= pgHref($__qs, (int)$pnum) ?>"
                 data-page="<?= (int)$pnum ?>"><?= (int)$pnum ?></a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>

        <!-- Если окно не заканчивается последней — показываем «… N» -->
        <?php if ($endPage < $pages): ?>
          <?php if ($endPage < $pages - 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
          <li class="page-item">
            <a class="page-link"
               href="<?= pgHref($__qs, (int)$pages) ?>"
               data-page="<?= (int)$pages ?>"><?= (int)$pages ?></a>
          </li>
        <?php endif; ?>

        <!-- Следующая страница -->
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
          <a class="page-link"
             href="<?= pgHref($__qs, (int)$next) ?>"
             data-page="<?= (int)$next ?>"
             aria-label="Следующая">
            <i class="fas fa-angle-right"></i>
          </a>
        </li>

        <!-- Последняя страница -->
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
          <a class="page-link"
             href="<?= pgHref($__qs, (int)$pages) ?>"
             data-page="<?= (int)$pages ?>"
             aria-label="Последняя">
            <i class="fas fa-angle-double-right"></i>
          </a>
        </li>

      </ul>
    </nav>

    </div>
    <?php endif; ?>

  </div>
</footer>
