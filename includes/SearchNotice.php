<?php
/**
 * Решение о том, что сказать пользователю про режим поиска.
 *
 * За что отвечает: по фактам «какой был запрос», «сколько нашлось», «сработал
 * ли откат на поиск по подстроке» и «включён ли режим только точного
 * совпадения» вернуть данные для плашки над таблицей — тип сообщения и ссылку
 * на противоположный режим. Текст и вёрстка живут в
 * templates/partials/table/search-notice.php, здесь их осознанно НЕТ: так
 * решение проверяется тестом без БД и без браузера.
 *
 * Зачем это нужно (случай 2026-08-28): поиск по номеру `4054` вернул 433
 * строки. Аккаунта с таким номером в базе нет, поэтому точный поиск (фаза 1)
 * дал ноль и молча включился откат на LIKE '%4054%' — совпадения нашлись
 * внутри длинных значений (ID соцсети, ссылка на профиль, ID фан-страницы).
 * Пользователю это выглядело как «найдено 433 аккаунта с номером 4054».
 *
 * @package includes
 */
class SearchNotice
{
    /**
     * Параметры, которым нечего делать в ссылке «сменить режим поиска».
     * `light`/`debug` приходят только от refresh.php и к фильтру отношения не
     * имеют; `_` — типичный анти-кэш; `page` и `exact` выставляются явно.
     */
    private static $serviceParams = array('light', 'debug', 'ajax', '_', 'page', 'exact');

    /**
     * Что показать над таблицей.
     *
     * @param mixed $query           Поисковый запрос (обычно строка; массив из
     *                               руками собранного `?q[]=` не должен ронять страницу)
     * @param int   $foundTotal      Сколько строк нашлось в итоге
     * @param bool  $fallbackApplied Сработал ли откат на поиск по подстроке (фаза 2)
     * @param bool  $exactOnly       Включён ли режим «только точное совпадение»
     * @param array $queryParams     Текущие параметры запроса ($_GET) — для ссылки
     * @return array|null null, если говорить не о чем. Иначе:
     *   ['type' => 'fallback'|'exact_empty'|'exact_active', 'query' => string,
     *    'total' => int, 'url' => string]
     */
    public static function build($query, $foundTotal, $fallbackApplied, $exactOnly, array $queryParams)
    {
        $query = is_array($query) ? '' : trim((string)$query);
        if ($query === '') {
            // Запрос могли передать только в $_GET (например, при частичном
            // обновлении таблицы) — берём его оттуда, но массив игнорируем.
            $fromParams = isset($queryParams['q']) && !is_array($queryParams['q'])
                ? trim((string)$queryParams['q'])
                : '';
            $query = $fromParams;
        }
        if ($query === '') {
            return null;
        }

        $foundTotal = (int)$foundTotal;

        // Откат состоялся и что-то нашёл: показанное — НЕ точные совпадения,
        // и об этом обязательно надо предупредить.
        if ($fallbackApplied && $foundTotal > 0) {
            return array(
                'type'  => 'fallback',
                'query' => $query,
                'total' => $foundTotal,
                'url'   => self::modeUrl($queryParams, $query, true),
            );
        }

        // Если откат состоялся, но не нашёл ничего, — про режим говорить нечего:
        // пустая выдача сама всё объясняет.

        // Точный режим включён вручную: надо и объяснить пустоту, и дать
        // дорогу назад, иначе режим выглядит как «поиск сломался».
        if ($exactOnly) {
            return array(
                'type'  => $foundTotal > 0 ? 'exact_active' : 'exact_empty',
                'query' => $query,
                'total' => $foundTotal,
                'url'   => self::modeUrl($queryParams, $query, false),
            );
        }

        return null;
    }

    /**
     * Ссылка на противоположный режим поиска с сохранением остальных фильтров.
     *
     * @param array  $queryParams Текущие параметры ($_GET)
     * @param string $query       Очищенный поисковый запрос
     * @param bool   $exact       true — включить точный режим, false — выключить
     * @return string Относительная ссылка вида `?q=4054&exact=1&page=1`
     */
    private static function modeUrl(array $queryParams, $query, $exact)
    {
        foreach (self::$serviceParams as $key) {
            unset($queryParams[$key]);
        }

        $queryParams['q'] = $query;
        if ($exact) {
            $queryParams['exact'] = '1';
        }
        // Смена режима меняет выдачу целиком — оставаться на седьмой странице
        // старого результата бессмысленно.
        $queryParams['page'] = '1';

        $qs = http_build_query($queryParams);
        return $qs === '' ? '?' : '?' . $qs;
    }
}
