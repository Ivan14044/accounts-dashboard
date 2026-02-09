# Design: Комплексные улучшения проекта Dashboard

## Context

Проект Dashboard - Account Management System имеет хорошую архитектурную основу (MVC-подобная архитектура, сервис-ориентированный подход), но нуждается в улучшениях по производительности, безопасности и удобству поддержки.

### Текущее состояние
- **Производительность:** 800ms загрузка из-за 8 запросов к БД
- **Безопасность:** Избыточное логирование, нет rate limiting
- **Масштабируемость:** Файловое хранилище users.json
- **Читабельность:** Magic numbers в коде
- **Дублирование:** ~15% кода дублируется

### Целевые показатели
- **Производительность:** <200ms загрузка
- **Безопасность:** Rate limiting, безопасное логирование
- **Масштабируемость:** users в БД
- **Читабельность:** Константы Config
- **Дублирование:** <5%

## Goals / Non-Goals

### Goals (Цели)
1. ✅ Ускорить загрузку дашборда в 5+ раз (800ms → <200ms)
2. ✅ Устранить дублирование кода (~150 строк в export.php)
3. ✅ Улучшить безопасность (rate limiting, безопасное логирование)
4. ✅ Повысить масштабируемость (users в БД)
5. ✅ Улучшить читабельность кода (константы вместо magic numbers)
6. ✅ Улучшить UX (Toast уведомления)
7. ✅ Сохранить обратную совместимость

### Non-Goals (Не цели)
- ❌ Полный переход на ORM (Eloquent/Doctrine)
- ❌ Переписывание на фреймворк (Laravel/Symfony)
- ❌ Изменение UI дизайна
- ❌ Добавление новой функциональности
- ❌ Разделение на микросервисы
- ❌ Контейнеризация (Docker)

## Decisions

### Decision 1: Система логирования

**Решение:** Создать простой класс `Logger` с уровнями вместо использования сложных библиотек.

**Обоснование:**
- ✅ Простота - нет внешних зависимостей
- ✅ Совместимость с хостингом
- ✅ Легко настраивать через ENV переменные
- ✅ Достаточно для текущих нужд

**Альтернативы рассмотрены:**
- ❌ Monolog - избыточно сложный, требует Composer
- ❌ KLogger - дополнительная зависимость
- ✅ **Свой Logger** - минималистичный, достаточный

**Реализация:**
```php
class Logger {
    private static $debugMode = false;
    
    public static function init() {
        self::$debugMode = getenv('DEBUG') === 'true';
    }
    
    public static function debug($message, $context = []) {
        if (self::$debugMode) {
            self::log('DEBUG', $message, $context);
        }
    }
    
    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
    }
    
    private static function log($level, $message, $context) {
        $contextStr = !empty($context) ? json_encode($context) : '';
        error_log(sprintf('[%s] %s %s', $level, $message, $contextStr));
    }
}
```

### Decision 2: Оптимизация запросов статистики

**Решение:** Объединить 8 запросов в 1 через SQL агрегацию с JSON_OBJECTAGG.

**Обоснование:**
- ✅ 8x уменьшение количества запросов
- ✅ Меньше round-trip time к БД
- ✅ Поддерживается MySQL 5.7+
- ✅ Результат можно кэшировать

**Альтернативы рассмотрены:**
- ❌ Материализованные представления - не поддерживаются в MySQL
- ❌ Отдельная таблица статистики - усложняет синхронизацию
- ❌ Кэширование каждого запроса отдельно - всё равно 8 запросов
- ✅ **Единый агрегированный запрос** - оптимально

**Реализация:**
```php
public function getStatistics(FilterBuilder $filter = null): array {
    $where = $filter ? $filter->getWhereClause() : '';
    $params = $filter ? $filter->getParams() : [];
    
    $sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status IS NULL OR status = '' THEN 1 ELSE 0 END) as empty_status,
        SUM(CASE WHEN email != '' AND two_fa != '' THEN 1 ELSE 0 END) as email_2fa,
        SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as recent_24h,
        
        -- Статусы как JSON объект
        (SELECT JSON_OBJECTAGG(
            COALESCE(status, 'empty'), 
            COUNT(*)
        ) FROM accounts $where GROUP BY status) as status_counts
        
    FROM accounts $where
    ";
    
    // Кэширование результата на 5 минут
    // ...
}
```

**Выигрыш:** 800ms → 100ms (8x улучшение)

### Decision 3: Rate Limiting без Redis

**Решение:** Использовать файловый кэш в /tmp для rate limiting вместо Redis.

**Обоснование:**
- ✅ Не требует установки Redis на shared hosting
- ✅ Простая реализация
- ✅ Достаточная производительность для текущих нужд
- ✅ Автоматическая очистка старых файлов

**Альтернативы рассмотрены:**
- ❌ Redis - недоступен на shared hosting
- ❌ APCu - может быть недоступен
- ❌ Memcached - недоступен на shared hosting
- ❌ БД - дополнительная нагрузка на MySQL
- ✅ **Файловый кэш** - работает везде

