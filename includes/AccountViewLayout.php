<?php
/**
 * AccountViewLayout — раскладка полей аккаунта по разделам для страницы
 * view.php («карточка аккаунта»).
 *
 * Зачем (19.11.2026). Старая карточка выводила ВСЕ колонки таблицы одним
 * столбцом подряд: на проде у аккаунта 173 колонки, заполнено обычно ~50,
 * остальные ~120 — строчки с прочерком. Логин, пароль и 2FA тонули среди
 * «ID Fan Page 7» и «Year bm 9», пароли были спрятаны за глазиком, а
 * «Дополнительная информация 2» на деле почти всегда — запасная почта.
 *
 * Что делает: чистая функция build() раскладывает строку из БД по двум
 * колонкам страницы:
 *   - слева «доступы» — то, что копируют для входа: Фейсбук, почта, браузер,
 *     и под ними служебные поля (так колонки выходят примерно равной высоты);
 *   - справа «об аккаунте» — профиль, реклама, фан-страницы, БМ.
 * Фан-страницы (поля *_N, до 10 штук) и бизнес-менеджеры (*_bm_N) сводятся в
 * таблицы — показываются только слоты, где хоть что-то заполнено.
 *
 * Инварианты (стережёт tests/test_account_view_layout.php):
 *   - каждая колонка строки попадает на страницу ровно один раз: в раздел,
 *     в таблицу ФП/БМ, в шапку (id, status) или в раздел «Прочее». Новая
 *     колонка, которой ещё нет в списках ниже, не пропадёт — уйдёт в «Прочее»;
 *   - пустые поля не выбрасываются, а помечаются empty=true — страница прячет
 *     их под кнопкой «Показать пустые».
 *
 * Чего здесь осознанно НЕТ: HTML и обращений к БД. Только данные → структура,
 * чтобы раскладку можно было проверить тестом без браузера и базы.
 */
class AccountViewLayout
{
    /** Значение длиннее — сворачивается в блок «Развернуть». */
    const LONG_THRESHOLD = 160;

    /** Колонки, которые показаны в шапке страницы, а не в разделах. */
    const HEADER_KEYS = ['id', 'status'];

    /**
     * Разделы страницы. Порядок полей внутри — порядок вывода.
     * Поле: ключ => [подпись, вид]. Вид:
     *   text — обычный текст;   mono — моноширинный (пароли, ID, ключи);
     *   long — длинный, сворачивается;   link — ссылка;   email — адрес почты;
     *   flag — да/нет (true/false/1/0);  date — дата и время из БД;
     *   json — JSON, показывается с отступами.
     * Ключ 'birthday' — составное поле из birth_day/birth_month/birth_year.
     */
    private static function sections(): array
    {
        return [
            'left' => [
                'facebook' => ['Вход в Фейсбук', 'fab fa-facebook', [
                    'login'          => ['Логин', 'mono'],
                    'password'       => ['Пароль', 'mono'],
                    'two_fa'         => ['2FA', 'mono'],
                    'social_url'     => ['Профиль', 'link'],
                    'id_soc_account' => ['ID в Фейсбуке', 'mono'],
                    'passkey'        => ['Passkey', 'long'],
                    'phone_removed'  => ['Телефон снят', 'phone_removed'],
                ]],
                'mail' => ['Почта', 'fas fa-envelope', [
                    'email'               => ['Почта', 'email'],
                    'email_password'      => ['Пароль почты', 'mono'],
                    'extra_info_2'        => ['Доп. почта', 'email'],
                    'email_not_confirmed' => ['Почта не подтверждена', 'text'],
                ]],
                'browser' => ['Браузер и вход по кукам', 'fas fa-globe', [
                    'user_agent'   => ['User-Agent', 'mono'],
                    'token'        => ['Токен', 'long'],
                    'cookies'      => ['Куки', 'long'],
                    'first_cookie' => ['Первые куки', 'long'],
                    'full_cookies' => ['Полные куки', 'long'],
                ]],
                'service' => ['Служебное', 'fas fa-database', [
                    'status_marketplace' => ['Статус маркетплейса', 'text'],
                    'selectedFolderPath' => ['Папка', 'mono'],
                    'extra_info_1'       => ['Доп. информация 1', 'text'],
                    'extra_info_3'       => ['Доп. информация 3', 'text'],
                    'extra_info_4'       => ['Доп. информация 4', 'text'],
                    'created_at'         => ['Добавлен в базу', 'date'],
                    'updated_at'         => ['Изменён', 'date'],
                    'deleted_at'         => ['В корзине с', 'date'],
                ]],
            ],
            'right' => [
                'profile' => ['Профиль', 'fas fa-user', [
                    'first_name'        => ['Имя', 'text'],
                    'last_name'         => ['Фамилия', 'text'],
                    'birthday'          => ['Дата рождения', 'text'],
                    'year_created'      => ['Год создания', 'text'],
                    'date_registration' => ['Дата регистрации', 'date'],
                    'quantity_friends'  => ['Друзей', 'text'],
                    'quantity_photo'    => ['Фото', 'text'],
                    'avatar'            => ['Аватар', 'flag'],
                    'cover'             => ['Обложка', 'flag'],
                    'full_filled'       => ['Профиль заполнен', 'flag'],
                    'add_photo'         => ['Фото добавлены', 'flag'],
                    'activity_status'   => ['Активность', 'text'],
                    'dialogs'           => ['Диалоги', 'text'],
                    'developer'         => ['Разработчик', 'text'],
                    'link_ava'          => ['Ссылка на аватар', 'link'],
                    'link_site'         => ['Ссылка на сайт', 'link'],
                    'link_nick'         => ['Ссылка-ник', 'link'],
                    'link_document'     => ['Ссылка на документ', 'link'],
                ]],
                'ads' => ['Реклама', 'fas fa-bullhorn', [
                    'status_rk'       => ['Статус РК', 'text'],
                    'rk_info'         => ['О кабинете', 'text'],
                    'ads_id'          => ['ID кабинета', 'mono'],
                    'limit_rk'        => ['Лимит', 'text'],
                    'currency'        => ['Валюта', 'text'],
                    'timezone_rk'     => ['Часовой пояс', 'text'],
                    'geo'             => ['Гео', 'text'],
                    'billing'         => ['Биллинг', 'text'],
                    'balance'         => ['Баланс', 'text'],
                    'scenario_pharma' => ['Сценарий фарма', 'text'],
                    'bundle'          => ['Связка', 'mono'],
                    'bundle_ads'      => ['Кабинеты связки', 'long'],
                ]],
                'fanpages' => ['Фан-страницы', 'fas fa-flag', [
                    'quantity_fp' => ['Всего страниц', 'text'],
                    'fp_note'     => ['Заметка', 'text'],
                    'fp_decor'    => ['Оформление страниц', 'json'],
                ]],
                'bm' => ['Бизнес-менеджеры', 'fas fa-briefcase', [
                    'bm'          => ['БМ', 'text'],
                    'quantity_bm' => ['Всего БМ', 'text'],
                ]],
            ],
        ];
    }

