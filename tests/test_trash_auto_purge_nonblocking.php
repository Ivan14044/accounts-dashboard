<?php
/**
 * Тест: автоочистка корзины не держит открытие страницы.
 *
 * Запуск:  php tests/test_trash_auto_purge_nonblocking.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем этот тест существует (найдено 2026-09-15).
 *
 * Раньше автоочистка запускалась внутри запроса самой страницы корзины через
 * register_shutdown_function(). Комментарий обещал «после отрисовки, не
 * блокируя пользователя», но это правда только там, где есть
 * fastcgi_finish_request(). Без него ответ не считается законченным, пока не
 * отработают shutdown-функции, а nginx хостинга и Cloudflare ещё и держат
 * буфер до конца ответа — браузер крутит загрузку и не переходит на страницу.
 *
 * Доказательство: на проде 15.09.2026 в 12:19 владелец нажал «Корзина», страница
 * открывалась очень долго, и ровно в этот момент автоочистка удалила 2 606
 * записей. Минутой позже та же страница — 0,44 с. На стенде (200 000 строк,
 * 2 666 под очистку): TTFB 0,15 с, но конец ответа 1,70 с; без очистки 0,04 с.
 *
 * Что теперь: страница только сообщает «пора чистить» (autoPurgeDue), а саму
 * очистку отдельным фоновым запросом запускает trash.js через purge_old.php
 * с флагом auto. Сервер сам перепроверяет, что пора, и сначала «занимает»
 * прогон, чтобы две вкладки не чистили одновременно.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$ROOT = dirname(__DIR__);
$failures = 0;
$passed   = 0;

/**
 * Фиксирует результат проверки.
 *
 * @param string $name Что проверяли
 * @param bool $ok Прошло ли
 * @param string $detail Подробности при провале
 * @return void
 */
function tapCheck($name, $ok, $detail = '')
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        echo "  [OK]   $name\n";
    } else {
        $failures++;
        echo "  [FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Исходник PHP без комментариев — чтобы слово в докблоке не засчитывалось.
 *
 * @param string $file Путь к файлу
 * @return string
 */
function tapCode($file)
{
    $src = '';
    foreach (token_get_all(file_get_contents($file)) as $tok) {
        if (is_array($tok)) {
            $src .= ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) ? "\n" : $tok[1];
            continue;
        }
        $src .= $tok;
    }
    return $src;
}

echo "\n=== Корзина: автоочистка не держит открытие страницы ===\n\n";

// ── 1. Страница корзины сама ничего не удаляет ──
$page = tapCode($ROOT . '/trash.php');
tapCheck(
    'trash.php не запускает очистку в shutdown',
    strpos($page, 'register_shutdown_function') === false,
    'shutdown-функция держит ответ открытым, пока идёт удаление'
);
tapCheck(
    'trash.php не вызывает purgeTrashOlderThan()',
    strpos($page, 'purgeTrashOlderThan') === false,
    'удаление внутри запроса страницы = долгий переход в корзину'
);
tapCheck(
    'trash.php вычисляет флаг autoPurgeDue',
    strpos($page, 'autoPurgeDue') !== false
);

// ── 2. Флаг доезжает до браузера в обоих конфигах ──
$tpl = file_get_contents($ROOT . '/templates/trash.php');
tapCheck(
    'шаблон отдаёт autoPurgeDue в window.TrashConfig и в data-list-config',
    substr_count($tpl, 'autoPurgeDue') >= 2,
    'конфиг заменяется при обновлении списка без перезагрузки — нужен в обоих'
);

// ── 3. Браузер запускает очистку фоном ──
$js = file_get_contents($ROOT . '/assets/js/trash.js');
tapCheck(
    'trash.js смотрит на autoPurgeDue',
    strpos($js, 'autoPurgeDue') !== false
);
tapCheck(
    'trash.js шлёт в purge_old.php флаг auto',
    (bool)preg_match("/purge_old\\.php'\\)?,\\s*\\{\\s*auto\\s*:\\s*true/", $js),
    'без флага сервер выполнит ручную очистку, не отметив суточный прогон'
);

tapCheck(
    'сообщения корзины доходят до window.Toast',
    strpos($js, 'window.Toast') !== false,
    'showToast на странице корзины не определён — без запасного пути отчёт об очистке не виден'
);

// ── 4. Сервер перепроверяет и занимает прогон ──
$api = tapCode($ROOT . '/purge_old.php');
tapCheck(
    'purge_old.php в режиме auto занимает прогон через claimAutoPurge()',
    strpos($api, 'claimAutoPurge') !== false,
    'иначе очистку можно запустить сколько угодно раз и из двух вкладок сразу'
);
tapCheck(
    'purge_old.php в режиме auto сохраняет результат через markPurged()',
    strpos($api, 'markPurged') !== false,
    'без этого «прошлая автоочистка удалила N» перестанет показываться'
);

// ── 5. Правило «пора ли» — чистая функция, проверяем краевые случаи ──
require_once $ROOT . '/includes/TrashSettings.php';
tapCheck('TrashSettings::isPurgeDue() существует', method_exists('TrashSettings', 'isPurgeDue'));
if (method_exists('TrashSettings', 'isPurgeDue')) {
    $now = strtotime('2026-09-15 12:00:00');
    $base = ['enabled' => true, 'days' => 30, 'last_purge_deleted' => 0];
    tapCheck('выключено → не пора (даже без прогонов)',
        TrashSettings::isPurgeDue(array_merge($base, ['enabled' => false, 'last_purge_at' => null]), $now) === false);
    tapCheck('ни разу не чистили → пора',
        TrashSettings::isPurgeDue(array_merge($base, ['last_purge_at' => null]), $now) === true);
    tapCheck('битая дата → пора',
        TrashSettings::isPurgeDue(array_merge($base, ['last_purge_at' => 'мусор']), $now) === true);
    tapCheck('чистили час назад → не пора',
        TrashSettings::isPurgeDue(array_merge($base, ['last_purge_at' => '2026-09-15 11:00:00']), $now) === false);
    tapCheck('ровно сутки назад → пора',
        TrashSettings::isPurgeDue(array_merge($base, ['last_purge_at' => '2026-09-14 12:00:00']), $now) === true);
}

echo "\n──────────────────────────────────────────────────\n";
echo "Результат: $passed пройдено, $failures провалено\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failures > 0 ? 1 : 0);
