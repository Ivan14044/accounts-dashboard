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
        header("ETag: \"$etag\"");
        
        // Проверяем If-None-Match для 304 ответа
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === "\"$etag\"") {
            http_response_code(304);
            exit;
        }
    }
}


