<?php
/**
 * login.php — страница авторизации
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';

// Если пользователь уже авторизован, перенаправляем на главную
if (isAuthenticated()) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $indexUrl = $protocol . '://' . $host . rtrim($scriptDir, '/') . '/index.php';
    
    header('Location: ' . $indexUrl);
    exit;
}

$error = '';
$message = '';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_once __DIR__ . '/includes/RateLimiter.php';
        require_once __DIR__ . '/includes/Config.php';
        
        // Проверка CSRF-токена
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            $error = 'Недействительный токен безопасности. Обновите страницу и попробуйте снова.';
        }
        
        if (empty($error) && Config::FEATURE_RATE_LIMITING) {
            $limiter = new RateLimiter();
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $key = 'login_' . $ip;
            $maxAttempts = Config::LOGIN_RATE_LIMIT ?? 5;
            $timeWindow = Config::RATE_LIMIT_WINDOW ?? 60;
            
            if (!$limiter->checkLimit($key, $maxAttempts, $timeWindow)) {
                $error = 'Слишком много попыток входа. Пожалуйста, подождите несколько секунд перед повторной попыткой.';
            }
        }
        
        if (empty($error)) {
            $dbConnectionString = trim($_POST['db_connection_string'] ?? '');
            $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            
            if (empty($dbConnectionString)) {
                $error = 'Введите строку подключения к базе данных';
            } else {
                if (!function_exists('parseConnectionString')) {
                    $error = 'Ошибка системы: функция parseConnectionString не найдена';
                    require_once __DIR__ . '/includes/Logger.php';
                    Logger::error('parseConnectionString function not found');
                } elseif (!function_exists('testDatabaseConnection')) {
                    $error = 'Ошибка системы: функция testDatabaseConnection не найдена';
                    require_once __DIR__ . '/includes/Logger.php';
                    Logger::error('testDatabaseConnection function not found');
                } else {
                    $dbConfig = parseConnectionString($dbConnectionString);
                    if (!$dbConfig) {
                        $error = 'Неверный формат строки подключения к БД';
                    } else {
                        $testConnection = testDatabaseConnection($dbConfig);
                        if (!$testConnection['success']) {
                            $error = 'Ошибка подключения к БД: ' . htmlspecialchars($testConnection['error']);
                        } elseif (authenticate($dbConfig, $rememberMe)) {
                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $host = $_SERVER['HTTP_HOST'];
                            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
                            $indexUrl = $protocol . '://' . $host . rtrim($scriptDir, '/') . '/index.php';
                            
                            if (!headers_sent()) {
                                header('Location: ' . $indexUrl);
                                exit;
                            } else {
                                $error = 'Ошибка редиректа: заголовки уже отправлены';
                                require_once __DIR__ . '/includes/Logger.php';
                                Logger::warning('Headers already sent when trying to redirect');
                            }
                        } else {
                            $error = 'Ошибка авторизации';
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $error = 'Произошла ошибка: ' . htmlspecialchars($e->getMessage());
        require_once __DIR__ . '/includes/Logger.php';
        Logger::error('ERROR in login.php', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    } catch (Throwable $e) {
        $error = 'Произошла критическая ошибка';
        require_once __DIR__ . '/includes/Logger.php';
        Logger::error('FATAL ERROR in login.php', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

// Получаем сообщения из сессии
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// Проверяем сообщения из URL
if (isset($_GET['message'])) {
    switch ($_GET['message']) {
        case 'timeout':
            $message = 'Время сессии истекло. Войдите снова.';
            break;
        case 'logout':
            $message = 'Вы успешно вышли из системы.';
            break;
    }
}

// Функция для экранирования
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Вход в систему - Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="alternate icon" href="assets/favicon.svg">
  <style>
    body {
      background: #eee !important;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      margin: 0;
      padding: 0;
    }

    .wrapper {
      margin-top: 80px;
      margin-bottom: 80px;
    }

    .form-signin {
      max-width: 400px;
      width: 100%;
      padding: 24px 28px 32px;
      margin: 0 auto;
      background-color: #fff;
      border: 1px solid rgba(0, 0, 0, 0.08);
      border-radius: 8px;
      box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
      box-sizing: border-box;
      overflow: hidden;
    }

    .form-signin-heading {
      margin-bottom: 30px;
      text-align: center;
      font-size: 24px;
      font-weight: 400;
      color: #333;
    }

    .checkbox {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 24px;
      font-weight: normal;
      font-size: 14px;
      color: #333;
      cursor: pointer;
      user-select: none;
    }

    .checkbox input[type="checkbox"] {
      width: 18px;
      height: 18px;
      margin: 0;
      cursor: pointer;
      accent-color: #337ab7;
      border-radius: 4px;
      border: 1px solid #cbd5e0;
      flex-shrink: 0;
    }

    .checkbox input[type="checkbox"]:focus {
      outline: none;
      box-shadow: 0 0 0 3px rgba(51, 122, 183, 0.25);
    }

    .checkbox input[type="checkbox"]:hover {
      border-color: #337ab7;
    }

    .form-control {
      position: relative;
      font-size: 16px;
      height: auto;
      padding: 10px;
      box-sizing: border-box;
      width: 100%;
      border: 1px solid #ddd;
      border-radius: 4px;
      margin-bottom: 0;
    }

    .form-control:focus {
      z-index: 2;
      outline: none;
      border-color: #66afe9;
      box-shadow: inset 0 1px 1px rgba(0, 0, 0, 0.075), 0 0 8px rgba(102, 175, 233, 0.6);
    }

    input[type="text"].form-control {
      margin-bottom: -1px;
      border-bottom-left-radius: 0;
      border-bottom-right-radius: 0;
    }

    input[type="password"].form-control {
      margin-bottom: 20px;
      border-top-left-radius: 0;
      border-top-right-radius: 0;
    }

    textarea.form-control {
      margin-bottom: 20px;
      border-radius: 4px;
      min-height: 120px;
      resize: vertical;
      font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
      font-size: 13px;
      line-height: 1.6;
    }

    .btn {
      display: block;
      width: 100%;
      padding: 10px 16px;
      font-size: 18px;
      font-weight: normal;
      line-height: 1.42857143;
      text-align: center;
      white-space: nowrap;
      vertical-align: middle;
      cursor: pointer;
      border: 1px solid transparent;
      border-radius: 4px;
      box-sizing: border-box;
    }

    .btn-lg {
      padding: 10px 16px;
      font-size: 18px;
      line-height: 1.3333333;
      border-radius: 4px;
    }

    .btn-primary {
      color: #fff;
      background-color: #337ab7;
      border-color: #2e6da4;
    }

    .btn-primary:hover {
      color: #fff;
      background-color: #286090;
      border-color: #204d74;
    }

    .btn-primary:focus {
      color: #fff;
      background-color: #286090;
      border-color: #204d74;
      outline: none;
      box-shadow: 0 0 0 3px rgba(51, 122, 183, 0.25);
    }

    .btn-primary:active {
      color: #fff;
      background-color: #204d74;
      border-color: #122b40;
    }

    .btn-primary:disabled {
      opacity: 0.65;
      cursor: not-allowed;
    }

    .btn-block {
      display: block;
      width: 100%;
    }

    .alert {
      padding: 15px;
      margin-bottom: 20px;
      border: 1px solid transparent;
      border-radius: 4px;
      font-size: 14px;
    }

    .alert-danger {
      color: #a94442;
      background-color: #f2dede;
      border-color: #ebccd1;
    }

    .alert-success {
      color: #3c763d;
      background-color: #dff0d8;
      border-color: #d6e9c6;
    }

    .help-text {
      font-size: 12px;
      color: #666;
      margin-top: 8px;
      margin-bottom: 20px;
      line-height: 1.5;
      max-width: 100%;
      overflow-wrap: break-word;
    }

    .help-text code {
      display: block;
      max-width: 100%;
      margin-top: 4px;
      padding: 8px 10px;
      background: #f6f8fa;
      border: 1px solid #e8eaed;
      border-radius: 6px;
      font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
      font-size: 11px;
      line-height: 1.5;
      word-break: break-all;
      overflow-wrap: break-word;
      white-space: pre-wrap;
      box-sizing: border-box;
    }

    @media (max-width: 480px) {
      .form-signin {
        padding: 20px 18px 28px;
        max-width: 100%;
        margin: 0 16px;
        border-radius: 8px;
      }

      .wrapper {
        margin-top: 40px;
        margin-bottom: 40px;
      }

      .help-text code {
        padding: 6px 8px;
        font-size: 10px;
      }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <form class="form-signin" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
      <h2 class="form-signin-heading">Вход в систему</h2>
      
      <?php if ($message): ?>
        <div class="alert alert-success" role="alert">
          <?= e($message) ?>
        </div>
      <?php endif; ?>
      
      <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
          <?= e($error) ?>
        </div>
      <?php endif; ?>
      
      <textarea 
        class="form-control" 
        name="db_connection_string" 
        placeholder="Строка подключения к БД: server=host;port=3306;user id=username;password=pass;database=dbname;characterset=utf8mb4" 
        required 
        autofocus><?= e($_POST['db_connection_string'] ?? '') ?></textarea>
      <div class="help-text">
        Формат: <code>server=host;port=3306;user id=username;password=pass;database=dbname;characterset=utf8mb4</code>
      </div>
      
      <label class="checkbox">
        <input type="checkbox" value="1" id="remember_me" name="remember_me" checked> Запомнить меня
      </label>
      
      <button class="btn btn-lg btn-primary btn-block" type="submit" id="loginBtn">Войти</button>
    </form>
  </div>
  
  <script>
    // Валидация формы
    (function() {
      'use strict';
      
      const form = document.querySelector('.form-signin');
      if (!form) return;
      
      form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
          event.preventDefault();
          event.stopPropagation();
          return false;
        }
        
        // Показываем индикатор загрузки
        const submitBtn = document.getElementById('loginBtn');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Вход...';
        }
      }, false);
    })();
    
    // Автофокус на поле ввода
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(() => {
        const dbField = document.querySelector('textarea[name="db_connection_string"]');
        if (dbField) {
          dbField.focus();
        }
      }, 100);
    });
  </script>
</body>
</html>
