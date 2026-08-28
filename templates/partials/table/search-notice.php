<?php
/**
 * Плашка над таблицей: честно говорит, ЧТО показано — точные совпадения или
 * похожие строки.
 *
 * Зачем: поиск двухфазный. Если точного совпадения нет, панель молча
 * расширяла поиск до «подстрока где угодно» — и запрос `4054` выдавал 433
 * строки, где это число сидит внутри чужих длинных значений (ID соцсети,
 * ссылка на профиль, ID фан-страницы, куки, токен). Со стороны это
 * неотличимо от «нашлось 433 аккаунта с номером 4054» (случай 2026-08-28).
 *
 * Чего здесь осознанно нет: решения «показывать или нет» — оно в
 * {@see SearchNotice::build()} и покрыто тестом без БД. Партиал только рисует.
 *
 * Ожидает: $searchNotice — массив от SearchNotice::build() или null.
 * Партиал самодостаточен: при любом другом входе не рисует ничего.
 */

$notice = isset($searchNotice) && is_array($searchNotice) ? $searchNotice : null;
if ($notice === null) {
    return;
}

$noticeType  = isset($notice['type']) ? (string)$notice['type'] : '';
$noticeQuery = isset($notice['query']) ? (string)$notice['query'] : '';
$noticeUrl   = isset($notice['url']) ? (string)$notice['url'] : '?';

if ($noticeQuery === '') {
    return;
}

if ($noticeType === 'fallback') {
    $noticeClass  = 'alert-warning';
    $noticeIcon   = 'fa-exclamation-triangle';
    $noticeTitle  = 'точных совпадений нет.';
    $noticeText   = 'Показаны аккаунты, у которых это значение встречается внутри других данных: ID соцсети, ссылка на профиль, ID фан-страницы, куки, токен.';
    $noticeAction = 'Искать только точное совпадение';
    $noticeMode   = 'exact';
} elseif ($noticeType === 'exact_empty') {
    $noticeClass  = 'alert-secondary';
    $noticeIcon   = 'fa-info-circle';
    $noticeTitle  = 'точных совпадений нет.';
    $noticeText   = 'Аккаунта с таким значением в поле ID, в логине или в ID соцсети не нашлось.';
    $noticeAction = 'Показать похожие';
    $noticeMode   = 'like';
} elseif ($noticeType === 'exact_active') {
    $noticeClass  = 'alert-secondary';
    $noticeIcon   = 'fa-info-circle';
    $noticeTitle  = 'показаны только точные совпадения.';
    $noticeText   = 'Похожие строки скрыты.';
    $noticeAction = 'Показать похожие';
    $noticeMode   = 'like';
} else {
    return;
}
?>
<div class="alert <?= e($noticeClass) ?> dashboard-table__search-notice d-flex flex-wrap align-items-center gap-2 mb-0 py-2 px-3" role="status" aria-live="polite">
  <i class="fas <?= e($noticeIcon) ?>" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <strong>По запросу «<?= e($noticeQuery) ?>» <?= e($noticeTitle) ?></strong>
    <span><?= e($noticeText) ?></span>
  </div>
  <a href="<?= e($noticeUrl) ?>" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-search-mode="<?= e($noticeMode) ?>"><?= e($noticeAction) ?></a>
</div>
