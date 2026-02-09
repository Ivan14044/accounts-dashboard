<?php
/**
 * Валидатор для проверки входных данных
 * Централизованная валидация параметров запросов
 */
require_once __DIR__ . '/ColumnMetadata.php';
require_once __DIR__ . '/Logger.php';

class Validator {
    /**
     * Валидация ID (положительное целое число)
     * 
     * @param mixed $id ID для валидации
     * @param bool $allowZero Разрешить ли ноль
     * @return int Валидированный ID
     * @throws InvalidArgumentException
     */
    public static function validateId($id, bool $allowZero = false): int {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        
        if ($id === false) {
            throw new InvalidArgumentException('Invalid ID format');
        }
        
        if (!$allowZero && $id <= 0) {
            throw new InvalidArgumentException('ID must be positive');
        }
        
        if ($allowZero && $id < 0) {
            throw new InvalidArgumentException('ID must be non-negative');
        }
        
        return $id;
    }
    
    /**
     * Валидация массива ID
     * 
     * @param mixed $ids Массив ID или строка с разделителями
     * @param int $maxCount Максимальное количество ID
     * @return array Массив валидированных ID
     * @throws InvalidArgumentException
     */
    public static function validateIds($ids, int $maxCount = 1000): array {
        if (empty($ids)) {
            throw new InvalidArgumentException('IDs are required');
        }
        
        // Если строка, преобразуем в массив
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }
        
        if (!is_array($ids)) {
            throw new InvalidArgumentException('IDs must be an array or comma-separated string');
        }
        
        if (count($ids) > $maxCount) {
            throw new InvalidArgumentException("Maximum $maxCount IDs allowed");
        }
        
        $validIds = [];
        foreach ($ids as $id) {
            try {
                $validIds[] = self::validateId($id);
            } catch (InvalidArgumentException $e) {
                Logger::warning('Invalid ID in array', ['id' => $id, 'error' => $e->getMessage()]);
                // Пропускаем невалидные ID, но продолжаем обработку
            }
        }
        
        if (empty($validIds)) {
            throw new InvalidArgumentException('No valid IDs found');
        }
        