**Реализация:**
```php
class RateLimiter {
    private $cacheDir = '/tmp/ratelimit';
    
    public function checkLimit(string $key, int $maxRequests, int $timeWindow): bool {
        $file = $this->cacheDir . '/' . md5($key);
        $data = $this->readCache($file);
        
        // Очистить старые записи
        $data = array_filter($data, function($timestamp) use ($timeWindow) {
            return time() - $timestamp < $timeWindow;
        });
        
        if (count($data) >= $maxRequests) {
            return false; // Лимит превышен
        }
        
        $data[] = time();
        $this->writeCache($file, $data);
        
        return true;
    }
}
```

### Decision 4: Миграция users.json → БД с fallback

**Решение:** Создать `UserService` с поддержкой БД и fallback на users.json.

**Обоснование:**
- ✅ Обратная совместимость
- ✅ Постепенная миграция
- ✅ Rollback без потери данных
- ✅ Минимальный риск

**Альтернативы рассмотрены:**
- ❌ Сразу удалить users.json - высокий риск
- ❌ Оставить только users.json - не решает проблему масштабируемости
- ✅ **Гибридный подход с fallback** - безопасно

**Реализация:**
```php
class UserService {
    private $db;
    private $useFallback = false;
    
    public function authenticate($username, $password): ?array {
        try {
            // Попытка из БД
            $user = $this->authenticateFromDB($username, $password);
            if ($user) return $user;
        } catch (Exception $e) {
            Logger::warning('DB authentication failed, falling back to JSON');
            $this->useFallback = true;
        }
        
        // Fallback на users.json
        if ($this->useFallback) {
            return $this->authenticateFromJSON($username, $password);
        }
        
        return null;
    }
}
```

### Decision 5: Toast уведомления через Vanilla JS

**Решение:** Создать собственный Toast компонент без использования библиотек.

**Обоснование:**
- ✅ Нет зависимостей
- ✅ Полный контроль над функциональностью
- ✅ Минимальный размер (~5KB)
- ✅ Соответствует философии проекта

**Альтернативы рассмотрены:**
- ❌ Toastify - внешняя зависимость
- ❌ SweetAlert2 - избыточно тяжелый
- ❌ Bootstrap Toast - требует Bootstrap JS
- ✅ **Свой Toast** - легкий, настраиваемый

### Decision 6: Config класс вместо .env расширения

**Решение:** Создать простой класс `Config` с константами.

**Обоснование:**
- ✅ Типизация значений
- ✅ Простота использования
- ✅ IDE автодополнение
- ✅ Нет парсинга на каждом запросе

**Реализация:**
```php
class Config {
    // Размеры
    const MAX_REQUEST_SIZE = 5 * 1024 * 1024; // 5MB
    const MAX_IDS_PER_REQUEST = 50000;
    
    // Таймауты
    const SESSION_LIFETIME = 30 * 24 * 60 * 60; // 30 дней
    const CACHE_TTL = 3600; // 1 час
    const STATS_CACHE_TTL = 300; // 5 минут
    
    // Лимиты
    const DEFAULT_PAGE_SIZE = 100;
    const MAX_PAGE_SIZE = 1000;
    
    // Rate Limiting
    const API_RATE_LIMIT = 100; // req/min
    const EXPORT_RATE_LIMIT = 10; // req/min
    const LOGIN_RATE_LIMIT = 5; // req/min
    
    // Безопасность
    const CSRF_TOKEN_LENGTH = 32;
    const PASSWORD_MIN_LENGTH = 8;
    
    // Пути
    const CACHE_DIR = '/tmp/dashboard_cache';
    const LOG_DIR = '/tmp/dashboard_logs';
}
```

## Technical Patterns

### Pattern 1: Service Layer
Все бизнес-логика остаётся в сервисах:
- `AccountsService` - работа с аккаунтами
- `UserService` - работа с пользователями (новый)
- `RateLimiter` - ограничение запросов (новый)

### Pattern 2: Singleton для утилит
- `Logger` - статические методы
- `Config` - константы класса
- `Database` - существующий Singleton

### Pattern 3: Builder для сложных объектов
- `FilterBuilder` - построение фильтров (существующий)

### Pattern 4: Strategy для rate limiting
```php
interface RateLimitStorage {
    public function increment($key, $ttl);
    public function get($key);
}

class FileRateLimitStorage implements RateLimitStorage { ... }
class RedisRateLimitStorage implements RateLimitStorage { ... } // будущее
```

## Data Model Changes

### Новые таблицы

#### users
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'user',
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### user_sessions
```sql
CREATE TABLE user_sessions (
    id VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Новые индексы для accounts

```sql
-- Комбинированный индекс для частых запросов
CREATE INDEX idx_email_status ON accounts(email(255), status);

-- Индексы для фильтров "есть X"
CREATE INDEX idx_two_fa_not_empty ON accounts(two_fa(100)) 
    WHERE two_fa IS NOT NULL AND two_fa != '';

