<?php
/**
 * Извлечение FB-идентификаторов аккаунта для дедупликации.
 *
 * "Личность" FB-аккаунта стабильно определяется не login (он меняется),
 * а его c_user — числовым FB user ID. Этот же ID лежит сразу в трёх местах:
 *   1. id_soc_account — явное поле, авторитетный источник (если заполнено).
 *   2. social_url     — обычно facebook.com/profile.php?id=... или vanity.
 *   3. cookies        — внутри JSON или cookie-string как c_user=<digits>.
 *
 * extract() собирает все эти ID плюс сам login, чтобы dedup при импорте
 * корректно ловил случай "тот же FB-аккаунт под другим логином": если у
 * нового и существующего совпал хоть один токен из fingerprint — это дубль.
 *
 * Раньше эта же логика жила в AccountValidationService::extractFbIds (private).
 * Чтобы не дублировать код, валидация теперь делегирует в этот класс.
 *
 * @package includes
 */
class AccountFingerprint
{
    /**
     * Pattern для FB user ID. Старые id начинаются с 10..., новые pseudo-id с 61...
     * Длина 12–25 знаков (10|61 + 10–23 цифр/букв). Буквы для новых API id.
     */
    public const FB_ID_PATTERN = '/\b(10|61)[0-9A-Za-z]{10,23}\b/';

    /**
     * Возвращает массив "fingerprint-токенов" аккаунта — все строки, по которым
     * может найтись дубликат. Все нормализованы (lowercase, trim).
     *
     * Формат токена: префикс + двоеточие + значение, например:
     *   - 'login:user@example.com'
     *   - 'fbid:100012345678901'
     * Префикс нужен чтобы login = "100012345" не схлопывался с числовым FB ID.
     *
     * @param array{login?:string|null,id_soc_account?:string|null,social_url?:string|null,cookies?:string|null} $row
     * @return string[] уникальные токены
     */
    public static function extract(array $row): array
    {
        $tokens = [];

        $login = trim((string)($row['login'] ?? ''));
        if ($login !== '') {
            $tokens[] = 'login:' . strtolower($login);
            // Edge-case: сам login может быть FB ID-ом (например, юзер положил
            // c_user в поле login без id_soc_account). Тогда новый аккаунт с
            // login="100012345" должен поймать дубль с существующим, у которого
            // тот же 100012345 лежит в id_soc_account / cookies / social_url.
            if (preg_match(self::FB_ID_PATTERN, $login)) {
                $tokens[] = 'fbid:' . $login;
            }
        }

        foreach (self::extractFbIds($row) as $fbId) {
            $tokens[] = 'fbid:' . $fbId;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Извлекает все FB user IDs из аккаунта (без login).
     * Источники: id_soc_account, social_url, c_user в cookies.
     *
     * @param array{id_soc_account?:string|null,social_url?:string|null,cookies?:string|null} $row
     * @return string[] уникальные FB IDs
     */
    public static function extractFbIds(array $row): array
    {
        $ids = [];

        $idSoc = trim((string)($row['id_soc_account'] ?? ''));
        if ($idSoc !== '' && preg_match_all(self::FB_ID_PATTERN, $idSoc, $m)) {
            foreach ($m[0] as $id) $ids[$id] = true;
        }

        $socialUrl = trim((string)($row['social_url'] ?? ''));
        if ($socialUrl !== '' && preg_match_all(self::FB_ID_PATTERN, $socialUrl, $m)) {
            foreach ($m[0] as $id) $ids[$id] = true;
        }

        $cookies = (string)($row['cookies'] ?? '');
        if ($cookies !== '') {
            $cUser = self::extractCUserFromCookies($cookies);
            if ($cUser !== null) {
                $ids[$cUser] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Извлекает c_user (FB user ID авторизованного пользователя) из cookies.
     * Поддерживает два формата:
     *   1. JSON array: [{"name":"c_user","value":"123..."}, ...]
     *   2. Cookie-string: "c_user=123...; xs=...; ..."
     *
     * Hot-path optimization: stripos() ранний return для всех cookies без
     * подстроки c_user (например, гугловые/трекерные cookies). На больших
     * батчах экономит мегабайты regex-сканирования и сотни json_decode вызовов.
     */
    public static function extractCUserFromCookies(string $cookies): ?string
    {
        if ($cookies === '' || stripos($cookies, 'c_user') === false) {
            return null;
        }

        $trim = ltrim($cookies);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($cookies, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cookie) {
                    if (is_array($cookie)
                        && isset($cookie['name'], $cookie['value'])
                        && strcasecmp((string)$cookie['name'], 'c_user') === 0
                    ) {
                        $val = (string)$cookie['value'];
                        if (preg_match('/^[0-9]{8,23}$/', $val)) {
                            return $val;
                        }
                    }
                }
            }
        }

        if (preg_match('/(?:^|[\s;])c_user=([0-9]{8,23})\b/i', $cookies, $m)) {
            return $m[1];
        }

        return null;
    }
}
