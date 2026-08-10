<?php
/**
 * login.php — страница авторизации
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/RateLimitMiddleware.php';
// Config нужен и на GET: страница показывает реальные лимиты входа, а не
// переписанные руками числа (ensureDirectories() внутри идемпотентен).
require_once __DIR__ . '/includes/Config.php';

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

// Срок «запомнить меня» берём из константы, а не пишем руками: иначе подпись
// у переключателя начнёт врать в первый же день, когда срок поменяют.
// Настройки безопасности (лимит попыток, длина обычной сессии) на странице
// намеренно НЕ показываются: постороннему они подсказка, своему — шум.
$rememberDays = class_exists('SessionManager') ? (int) round(SessionManager::REMEMBER_ME_LIFETIME / 86400) : 30;

/**
 * Склонение существительного при числе: 1 день / 2 дня / 5 дней.
 *
 * @param int    $n     число
 * @param string $one   форма для 1
 * @param string $few   форма для 2–4
 * @param string $many  форма для 5+ и 11–14
 * @return string только слово, без самого числа
 */
function loginPlural($n, $one, $few, $many) {
    $n = abs((int) $n) % 100;
    if ($n >= 11 && $n <= 14) return $many;
    $n = $n % 10;
    if ($n === 1) return $one;
    if ($n >= 2 && $n <= 4) return $few;
    return $many;
}

$daysLabel = $rememberDays . ' ' . loginPlural($rememberDays, 'день', 'дня', 'дней');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex, nofollow">
  <title>Вход — Accounts Dashboard</title>

  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="alternate icon" href="assets/favicon.svg">

  <!-- Тема выставляется ДО первой отрисовки: иначе тёмная тема мигает белым.
       Ключ localStorage тот же, что у дашборда, — выбор переносится внутрь. -->
  <script>
    (function () {
      try {
        var t = localStorage.getItem('dashboard-theme');
        if (t !== 'dark' && t !== 'light') {
          t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-bs-theme', t);
      } catch (e) {
        document.documentElement.setAttribute('data-bs-theme', 'light');
      }
    })();
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=JetBrains+Mono:wght@400;500&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link href="assets/css/login.css?v=<?= e($assetV) ?>" rel="stylesheet">

  <noscript>
    <style>
      /* Без JS показания разбора и кнопки-переключатели бесполезны — убираем,
         а подсказку по формату, наоборот, раскрываем сразу. */
      .readout, .ghost-btn, .hint__toggle { display: none !important; }
      .hint__body { display: block !important; }
      .theme-toggle { display: none !important; }
    </style>
  </noscript>
</head>
<body>

<button type="button" id="themeToggle" class="theme-toggle" aria-pressed="false" aria-label="Переключить тему" title="Тёмная тема">
  <svg data-theme-icon="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
  </svg>
  <svg data-theme-icon="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none">
    <circle cx="12" cy="12" r="4"/>
    <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
  </svg>
</button>

<div class="auth">

  <main class="auth__inner">

      <div class="brand reveal" style="--d:0">
        <span class="brand__mark" aria-hidden="true">
          <svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-linecap="round">
            <circle cx="20" cy="20" r="18" stroke-width="1"/>
            <path d="M11 14h18M11 20h13M11 26h8" stroke-width="1.6"/>
            <circle cx="28.5" cy="26" r="1.6" fill="currentColor" stroke="none"/>
          </svg>
        </span>
        <span class="brand__name">Accounts Dashboard</span>
      </div>

      <h1 class="title reveal" style="--d:1">Вход в панель</h1>
      <p class="subtitle reveal" style="--d:2">Подключитесь к базе данных аккаунтов строкой подключения.</p>

      <?php if ($message): ?>
        <div class="note note--ok reveal" style="--d:3" role="status">
          <!-- Нейтральная «i», а не галочка: через этот же блок приходит
               «время сессии истекло», и галочка там читалась бы как успех. -->
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8v.2"/>
          </svg>
          <span><?= e($message) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="note note--error reveal" style="--d:3" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16.2v.2"/>
          </svg>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form id="loginForm" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

        <div class="field reveal" style="--d:4">
          <div class="field__head">
            <label class="field__label" for="db_connection_string">Строка подключения</label>
            <button type="button" id="maskToggle" class="ghost-btn" aria-pressed="false" aria-controls="db_connection_string">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/>
              </svg>
              <span data-mask-label>Скрыть</span>
            </button>
          </div>

          <div class="field__box">
            <textarea
              class="field__input"
              id="db_connection_string"
              name="db_connection_string"
              rows="4"
              spellcheck="false"
              autocapitalize="off"
              autocomplete="off"
              autocorrect="off"
              placeholder="server=host;port=3306;user id=имя;password=пароль;database=база"
              aria-describedby="connHint"
              required
              autofocus><?= e($_POST['db_connection_string'] ?? '') ?></textarea>

            <!-- Показания: как сервер поймёт строку. Пароль здесь не выводится. -->
            <div class="readout">
              <div class="readout__cell" data-readout="host">
                <span class="readout__key">Хост</span>
                <span class="readout__val">—</span>
              </div>
              <div class="readout__cell" data-readout="port">
                <span class="readout__key">Порт</span>
                <span class="readout__val">—</span>
              </div>
              <div class="readout__cell" data-readout="database">
                <span class="readout__key">База</span>
                <span class="readout__val">—</span>
              </div>
              <div class="readout__cell" data-readout="user">
                <span class="readout__key">Пользователь</span>
                <span class="readout__val">—</span>
              </div>
            </div>
          </div>

          <!-- Ключи, которых сервер не знает: он их молча пропустит, а человек
               увидит «Неверный формат» и не поймёт, из-за чего. -->
          <p class="field__warn" id="connUnknown" hidden></p>
        </div>

        <div class="hint reveal" style="--d:5" id="connHint">
          <button type="button" id="hintToggle" class="hint__toggle" aria-expanded="false" aria-controls="hintBody">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M9 6l6 6-6 6"/>
            </svg>
            Формат строки
          </button>
          <div class="hint__body" id="hintBody">
            <code class="hint__code"><b>server</b>=host;<b>port</b>=3306;<b>user id</b>=имя;<b>password</b>=пароль;<b>database</b>=база;<b>characterset</b>=utf8mb4</code>
            <p class="hint__note">Обязательны <b>server</b>, <b>user id</b> и <b>database</b>. Порт по умолчанию — 3306, кодировка — utf8mb4.</p>
          </div>
        </div>

        <label class="remember reveal" style="--d:6">
          <input type="checkbox" value="1" id="remember_me" name="remember_me" checked>
          <span class="switch" aria-hidden="true"></span>
          <span class="remember__text">
            Запомнить меня
            <span class="remember__hint" data-remember-hint
                  data-on="Вход сохранится на <?= e($daysLabel) ?>"
                  data-off="Только до конца сеанса">Вход сохранится на <?= e($daysLabel) ?></span>
          </span>
        </label>

        <button type="submit" id="submitBtn" class="submit reveal" style="--d:7">
          Войти
          <svg class="submit__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 12h15M13 6l6 6-6 6"/>
          </svg>
        </button>
      </form>

  </main>
</div>

<script src="assets/js/login.js?v=<?= e($assetV) ?>" defer></script>
</body>
</html>
