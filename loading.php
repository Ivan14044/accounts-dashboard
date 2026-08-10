<?php
/**
 * Промежуточная страница после входа: сразу показывает «Загрузка дашборда…»,
 * в фоне запрашивает index.php и подменяет документ по готовности.
 * Уменьшает ощущение «зависания» при долгой первой загрузке.
 */

require_once __DIR__ . '/auth.php';

if (!isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . '://' . $host . rtrim($scriptDir, '/');
// Передаём GET-параметры в index.php (фильтры, страница и т.д.) — тяжёлая загрузка идёт в фоне
$queryString = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
$indexUrl = $baseUrl . '/index.php' . $queryString;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    (function(){try{var t=localStorage.getItem('dashboard-theme');
      if(!t){t=(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}
      document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
  </script>
  <title>Загрузка дашборда — Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400&family=Manrope:wght@400;500&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      /* Экран-заставка не подключает общий CSS (он висит на fetch к index.php
         и должен появиться мгновенно), поэтому палитра редизайна повторена
         здесь значениями. Меняешь токены — поправь и тут. */
      background: #F2F1EE;
      font-family: "Manrope", -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      color: #131416;
    }
    .loading-box {
      text-align: center;
      width: 100%;
      max-width: 420px;
      padding: 2rem 3rem;
      background: #FFFFFF;
      border: 1px solid rgba(19, 20, 22, 0.10);
      border-radius: 14px;
      box-shadow: 0 14px 34px -16px rgba(19, 20, 22, 0.14);
    }
    @media (max-width: 480px) {
      .loading-box { padding: 1.75rem 1.25rem; }
    }
    .spinner {
      width: 48px;
      height: 48px;
      margin: 0 auto 1.5rem;
      border: 3px solid rgba(19, 20, 22, 0.10);
      border-top-color: #131416;
      border-radius: 50%;
      animation: spin 0.9s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .loading-box h1 {
      font-family: "Cormorant Garamond", "Times New Roman", Georgia, serif;
      font-size: 1.7rem; font-weight: 400; margin: 0 0 0.5rem;
    }
    .loading-box p  { font-size: 0.9rem; color: #686B6F; margin: 0; }

    /* ===== Тёмная тема ===== */
    [data-bs-theme="dark"] body { background: #0C0D0F; color: #E9E9E7; }
    [data-bs-theme="dark"] .loading-box { background: #141517; border-color: rgba(233, 233, 231, 0.10); box-shadow: 0 26px 52px -26px rgba(0,0,0,0.8); }
    [data-bs-theme="dark"] .spinner { border-color: rgba(233, 233, 231, 0.10); border-top-color: #E9E9E7; }
    [data-bs-theme="dark"] .loading-box p { color: #7E838C; }
    @media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 1.6s; } }
  </style>
</head>
<body>
  <div class="loading-box">
    <div class="spinner" aria-hidden="true"></div>
    <h1>Загрузка дашборда</h1>
    <p>Подключение к базе данных и подготовка данных…</p>
  </div>
  <script>
(function() {
  var indexUrl = <?= json_encode($indexUrl) ?>;
  fetch(indexUrl, { credentials: 'same-origin', redirect: 'follow' })
    .then(function(r) { return r.text(); })
    .then(function(html) {
      document.open();
      document.write(html);
      document.close();
    })
    .catch(function() {
      window.location.href = indexUrl;
    });
})();
  </script>
</body>
</html>