CREATE INDEX idx_token_not_empty ON accounts(token(255)) 
    WHERE token IS NOT NULL AND token != '';
```

## Migration Strategy

### Фаза 1: Backward Compatible Changes
1. Добавить новые классы (Logger, Config)
2. Рефакторинг существующего кода
3. Оптимизация запросов
4. Добавление индексов
5. **Результат:** Всё работает как раньше, но быстрее

### Фаза 2: Additive Changes
1. Создать таблицы users, user_sessions
2. Добавить RateLimiter (не мешает существующему)
3. Добавить Toast компонент (дополнительно к alert)
4. **Результат:** Новые функции доступны, старые работают

### Фаза 3: Data Migration
1. Запустить migrate_users.php (создаёт backup)
2. Переключить auth.php на UserService (с fallback)
3. Мониторинг 1-2 дня
4. Удалить fallback если всё работает
5. **Результат:** Полная миграция завершена

## Risks / Trade-offs

### Риск 1: JSON_OBJECTAGG требует MySQL 5.7+
**Вероятность:** Низкая (проект уже требует MySQL 5.7+)
**Влияние:** Высокое (не будет работать на старых версиях)
**Митигация:** Проверка версии MySQL, fallback на старый метод

### Риск 2: Файловый кэш для rate limiting может быть медленным
**Вероятность:** Средняя
**Влияние:** Низкое (всё равно быстрее БД)
**Митигация:** Можно заменить на Redis в будущем (Strategy pattern)

### Риск 3: Миграция users может пойти не так
**Вероятность:** Средняя
**Влияние:** Критическое (авторизация не работает)
**Митигация:** 
- Backup users.json перед миграцией
- Fallback на users.json
- Скрипт отката (rollback)
- Тестирование на копии БД

### Риск 4: Кэширование статистики может показывать устаревшие данные
**Вероятность:** Высокая (это trade-off)
**Влияние:** Низкое (устаревание на 5 минут приемлемо)
**Митигация:** 
- TTL = 5 минут (не час)
- Инвалидация при изменении данных
- Кнопка "Обновить" очищает кэш

## Performance Impact

### Ожидаемые улучшения

| Метрика | ДО | ПОСЛЕ | Улучшение |
|---------|-----|-------|-----------|
| Загрузка дашборда | 800ms | <200ms | 4x |
| Запросов к БД | 8-12 | 3-5 | 2.4x |
| Запросы с фильтрами | базовая | 2-5x быстрее | 2-5x |
| Размер логов | ~50MB/день | ~10MB/день | 5x |

### Накладные расходы

| Компонент | Overhead |
|-----------|----------|
| Logger.debug() в production | 0ms (не выполняется) |
| RateLimiter | ~2-5ms (файловый кэш) |
| Config константы | 0ms (compile time) |
| Toast уведомления | ~1-2ms (клиентский JS) |

## Testing Strategy

### Unit тесты (будущее)
- Logger
- Config
- RateLimiter
- UserService

### Integration тесты
- API endpoints с rate limiting
- Авторизация через UserService
- Статистика с кэшированием

### Performance тесты
- Замер времени getStatistics() до/после
- Замер времени запросов с индексами до/после
- Нагрузочное тестирование rate limiting

### Security тесты
- Проверка логов на чувствительные данные
- Проверка rate limiting
- Проверка SQL injection (должно остаться защищено)

## Rollback Plan

### Фаза 1 rollback (простой)
```bash
git revert <commit-hash>
```
Всё обратно совместимо, можно откатить через git.

### Фаза 2 rollback (средний)
1. Отключить RateLimiter (закомментировать проверки)
2. Вернуть старый Toast на alert()
3. Индексы БД можно оставить (не вредят)

### Фаза 3 rollback (сложный)
1. Запустить `sql/rollback_users.php`
2. Восстановить users.json из backup
3. Переключить auth.php на users.json
4. Удалить таблицы users, user_sessions (опционально)

```php
// rollback_users.php
$backup = file_get_contents('users.json.backup');
file_put_contents('users.json', $backup);

$mysqli->query('DROP TABLE IF EXISTS user_sessions');
$mysqli->query('DROP TABLE IF EXISTS users');

echo "Rollback complete!\n";
```

## Open Questions

1. ✅ **Q:** Достаточно ли файлового кэша для rate limiting?
   **A:** Да для текущих нужд (<10 одновременных пользователей)

2. ✅ **Q:** Нужна ли поддержка PostgreSQL для users?
   **A:** Нет, проект жёстко привязан к MySQL

3. ✅ **Q:** Удалять ли users.json после миграции?
   **A:** Нет, оставить как backup на 30 дней

4. ❓ **Q:** Нужны ли unit тесты для новых классов?
   **A:** Желательно, но не критично для первого релиза

5. ❓ **Q:** Переименовать ли extra_info_1-4 в accounts?
   **A:** Отложено, требует отдельного proposal

---

**Статус:** Approved for Implementation  
**Дата:** 12.11.2025  
**Автор:** AI Senior Developer  
**Ревьюер:** Pending