        return array_unique($validIds);
    }
    
    /**
     * Валидация имени поля (whitelist колонок)
     * 
     * @param string $field Имя поля
     * @param array $allowedColumns Whitelist разрешенных колонок
     * @return string Валидированное имя поля
     * @throws InvalidArgumentException
     */
    public static function validateField(string $field, array $allowedColumns): string {
        $field = trim($field);
        
        if (empty($field)) {
            throw new InvalidArgumentException('Field name is required');
        }
        
        // Запрещенные поля
        $forbiddenFields = ['id'];
        if (in_array($field, $forbiddenFields, true)) {
            throw new InvalidArgumentException("Field '$field' is read-only");
        }
        
        // Проверка whitelist
        if (!in_array($field, $allowedColumns, true)) {
            throw new InvalidArgumentException("Field '$field' is not allowed");
        }
        
        return $field;
    }
    
    /**
     * Валидация статуса
     * 
     * @param string $status Статус для валидации
     * @param int $maxLength Максимальная длина
     * @return string Валидированный статус
     * @throws InvalidArgumentException
     */
    public static function validateStatus(string $status, int $maxLength = 100): string {
        $status = trim($status);
        
        if (empty($status)) {
            throw new InvalidArgumentException('Status cannot be empty');
        }
        
        if (strlen($status) > $maxLength) {
            throw new InvalidArgumentException("Status must not exceed $maxLength characters");
        }
        
        // Разрешаем буквы (включая кириллицу), цифры, подчеркивания, дефисы и пробелы
        // Используем \p{L} для поддержки Unicode букв (латиница, кириллица и др.)
        if (!preg_match('/^[\p{L}0-9_\-\s]+$/u', $status)) {
            throw new InvalidArgumentException('Status contains invalid characters. Only letters (including Cyrillic), numbers, underscores, hyphens and spaces are allowed');
        }
        
        return $status;
    }
    
    /**
     * Валидация параметров пагинации
     * 
     * @param int $page Номер страницы
     * @param int $perPage Записей на странице
     * @param int $maxPerPage Максимальное количество записей на странице
     * @return array ['page' => int, 'perPage' => int]
     * @throws InvalidArgumentException
     */
    public static function validatePagination(int $page = 1, int $perPage = 100, int $maxPerPage = 1000): array {
        $page = filter_var($page, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        
        if ($page === false) {
            $page = 1;
        }
        
        $perPage = filter_var($perPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $maxPerPage]
        ]);
        
        if ($perPage === false) {
            $perPage = 100;
        }
        
        return [
            'page' => $page,
            'perPage' => $perPage
        ];
    }
    
    /**
     * Валидация параметров сортировки
     * 
     * @param string $sort Колонка для сортировки
     * @param string $dir Направление сортировки
     * @param array $allowedColumns Whitelist разрешенных колонок
     * @return array ['sort' => string, 'dir' => string]
     * @throws InvalidArgumentException
     */
    public static function validateSort(string $sort, string $dir, array $allowedColumns): array {
        // Валидация колонки
        if (!in_array($sort, $allowedColumns, true)) {
            $sort = 'id'; // Значение по умолчанию
        }
        
        // Валидация направления
        $dir = strtoupper(trim($dir));
        if ($dir !== 'DESC' && $dir !== 'ASC') {
            $dir = 'ASC';
        }
        
        return [
            'sort' => $sort,
            'dir' => $dir
        ];
    }
    
    /**
     * Валидация CSRF токена
     * 
     * @param string $token CSRF токен
     * @return bool true если токен валиден
     * @throws InvalidArgumentException
     */
    public static function validateCsrfToken(string $token): bool {
        if (empty($token)) {
            throw new InvalidArgumentException('CSRF token is required');
        }
        
        if (!function_exists('verifyCsrfToken')) {
            Logger::warning('verifyCsrfToken function not found');
            return false;
        }
        
        return verifyCsrfToken($token);
    }
    
    /**
     * Валидация строки поиска
     * 
     * @param string $query Строка поиска
     * @param int $maxLength Максимальная длина
     * @return string Валидированная строка поиска
     */
    public static function validateSearchQuery(string $query, int $maxLength = 500): string {
        $query = trim($query);
        
        if (strlen($query) > $maxLength) {
            $query = substr($query, 0, $maxLength);
        }
        
        return $query;
    }
    
    /**
     * Валидация числового диапазона
     * 
     * @param mixed $from Начальное значение
     * @param mixed $to Конечное значение
     * @param int|null $min Минимальное значение
     * @param int|null $max Максимальное значение
     * @return array ['from' => int|null, 'to' => int|null]
     * @throws InvalidArgumentException
     */
    public static function validateRange($from, $to, ?int $min = null, ?int $max = null): array {
        $fromValue = null;
        $toValue = null;
        
        if ($from !== null && $from !== '') {
            $fromValue = filter_var($from, FILTER_VALIDATE_INT);
            if ($fromValue === false) {
                throw new InvalidArgumentException('Invalid range from value');
            }
            if ($min !== null && $fromValue < $min) {
                throw new InvalidArgumentException("Range from value must be at least $min");
            }
            if ($max !== null && $fromValue > $max) {
                throw new InvalidArgumentException("Range from value must not exceed $max");
            }
        }
        
        if ($to !== null && $to !== '') {
            $toValue = filter_var($to, FILTER_VALIDATE_INT);
            if ($toValue === false) {
                throw new InvalidArgumentException('Invalid range to value');
            }
            if ($min !== null && $toValue < $min) {
                throw new InvalidArgumentException("Range to value must be at least $min");
            }
            if ($max !== null && $toValue > $max) {
                throw new InvalidArgumentException("Range to value must not exceed $max");
            }
        }
        
        // Проверка логики диапазона
        if ($fromValue !== null && $toValue !== null && $fromValue > $toValue) {
            throw new InvalidArgumentException('Range from value must be less than or equal to to value');
        }
        
        return [
            'from' => $fromValue,
            'to' => $toValue
        ];
    }
    
    /**
     * Валидация булевого параметра
     * 
     * @param mixed $value Значение для валидации
     * @return bool
     */
    public static function validateBoolean($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }
        
        if (is_int($value)) {
            return $value !== 0;
        }
        
        return (bool)$value;
    }
}












