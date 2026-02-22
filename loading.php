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
  <title>Загрузка дашборда — Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f0f2f5;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      color: #333;
    }
    .loading-box {
      text-align: center;
      padding: 2rem 3rem;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    }
    .spinner {
      width: 48px;
      height: 48px;
      margin: 0 auto 1.5rem;
      border: 4px solid #e8eaed;
      border-top-color: #337ab7;
      border-radius: 50%;
      animation: spin 0.9s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .loading-box h1 { font-size: 1.25rem; font-weight: 500; margin: 0 0 0.5rem; }
    .loading-box p  { font-size: 0.9rem; color: #666; margin: 0; }
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
