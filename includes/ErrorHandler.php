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
        
        $code = self::resolveHttpCode($e, $httpCode);

        // Для API запросов - JSON ответ
        if (self::isApiRequest()) {
            self::sendJsonError($e, $code);
            return;
        }

        // Для обычных запросов - HTML страница с тем же кодом.
        // Раньше здесь всегда был 500, и кривой ввод клиента выглядел как отказ
        // сервера: мониторинг видел всплеск 500 на ровном месте.
        self::renderErrorPage($e, $code);
    }

    /**
     * Проверка, является ли запрос API запросом (то есть надо отвечать JSON).
     *
     * Публичный метод: решение «JSON или HTML» проверяется тестом
     * tests/test_error_http_codes.php.
     *
     * @return bool
     */
    public static function isApiRequest(): bool {
        // Проверка AJAX запроса
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }

        // Клиент прислал JSON — значит, ждёт JSON в ответ.
        // Без этой ветки запрос с корректным Content-Type, но без X-Requested-With
        // получал в ответ HTML-страницу ошибки.
        $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if ($contentType !== '' && stripos($contentType, 'application/json') !== false) {
            return true;
        }

        // Проверка по пути (файлы api_*.php и всё внутри /api/)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#/api[^/]*\.php$|^/api/#', $scriptName)) {
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
     * Выбор HTTP-кода ответа по исключению.
     *
     * Явно переданный код всегда побеждает: точка входа знает про свой контракт
     * больше, чем эвристика по тексту сообщения.
     *
     * @param Throwable $e
     * @param int|null  $explicit Код, переданный вызывающим кодом
     * @return int
     */
    public static function resolveHttpCode(Throwable $e, ?int $explicit = null): int {
        if ($explicit !== null) {
            return $explicit;
        }

        // Провал CSRF — это запрет, а не «неправильно заполненное поле».
        // Маршруты (api/routes/favorites.php, filters.php, settings.php,
        // accounts.php) и писали json_error(..., 403) — только код туда никогда
        // не доходил: Validator::validateCsrfToken не возвращает false, а бросает
        // InvalidArgumentException, поэтому все эти ветки — мёртвый код.
        // Проверяем ДО общей ветки InvalidArgumentException, иначе получим 400.
        if ($e instanceof InvalidArgumentException && stripos($e->getMessage(), 'csrf') !== false) {
            return 403;
        }

        // Ошибка ввода — это вина клиента, а не сервера.
        if ($e instanceof InvalidArgumentException) {
            return 400;
        }

        // mb_strtolower, а не strtolower: последний не понижает регистр кириллицы,
        // и проверка на «Необходима авторизация» с заглавной буквы не срабатывала —
        // такие ответы уезжали в 500 вместо 401.
        $raw = $e->getMessage();
        $message = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);

        if (strpos($message, 'not authenticated') !== false
            || strpos($message, 'unauthorized') !== false
            || strpos($message, 'необходима авторизация') !== false
        ) {
            return 401;
        }

        return 500;
    }
    
    /**
     * Отправка JSON ответа об ошибке
     * 
     * @param Throwable $e Исключение
     * @param int|null $httpCode HTTP код ответа
     * @return void
     */
    private static function sendJsonError(Throwable $e, ?int $httpCode = null): void {
        $httpCode = self::resolveHttpCode($e, $httpCode);

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
     * @param int $httpCode Код ответа (400 для ошибок ввода, 500 для сбоев сервера)
     * @return void
     */
    private static function renderErrorPage(Throwable $e, int $httpCode = 500): void {
        http_response_code($httpCode);
        
        // Если есть шаблон ошибки - используем его
        $errorTemplate = __DIR__ . '/../templates/error.php';
        if (file_exists($errorTemplate)) {
            include $errorTemplate;
            exit;
        }
        
        // Иначе - простой HTML вывод
        $showDetails = ini_get('display_errors') || Logger::isDebugEnabled();
        
        $themeInit = '<script>(function(){try{var t=localStorage.getItem("dashboard-theme");'
            . 'if(!t){t=(window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)?"dark":"light";}'
            . 'document.documentElement.setAttribute("data-bs-theme",t);}catch(e){}})();</script>';
        $css = '<style>'
            . ':root{--bg:#f9fafb;--card:#fff;--bd:#e5e7eb;--tx:#111827;--mut:#6b7280;--accent:#2563eb;--code:#f3f4f6;}'
            . '[data-bs-theme="dark"]{--bg:#0A0A0F;--card:#15161A;--bd:#2C2E36;--tx:#F1F2F4;--mut:#8C929C;--accent:#60a5fa;--code:#23252B;}'
            . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;'
            . 'font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--tx);}'
            . '.err-card{width:100%;max-width:520px;background:var(--card);border:1px solid var(--bd);border-radius:16px;padding:32px;'
            . 'box-shadow:0 10px 24px rgba(0,0,0,.10);}'
            . '.err-ico{width:48px;height:48px;border-radius:12px;background:rgba(239,68,68,.12);color:#ef4444;display:flex;'
            . 'align-items:center;justify-content:center;font-size:24px;margin-bottom:16px;}'
            . 'h1{font-size:1.25rem;margin:0 0 8px;letter-spacing:-.02em;}p{color:var(--mut);margin:0 0 16px;line-height:1.5;}'
            . 'pre{background:var(--code);border:1px solid var(--bd);border-radius:8px;padding:12px;overflow:auto;font-size:12px;'
            . 'color:var(--tx);white-space:pre-wrap;word-break:break-word;}'
            . 'a.btn{display:inline-block;margin-top:8px;padding:10px 18px;background:var(--accent);color:#fff;border-radius:8px;'
            . 'text-decoration:none;font-weight:600;font-size:14px;}summary{cursor:pointer;color:var(--mut);font-size:13px;}'
            . '</style>';
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Ошибка</title>'
            . $themeInit . $css . '</head><body>';
        // 4xx — виноват запрос, 5xx — виноват сервер. Текст должен это отражать,
        // иначе пользователь идёт искать несуществующую поломку в логах.
        $isClientError = $httpCode >= 400 && $httpCode < 500;

        echo '<div class="err-card">';
        echo '<div class="err-ico">&#9888;</div>';
        echo '<h1>' . ($isClientError ? 'Некорректный запрос' : 'Произошла ошибка') . '</h1>';
        echo '<p>' . ($isClientError
            ? 'Сервер не смог обработать запрос: в нём не хватает данных или они неверны.'
            : 'При обработке запроса возникла ошибка.') . '</p>';

        if ($showDetails) {
            echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            echo '<p style="font-size:13px"><strong>Файл:</strong> ' . htmlspecialchars($e->getFile())
                . '<br><strong>Строка:</strong> ' . htmlspecialchars($e->getLine()) . '</p>';
            echo '<details><summary>Стек вызовов</summary><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre></details>';
        } else {
            echo '<p>Проверьте логи сервера для получения подробной информации.</p>';
        }

        echo '<a class="btn" href="index.php">Вернуться на главную</a>';
        echo '</div></body></html>';
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


