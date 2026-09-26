<?php
/**
 * EmailPurgePlanner — чистая логика точного отбора аккаунтов, у которых почта
 * принадлежит одному из заданных доменов.
 *
 * За что отвечает: решить, попадает ли конкретный адрес почты под покупку
 * «домен из списка», строго по ДОМЕНУ адреса (часть после последней `@`),
 * а не по «где-то в строке встретилось имя домена». Это единственная логика
 * в задаче чистки почт, которая может ИСПОРТИТЬ данные (снести чужой ящик),
 * поэтому она вынесена в отдельный тестируемый класс.
 *
 * Чего здесь осознанно НЕТ: обращения к БД, ввода-вывода, чтения запроса.
 * Отбор строк и сам UPDATE делает admin_purge_emails.php; этот класс —
 * эталон семантики и двойная страховка (PHP-перепроверка того, что вернул SQL).
 *
 * Ловушки, которые класс обязан отсечь (см. tests/test_email_purge_planner.php):
 *   - поддомен: `x@sub.scalomail.com` НЕ равен домену `scalomail.com`;
 *   - имя ящика: `scalomail.com@gmail.com` — домен `gmail.com`, не трогаем;
 *   - регистр: `X@Scalomail.COM` = `scalomail.com`;
 *   - пробелы по краям адреса;
 *   - пустой адрес / без `@` — не совпадает ни с чем.
 */
class EmailPurgePlanner {
    /**
     * Домен адреса: всё после последней `@`, в нижнем регистре, без пробелов.
     *
     * @param string|null $email Адрес почты как он лежит в БД
     * @return string|null Домен в нижнем регистре или null, если `@` нет
     */
    public static function emailDomain(?string $email): ?string {
        $email = trim((string)$email);
        $at = strrpos($email, '@');
        if ($at === false) return null;
        $domain = substr($email, $at + 1);
        $domain = strtolower(trim($domain));
        return $domain === '' ? null : $domain;
    }

    /**
     * Приводит список доменов к канону: нижний регистр, без пробелов, без пустых,
     * без дублей. Используется и для отбора, и для сравнения.
     *
     * @param string[] $domains
     * @return string[] уникальные домены в нижнем регистре
     */
    public static function normalizeDomains(array $domains): array {
        $out = [];
        foreach ($domains as $d) {
            $d = strtolower(trim((string)$d));
            if ($d !== '') $out[$d] = true;
        }
        return array_keys($out);
    }

    /**
     * Принадлежит ли адрес одному из доменов (строгое сравнение целого домена).
     *
     * @param string|null $email
     * @param string[] $domainsLower Уже нормализованный список (normalizeDomains)
     * @return bool
     */
    public static function matches(?string $email, array $domainsLower): bool {
        $domain = self::emailDomain($email);
        if ($domain === null) return false;
        return in_array($domain, $domainsLower, true);
    }

    /**
     * Бьёт список id на пачки фиксированного размера.
     *
     * Нужен, чтобы UPDATE шёл через `WHERE id IN (...)` по первичному ключу
     * пачками (см. правило про range_optimizer в CLAUDE.md), а не одним
     * сканом всей таблицы по LIKE.
     *
     * @param int[] $ids
     * @param int $size Размер пачки (>0)
     * @return int[][]
     */
    public static function chunk(array $ids, int $size): array {
        if ($size < 1) $size = 1;
        return array_chunk(array_values($ids), $size);
    }
}
