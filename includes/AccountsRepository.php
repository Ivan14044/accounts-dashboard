<?php
/**
 * Репозиторий аккаунтов: прямой доступ к БД без бизнес-логики.
 *
 * Из-за размера операции разложены по трейтам в `includes/repositories/`:
 *   - {@see AccountsRepoSelectTrait}  — SELECT, выборка одной/многих записей, validation-проекции
 *   - {@see AccountsRepoWriteTrait}   — UPDATE статуса и произвольного поля (по ID/фильтру/всем)
 *   - {@see AccountsRepoDeleteTrait}  — Soft/Hard delete и восстановление из корзины
 *   - {@see AccountsRepoCreateTrait}  — INSERT одной записи с проверкой дубликата
 *   - {@see AccountsRepoBulkTrait}    — массовый импорт с fingerprint-индексом и savepoint-ами
 *
 * Здесь остаются только конструктор, общие свойства и помощники, нужные сразу
 * нескольким трейтам:
 *   - normalizeValueByColumnType()  — приведение значения к типу колонки
 *   - findExistingByFingerprint()   — точечная проверка дубликата для одиночного INSERT
 *
 * Публичный API класса (имена и сигнатуры методов) не меняется — трейты просто
 * физически разносят реализацию по файлам.
 *
 * @package includes
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/FilterBuilder.php';
require_once __DIR__ . '/ColumnMetadata.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/AccountFingerprint.php';
require_once __DIR__ . '/repositories/AccountsRepoSelectTrait.php';
require_once __DIR__ . '/repositories/AccountsRepoWriteTrait.php';
require_once __DIR__ . '/repositories/AccountsRepoDeleteTrait.php';
require_once __DIR__ . '/repositories/AccountsRepoCreateTrait.php';
require_once __DIR__ . '/repositories/AccountsRepoBulkTrait.php';

class AccountsRepository {
    use AccountsRepoSelectTrait;
    use AccountsRepoWriteTrait;
    use AccountsRepoDeleteTrait;
    use AccountsRepoCreateTrait;
    use AccountsRepoBulkTrait;

    private $db;
    private $table;
    private $metadata;

    public function __construct(string $table = 'accounts') {
        $this->table = $table;
        $this->db = Database::getInstance();
        $mysqli = $this->db->getConnection();
        $this->metadata = ColumnMetadata::getInstance($mysqli, $this->table);
    }

    /**
     * Нормализация значения по типу колонки.
     * Приводит значение к правильному типу на основе метаданных колонки.
     *
     * @param string $field Имя поля
     * @param mixed $value Значение для нормализации
     * @return array ['value' => mixed, 'type' => string] — нормализованное значение и тип для bind_param
     */
    /**
     * Предел длины для типа колонки: символы для VARCHAR/CHAR, байты для TEXT.
     *
     * @param string $columnType Тип колонки как его отдаёт INFORMATION_SCHEMA,
     *   например «varchar(50)» или «mediumtext»
     * @return int|null Предел или null, если ограничивать нечего
     */
    public static function maxLengthForType(string $columnType) {
        $type = strtolower(trim($columnType));

        // VARCHAR(N) / CHAR(N) — предел в СИМВОЛАХ
        if (preg_match('/^(var)?char\s*\(\s*(\d+)\s*\)/', $type, $m)) {
            return (int)$m[2];
        }

        // Семейство TEXT — предел в БАЙТАХ, длина в типе не указывается
        $textLimits = array(
            'tinytext'   => 255,
            'text'       => 65535,
            'mediumtext' => 16777215,
            'longtext'   => 4294967295,
        );
        foreach ($textLimits as $name => $limit) {
            if (strpos($type, $name) === 0) {
                return $limit;
            }
        }

        // Числа, даты и прочее по длине не ограничиваем: там свои правила,
        // и «обрезки строки» в нашем смысле не бывает.
        return null;
    }

    /**
     * Помещается ли значение в колонку такого типа.
     *
     * Зачем это вообще нужно. sql_mode приложения — без STRICT
     * (includes/Database.php), поэтому MySQL не отвергает слишком длинное
     * значение, а молча обрезает его и отдаёт Warning, которого никто не читает.
     * Проверено на стенде: статус в 60 символов в колонку VARCHAR(50) дал
     * ответ {"success":true,"affected":1} и 50 символов в базе.
     * Хуже того, такую правку потом не откатить: в account_history попадает
     * отправленное значение (60), а UndoService откатывает только при точном
     * совпадении с тем, что сейчас в БД (50) — откат уходит в skipped_conflict.
     *
     * Считаем символы для VARCHAR/CHAR и байты для TEXT — ровно как считает
     * сама MySQL. Если считать байты везде, кириллица начнёт «не помещаться»
     * в колонки, куда она на самом деле влезает.
     *
     * @param mixed $value Значение
     * @param string $columnType Тип колонки
     * @return bool false — значение будет обрезано, записывать нельзя
     */
    public static function valueFitsColumnType($value, string $columnType): bool {
        if (!is_string($value)) {
            return true;
        }
        $limit = self::maxLengthForType($columnType);
        if ($limit === null) {
            return true;
        }

        $type = strtolower(trim($columnType));
        if (preg_match('/^(var)?char/', $type)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        } else {
            $length = strlen($value); // TEXT считается в байтах
        }

        return $length <= $limit;
    }

    private function normalizeValueByColumnType(string $field, $value): array {
        $columnInfo = $this->metadata->getColumn($field);

        // Значение, которое не помещается в колонку, — это ошибка ввода, а не
        // повод тихо укоротить данные. Без STRICT sql_mode MySQL обрезал бы его
        // молча (см. докблок valueFitsColumnType).
        if ($columnInfo && isset($columnInfo['type'])
            && !self::valueFitsColumnType($value, (string)$columnInfo['type'])) {
            $limit = self::maxLengthForType((string)$columnInfo['type']);
            throw new InvalidArgumentException(
                "Значение поля «{$field}» длиннее допустимого ({$limit}). "
                . 'Сохранение отменено, чтобы значение не обрезалось молча.'
            );
        }

        if (!$columnInfo) {
            // Если метаданные недоступны, возвращаем как строку
            return ['value' => (string)$value, 'type' => 's'];
        }

        $columnType = strtolower($columnInfo['type']);
        $isNullable = $columnInfo['null'] === 'YES';
        $numericCols = $this->metadata->getNumericColumns();
        $isNumeric = in_array($field, $numericCols, true);

        // Обработка пустых значений
        if ($value === '' || $value === null) {
            if ($isNumeric) {
                // Для числовых полей: NULL если разрешено, иначе 0
                if ($isNullable) {
                    return ['value' => null, 'type' => 's']; // NULL будет обработан специально в bind_param
                } else {
                    return ['value' => 0, 'type' => 'i'];
                }
            } else {
                // Для текстовых полей: пустая строка или NULL
                if ($isNullable) {
                    return ['value' => null, 'type' => 's'];
                } else {
                    return ['value' => '', 'type' => 's'];
                }
            }
        } elseif ($value === '0' && $isNumeric) {
            // Специальная обработка для строки '0' в числовых полях
            if (preg_match('/(decimal|float|double|numeric)/', $columnType)) {
                return ['value' => 0.0, 'type' => 'd'];
            } else {
                return ['value' => 0, 'type' => 'i'];
            }
        }

        // Нормализация числовых полей
        if ($isNumeric) {
            // Проверяем, является ли значение числом или строкой с числом
            if (is_numeric($value)) {
                // Определяем тип числа на основе типа колонки
                if (preg_match('/(decimal|float|double|numeric)/', $columnType)) {
                    // Для десятичных чисел
                    $normalized = (float)$value;
                    return ['value' => $normalized, 'type' => 'd'];
                } else {
                    // Для целых чисел
                    $normalized = (int)$value;
                    return ['value' => $normalized, 'type' => 'i'];
                }
            } else {
                // Если значение не числовое, но поле числовое - пытаемся преобразовать
                // Убираем пробелы и проверяем снова
                $trimmed = trim((string)$value);
                if ($trimmed === '' || $trimmed === null) {
                    if ($isNullable) {
                        return ['value' => null, 'type' => 's'];
                    } else {
                        return ['value' => 0, 'type' => 'i'];
                    }
                }
                // Если после trim все еще не число - выбрасываем исключение
                throw new InvalidArgumentException("Value for numeric field '{$field}' must be a number, got: " . gettype($value));
            }
        }

        // Для текстовых полей просто приводим к строке
        return ['value' => (string)$value, 'type' => 's'];
    }

    /**
     * Точечная проверка на дубликат для одиночного createAccount.
     * Делает узкий SELECT по индексу login + один по id_soc_account + один по
     * c_user (если найден в cookies нового аккаунта). Не строит индекс по всей
     * БД — для одиночного создания это перебор.
     *
     * @return array{id:int, login:string, match_kind:string, match_value:string}|null
     */
    public function findExistingByFingerprint(array $row): ?array {
        $conn = $this->db->getConnection();
        $hasDeleted = $this->metadata->columnExists('deleted_at');
        $deletedClause = $hasDeleted ? ' AND deleted_at IS NULL' : '';

        // 1. Точное совпадение по login (case-insensitive — MySQL collation
        // utf8mb4_general_ci/0900_ai_ci уже делает это автоматически).
        $login = trim((string)($row['login'] ?? ''));
        if ($login !== '') {
            $sql = "SELECT id, login FROM {$this->table} WHERE login = ?{$deletedClause} LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('s', $login);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'login',
                        'match_value' => $login,
                    ];
                }
                $stmt->close();
            }
        }

        // 2. FB IDs (id_soc_account / social_url / c_user в cookies).
        $fbIds = AccountFingerprint::extractFbIds($row);
        if (empty($fbIds)) {
            return null;
        }

        $hasIdSoc     = $this->metadata->columnExists('id_soc_account');
        $hasSocialUrl = $this->metadata->columnExists('social_url');
        $hasCookies   = $this->metadata->columnExists('cookies');

        // Точные матчи по id_soc_account для каждого FB ID — один IN-запрос.
        if ($hasIdSoc) {
            $placeholders = implode(',', array_fill(0, count($fbIds), '?'));
            $sql = "SELECT id, login, id_soc_account FROM {$this->table} "
                 . "WHERE id_soc_account IN ($placeholders){$deletedClause} LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(str_repeat('s', count($fbIds)), ...$fbIds);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'FB ID (id_soc_account)',
                        'match_value' => (string)$found['id_soc_account'],
                    ];
                }
                $stmt->close();
            }
        }

        // social_url — LIKE %fbid% (на больших таблицах потребует full scan,
        // но social_url обычно short — допустимо при единичном createAccount).
        if ($hasSocialUrl) {
            foreach ($fbIds as $fbId) {
                $like = '%' . $fbId . '%';
                $sql = "SELECT id, login, social_url FROM {$this->table} "
                     . "WHERE social_url LIKE ?{$deletedClause} LIMIT 1";
                $stmt = $conn->prepare($sql);
                if (!$stmt) continue;
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'FB ID (social_url)',
                        'match_value' => $fbId,
                    ];
                }
                $stmt->close();
            }
        }

        // cookies — LIKE %c_user=<id>% по preview-области (первые 4KB).
        // Полный LONGTEXT scan убил бы перформанс на большой БД.
        if ($hasCookies) {
            $cookiesTrunc = (int)Config::VALIDATE_COOKIES_TRUNCATE;
            foreach ($fbIds as $fbId) {
                $like = '%c_user%' . $fbId . '%';
                $sql = "SELECT id, login FROM {$this->table} "
                     . "WHERE SUBSTRING(cookies, 1, $cookiesTrunc) LIKE ?{$deletedClause} LIMIT 1";
                $stmt = $conn->prepare($sql);
                if (!$stmt) continue;
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $r = $stmt->get_result();
                if ($r && ($found = $r->fetch_assoc())) {
                    $stmt->close();
                    return [
                        'id' => (int)$found['id'],
                        'login' => (string)$found['login'],
                        'match_kind' => 'FB ID (cookies c_user)',
                        'match_value' => $fbId,
                    ];
                }
                $stmt->close();
            }
        }

        return null;
    }
}
