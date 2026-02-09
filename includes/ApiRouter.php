<?php
/**
 * Единый API роутер
 * Объединяет все API endpoints в одну точку входа
 * 
 * Использование:
 * $router = new ApiRouter();
 * $router->get('/accounts/count', 'AccountsController::count');
 * $router->post('/accounts', 'AccountsController::create');
 * $router->dispatch();
 */
require_once __DIR__ . '/Utils.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/ErrorHandler.php';
require_once __DIR__ . '/ResponseHeaders.php';

class ApiRouter {
    private $routes = [];
    private $middleware = [];
    
    /**
     * Регистрация маршрута
     * 
     * @param string $method HTTP метод (GET, POST, PUT, DELETE)
     * @param string $path Путь маршрута (например, '/accounts')
     * @param callable|string $handler Обработчик (функция или строка 'Controller::method')
     */
    public function addRoute(string $method, string $path, $handler): void {
        $method = strtoupper($method);
        if (!isset($this->routes[$method])) {
            $this->routes[$method] = [];
        }
        $this->routes[$method][$path] = $handler;
    }
    
    /**
     * Регистрация GET маршрута
     */
    public function get(string $path, $handler): void {
        $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * Регистрация POST маршрута
     */
    public function post(string $path, $handler): void {
        $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * Регистрация PUT маршрута
     */
    public function put(string $path, $handler): void {
        $this->addRoute('PUT', $path, $handler);
    }
    
    /**
     * Регистрация DELETE маршрута
     */
    public function delete(string $path, $handler): void {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    /**
     * Добавление middleware
     */
    public function addMiddleware(callable $middleware): void {
        $this->middleware[] = $middleware;
    }
    
    /**
     * Получение пути из запроса
     */
    private function getPath(): string {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($path, PHP_URL_PATH);
        
        // Убираем базовый путь API
        $basePath = '/api';
        if (strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        
        // Убираем начальный и конечный слэш
        $path = trim($path, '/');
        return '/' . $path;
    }
    
    /**
     * Поиск маршрута
     */
    private function findRoute(string $method, string $path): ?array {
        $method = strtoupper($method);
        
        if (!isset($this->routes[$method])) {
            return null;
        }
        
        // Точное совпадение
        if (isset($this->routes[$method][$path])) {
            return ['handler' => $this->routes[$method][$path], 'params' => []];
        }
        
        // Поиск с параметрами (например, /accounts/:id)
        foreach ($this->routes[$method] as $routePath => $handler) {
            $pattern = '#^' . preg_replace('#:[a-zA-Z0-9_]+#', '([^/]+)', $routePath) . '$#';
            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches); // Убираем полное совпадение
                return ['handler' => $handler, 'params' => $matches];
            }
        }
        
        return null;
    }
    
    /**
     * Вызов обработчика
     */
    private function callHandler($handler, array $params = []): void {
        if (is_string($handler) && strpos($handler, '::') !== false) {
            // Вызов метода контроллера
            list($controller, $method) = explode('::', $handler, 2);
            $controllerFile = __DIR__ . '/controllers/' . $controller . '.php';
            
            if (file_exists($controllerFile)) {
                require_once $controllerFile;
                if (class_exists($controller) && method_exists($controller, $method)) {
                    $instance = new $controller();
                    call_user_func_array([$instance, $method], $params);
                    return;
                }
            }
            
            throw new RuntimeException("Controller {$controller}::{$method} not found");
        } elseif (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }
        
        throw new RuntimeException("Invalid handler");
    }
    
    /**
     * Обработка запроса
     */
    public function dispatch(): void {
        try {
            // Устанавливаем заголовки
            ResponseHeaders::setJsonHeaders();
            
            // Выполняем middleware
            foreach ($this->middleware as $middleware) {
                $result = $middleware();
                if ($result === false) {
                    return; // Middleware остановил выполнение
                }
            }
            
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = $this->getPath();
            
            // Поиск маршрута
            $route = $this->findRoute($method, $path);
            
            if (!$route) {
                json_error('Route not found', 404);
                return;
            }
            
            // Вызов обработчика
            $this->callHandler($route['handler'], $route['params']);
            
        } catch (Throwable $e) {
            ErrorHandler::handleError($e, 'API Router', 500);
        }
    }
}
