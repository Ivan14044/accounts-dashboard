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

// Версия ассетов (login.php не подключает config.php — отсюда guard)
$assetV = defined('ASSETS_VERSION') ? ASSETS_VERSION : (string) time();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Вход в систему — Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="alternate icon" href="assets/favicon.svg">
  <!-- Тема: выставляем data-bs-theme ДО отрисовки (no-flash), синхронно с дашбордом -->
  <script>
    (function(){try{var t=localStorage.getItem('dashboard-theme');
      if(!t){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}
      document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
  <!-- Единая токен-система (один источник правды) -->
  <link href="assets/css/core-base.css?v=<?= e($assetV) ?>" rel="stylesheet">
  <link href="assets/css/core-dark.css?v=<?= e($assetV) ?>" rel="stylesheet">
  <style>
    /* ================================================================
       LOGIN — premium minimalism on the shared token system.
       No glassmorphism, no purple→blue gradient, no animated blobs.
       ================================================================ */
    body {
      min-height: 100vh;
      margin: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: var(--gray-50);          /* light: чистый светлый нейтрал */
      color: var(--color-text);
      font-family: var(--font-family-base);
    }
    [data-bs-theme="dark"] body { background: #0A0A0F; }   /* deep, не чёрный */

    /* Переключатель темы — тихая иконка в углу */
    .login-theme-toggle {
      position: fixed;
      top: 20px; right: 20px;
      width: 40px; height: 40px;
      display: flex; align-items: center; justify-content: center;
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      background: var(--bg-primary);
      color: var(--color-text-secondary);
      cursor: pointer;
      transition: color .15s ease, border-color .15s ease, background-color .15s ease;
    }
    .login-theme-toggle:hover { color: var(--color-text); border-color: var(--color-border-hover); }
    .login-theme-toggle:active { transform: scale(0.96); }
    .login-theme-toggle:focus-visible { outline: 2px solid var(--primary-500); outline-offset: 2px; }

    /* Карточка — граница + мягкая тень, без блюра */
    .login-card {
      width: 100%;
      max-width: 440px;
      background: var(--bg-primary);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-2xl);
      padding: 40px;
      box-shadow: var(--shadow-lg);
      animation: loginIn .4s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    @keyframes loginIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

    .login-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; }
    .login-brand-mark {
      width: 44px; height: 44px;
      border-radius: var(--radius-lg);
      background: var(--primary-600);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .login-brand-mark svg {
      width: 22px; height: 22px;
      fill: none; stroke: #fff; stroke-width: 2;
      stroke-linecap: round; stroke-linejoin: round;
    }
    .login-brand-text { display: flex; flex-direction: column; line-height: 1.25; }
    .login-brand-name { font-size: var(--font-size-md); font-weight: 600; letter-spacing: -0.02em; color: var(--color-text); }
    .login-brand-sub { font-size: var(--font-size-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.06em; }

    .login-heading { font-size: var(--font-size-2xl); font-weight: 700; letter-spacing: -0.03em; color: var(--color-text); margin: 0 0 6px; }
    .login-subtitle { font-size: var(--font-size-sm); color: var(--color-text-secondary); margin: 0 0 28px; line-height: 1.5; }

    .login-field { margin-bottom: 20px; }
    .login-label { display: block; font-size: var(--font-size-sm); font-weight: 500; color: var(--color-text); margin-bottom: 8px; }
    .login-input {
      width: 100%;
      padding: 12px 14px;
      font-family: var(--font-family-mono);
      font-size: 13px; line-height: 1.6;
      color: var(--color-text);
      background: var(--bg-primary);
      border: 1.5px solid var(--color-border);
      border-radius: var(--radius-md);
      outline: none; resize: vertical; min-height: 104px;
      transition: border-color .18s ease, box-shadow .18s ease;
    }
    .login-input::placeholder { color: var(--color-text-muted); }
    .login-input:hover { border-color: var(--color-border-hover); }
    .login-input:focus { border-color: var(--primary-500); box-shadow: var(--shadow-focus); }

    .login-help { font-size: var(--font-size-xs); color: var(--color-text-muted); margin-top: 10px; line-height: 1.5; }
    .login-help code {
      display: block; margin-top: 8px; padding: 10px 12px;
      background: var(--bg-secondary);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-sm);
      font-family: var(--font-family-mono); font-size: 11px;
      color: var(--color-text-secondary); word-break: break-all;
    }

    .login-check { display: flex; align-items: center; gap: 10px; margin: 4px 0 24px; font-size: var(--font-size-sm); color: var(--color-text-secondary); cursor: pointer; user-select: none; }
    .login-check input { width: 18px; height: 18px; }

    .login-btn {
      width: 100%;
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 13px 20px;
      font-family: inherit; font-size: var(--font-size-md); font-weight: 600;
      color: #fff; background: var(--primary-600);
      border: 1px solid var(--primary-600);
      border-radius: var(--radius-md);
      cursor: pointer;
      transition: background-color .15s ease, transform .12s ease, opacity .15s ease;
    }
    .login-btn:hover:not(:disabled) { background: var(--primary-700); border-color: var(--primary-700); }
    .login-btn:active:not(:disabled) { transform: scale(0.99); }
    .login-btn:disabled { opacity: .6; cursor: not-allowed; }

    .login-alert {
      padding: 12px 14px; margin-bottom: 20px;
      border-radius: var(--radius-md);
      font-size: var(--font-size-sm); line-height: 1.5;
      border: 1px solid transparent; border-left: 3px solid;
    }
    .login-alert--error { background: var(--danger-50); border-color: var(--danger-100); border-left-color: var(--danger-500); color: var(--danger-700); }
    .login-alert--ok { background: var(--success-50); border-color: var(--success-100); border-left-color: var(--success-500); color: var(--success-700); }

    .login-spinner {
      display: inline-block; width: 16px; height: 16px;
      border: 2px solid rgba(255,255,255,0.35); border-top-color: #fff;
      border-radius: 50%; animation: loginSpin .6s linear infinite;
    }
    @keyframes loginSpin { to { transform: rotate(360deg); } }

    @media (prefers-reduced-motion: reduce) {
      .login-card { animation: none; }
      .login-theme-toggle:active, .login-btn:active { transform: none; }
    }

    @media (max-width: 480px) {
      body { padding: 16px; }
      .login-card { padding: 28px 22px; border-radius: var(--radius-xl); }
      .login-heading { font-size: var(--font-size-xl); }
      .login-theme-toggle { top: 14px; right: 14px; }
      /* iOS не зумит поле при фокусе, если шрифт ≥ 16px */
      .login-input { font-size: 16px; }
    }

    @media (max-width: 360px) {
      body { padding: 12px; }
      .login-card { padding: 22px 16px; }
      .login-brand { margin-bottom: 22px; }
    }
  </style>
</head>
<body>
  <button type="button" id="themeToggle" class="login-theme-toggle" title="Тёмная тема" aria-pressed="false" aria-label="Переключить тему">
    <i class="fas fa-moon" aria-hidden="true"></i>
  </button>

  <main class="login-card">
    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

      <div class="login-brand">
        <div class="login-brand-mark">
          <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
        </div>
        <div class="login-brand-text">
          <span class="login-brand-name">Accounts Dashboard</span>
          <span class="login-brand-sub">Панель управления</span>
        </div>
      </div>

      <h1 class="login-heading">Вход в систему</h1>
      <p class="login-subtitle">Подключение к базе данных аккаунтов</p>

      <?php if ($message): ?>
        <div class="login-alert login-alert--ok" role="alert"><?= e($message) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="login-alert login-alert--error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="login-field">
        <label class="login-label" for="db_connection_string">Строка подключения</label>
        <textarea
          class="login-input"
          name="db_connection_string"
          id="db_connection_string"
          placeholder="server=host;port=3306;user id=username;password=pass;database=dbname"
          required
          autofocus><?= e($_POST['db_connection_string'] ?? '') ?></textarea>
        <div class="login-help">
          Формат: <code>server=host;port=3306;user id=username;password=pass;database=dbname;characterset=utf8mb4</code>
        </div>
      </div>

      <label class="login-check">
        <input type="checkbox" value="1" id="remember_me" name="remember_me" checked> Запомнить меня
      </label>

      <button class="login-btn" type="submit" id="loginBtn">Войти</button>
    </form>
  </main>

  <script>
    (function () {
      'use strict';
      var form = document.querySelector('form');
      if (!form) return;
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault();
          event.stopPropagation();
          return false;
        }
        var btn = document.getElementById('loginBtn');
        if (btn) {
          btn.disabled = true;
          btn.innerHTML = '<span class="login-spinner"></span>Вход…';
        }
      }, false);

      document.addEventListener('DOMContentLoaded', function () {
        var field = document.getElementById('db_connection_string');
        if (field) setTimeout(function () { field.focus(); }, 100);
      });
    })();
  </script>
  <script src="assets/js/theme-toggle.js?v=<?= e($assetV) ?>" defer></script>
</body>
</html>
