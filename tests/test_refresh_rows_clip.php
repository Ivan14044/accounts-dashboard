<?php
/**
 * Тест: обновление таблицы не тащит в браузер мегабайты кук.
 *
 * Запуск:  php tests/test_refresh_rows_clip.php
 * Код выхода: 0 — прошло, 1 — есть падения.
 *
 * Зачем (15.11.2026). Владелец: «делаю действие — и долго висит "Загрузка
 * данных"». Замер на рабочей панели: ответ обновления на 50 строк весил
 * 2,2 МБ (420 КБ сжатыми), из них 1,6 МБ — колонка full_cookies, ~33 КБ на
 * аккаунт; скачивание ~0,5 с на каждое обновление, на 100 строках ~1 с.
 * При этом таблица показывает из длинного значения только первые 80 знаков,
 * а полное значение для копирования/просмотра/правки и так умеет догружать
 * с сервера (field_value.php через CellValues в cell-actions.js) — так
 * работает первая загрузка страницы.
 *
 * Тест стережёт:
 *   1) длинное значение обрезается, а строка помечается списком _clipped —
 *      по нему браузер понимает, что полное значение надо брать с сервера
 *      (иначе копирование отдало бы обрезок, а правка сохранила бы обрезок);
 *   2) поля, которые таблица рисует ЦЕЛИКОМ (логин, почта, пароли, ссылка,
 *      статус), не обрезаются ни при какой длине;
 *   3) обрезка не рвёт русские буквы: битый UTF-8 валит json_encode, и вместо
 *      таблицы прилетела бы ошибка;
 *   4) короткие значения, числа и NULL проходят как есть.
 */

set_error_handler(static function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once dirname(__DIR__) . '/includes/RefreshRowsClipper.php';

$failures = 0;
$passed = 0;

function rrcCheck($ok, $label)
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

$max = RefreshRowsClipper::MAX_CHARS;
rrcCheck($max > 80, 'порог обрезки больше того, что таблица показывает (80 знаков)');

$cookies = str_repeat('c_user=1000; xs=abc; ', 2000); // ~42 КБ, как на рабочей панели
$rows = [
    [
        'id' => 7,
        'login' => 'user7',
        'full_cookies' => $cookies,
        'cookies' => 'short',
        'two_fa' => null,
        'friends' => 15,
        'email' => str_repeat('a', 300) . '@mail.test',
        'password' => str_repeat('p', 400),
        'email_password' => str_repeat('q', 400),
        'social_url' => 'https://www.facebook.com/profile.php?id=' . str_repeat('1', 300),
        'status' => str_repeat('s', 300),
        'extra_info_1' => str_repeat('Ж', $max + 50),
    ],
    ['id' => 8, 'login' => 'user8', 'full_cookies' => '', 'cookies' => 'x'],
];

$out = RefreshRowsClipper::clip($rows);

echo "Длинное значение:\n";
rrcCheck(mb_strlen($out[0]['full_cookies'], 'UTF-8') === $max, "обрезано до {$max} знаков");
rrcCheck(strpos($cookies, $out[0]['full_cookies']) === 0, 'обрезок — начало настоящего значения (таблица показывает то же, что раньше)');
rrcCheck(isset($out[0]['_clipped']) && in_array('full_cookies', $out[0]['_clipped'], true), 'строка помечена: full_cookies обрезано');

echo "Поля, которые таблица показывает целиком:\n";
foreach (['login', 'email', 'password', 'email_password', 'social_url', 'status', 'id'] as $k) {
    rrcCheck($out[0][$k] === $rows[0][$k], "{$k} не тронуто");
}
rrcCheck(!in_array('email', $out[0]['_clipped'], true) && !in_array('password', $out[0]['_clipped'], true),
    'и не попало в список обрезанных');

echo "Русские буквы:\n";
rrcCheck(mb_check_encoding($out[0]['extra_info_1'], 'UTF-8'), 'обрезок — правильный UTF-8');
rrcCheck(json_encode($out) !== false, 'json_encode ответа не падает');
rrcCheck(in_array('extra_info_1', $out[0]['_clipped'], true), 'длина считается в знаках, а не в байтах');
$exact = RefreshRowsClipper::clip([['id' => 1, 'note' => str_repeat('Ж', $max)]]);
rrcCheck(!isset($exact[0]['_clipped']) && $exact[0]['note'] === str_repeat('Ж', $max),
    "ровно {$max} русских знаков (вдвое больше байт) не обрезаются");

echo "Короткое, числа, NULL, пусто:\n";
rrcCheck($out[0]['cookies'] === 'short' && $out[0]['two_fa'] === null && $out[0]['friends'] === 15, 'проходят как есть');
rrcCheck(!isset($out[1]['_clipped']), 'у строки без длинных значений метки нет');
rrcCheck($out[1] === $rows[1], 'такая строка не меняется вообще');
rrcCheck(RefreshRowsClipper::clip([]) === [], 'пустая выдача → пусто');

echo "Вес:\n";
$before = strlen(json_encode($rows));
$after = strlen(json_encode($out));
rrcCheck($after * 10 < $before, "ответ стал легче в разы ({$before} → {$after} байт)");

echo "\n{$passed} прошло, {$failures} упало\n";
exit($failures > 0 ? 1 : 0);
