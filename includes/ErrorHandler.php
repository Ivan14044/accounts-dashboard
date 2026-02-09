<?php
/**
 * Централизованный обработчик ошибок
 * Обеспечивает единообразную обработку исключений во всем приложении
 */
require_once __DIR__ . '/Logger.php';

class ErrorHandler {
    /**
     * Обработка исключения с логированием и выводом
     * 
     * @param Throwable $e Исключение для обработки
     * @param string $context Контекст, в котором произошла ошибка
     * @param int|null $httpCode HTTP код ответа (для API)
     * @return void
     */
    public static function handleError(Throwable $e, string $context = '', ?int $httpCode = null): void {
        $message = $context ? "[$context] " : '';
        $message .= $e->getMessage();
        
        // Определяем уровень логирования по типу исключения
        $logLevel = 'error';
        if ($e instanceof InvalidArgumentException) {
            $logLevel = 'warning'; // Валидационные ошибки - warning
        }
        
        // Логируем ошибку с полной информацией
        Logger::$logLevel($message, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'exception_type' => get_class($e),
            'context' => $context,
            'full_message' => $e->getMessage()
        ]);
        
        // Для API запросов - JSON ответ
        if (self::isApiRequest()) {
            self::sendJsonError($e, $httpCode);
            return;
        }
        
        // Для обычных запросов - HTML страница
        self::renderErrorPage($e);
    }
    
    /**
     * Проверка, является ли запрос API запросом
     * 
     * @return bool
     */
    private static function isApiRequest(): bool {
        // Проверка AJAX запроса
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        
        // Проверка по пути (файлы api_*.php)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('/\/api[^\/]*\.php$/', $scriptName)) {
            return true;
        }
        
        // Проверка по заголовку Accept
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'application/json') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Отправка JSON ответа об ошибке
     * 
     * @param Throwable $e Исключение
     * @param int|null $httpCode HTTP код ответа
     * @return void
     */
    private static function sendJsonError(Throwable $e, ?int $httpCode = null): void {
        // Определяем HTTP код по типу исключения и сообщению
        if ($httpCode === null) {
            $message = strtolower($e->getMessage());
            if ($e instanceof InvalidArgumentException) {
                $httpCode = 400; // Bad Request для валидационных ошибок
            } elseif (strpos($message, 'not authenticated') !== false || 
                      strpos($message, 'unauthorized') !== false ||
                      strpos($message, 'необходима авторизация') !== false) {
                $httpCode = 401; // Unauthorized для ошибок авторизации
            } elseif (strpos($message, 'database connection') !== false ||
                      strpos($message, 'failed to prepare') !== false ||
                      strpos($message, 'failed to execute') !== false ||
                      strpos($message, 'mysqli') !== false) {
                $httpCode = 500; // Internal Server Error для ошибок БД
            } elseif ($e instanceof \RuntimeException) {
                $httpCode = 500; // Internal Server Error
            } else {
                $httpCode = 500; // По умолчанию 500
            }
        }
        
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        
        $isDevelopment = self::isDevelopmentMode();
        
        // Для валидационных ошибок и некоторых других типов показываем сообщение даже в продакшене
        $errorMsg = strtolower($e->getMessage());
        $showMessage = $isDevelopment 
            || $e instanceof InvalidArgumentException 
            || $e instanceof \RuntimeException
            || $e instanceof Exception 
            || $e instanceof \Error // PHP 7+ Error класс
            || (stripos($errorMsg, 'failed to') !== false) 
            || (stripos($errorMsg, 'parameter') !== false) 
            || (stripos($errorMsg, 'prepare') !== false) 
            || (stripos($errorMsg, 'execute') !== false)
            || (stripos($errorMsg, 'database') !== false)
            || (stripos($errorMsg, 'connection') !== false)
            || (stripos($errorMsg, 'mysqli') !== false)
            || (stripos($errorMsg, 'config') !== false)
            || (stripos($errorMsg, 'сервис') !== false)
            || (stripos($errorMsg, 'временно недоступен') !== false)
            || (stripos($errorMsg, 'ошибка подключения') !== false);
        
        // Если сообщение слишком длинное или содержит технические детали, обрезаем его
        $errorMessage = $showMessage ? $e->getMessage() : 'Internal server error';
        if ($showMessage && !$isDevelopment) {
            // В продакшене обрезаем слишком длинные сообщения и убираем технические детали
            if (strlen($errorMessage) > 200) {
                $errorMessage = substr($errorMessage, 0, 200) . '...';
            }
            // Убираем пути к файлам из сообщения
            $errorMessage = preg_replace('/\/[^\s]+\.php:\d+/', '', $errorMessage);
        }
        
        $response = [
            'success' => false,
            'error' => $errorMessage
        ];
        
        // В режиме разработки добавляем подробности
        if ($isDevelopment) {
            $response['debug'] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'type' => get_class($e),
                'trace' => $e->getTraceAsString()
            ];
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Проверка, включен ли режим разработки
     * 
     * @return bool
     */
    private static function isDevelopmentMode(): bool {
        // Проверка через переменную окружения
        $env = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production');
        if (strtolower($env) === 'development' || strtolower($env) === 'dev') {
            return true;
        }
        
        // Проверка через display_errors
        if (ini_get('display_errors')) {
            return true;
        }
        
        // Проверка через Logger
        if (Logger::isDebugEnabled()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Отрисовка HTML страницы ошибки
     * 
     * @param Throwable $e Исключение
     * @return void
     */
    private static function renderErrorPage(Throwable $e): void {
        http_response_code(500);
        
        // Если есть шаблон ошибки - используем его
        $errorTemplate = __DIR__ . '/../templates/error.php';
        if (file_exists($errorTemplate)) {
            include $errorTemplate;
            exit;
        }
        
        // Иначе - простой HTML вывод
        $showDetails = ini_get('display_errors') || Logger::isDebugEnabled();
        
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
        echo '<h1>Ошибка</h1>';
        echo '<p>Произошла ошибка при обработке запроса.</p>';
        
        if ($showDetails) {
            echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            echo '<p><strong>Файл:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
            echo '<p><strong>Строка:</strong> ' . htmlspecialchars($e->getLine()) . '</p>';
            echo '<details><summary>Стек вызовов</summary><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre></details>';
        } else {
            echo '<p>Проверьте логи сервера для получения подробной информации.</p>';
        }
        
        echo '<p><a href="index.php">Вернуться на главную</a></p>';
        echo '</body></html>';
        exit;
    }
    
    /**
     * Установка глобальных обработчиков ошибок PHP
     * 
     * @return void
     */
    public static function register(): void {
        set_error_handler(function($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            
            $exception = new ErrorException($message, 0, $severity, $file, $line);
            self::handleError($exception, 'PHP Error');
        });
        
        set_exception_handler(function(Throwable $e) {
            self::handleError($e, 'Uncaught Exception');
        });
        
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $exception = new ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );
                self::handleError($exception, 'Fatal Error');
            }
        });
    }
}


