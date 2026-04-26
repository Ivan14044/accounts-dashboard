<?php
/**
 * Страница корзины (Trash)
 * Показывает удалённые аккаунты (Soft Delete)
 * Позволяет восстановить или окончательно удалить
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/AccountsService.php';
require_once __DIR__ . '/includes/Utils.php';
require_once __DIR__ . '/includes/RequestHandler.php';
require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/ErrorHandler.php';

// Для отладки - проверим, что файл выполняется
if (!defined('TRASH_PHP_LOADED')) {
    define('TRASH_PHP_LOADED', true);
}

// Инициализация переменных по умолчанию
$q = '';
$rows = [];
$deletedCount = 0;
$filteredTotal = 0;
$page = 1;
$pages = 1;
$perPage = 100;
$sort = 'deleted_at';
$dir = 'DESC';
$ALL_COLUMNS = [];
$NUMERIC_COLS = [];
$LONG_FIELDS = ['cookies', 'first_cookie', 'token', 'user_agent', 'social_url'];
$meta = ['all' => [], 'columns' => [], 'numeric' => []];

try {
    requireAuth();
    checkSessionTimeout();
    
    $service = new AccountsService($tableName);
    
    // Проверяем, поддерживается ли Soft Delete
    $meta = $service->getColumnMetadata();
    $supportsSoftDelete = in_array('deleted_at', $meta['all'], true);
    
    if (!$supportsSoftDelete) {
        $errorMessage = 'Soft Delete не поддерживается. Поле deleted_at не существует в таблице accounts.';
        throw new Exception($errorMessage);
    }
    
    // Получаем параметры поиска
    $q = get_param('q', '');
    
    // Получаем фильтр из GET-параметров (для поиска в корзине)
    $filter = $service->createFilterFromRequest($_GET);
    
    // Добавляем фильтр по deleted_at для показа только удалённых
    $filter->addDeletedOnly();
    
    // Пагинация
    require_once __DIR__ . '/includes/RequestHandler.php';
    require_once __DIR__ . '/includes/Config.php';
    $paginationParams = RequestHandler::getPaginationParams();
    $page = $paginationParams['page'];
    $perPage = $paginationParams['perPage'];
    
    // Сортировка
    $defaultSort = 'deleted_at';
    
    // Проверяем, доступна ли сортировка по deleted_at
    if (!in_array('deleted_at', $meta['all'], true)) {
        $defaultSort = 'id'; // Fallback на id, если deleted_at не существует
    }
    
    if (isset($meta['all']) && is_array($meta['all'])) {
        $sortParams = RequestHandler::getSortParams($meta['all']);
        $sort = $sortParams['sort'];
        $dir = $sortParams['dir'];
        
        // Валидация сортировки - если deleted_at недоступен, используем id
        if ($sort === 'deleted_at' && !in_array('deleted_at', $meta['all'], true)) {
            $sort = 'id';
        }
    } else {
        $sort = get_param('sort', $defaultSort);
        $dir = strtolower(get_param('dir', 'desc')) === 'desc' ? 'DESC' : 'ASC';
    }
    
    // Получаем количество удалённых записей (с фильтрами)
    $filteredTotal = $service->getAccountsCount($filter, true);
    
    // Корректируем страницу
    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    
    if ($filteredTotal > 0) {
        $page = min(max(1, $page), $pages);
    } else {
        $page = 1;
    }
    
    $offset = ($page - 1) * $perPage;
    
    // Получаем данные таблицы (включая удалённые)
    $rows = $service->getAccounts($filter, $sort, $dir, $perPage, $offset, true);
    
    // Метаданные колонок
    $ALL_COLUMNS = $meta['columns'];
    $NUMERIC_COLS = $meta['numeric'];
    
    // Подсчитываем количество удалённых
    $deletedCount = $filteredTotal;
    
} catch (Throwable $e) {
    Logger::error('FATAL ERROR in trash.php', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    // Показываем ошибку, но не останавливаем выполнение
    if (!isset($errorMessage)) {
        $errorMessage = $e->getMessage();
    }
    
    // Убедимся, что все переменные инициализированы
    if (!isset($supportsSoftDelete)) {
        $supportsSoftDelete = false;
    }
    
    // Если это критическая ошибка (нет подключения к БД), выводим страницу ошибки
    if (strpos($e->getMessage(), 'Database connection') !== false || 
        strpos($e->getMessage(), 'not initialized') !== false) {
        ErrorHandler::handleException($e);
        exit;
    }
}

// Подключаем шаблон
// Используем require (не require_once) для гарантии загрузки
try {
    require __DIR__ . '/templates/trash.php';
} catch (Throwable $e) {
    // Если шаблон не загрузился, выводим простую страницу ошибки
    Logger::error('Template load error in trash.php', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
    echo '<h1>Ошибка загрузки шаблона</h1>';
    echo '<p>Произошла ошибка при загрузке страницы корзины.</p>';
    echo '<p><strong>Ошибка:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="index.php">Вернуться к дашборду</a></p>';
    if (ini_get('display_errors')) {
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
    echo '</body></html>';
    exit;
}