    /** Столбцы таблицы фан-страниц: префикс колонки => подпись. */
    const FP_COLUMNS = [
        'id_fan_page'         => 'ID',
        'name_fan_page'       => 'Название',
        'year_fan_page'       => 'Год',
        'fan_count'           => 'Подписчики',
        'like_count'          => 'Лайки',
        'review_count'        => 'Отзывы',
        'admin_count'         => 'Админов',
        'verification_status' => 'Верификация',
    ];

    /** Столбцы таблицы бизнес-менеджеров. */
    const BM_COLUMNS = [
        'id_bm'     => 'ID',
        'status_bm' => 'Статус',
        'year_bm'   => 'Год',
    ];

    /**
     * Раскладывает строку аккаунта по разделам страницы.
     *
     * @param array $row Строка из БД (колонка => значение, значения string|null).
     * @return array {
     *   header:     {id:int, status:string, name:string, created:string, updated:string},
     *   columns:    {left: section[], right: section[]},
     *   emptyCount: int — сколько полей в разделах пустые (спрятаны по умолчанию),
     *   placedKeys: string[] — все колонки строки, которые куда-то попали (для теста)
     * }
     * section = {id, title, icon, fields: field[], table: null|{columns, rows}, filled:int}
     * field   = {key, title, kind, value:string, empty:bool, flag:?bool,
     *            display:string, size:int, totp:bool}
     */
    public static function build(array $row): array
    {
        $placed = [];
        foreach (self::HEADER_KEYS as $k) {
            if (array_key_exists($k, $row)) {
                $placed[$k] = true;
            }
        }

        $fpTable = self::slotTable($row, self::FP_COLUMNS, $placed);
        $bmTable = self::slotTable($row, self::BM_COLUMNS, $placed);

        $emptyCount = 0;
        $columns = ['left' => [], 'right' => []];

        foreach (self::sections() as $side => $sections) {
            foreach ($sections as $id => $def) {
                list($title, $icon, $fieldDefs) = $def;
                $fields = [];
                foreach ($fieldDefs as $key => $fd) {
                    if ($key === 'birthday') {
                        $f = self::birthdayField($row, $placed);
                    } elseif (array_key_exists($key, $row)) {
                        $placed[$key] = true;
                        $f = self::field($key, $fd[0], $fd[1], $row[$key]);
                    } else {
                        continue; // в этой БД такой колонки нет
                    }
                    if ($f === null) {
                        continue;
                    }
                    $fields[] = $f;
                }

                $table = null;
                if ($id === 'fanpages') {
                    $table = $fpTable;
                } elseif ($id === 'bm') {
                    $table = $bmTable;
                }

                $columns[$side][] = self::section($id, $title, $icon, $fields, $table, $emptyCount);
            }
        }

        // Всё, что не попало в известные разделы, — «Прочее»: новая колонка
        // в базе не должна молча пропасть со страницы.
        $rest = [];
        foreach ($row as $key => $value) {
            $key = (string)$key;
            if (isset($placed[$key])) {
                continue;
            }
            $placed[$key] = true;
            $rest[$key] = $value;
        }
        if ($rest) {
            uksort($rest, 'strnatcasecmp');
            $fields = [];
            foreach ($rest as $key => $value) {
                $fields[] = self::field($key, self::humanize($key), 'text', $value);
            }
            $columns['left'][] = self::section('other', 'Прочее', 'fas fa-ellipsis-h', $fields, null, $emptyCount);
        }

        $first = trim((string)($row['first_name'] ?? ''));
        $last  = trim((string)($row['last_name'] ?? ''));
        $name  = trim($first . ' ' . $last);
        if ($name === '') {
            $name = trim((string)($row['login'] ?? ''));
        }

        return [
            'header' => [
                'id'      => (int)($row['id'] ?? 0),
                'status'  => (string)($row['status'] ?? ''),
                'name'    => $name !== '' ? $name : 'Без имени',
                'created' => self::formatDate((string)($row['created_at'] ?? '')),
                'updated' => self::formatDate((string)($row['updated_at'] ?? '')),
            ],
            'columns'    => $columns,
            'emptyCount' => $emptyCount,
            'placedKeys' => array_keys($placed),
        ];
    }

