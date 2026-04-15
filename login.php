<?php
/**
 * login.php — страница авторизации
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after {
      box-sizing: border-box;
    }

    :root {
      --brand-primary: #6366f1;
      --brand-secondary: #8b5cf6;
      --brand-accent: #ec4899;
      --bg-dark: #09090b;
    }

    body {
      margin: 0;
      padding: 0;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: var(--bg-dark);
      background-image: 
        radial-gradient(circle at 15% 50%, rgba(99, 102, 241, 0.15), transparent 25%),
        radial-gradient(circle at 85% 30%, rgba(139, 92, 246, 0.15), transparent 25%);
      position: relative;
      overflow: hidden;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      color: #fafafa;
    }

    /* Анимированный Mesh-градиент на фоне */
    body::before, body::after {
      content: '';
      position: absolute;
      width: 60vw;
      height: 60vw;
      border-radius: 50%;
      filter: blur(80px);
      z-index: -1;
      opacity: 0.5;
      animation: float 20s infinite alternate ease-in-out;
    }

    body::before {
      background: radial-gradient(circle, rgba(99,102,241,0.2) 0%, transparent 70%);
      top: -20%;
      left: -10%;
    }

    body::after {
      background: radial-gradient(circle, rgba(236,72,153,0.15) 0%, transparent 70%);
      bottom: -20%;
      right: -10%;
      animation-delay: -10s;
      animation-duration: 25s;
    }

    @keyframes float {
      0% { transform: translate(0, 0) scale(1); }
      50% { transform: translate(5%, 5%) scale(1.1); }
      100% { transform: translate(-5%, -5%) scale(0.9); }
    }

    /* Контейнер карточки */
    .wrapper {
      position: relative;
      z-index: 10;
      padding: 24px;
      width: 100%;
      max-width: 480px;
      perspective: 1000px;
    }

    /* Появление формы */
    .form-signin {
      background: rgba(24, 24, 27, 0.65); /* zinc-900 / 65% */
      backdrop-filter: blur(32px);
      -webkit-backdrop-filter: blur(32px);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 24px;
      padding: 48px 40px 40px;
      box-shadow: 
        0 4px 6px -1px rgba(0, 0, 0, 0.1), 
        0 24px 48px -12px rgba(0, 0, 0, 0.5),
        inset 0 1px 1px rgba(255, 255, 255, 0.1);
      animation: cardIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      opacity: 0;
      transform: translateY(30px) scale(0.95);
    }

    @keyframes cardIn {
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    /* Логотип */
    .form-brand {
      display: flex;
      justify-content: center;
      margin-bottom: 24px;
    }

    .form-brand-icon {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 
        0 8px 16px rgba(99, 102, 241, 0.3),
        inset 0 2px 2px rgba(255,255,255,0.4);
      position: relative;
    }

    .form-brand-icon::after {
      content: '';
      position: absolute;
      inset: -2px;
      border-radius: 18px;
      background: linear-gradient(135deg, var(--brand-accent), transparent, var(--brand-primary));
      z-index: -1;
      opacity: 0.5;
      filter: blur(8px);
    }

    .form-brand-icon svg {
      width: 28px;
      height: 28px;
      fill: none;
      stroke: #ffffff;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
      filter: drop-shadow(0 2px 2px rgba(0,0,0,0.2));
    }

    /* Заголовки */
    .form-signin-heading {
      margin: 0 0 8px;
      text-align: center;
      font-size: 28px;
      font-weight: 800;
      letter-spacing: -0.03em;
      background: linear-gradient(180deg, #ffffff 0%, #a1a1aa 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .form-signin-subtitle {
      text-align: center;
      font-size: 15px;
      color: #a1a1aa; /* zinc-400 */
      margin-bottom: 32px;
      line-height: 1.5;
    }

    /* Оповещения */
    .alert {
      padding: 14px 16px;
      margin-bottom: 24px;
      border-radius: 12px;
      font-size: 13px;
      font-weight: 500;
      line-height: 1.5;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      animation: slideIn 0.3s ease-out forwards;
    }

    @keyframes slideIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .alert-danger {
      color: #fca5a5;
      background: rgba(220, 38, 38, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .alert-success {
      color: #86efac;
      background: rgba(5, 150, 105, 0.1);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    /* Инпуты */
    .form-group {
      margin-bottom: 24px;
    }

    .form-label-login {
      display: block;
      font-size: 13px;
      font-weight: 500;
      color: #d4d4d8; /* zinc-300 */
      margin-bottom: 8px;
    }

    .form-control {
      width: 100%;
      padding: 14px 16px;
      font-size: 15px;
      font-family: inherit;
      color: #f4f4f5;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      outline: none;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      line-height: 1.5;
    }

    .form-control::placeholder {
      color: #71717a; /* zinc-500 */
    }

    .form-control:hover {
      background: rgba(255, 255, 255, 0.05);
      border-color: rgba(255, 255, 255, 0.15);
    }

    .form-control:focus {
      background: rgba(255, 255, 255, 0.05);
      border-color: var(--brand-primary);
      box-shadow: 
        0 0 0 3px rgba(99, 102, 241, 0.2),
        inset 0 1px 1px rgba(255,255,255,0.05);
      transform: translateY(-1px);
    }

    textarea.form-control {
      min-height: 120px;
      resize: vertical;
      font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
      font-size: 13px;
      line-height: 1.7;
    }

    /* Help text */
    .help-text {
      font-size: 12px;
      color: #a1a1aa;
      margin-top: 10px;
      line-height: 1.5;
    }

    .help-text code {
      display: block;
      margin-top: 8px;
      padding: 12px;
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid rgba(255, 255, 255, 0.05);
      border-radius: 8px;
      font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
      font-size: 11px;
      color: #93c5fd; /* blue-300 */
      word-break: break-all;
    }

    /* Чекбокс */
    .checkbox {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 32px;
      font-size: 14px;
      color: #d4d4d8;
      cursor: pointer;
      user-select: none;
    }

    .checkbox input[type="checkbox"] {
      appearance: none;
      -webkit-appearance: none;
      width: 20px;
      height: 20px;
      border: 1.5px solid rgba(255, 255, 255, 0.2);
      border-radius: 6px;
      background: rgba(255, 255, 255, 0.03);
      cursor: pointer;
      position: relative;
      flex-shrink: 0;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .checkbox input[type="checkbox"]:checked {
      background: var(--brand-primary);
      border-color: var(--brand-primary);
      box-shadow: 0 2px 8px rgba(99, 102, 241, 0.4);
    }

    .checkbox input[type="checkbox"]:checked::after {
      content: '';
      position: absolute;
      top: 45%; left: 50%;
      width: 5px; height: 10px;
      border: solid white;
      border-width: 0 2px 2px 0;
      transform: translate(-50%, -50%) rotate(45deg);
    }

    .checkbox input[type="checkbox"]:focus {
      outline: none;
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    /* Кнопка */
    .btn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      padding: 14px 28px;
      font-size: 16px;
      font-weight: 600;
      font-family: inherit;
      color: #ffffff;
      cursor: pointer;
      border: none;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
      box-shadow: 
        0 4px 12px rgba(99, 102, 241, 0.3),
        inset 0 1px 1px rgba(255, 255, 255, 0.2);
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 0; left: -100%;
      width: 50%; height: 100%;
      background: linear-gradient(to right, transparent, rgba(255,255,255,0.2), transparent);
      transform: skewX(-20deg);
      transition: 0.5s;
    }

    .btn:hover::before {
      left: 150%;
    }

    .btn:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 
        0 8px 20px rgba(99, 102, 241, 0.4),
        0 0 20px rgba(139, 92, 246, 0.2),
        inset 0 1px 1px rgba(255, 255, 255, 0.3);
    }

    .btn:active:not(:disabled) {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(99, 102, 241, 0.4);
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      filter: grayscale(0.5);
    }

    /* Кнопка наверх для мелких экранов */
    @media (max-width: 480px) {
      .wrapper {
        padding: 16px;
      }
      .form-signin {
        padding: 32px 24px 28px;
        border-radius: 20px;
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
      }
      .form-signin-heading {
        font-size: 24px;
      }
      textarea.form-control {
        min-height: 100px;
        font-size: 12px;
      }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <form class="form-signin" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
      
      <div class="form-brand">
        <div class="form-brand-icon">
          <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        </div>
      </div>
      
      <h2 class="form-signin-heading">Вход в систему</h2>
      <p class="form-signin-subtitle">Подключение к базе данных аккаунтов</p>
      
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
      
      <div class="form-group">
        <label class="form-label-login" for="db_connection_string">Строка подключения</label>
        <textarea 
          class="form-control" 
          name="db_connection_string" 
          id="db_connection_string"
          placeholder="server=host;port=3306;user id=username;password=pass;database=dbname" 
          required 
          autofocus><?= e($_POST['db_connection_string'] ?? '') ?></textarea>
        <div class="help-text">
          Формат: <code>server=host;port=3306;user id=username;password=pass;database=dbname;characterset=utf8mb4</code>
        </div>
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
          submitBtn.innerHTML = '<span style="display:inline-block;width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;margin-right:8px;vertical-align:middle"></span>Вход...';
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
  <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
</body>
</html>
