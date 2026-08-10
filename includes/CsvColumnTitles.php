<?php
/**
 * Заголовки колонок в CSV: единый источник правды для экспорта и импорта.
 *
 * Зачем этот класс появился (2026-08-10). Экспорт писал в заголовок
 * человекочитаемые названия («First Name», «Social URL», «2FA»), а импорт
 * сопоставлял заголовок с именем колонки в БД. Совпадали только девять колонок
 * из сорока четырёх — те, у которых название и имя колонки случайно одинаковы
 * (login, password, email, token, cookies, status, avatar, cover). Остальные
 * 35 импорт молча пропускал, и типовой сценарий «выгрузил → поправил в Excel →
 * залил обратно» возвращал в базу меньше десятой части полей, ничего не сообщая.
 *
 * Корнем была не ошибка сопоставления, а ДВЕ несвязанные карты названий: своя
 * в export.php и своя в ColumnMetadata (там русские названия, для таблицы).
 * Поэтому здесь не «умное угадывание», а один список, которым пользуются обе
 * стороны: titleFor() для выгрузки, resolve() для чтения.
 *
 * Чего здесь осознанно НЕТ:
 *  - русских названий из ColumnMetadata::getColumnTitles(). Это подписи столбцов
 *    в интерфейсе, они меняются ради удобства чтения; привязывать к ним формат
 *    файла нельзя — переименование подписи сломало бы импорт старых выгрузок.
 *    resolve() их и не принимает: заголовок «Имя» неоднозначен.
 *  - угадывания по похожести (levenshtein и подобное). Неверно угаданная
 *    колонка тихо запишет данные не туда — это хуже, чем честный отказ.
 */
class CsvColumnTitles
{
    /**
     * Человекочитаемые названия для колонок, у которых простое преобразование
     * «подчёркивания в пробелы» даёт неудачный результат.
     *
     * ВАЖНО: менять значения здесь можно только с пониманием, что старые
     * выгруженные файлы должны продолжать читаться. Новое название добавляется,
     * старое переносится в LEGACY_TITLES.
     *
     * @var array<string, string>
     */
    private static $titles = array(
        'id'             => 'ID',
        'login'          => 'Login',
        'email'          => 'Email',
        'first_name'     => 'First Name',
        'last_name'      => 'Last Name',
        'status'         => 'Status',
        'password'       => 'Password',
        'email_password' => 'Email Password',
        'birth_day'      => 'Birth Day',
        'birth_month'    => 'Birth Month',
        'birth_year'     => 'Birth Year',
        'social_url'     => 'Social URL',
        'ads_id'         => 'Ads ID',
        'user_agent'     => 'User Agent',
        'two_fa'         => '2FA',
        'token'          => 'Token',
        'cookies'        => 'Cookies',
        'extra_info_1'   => 'Extra Info 1',
        'extra_info_2'   => 'Extra Info 2',
        'extra_info_3'   => 'Extra Info 3',
        'extra_info_4'   => 'Extra Info 4',
        'created_at'     => 'Created At',
        'updated_at'     => 'Updated At',
    );

    /**
     * Заголовки, которые когда-то писал экспорт, но больше не пишет.
     * Нужны, чтобы уже выгруженные пользователем файлы читались и дальше.
     * Ключ — заголовок в нормализованном виде, значение — колонка БД.
     *
     * @var array<string, string>
     */
    private static $legacy = array();

    /**
     * Заголовок для колонки — то, что уходит в файл.
     *
     * @param string $column Имя колонки в БД
     * @return string Человекочитаемый заголовок
     */
    public static function titleFor($column)
    {
        if (isset(self::$titles[$column])) {
            return self::$titles[$column];
        }
        return ucfirst(str_replace('_', ' ', $column));
    }

    /**
     * Заголовки для списка колонок, в том же порядке.
     *
     * @param string[] $columns Имена колонок в БД
     * @return string[] Заголовки
     */
    public static function titlesFor(array $columns)
    {
        $out = array();
        foreach ($columns as $col) {
            $out[] = self::titleFor($col);
        }
        return $out;
    }

    /**
     * Колонка БД по заголовку из файла.
     *
     * Принимает и человекочитаемый заголовок из выгрузки, и голое имя колонки
     * (так делают руками собранные файлы и наш же шаблон импорта).
     * Терпит то, что реально встречается в файлах: BOM от Excel, лишние пробелы,
     * различия в регистре, звёздочку-пометку обязательного поля из шаблона.
     *
     * @param string $header Заголовок из файла
     * @param string[] $dbColumns Колонки, которые есть в этой таблице
     * @return string|null Имя колонки или null, если сопоставить не удалось
     */
    public static function resolve($header, array $dbColumns)
    {
        $key = self::normalize($header);
        if ($key === '') {
            return null;
        }

        // Индекс существующих колонок: сопоставляем только с тем, что реально
        // есть в таблице, — иначе вернём колонку, которой в этой БД нет.
        $existing = array();
        foreach ($dbColumns as $col) {
            $existing[self::normalize($col)] = $col;
        }

        // 1. Голое имя колонки
        if (isset($existing[$key])) {
            return $existing[$key];
        }

        // 2. Человекочитаемый заголовок из нашей выгрузки
        foreach (self::$titles as $col => $title) {
            if (self::normalize($title) === $key && isset($existing[self::normalize($col)])) {
                return $existing[self::normalize($col)];
            }
        }

        // 3. Заголовки прошлых версий формата
        if (isset(self::$legacy[$key])) {
            $col = self::$legacy[$key];
            $normCol = self::normalize($col);
            if (isset($existing[$normCol])) {
                return $existing[$normCol];
            }
        }

        // 4. Заголовок, полученный автоматическим преобразованием: «Extra Info 5»
        //    для колонки extra_info_5. Обратное преобразование — пробелы в
        //    подчёркивания — уже сделано в normalize(), поэтому сюда попадаем
        //    только если колонки с таким именем нет. Значит, сопоставить нечем.
        return null;
    }

    /**
     * Приводит заголовок к сравнимому виду: без BOM, пробелов по краям,
     * звёздочки-пометки, в нижнем регистре, пробелы — подчёркиваниями.
     *
     * @param string $value Заголовок или имя колонки
     * @return string Нормализованный ключ
     */
    private static function normalize($value)
    {
        $s = (string)$value;
        // BOM ставит Excel в начало первой ячейки
        $s = preg_replace('~^\xEF\xBB\xBF~', '', $s);
        $s = trim($s);
        // Шаблон импорта помечает обязательные поля звёздочкой: «login*»
        $s = rtrim($s, "* \t");
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        // Несколько пробелов подряд и подчёркивания — к одному виду
        $s = preg_replace('~\s+~u', '_', $s);
        return $s === null ? '' : $s;
    }
}