    /**
     * Список «подпись — значение» всех НЕПУСТЫХ полей в порядке страницы.
     * Для кнопки «Копировать всё». Таблицы ФП/БМ — строкой на слот.
     *
     * @param array $layout Результат build().
     * @return array<int, array{0:string,1:string}>
     */
    public static function copyList(array $layout): array
    {
        $out = [['ID', (string)$layout['header']['id']]];
        if ($layout['header']['status'] !== '') {
            $out[] = ['Статус', $layout['header']['status']];
        }
        foreach (['left', 'right'] as $side) {
            foreach ($layout['columns'][$side] as $section) {
                foreach ($section['fields'] as $f) {
                    if (!$f['empty']) {
                        $out[] = [$f['title'], $f['value']];
                    }
                }
                if ($section['table']) {
                    foreach ($section['table']['rows'] as $i => $cells) {
                        $parts = [];
                        foreach ($section['table']['columns'] as $c => $colTitle) {
                            if ($cells[$c] !== '') {
                                $parts[] = $colTitle . ': ' . $cells[$c];
                            }
                        }
                        $out[] = [$section['title'] . ' ' . ($i + 1), implode(', ', $parts)];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Доступы для кнопки «Копировать доступы»: логин, пароли, 2FA, почты.
     *
     * @param array $row Строка из БД.
     * @return array<int, array{0:string,1:string}>
     */
    public static function accessList(array $row): array
    {
        $map = [
            'login'          => 'Логин',
            'password'       => 'Пароль',
            'two_fa'         => '2FA',
            'email'          => 'Почта',
            'email_password' => 'Пароль почты',
            'extra_info_2'   => 'Доп. почта',
        ];
        $out = [];
        foreach ($map as $k => $title) {
            $v = isset($row[$k]) ? trim((string)$row[$k]) : '';
            if ($v !== '') {
                $out[] = [$title, $v];
            }
        }
        return $out;
    }

    /**
     * Похоже ли значение 2FA на ключ для генератора кодов (base32, ≥16 знаков).
     * Пробелы и дефисы, которыми ключ часто бьют на группы, игнорируются.
     */
    public static function isTotpSecret(string $value): bool
    {
        $s = strtoupper(preg_replace('~[\s-]+~', '', $value));
        return strlen($s) >= 16 && (bool)preg_match('~^[A-Z2-7]+=*$~', $s);
    }

    /**
     * «2026-11-18 14:05:09» → «18.11.2026 14:05». Нераспознанное — как есть.
     */
    public static function formatDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?~', $value, $m)) {
            $out = $m[3] . '.' . $m[2] . '.' . $m[1];
            if (isset($m[4]) && $m[4] !== '') {
                $out .= ' ' . $m[4] . ':' . $m[5];
            }
            return $out;
        }
        return $value;
    }

    /** Размер значения для подписи у свёрнутого поля: «840 Б», «33,5 КБ». */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }
        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return str_replace('.', ',', (string)round($kb, $kb < 10 ? 1 : 0)) . ' КБ';
        }
        return str_replace('.', ',', (string)round($kb / 1024, 1)) . ' МБ';
    }

