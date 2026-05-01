<?php
/**
 * Конфигурация дашборда для JavaScript.
 * Должен быть включён до dashboard-init.js и других модулей.
 */
require_once __DIR__ . '/../../../includes/Config.php';
$cfg = [
  'csrfToken' => $csrfToken ?? '',
  'activeFiltersCount' => (int)($activeFiltersCount ?? 0),
  'sort' => $sort ?? '',
  'dir' => $dir ?? '',
  'allColumnKeys' => isset($ALL_COLUMNS) && is_array($ALL_COLUMNS) ? array_keys($ALL_COLUMNS) : [],
  'numericCols'   => isset($NUMERIC_COLS) && is_array($NUMERIC_COLS) ? $NUMERIC_COLS : [],
  'filteredTotal' => (int)($filteredTotal ?? 0),
  'currentTable' => $currentTable ?? 'accounts',
  // Поля, чьи значения в SELECT обрезаны до preview-лимита (см. AccountsRepository::getAccounts).
  // Клиентский renderCell использует их, чтобы при AJAX-обновлении таблицы НЕ копировать
  // обрезанный preview, а ставить data-truncated="1" и лениво грузить полное значение
  // через GET /api/accounts/field. Без этого копирование cookies/token после смены фильтра
  // или страницы возвращало обрезанные 256 байт вместо полного содержимого.
  'heavyFields' => Config::TABLE_HEAVY_FIELDS,
  'longFields'  => isset($LONG_FIELDS) && is_array($LONG_FIELDS)
    ? $LONG_FIELDS
    : ['cookies', 'first_cookie', 'token', 'user_agent', 'social_url'],
  'clipLen'   => isset($CLIP_LEN)   ? (int)$CLIP_LEN   : 80,
  'tokenClip' => isset($TOKEN_CLIP) ? (int)$TOKEN_CLIP : 20,
];
?>
<script>
window.__DASHBOARD_CONFIG__ = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
</script>
