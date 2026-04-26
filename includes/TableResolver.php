<?php
/**
 * Определяет текущую рабочую таблицу и список доступных таблиц в БД.
 *
 * Используется для переключения между таблицами через GET-параметр ?table=xxx.
 * Исключает системные таблицы (history, favorites, settings, filters).
 * Валидирует имя таблицы для защиты от SQL-инъекций.
 */
class TableResolver {
    private static $instances = [];

    private $mysqli;
    private $dbName;
    private $availableTables = [];
    private $currentTable;

    /** Системные таблицы, которые не показываются в выпадающем списке */
    private static $systemTables = [
        'account_history',
        'account_favorites',
        'saved_filters',
        'user_settings',
    ];

    private function __construct($mysqli, string $dbName) {
        $this->mysqli = $mysqli;
        $this->dbName = $dbName;
        $this->discoverTables();
        $this->resolveCurrentTable();
    }

    public static function getInstance($mysqli, string $dbName): self {
        $key = md5($dbName);
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($mysqli, $dbName);
        }
        return self::$instances[$key];
    }

    /**
     * Получение списка всех пользовательских таблиц в БД
     */
    private function discoverTables(): void {
        $stmt = $this->mysqli->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        );
        if (!$stmt) {
            $this->availableTables = [];
            return;
        }
        $stmt->bind_param('s', $this->dbName);
        $stmt->execute();
        $result = $stmt->get_result();

        $tables = [];
        while ($row = $result->fetch_assoc()) {
            $name = $row['TABLE_NAME'];
            if (!in_array($name, self::$systemTables, true)) {
                $tables[] = $name;
            }
        }
        $stmt->close();

        $this->availableTables = $tables;
    }

    /**
     * Определение текущей таблицы из GET-параметра
     */
    private function resolveCurrentTable(): void {
        $requested = $_GET['table'] ?? '';

        if ($requested !== '' && $this->isValidTableName($requested) && in_array($requested, $this->availableTables, true)) {
            $this->currentTable = $requested;
        } else {
            // По умолчанию 'accounts', если она существует; иначе первая доступная
            $this->currentTable = in_array('accounts', $this->availableTables, true)
                ? 'accounts'
                : ($this->availableTables[0] ?? 'accounts');
        }
    }

    /**
     * Валидация имени таблицы (защита от SQL-инъекций)
     */
    private function isValidTableName(string $name): bool {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $name) && strlen($name) <= 64;
    }

    public function getCurrentTable(): string {
        return $this->currentTable;
    }

    public function getAvailableTables(): array {
        return $this->availableTables;
    }
}
