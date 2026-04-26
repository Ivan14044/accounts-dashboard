<?php
/**
 * Утилита для установки HTTP заголовков для оптимизации производительности
 * 
 * @package includes
 */
class ResponseHeaders {
    /**
     * Устанавливает заголовки для кэширования статических ресурсов
     * 
     * @param int $maxAge Время кэширования в секундах
     * @param bool $immutable Можно ли считать ресурс неизменяемым
     */
    public static function setCacheHeaders(int $maxAge = 2592000, bool $immutable = false): void {
        $cacheControl = "public, max-age=$maxAge";
        if ($immutable) {
            $cacheControl .= ", immutable";
        }
        header("Cache-Control: $cacheControl");
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + $maxAge) . " GMT");
    }
    
    /**
     * Устанавливает заголовки для запрета кэширования
     */
    public static function setNoCacheHeaders(): void {
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    }
    
    /**
     * Устанавливает заголовки для JSON ответов
     */
    public static function setJsonHeaders(): void {
        header("Content-Type: application/json; charset=utf-8");
        self::setNoCacheHeaders();
    }
    
    /**
     * Устанавливает заголовки для сжатия ответа
     */
    public static function enableCompression(): void {
        if (extension_loaded('zlib') && !ob_get_level()) {
            ob_start('ob_gzhandler');
        }
    }
    
    /**
     * Устанавливает ETag для условных запросов
     *
     * @param string $etag Значение ETag
     */
    public static function setETag(string $etag): void {
        // Sanitize etag by removing control characters that could be used for header injection
        $etag = str_replace(["\r", "\n", "\0"], '', $etag);

        header("ETag: \"$etag\"");

        // Проверяем If-None-Match для 304 ответа
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === "\"$etag\"") {
            http_response_code(304);
            exit;
        }
    }

    /**
     * Устанавливает базовый набор security-заголовков.
     *
     * CSP намеренно разрешает CDN (jsdelivr, font-awesome) и 'unsafe-inline',
     * потому что в шаблонах ещё встречаются inline onclick/style. Это временный
     * компромисс — после устранения inline-обработчиков можно затянуть до
     * 'self' + nonce.
     *
     * Вызывать в начале каждой HTML-страницы (или централизованно из config.php).
     */
    public static function setSecurityHeaders(): void {
        if (headers_sent()) {
            return;
        }

        // Запрещаем встраивать страницы дашборда в <iframe> другого сайта.
        header('X-Frame-Options: SAMEORIGIN');
        // Запрещаем MIME-sniffing (браузер не будет угадывать тип по содержимому).
        header('X-Content-Type-Options: nosniff');
        // Не утекаем полный URL на внешние ресурсы в Referer.
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // Отключаем опасные Permissions-Policy по умолчанию.
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

        // HSTS — только при HTTPS, иначе ломаем локалку.
        $isHttps =
            (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || ((strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // CSP — defensive defaults. 'unsafe-inline' остаётся для inline-onclick
        // и <style> в шаблонах; после их вычистки можно ужесточить.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "style-src  'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "font-src   'self' https://cdnjs.cloudflare.com data:",
            "img-src    'self' data: blob: https:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
        header('Content-Security-Policy: ' . $csp);
    }
}


