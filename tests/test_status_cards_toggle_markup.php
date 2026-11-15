<?php
/**
 * Тест: кнопка «Свернуть статусы» над карточками статусов.
 *
 * Запуск:  php tests/test_status_cards_toggle_markup.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем (15.11.2026). На рабочей панели 223 карточки статусов, видимых 75, и
 * сетка из них — 1207px, то есть весь первый экран на мониторе 1720×1213.
 * До фильтров и таблицы приходилось листать. Владелец попросил кнопку, которая
 * сворачивает карточки статусов и запоминает это.
 *
 * Тест стережёт то, что легко сломать незаметно:
 *   1) свёрнутое состояние ставится ДО отрисовки (скрипт в <head>), иначе при
 *      каждой загрузке экран карточек мелькал бы и схлопывался, а фильтры и
 *      таблица прыгали вверх на тысячу пикселей;
 *   2) ключ в <head> тот же, что в constants.js, — разъедутся, и кнопка
 *      перестанет «помнить», причём молча;
 *   3) прячутся только карточки статусов: «Всего аккаунтов», «Пустые статусы»
 *      (в ней кнопка «Управление») и свои карточки пользователя остаются;
 *   4) надпись на кнопке до запуска скриптов тоже совпадает с состоянием —
 *      две надписи переключаются тем же классом из <head>, а не скриптом.
 *
 * Проверка по тексту файлов: инструмента для имитации страницы в проекте нет.
 * Живое поведение проверено в браузере на стенде.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = dirname(__DIR__);
$failures = 0;
$passed = 0;

function sctCheck($ok, $label)
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failures++;
        echo "  ✗ {$label}\n";
    }
}

$tpl = file_get_contents($ROOT . '/templates/dashboard.php');
$constants = file_get_contents($ROOT . '/assets/js/modules/constants.js');
$cssAll = file_get_contents($ROOT . '/assets/css/core-components.css');
$bundles = file_get_contents($ROOT . '/includes/AssetBundles.php');
$main = file_get_contents($ROOT . '/assets/js/modules/dashboard-main.js');

echo "Состояние ставится до отрисовки:\n";
$headEnd = strpos($tpl, '</head>');
$head = $headEnd === false ? '' : substr($tpl, 0, $headEnd);
$key = null;
if (preg_match("~LS_KEY_STATUS_CARDS_COLLAPSED\s*=\s*'([^']+)'~", $constants, $m)) {
    $key = $m[1];
}
sctCheck($key !== null, 'ключ объявлен в constants.js');
sctCheck($key !== null && strpos($head, "'" . $key . "'") !== false,
    'скрипт в <head> читает тот же ключ');
sctCheck(preg_match("~classList\.add\(\s*'status-cards-collapsed'\s*\)~", $head) === 1,
    'скрипт в <head> ставит класс status-cards-collapsed на <html>');

echo "Кнопка:\n";
sctCheck(preg_match('~<button[^>]*id="statusCardsToggle"[^>]*>~', $tpl, $btn) === 1, 'кнопка есть в шаблоне');
$btnTag = isset($btn[0]) ? $btn[0] : '';
sctCheck(strpos($btnTag, 'type="button"') !== false, 'это type="button" (не отправляет форм)');
sctCheck(strpos($btnTag, 'aria-controls="statsRow"') !== false, 'aria-controls указывает на сетку карточек');
sctCheck(strpos($btnTag, 'aria-expanded=') !== false, 'есть aria-expanded');
sctCheck(strpos($tpl, 'status-cards-toggle-open') !== false && strpos($tpl, 'status-cards-toggle-closed') !== false,
    'обе надписи («Свернуть» и «Показать») лежат в разметке');

echo "Прячутся только карточки статусов:\n";
sctCheck(preg_match('~\.status-cards-collapsed\s+#statsRow\s+\.stat-card\[data-card\^="status:"\]\s*\{[^}]*display:\s*none~', $cssAll) === 1,
    'правило прячет .stat-card[data-card^="status:"]');
sctCheck(preg_match('~\.status-cards-collapsed[^{]*\[data-card="(total|empty_status)"\][^{]*\{[^}]*display:\s*none~', $cssAll) === 0
    && preg_match('~\.status-cards-collapsed[^{]*\[data-card\^="custom:"\][^{]*\{[^}]*display:\s*none~', $cssAll) === 0,
    '«Всего», «Пустые статусы» и свои карточки не прячутся');
sctCheck(preg_match('~\.status-cards-collapsed\s+\.status-cards-toggle-open\s*\{[^}]*display:\s*none~', $cssAll) === 1
    && preg_match('~:root:not\(\.status-cards-collapsed\)\s+\.status-cards-toggle-closed\s*\{[^}]*display:\s*none~', $cssAll) === 1,
    'надписи переключает класс из <head>, а не скрипт');

echo "Подключение:\n";
$posModule = strpos($bundles, "'assets/js/modules/status-cards-toggle.js'");
$posMain = strpos($bundles, "'assets/js/modules/dashboard-main.js'");
sctCheck($posModule !== false && $posMain !== false && $posModule < $posMain,
    'модуль в бандле и стоит до dashboard-main.js, который его запускает');
sctCheck(strpos($main, 'DashboardStatusCards.init()') !== false, 'dashboard-main.js запускает модуль');

echo "\n{$passed} прошло, {$failures} упало\n";
exit($failures > 0 ? 1 : 0);
