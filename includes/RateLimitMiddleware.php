<?php
/**
 * Rate Limit Middleware - Helper для проверки лимитов в API endpoints
 * 
 * Использование:
 * require_once __DIR__ . '/includes/RateLimitMiddleware.php';
 * checkRateLimit('api'); // Для обычных API
 * checkRateLimit('export'); // Для экспорта
 * checkRateLimit('login'); // Для логина
 */

require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';

/**
 * Проверка rate limit для текущего запроса
 * 
 * @param string $endpoint Тип endpoint: 'api', 'export', 'login'
 * @param string|null $customKey Кастомный ключ вместо IP
 * @return bool true если проверка прошла
 * @throws Exception если лимит превышен
 */
function checkRateLimit($endpoint = 'api', $customKey = null) {
    // Если rate limiting отключен
    if (!Config::FEATURE_RATE_LIMITING) {
        return true;
    }
    
    $limiter = new RateLimiter();
    
    // Определяем лимиты в зависимости от endpoint
    $limits = [
        'api' => [
            'max' => Config::API_RATE_LIMIT,
            'window' => Config::RATE_LIMIT_WINDOW
        ],
        'export' => [
            'max' => Config::EXPORT_RATE_LIMIT,
            'window' => Config::RATE_LIMIT_WINDOW
        ],
        'login' => [
            'max' => Config::LOGIN_RATE_LIMIT,
            'window' => Config::RATE_LIMIT_WINDOW
        ]
    ];
    
    $config = $limits[$endpoint] ?? $limits['api'];
    
    // Формируем ключ (IP + endpoint)
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = $customKey ?? ($endpoint . '_' . $ip);
    
    // Проверяем лимит
    if (!$limiter->checkLimit($key, $config['max'], $config['window'])) {
        // Лимит превышен
        $remaining = $limiter->getRemainingAttempts($key, $config['max'], $config['window']);
        $resetTime = $limiter->getTimeUntilReset($key, $config['window']);
        
        Logger::warning('RATE LIMIT: Request blocked', [
            'endpoint' => $endpoint,
            'ip_masked' => maskIp($ip),
            'limit' => $config['max']
        ]);
        
        // Возвращаем HTTP 429 Too Many Requests
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $resetTime);
        header('X-RateLimit-Limit: ' . $config['max']);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . (time() + $resetTime));
        
        echo json_encode([
            'success' => false,
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded. Please try again later.',
            'retry_after' => $resetTime,
            'limit' => $config['max'],
            'window' => $config['window']
        ], JSON_UNESCAPED_UNICODE);
        
        exit;
    }
    
    // Добавляем заголовки с информацией о лимите
    $remaining = $limiter->getRemainingAttempts($key, $config['max'], $config['window']);
    header('X-RateLimit-Limit: ' . $config['max']);
    header('X-RateLimit-Remaining: ' . $remaining);
    header('X-RateLimit-Reset: ' . (time() + $config['window']));
    
    return true;
}

/**
 * Маскировка IP адреса для логирования
 * 
 * @param string $ip IP адрес
 * @return string Замаскированный IP
 */
function maskIp($ip) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        return $parts[0] . '.' . $parts[1] . '.xxx.xxx';
    }
    return 'xxx.xxx.xxx.xxx';
}

/**
 * Периодическая очистка старых файлов rate limit
 * Вызывать редко (например, раз в час)
 */
function cleanupRateLimitCache() {
    $limiter = new RateLimiter();
    $limiter->cleanup(3600); // Удаляем файлы старше 1 часа
}