    // ───────────────────────── внутреннее ─────────────────────────

    private static function field(string $key, string $title, string $kind, $raw): array
    {
        $value = $raw === null ? '' : (string)$raw;
        $empty = trim($value) === '';
        $flag = null;
        $display = $value;

        if (!$empty) {
            switch ($kind) {
                case 'flag':
                    $flag = self::parseFlag($value);
                    if ($flag === null) {
                        $kind = 'text'; // неожиданное значение — показываем как есть
                    }
                    break;
                case 'phone_removed':
                    // Контракт колонки (CLAUDE.md): '1' пишется только в момент
                    // реального снятия номера, пусто — не снят.
                    $kind = 'flag';
                    $flag = self::parseFlag($value);
                    if ($flag === null) {
                        $kind = 'text';
                    }
                    break;
                case 'date':
                    $display = self::formatDate($value);
                    break;
                case 'json':
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if (is_string($pretty)) {
                            $display = $pretty;
                        }
                    }
                    $kind = 'long';
                    break;
                case 'link':
                    if (!preg_match('~^https?://~i', $value)) {
                        $kind = 'mono';
                    }
                    break;
                case 'email':
                    if (strpos($value, '@') === false) {
                        $kind = 'mono';
                    }
                    break;
            }
            if (in_array($kind, ['text', 'mono'], true) && strlen($value) > self::LONG_THRESHOLD) {
                $kind = 'long';
            }
        } elseif ($kind === 'phone_removed') {
            $kind = 'flag';
        }

        return [
            'key'     => $key,
            'title'   => $title,
            'kind'    => $kind,
            'value'   => $value,
            'display' => $display,
            'empty'   => $empty,
            'flag'    => $flag,
            'size'    => strlen($value),
            'totp'    => $key === 'two_fa' && !$empty && self::isTotpSecret($value),
        ];
    }

    /** Дата рождения из трёх колонок одной строкой «дд.мм.гггг». */
    private static function birthdayField(array $row, array &$placed)
    {
        $parts = [];
        $any = false;
        foreach (['birth_day', 'birth_month', 'birth_year'] as $k) {
            if (array_key_exists($k, $row)) {
                $any = true;
                $placed[$k] = true;
            }
            $parts[$k] = trim((string)($row[$k] ?? ''));
        }
        if (!$any) {
            return null;
        }
        $d = $parts['birth_day'];
        $m = $parts['birth_month'];
        $y = $parts['birth_year'];
        $pad = function ($v) {
            return ctype_digit($v) && strlen($v) === 1 ? '0' . $v : $v;
        };
        $value = implode('.', array_filter([$pad($d), $pad($m), $y], 'strlen'));

        $f = self::field('birthday', 'Дата рождения', 'text', $value);
        return $f;
    }

    /**
     * Сводит пронумерованные колонки (id_fan_page_1, fan_count_1, …) в таблицу.
     * Строка таблицы = номер слота, где заполнено хоть одно поле.
     */
    private static function slotTable(array $row, array $columns, array &$placed)
    {
        $slots = [];
        foreach ($row as $key => $value) {
            $key = (string)$key;
            if (!preg_match('~^(.+)_(\d+)$~', $key, $m) || !isset($columns[$m[1]])) {
                continue;
            }
            $placed[$key] = true;
            $n = (int)$m[2];
            $v = trim((string)$value);
            if (!isset($slots[$n])) {
                $slots[$n] = array_fill_keys(array_keys($columns), '');
            }
            $slots[$n][$m[1]] = $v;
        }
        ksort($slots);
        $rows = [];
        foreach ($slots as $cells) {
            if (implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }
        return $rows ? ['columns' => $columns, 'rows' => $rows] : null;
    }

    private static function section(string $id, string $title, string $icon, array $fields, $table, int &$emptyCount): array
    {
        $filled = $table ? count($table['rows']) : 0;
        foreach ($fields as $f) {
            if ($f['empty']) {
                $emptyCount++;
            } else {
                $filled++;
            }
        }
        return [
            'id'     => $id,
            'title'  => $title,
            'icon'   => $icon,
            'fields' => $fields,
            'table'  => $table,
            'filled' => $filled,
        ];
    }

    /** true/false/1/0/yes/no → bool; всё прочее → null. */
    private static function parseFlag(string $value)
    {
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 'yes', 'да'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'нет'], true)) {
            return false;
        }
        return null;
    }

    /** some_column_name → «Some column name» для колонок без своей подписи. */
    private static function humanize(string $key): string
    {
        $s = str_replace('_', ' ', $key);
        return function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1)
            : ucfirst($s);
    }
}
