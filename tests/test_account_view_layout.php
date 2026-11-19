<?php
/**
 * Раскладка карточки аккаунта (includes/AccountViewLayout.php, страница view.php).
 *
 * Запуск: php tests/test_account_view_layout.php
 *
 * Главное, что стережёт: при раскладке по разделам НИ ОДНА колонка аккаунта
 * не пропадает со страницы и не выводится дважды. Строка-фикстура повторяет
 * форму прода (173 колонки на 19.11.2026), значения — заведомо фейковые.
 */
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../includes/AccountViewLayout.php';

$passed = 0;
$failures = 0;
function check($name, $cond, $detail = '') {
    global $passed, $failures;
    if ($cond) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failures++;
        echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Строка формы прода: все 173 колонки, заполнена примерно треть. */
function prodShapedRow() {
    $keys = ['id','login','password','email','email_password','first_name','last_name','social_url',
        'birth_day','birth_month','birth_year','token','ads_id','cookies','user_agent','two_fa','passkey',
        'phone_removed','developer','extra_info_1','extra_info_2','link_ava','link_site','link_nick',
        'link_document','status','bundle','bundle_ads','created_at','updated_at','date_registration',
        'full_cookies','avatar','cover','full_filled','add_photo','quantity_friends','quantity_fp','fp_decor',
        'quantity_bm','scenario_pharma','quantity_photo','status_rk','selectedFolderPath','id_soc_account',
        'status_marketplace','year_created','limit_rk','currency','timezone_rk','geo','activity_status',
        'billing','balance','bm','deleted_at','extra_info_3','extra_info_4','first_cookie',
        'email_not_confirmed','fp_note','rk_info','dialogs'];
    for ($i = 1; $i <= 10; $i++) {
        foreach (['id_fan_page','fan_count','review_count','like_count','verification_status',
                  'name_fan_page','admin_count','year_fan_page','id_bm','status_bm','year_bm'] as $p) {
            $keys[] = "{$p}_{$i}";
        }
    }
    $row = array_fill_keys($keys, null);
    $row = array_merge($row, [
        'id' => '100001', 'login' => '70000000001', 'password' => 'fakePass1', 'email' => 'fake@example.test',
        'email_password' => 'fakeMailPass', 'first_name' => 'Тест', 'last_name' => 'Тестов',
        'social_url' => 'https://www.facebook.com/profile.php?id=1', 'birth_day' => '7', 'birth_month' => '3',
        'birth_year' => '1990', 'token' => str_repeat('T', 200), 'cookies' => str_repeat('c', 1700),
        'user_agent' => 'Mozilla/5.0 (fake)', 'two_fa' => 'JBSW Y3DP EHPK 3PXP', 'status' => 'sale',
        'created_at' => '2026-11-18 14:05:09', 'updated_at' => '2026-11-19 09:00:00',
        'full_cookies' => str_repeat('f', 33537), 'avatar' => 'true', 'cover' => 'false',
        'fp_decor' => '{"pages":{"1":{"page":"x"}}}', 'quantity_fp' => '1', 'extra_info_2' => 'backup@example.test',
        'id_fan_page_1' => '61500000000001', 'fan_count_1' => '5', 'year_fan_page_1' => '2019',
        'id_fan_page_3' => '61500000000003', 'id_bm_2' => '900000000002', 'status_bm_2' => 'active',
        'rk_info' => 'чисто', 'phone_removed' => '1',
    ]);
    return $row;
}

echo "── 1. Ни одна колонка не теряется и не дублируется\n";

$row = prodShapedRow();
$L = AccountViewLayout::build($row);
$placed = $L['placedKeys'];
sort($placed);
$all = array_keys($row);
sort($all);
check('все 173 колонки куда-то попали', $placed === $all,
    'потеряны: ' . implode(',', array_diff($all, $placed)));
check('в фикстуре действительно 173 колонки', count($row) === 173, (string)count($row));

$seen = [];
$dups = [];
foreach (['left', 'right'] as $side) {
    foreach ($L['columns'][$side] as $s) {
        foreach ($s['fields'] as $f) {
            if (isset($seen[$f['key']])) {
                $dups[] = $f['key'];
            }
            $seen[$f['key']] = true;
        }
    }
}
check('ни одно поле не выведено дважды', !$dups, implode(',', $dups));

$withUnknown = $row + ['brand_new_column' => 'xyz'];
$L2 = AccountViewLayout::build($withUnknown);
$other = null;
foreach ($L2['columns']['left'] as $s) {
    if ($s['id'] === 'other') {
        $other = $s;
    }
}
check('новая колонка базы уходит в «Прочее», а не пропадает',
    $other !== null && $other['fields'][0]['key'] === 'brand_new_column' && $other['fields'][0]['title'] === 'Brand new column');
check('без неизвестных колонок раздела «Прочее» нет', !in_array('other', array_column($L['columns']['left'], 'id'), true));

echo "── 2. Колонки страницы\n";

$leftIds = array_column($L['columns']['left'], 'id');
$rightIds = array_column($L['columns']['right'], 'id');
check('слева доступы: Фейсбук, почта, браузер, затем служебное', $leftIds === ['facebook', 'mail', 'browser', 'service'], implode(',', $leftIds));
check('справа сведения: профиль, реклама, ФП, БМ',
    $rightIds === ['profile', 'ads', 'fanpages', 'bm'], implode(',', $rightIds));

function findField($L, $key) {
    foreach (['left', 'right'] as $side) {
        foreach ($L['columns'][$side] as $s) {
            foreach ($s['fields'] as $f) {
                if ($f['key'] === $key) {
                    return [$s['id'], $f];
                }
            }
        }
    }
    return [null, null];
}

list($sec, $f) = findField($L, 'extra_info_2');
check('extra_info_2 называется «Доп. почта» и стоит в разделе «Почта»', $sec === 'mail' && $f['title'] === 'Доп. почта' && $f['kind'] === 'email');

list($sec, $f) = findField($L, 'password');
check('пароль — обычное поле с полным значением', $f['value'] === 'fakePass1' && $f['kind'] === 'mono');

list($sec, $f) = findField($L, 'birthday');
check('дата рождения собрана из трёх колонок с нулями', $f['value'] === '07.03.1990', $f['value']);

list($sec, $f) = findField($L, 'created_at');
check('дата показывается по-русски', $f['display'] === '18.11.2026 14:05', $f['display']);
check('шапка: дата добавления', $L['header']['created'] === '18.11.2026 14:05');
check('шапка: имя из имени и фамилии', $L['header']['name'] === 'Тест Тестов');

list($sec, $f) = findField($L, 'full_cookies');
check('полные куки сворачиваются и знают свой размер', $f['kind'] === 'long' && $f['size'] === 33537);
check('размер подписан по-человечески', AccountViewLayout::formatSize(33537) === '33 КБ' && AccountViewLayout::formatSize(1700) === '1,7 КБ' && AccountViewLayout::formatSize(840) === '840 Б', AccountViewLayout::formatSize(33537));

list($sec, $f) = findField($L, 'avatar');
check('аватар true → флажок «да»', $f['kind'] === 'flag' && $f['flag'] === true);
list($sec, $f) = findField($L, 'cover');
check('обложка false → флажок «нет»', $f['kind'] === 'flag' && $f['flag'] === false);
list($sec, $f) = findField($L, 'phone_removed');
check('телефон снят: «1» → да', $f['kind'] === 'flag' && $f['flag'] === true);

list($sec, $f) = findField($L, 'fp_decor');
check('JSON оформления страниц — с отступами', $f['kind'] === 'long' && strpos($f['display'], "\n") !== false);

echo "── 3. Фан-страницы и БМ — таблицами, только заполненные слоты\n";

$fp = $L['columns']['right'][2]['table'];
check('таблица ФП: 2 строки (слоты 1 и 3)', $fp !== null && count($fp['rows']) === 2);
check('таблица ФП: значения слота 1 на месте',
    $fp['rows'][0]['id_fan_page'] === '61500000000001' && $fp['rows'][0]['fan_count'] === '5' && $fp['rows'][0]['year_fan_page'] === '2019');
$bm = $L['columns']['right'][3]['table'];
check('таблица БМ: 1 строка (слот 2)', $bm !== null && count($bm['rows']) === 1 && $bm['rows'][0]['status_bm'] === 'active');

$noFp = $row;
foreach ($noFp as $k => $v) {
    if (preg_match('~^(id_fan_page|fan_count|year_fan_page)_\d+$~', $k)) {
        $noFp[$k] = null;
    }
}
$L3 = AccountViewLayout::build($noFp);
check('без заполненных ФП таблицы нет', $L3['columns']['right'][2]['table'] === null);

echo "── 4. Пустые поля и краевые случаи\n";

check('пустые поля посчитаны', $L['emptyCount'] > 30, (string)$L['emptyCount']);
$minimal = AccountViewLayout::build(['id' => '5', 'login' => 'x']);
check('строка из двух колонок не падает', $minimal['header']['id'] === 5 && $minimal['header']['name'] === 'x');
$empty = AccountViewLayout::build([]);
check('пустая строка не падает', $empty['header']['name'] === 'Без имени' && $empty['emptyCount'] === 0);
$badJson = AccountViewLayout::build(['fp_decor' => '{не json']);
list($sec, $f) = findField($badJson, 'fp_decor');
check('битый JSON показывается как есть', $f['display'] === '{не json');
$weirdFlag = AccountViewLayout::build(['avatar' => 'maybe']);
list($sec, $f) = findField($weirdFlag, 'avatar');
check('непонятный флажок показывается текстом', $f['kind'] === 'text' && $f['display'] === 'maybe');
$plainSocial = AccountViewLayout::build(['social_url' => 'facebook.com/x', 'extra_info_2' => 'not-a-mail']);
list($sec, $f) = findField($plainSocial, 'social_url');
check('ссылка без http не превращается в ссылку', $f['kind'] === 'mono');
list($sec, $f) = findField($plainSocial, 'extra_info_2');
check('доп. почта без @ показывается текстом, не mailto', $f['kind'] === 'mono');

echo "── 5. 2FA и списки для копирования\n";

check('ключ 2FA с пробелами распознан', AccountViewLayout::isTotpSecret('JBSW Y3DP EHPK 3PXP'));
check('короткое значение — не ключ', !AccountViewLayout::isTotpSecret('123456'));
check('значение с недопустимыми буквами — не ключ', !AccountViewLayout::isTotpSecret('JBSWY3DPEHPK3PX1'));
list($sec, $f) = findField($L, 'two_fa');
check('у 2FA включён генератор кода', $f['totp'] === true);

$access = AccountViewLayout::accessList($row);
check('доступы: логин, пароль, 2FA, почта, пароль почты, доп. почта',
    array_column($access, 0) === ['Логин', 'Пароль', '2FA', 'Почта', 'Пароль почты', 'Доп. почта'],
    implode(',', array_column($access, 0)));

$copy = AccountViewLayout::copyList($L);
$titles = array_column($copy, 0);
check('«Копировать всё» без пустых полей', !in_array('Passkey', $titles, true));
check('«Копировать всё» с русскими подписями', in_array('Пароль почты', $titles, true) && !in_array('email_password', $titles, true));
check('«Копировать всё» включает фан-страницы', in_array('Фан-страницы 1', $titles, true));

echo "\nИтого: {$passed} прошло, {$failures} упало\n";
exit($failures > 0 ? 1 : 0);
