<?php
/**
 * Запись CSV по RFC 4180 — единственное правильное место для этого в проекте.
 *
 * Зачем отдельный класс, а не голый fputcsv():
 * у PHP-шного fputcsv по умолчанию escape='\', то есть обратный слэш считается
 * экранирующим символом. На значениях вроде cookies/token (JSON с \" внутри)
 * это ломает round-trip: записанное и прочитанное обратно не совпадают, данные
 * в выгрузке искажаются. Лечится escape='' (доступно с PHP 7.4) — тогда работает
 * стандартное правило RFC 4180: единственное экранирование внутри кавычек это "".
 *
 * Раньше обёртка `writeCsvRow()` была скопирована в export.php и
 * download_account_template.php, а admin_logs.php писал дефолтным fputcsv и
 * портил историю изменений. Теперь точка одна.
 *
 * Чего здесь осознанно НЕТ: защиты от формул Excel (значение, начинающееся с
 * `=`, `+`, `-`, `@`). Она меняет содержимое выгрузки, то есть контракт экспорта,
 * и включать её нужно осознанно — см. sanitizeCell() и admin_logs.php.
 *
 * Чтение CSV живёт в CsvParser (там же ручной парсер для PHP < 7.4).
 */
class Csv
{
    /**
     * Записывает одну строку CSV по правилам RFC 4180.
     *
     * На PHP 7.4+ делегирует в fputcsv с escape=''. На более старых версиях,
     * где escape='' не поддерживается, собирает строку руками: поле берётся в
     * кавычки, если содержит разделитель, кавычку или перевод строки; кавычка
     * внутри удваивается. Обратный слэш во всех случаях остаётся обычным символом.
     *
     * @param resource $handle    Открытый на запись поток
     * @param array    $fields    Значения полей; приводятся к строке
     * @param string   $delimiter Разделитель (по умолчанию `;` — как в экспорте аккаунтов)
     * @return int|false Число записанных байт или false при ошибке записи
     */
    public static function writeRow($handle, array $fields, string $delimiter = ';')
    {
        if (PHP_VERSION_ID >= 70400) {
            return fputcsv($handle, $fields, $delimiter, '"', '');
        }

        $out = [];
        foreach ($fields as $field) {
            $field = (string)$field;
            if (strpos($field, '"') !== false
                || strpos($field, $delimiter) !== false
                || strpos($field, "\n") !== false
                || strpos($field, "\r") !== false
            ) {
                $out[] = '"' . str_replace('"', '""', $field) . '"';
            } else {
                $out[] = $field;
            }
        }

        return fwrite($handle, implode($delimiter, $out) . "\n");
    }

    /**
     * Защита от исполнения формул при открытии выгрузки в Excel/Sheets:
     * значение, начинающееся с `=`, `+`, `-`, `@`, таба или CR, получает
     * ведущий апостроф.
     *
     * ВНИМАНИЕ: меняет содержимое ячейки. Применять только там, где выгрузка
     * предназначена человеку в таблице (audit log), и НЕ применять там, где
     * выгрузка потом импортируется обратно как данные.
     *
     * @param mixed $value
     * @return string
     */
    public static function sanitizeCell($value): string
    {
        $s = (string)($value ?? '');
        if ($s === '') {
            return $s;
        }

        $first = $s[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r"
        ) {
            return "'" . $s;
        }

        return $s;
    }
}
