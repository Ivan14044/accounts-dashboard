<?php
/**
 * Обрезка длинных значений в строках, которые refresh.php отдаёт браузеру.
 *
 * Зачем (15.11.2026). Ответ обновления таблицы на 50 строк весил 2,2 МБ
 * (420 КБ сжатыми), из них 1,6 МБ — full_cookies (~33 КБ на аккаунт). Это
 * ~0,5 с скачивания на КАЖДОЕ обновление после любого действия, ~1 с на 100
 * строках. А таблица из длинного значения показывает только первые 80 знаков
 * (CLIP_LEN в table-module.js и rows.php).
 *
 * Полное значение браузер берёт по требованию — копирование, просмотр и
 * правка идут через CellValues (cell-actions.js) → field_value.php. Так уже
 * работает первая загрузка страницы, где длинные значения в HTML не кладутся.
 * Чтобы CellValues не принял обрезок за полное значение, строка несёт список
 * обрезанных полей `_clipped`; TableModule::getRowValue по нему отвечает
 * «в памяти нет», и значение идёт с сервера.
 *
 * Чего здесь осознанно НЕТ:
 *   - обрезки полей, которые таблица рисует целиком (логин, почта, пароли,
 *     ссылка, статус) — иначе на экране стало бы иначе, чем было;
 *   - обрезки в выгрузке и массовых операциях — они строки из refresh.php не
 *     берут.
 */
class RefreshRowsClipper
{
    /**
     * Сколько знаков длинного значения оставить. С запасом больше 80, которые
     * показывает таблица, — обрезок рисуется ровно так же, как полное значение.
     */
    const MAX_CHARS = 200;

    /** Поля, которые table-module.js показывает целиком, без обрезки до 80. */
    const KEEP_FULL = ['id', 'login', 'email', 'password', 'email_password', 'social_url', 'status'];

    /**
     * @param array $rows строки из AccountsService::getAccounts()
     * @return array те же строки; у строки с обрезанными полями — ключ
     *               `_clipped` со списком имён этих полей
     */
    public static function clip(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $out[] = $row;
                continue;
            }
            $clipped = [];
            foreach ($row as $field => $value) {
                if (!is_string($value) || in_array($field, self::KEEP_FULL, true)) {
                    continue;
                }
                // Быстрый отсев по байтам: в MAX_CHARS знаков UTF-8 не больше
                // 4*MAX_CHARS байт, а короче MAX_CHARS байт — точно не длинное.
                if (strlen($value) <= self::MAX_CHARS) {
                    continue;
                }
                if (mb_strlen($value, 'UTF-8') <= self::MAX_CHARS) {
                    continue;
                }
                $row[$field] = mb_substr($value, 0, self::MAX_CHARS, 'UTF-8');
                $clipped[] = (string)$field;
            }
            if ($clipped) {
                $row['_clipped'] = $clipped;
            }
            $out[] = $row;
        }
        return $out;
    }
}
