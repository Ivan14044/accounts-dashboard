<?php
/**
 * Сервис для массового переноса аккаунтов в другой статус
 * 
 * Логика:
 * 1. Парсит ID из текста (формат: (10|61)[0-9A-Za-z]{10,23} или числа 11+ цифр)
 * 2. Ищет точное совпадение в колонке id_soc_account (мгновенно, по индексу)
 * 3. Если enable_like=true (или во входе были ссылки) — для строк, где точный поиск
 *    не нашёл ничего, ищет в social_url батчевым OR-запросом
 * 4. Обновляет статус найденных аккаунтов батчами в транзакции
 * 
 * @version 5.0
 */

require_once __DIR__ . '/AccountsService.php';
require_once __DIR__ . '/Database.php';

class MassTransferService {
    private $db;
    private $table;

    const MAX_INPUT_SIZE = 20 * 1024 * 1024; // 20MB
    const MAX_LINES = 50000;
    // SELECT IN() для поиска по id_soc_account/login. Не больше Config::ID_LIST_CHUNK:
    // список в 1000 значений на проде уводил запрос в полный проход по таблице
    const MAX_BATCH_SIZE = 500;
    const MAX_URL_BATCH_SIZE = 50;
    const UPDATE_BATCH_SIZE = 200;

    const ID_PATTERN = '/\b(10|61)[0-9A-Za-z]{10,23}\b/';

    public function __construct(string $table = 'accounts') {
        $this->table = $table;
        $this->db = Database::getInstance()->getConnection();
    }
    
    private function validateInputSize(string $text): void {
        $size = strlen($text);
        if ($size > self::MAX_INPUT_SIZE) {
            $sizeMB = round($size / 1024 / 1024, 1);
            $maxMB = round(self::MAX_INPUT_SIZE / 1024 / 1024, 1);
            throw new Exception("Слишком большой запрос ({$sizeMB}MB). Максимум {$maxMB}MB.");
        }
    }
    
    /**
     * Парсинг текста и извлечение ID
     *
     * Поддерживаемые форматы:
     * - Полные URL: https://www.facebook.com/profile.php?id=61579160816458
     * - URL с коротким путём: https://www.facebook.com/61579160816458
     * - Чистый ID: 61579160816458
     * - Формат число_строка_число: 97693208494_H9wZQ30BEX_61571235444141
     * - Любое число 11+ цифр
     */
    public function parseText(string $text): array {
        $this->validateInputSize($text);

        $lines = array_filter(array_map('trim', explode("\n", $text)));

        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES);
        }

        $ids = [];
        $urls = []; // Сохраняем оригинальные URL для поиска по social_url
        $unparsed = [];

        $lineTokens = [];

        foreach ($lines as $line) {
            $parsed = false;
            $idsBeforeLine = count($ids);

            // 1. Специальная обработка Facebook URL: profile.php?id=XXXXX (ID + сохраняем для URL-поиска)
            if (preg_match_all('/profile\.php\?id=(\d{8,})/', $line, $urlMatch)) {
                foreach ($urlMatch[1] as $id) {
                    $ids[] = $id;
                    $urls[] = $id;
                }
                $parsed = true;
            }

            // 2. Facebook URL без profile.php: facebook.com/XXXXX (числовой профиль)
            if (preg_match_all('/facebook\.com\/(\d{8,})/', $line, $shortUrlMatch)) {
                foreach ($shortUrlMatch[1] as $id) { $ids[] = $id; }
                $parsed = true;
            }

            // 3. Стандартный паттерн: ID начинающийся с 10 или 61
            if (preg_match_all(self::ID_PATTERN, $line, $matches)) {
                foreach ($matches[0] as $id) { $ids[] = $id; }
                $parsed = true;
            }

            // 4. Формат: число_строка_число (например 97693208494_H9wZQ30BEX_61571235444141)
            if (preg_match('/^(\d{11,})_[^_]+_(\d{11,})$/', $line, $formatMatches)) {
                if (isset($formatMatches[1])) { $ids[] = $formatMatches[1]; }
                if (isset($formatMatches[2])) { $ids[] = $formatMatches[2]; }
                $parsed = true;
            }

            // 5. Числовые ID длиной 11+ цифр — выполняется ВСЕГДА, ловит логины-числа,
            //    которые лежат в строке рядом с URL (например `4915212806074|...|profile.php?id=...`).
            //    Раньше этот regex был fallback'ом и не запускался, если до него уже что-то нашлось.
            if (preg_match_all('/\d{11,}/', $line, $numericMatches)) {
                foreach ($numericMatches[0] as $numericId) { $ids[] = $numericId; }
                $parsed = true;
            }

            if (!$parsed && $line !== '' && count($unparsed) < 50) {
                $unparsed[] = mb_substr($line, 0, 100);
            }

            if (count($ids) > $idsBeforeLine) {
                $lineTokens[] = array_values(array_unique(array_slice($ids, $idsBeforeLine)));
            }
        }

        $ids = array_values(array_unique($ids));

        return [
            'ids' => $ids,
            'urls' => array_values(array_unique($urls)),
            // Кандидаты каждой строки отдельно: строка = один аккаунт, и если он
            // нашёлся по одному кандидату, остальные — мусор (см. selectUrlSearchCandidates)
            'line_tokens' => $lineTokens,
            'unparsed' => $unparsed,
            'total_lines' => count($lines)
        ];
    }
    
    /**
     * Поиск аккаунтов в БД.
     * @param bool $enableLike Искать ли в social_url для ненайденных (медленно)
     * @param bool $hadUrls Входные данные содержали URL (автоматически включает поиск по social_url)
     * @param array|null $lineTokens Кандидаты по строкам из parseText(); если переданы,
     *                   поиск по social_url идёт только для строк без точного совпадения
     */
    public function findAccounts(array $ids, bool $enableLike = false, bool $hadUrls = false, ?array $lineTokens = null): array {
        if (empty($ids)) {
            return ['ids' => [], 'matched_by_id_soc' => 0, 'matched_by_url' => 0, 'total' => 0];
        }

        // Фаза 1: точный поиск по id_soc_account (быстро, по индексу)
        $result = $this->searchByIdSocAccount($ids);
        $foundIds = $result['ids'];
        $matchedTokens = $result['matched_tokens'];
        $matchedByIdSoc = count($foundIds);

        // Фаза 1.5: точный поиск по login для оставшихся (быстро, по индексу).
        // CSV-импорт записывает FB ID в login, поэтому без этой фазы массовый перенос
        // не находит аккаунты, добавленные через импорт.
        $matchedByLogin = 0;
        $notFoundAfterIdSoc = array_values(array_filter($ids, function($id) use ($matchedTokens) {
            return !isset($matchedTokens[$id]);
        }));
        if (!empty($notFoundAfterIdSoc)) {
            $loginResult = $this->searchByLogin($notFoundAfterIdSoc);
            $foundIds = array_merge($foundIds, $loginResult['ids']);
            foreach ($loginResult['matched_tokens'] as $token => $_) {
                $matchedTokens[$token] = true;
            }
            $matchedByLogin = count($loginResult['ids']);
        }

        // Фаза 2: поиск в social_url
        // Включается если: (а) пользователь включил enable_like, ИЛИ (б) входные данные были URL
        // и не все ID найдены по точным фазам
        $matchedByUrl = 0;
        $shouldSearchUrl = $enableLike || ($hadUrls && count($matchedTokens) < count($ids));

        if ($shouldSearchUrl) {
            $notFoundIds = array_values(array_filter($ids, function($id) use ($matchedTokens) {
                return !isset($matchedTokens[$id]);
            }));
            $notFoundIds = self::selectUrlSearchCandidates($notFoundIds, $matchedTokens, $lineTokens);

            if (!empty($notFoundIds)) {
                $urlResult = $this->searchBySocialUrl($notFoundIds);
                $foundIds = array_merge($foundIds, $urlResult['ids']);
                $matchedByUrl = count($urlResult['ids']);
            }
        }

        $foundIds = array_values(array_unique(array_map('intval', $foundIds)));

        return [
            'ids' => $foundIds,
            'matched_by_id_soc' => $matchedByIdSoc,
            'matched_by_login' => $matchedByLogin,
            'matched_by_url' => $matchedByUrl,
            'total' => count($foundIds)
        ];
    }

    /**
     * Какие ненайденные кандидаты стоит искать медленным `social_url LIKE`.
     *
     * Поиск по social_url — полный проход по таблице на каждые
     * MAX_URL_BATCH_SIZE кандидатов. Раньше туда шли ВСЕ ненайденные, а в
     * типичной строке `логин|пароль|ссылка|куки` кандидатов три-четыре, и
     * аккаунт находится точным поиском по одному из них. Остальные — логин,
     * числа из кук — уходили в LIKE впустую: 21.10.2026 перенос 2101 аккаунта
     * из 6479 кандидатов шёл на проде 253 с, и почти всё это время — проходы,
     * не нашедшие ничего (воспроизведено на стенде: 300 строк — 18,4 с).
     *
     * Правило: кандидат ищется по ссылке, только если он встречается хотя бы
     * в одной строке, где точный поиск не нашёл НИ ОДНОГО кандидата. Строка —
     * один аккаунт; если он уже найден, искать его второй раз по ссылке незачем.
     * Строки без совпадений ищутся по ссылке целиком, как и раньше.
     * Сравнение без учёта регистра: база (коллация *_ci) возвращает значение
     * в том регистре, в котором оно лежит, а не в котором его вставили.
     *
     * @param string[]   $notFoundIds   Кандидаты, не найденные точным поиском
     * @param array      $matchedTokens Найденные значения [значение => true]
     * @param array|null $lineTokens    Кандидаты по строкам (parseText()['line_tokens']);
     *                                  null — старое поведение, ищутся все ненайденные
     * @return string[] Подмножество $notFoundIds в исходном порядке
     */
    public static function selectUrlSearchCandidates(array $notFoundIds, array $matchedTokens, ?array $lineTokens): array {
        if ($lineTokens === null || empty($notFoundIds)) {
            return array_values($notFoundIds);
        }

        $matchedLower = [];
        foreach ($matchedTokens as $token => $_) {
            $matchedLower[strtolower((string)$token)] = true;
        }

        $fromUnmatchedLines = [];
        foreach ($lineTokens as $tokens) {
            $lineMatched = false;
            foreach ($tokens as $token) {
                if (isset($matchedLower[strtolower((string)$token)])) {
                    $lineMatched = true;
                    break;
                }
            }
            if (!$lineMatched) {
                foreach ($tokens as $token) {
                    $fromUnmatchedLines[(string)$token] = true;
                }
            }
        }

        return array_values(array_filter($notFoundIds, function ($id) use ($fromUnmatchedLines) {
            return isset($fromUnmatchedLines[(string)$id]);
        }));
    }

    /**
     * Точный поиск по login. Исключает удалённые аккаунты.
     * CSV-импорт пишет FB ID в login, и эта фаза ловит такие записи.
     */
    private function searchByLogin(array $ids): array {
        $foundIds = [];
        $matchedTokens = [];

        for ($i = 0; $i < count($ids); $i += self::MAX_BATCH_SIZE) {
            $chunk = array_slice($ids, $i, self::MAX_BATCH_SIZE);
            if (empty($chunk)) continue;

            $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
            $sql = "SELECT id, login
                    FROM {$this->table}
                    WHERE deleted_at IS NULL AND login IN ($placeholders)";

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare search by login: ' . $this->db->error);
            }
            $types = str_repeat('s', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $foundIds[] = (int)$row['id'];
                if (!empty($row['login'])) {
                    $matchedTokens[$row['login']] = true;
                }
            }
            $stmt->close();
        }

        return ['ids' => $foundIds, 'matched_tokens' => $matchedTokens];
    }
    
    /**
     * Точный поиск по id_soc_account. Исключает удалённые аккаунты.
     */
    private function searchByIdSocAccount(array $ids): array {
        $foundIds = [];
        $matchedTokens = [];
        
        for ($i = 0; $i < count($ids); $i += self::MAX_BATCH_SIZE) {
            $chunk = array_slice($ids, $i, self::MAX_BATCH_SIZE);
            if (empty($chunk)) continue;
            
            $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
            $sql = "SELECT id, id_soc_account
                    FROM {$this->table}
                    WHERE deleted_at IS NULL AND id_soc_account IN ($placeholders)";

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare search by id_soc_account: ' . $this->db->error);
            }
            $types = str_repeat('s', count($chunk));
            $stmt->bind_param($types, ...$chunk);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $foundIds[] = (int)$row['id'];
                if (!empty($row['id_soc_account'])) {
                    $matchedTokens[$row['id_soc_account']] = true;
                }
            }
            $stmt->close();
        }
        
        return ['ids' => $foundIds, 'matched_tokens' => $matchedTokens];
    }
    
    /**
     * Батчевый поиск по social_url. Исключает удалённые.
     * Вместо N отдельных запросов — один OR-запрос на батч.
     * Поддерживает форматы: profile.php?id=XXX и facebook.com/XXX
     */
    private function searchBySocialUrl(array $ids): array {
        $foundAccountIds = [];
        
        for ($i = 0; $i < count($ids); $i += self::MAX_URL_BATCH_SIZE) {
            $chunk = array_slice($ids, $i, self::MAX_URL_BATCH_SIZE);
            if (empty($chunk)) continue;
            
            // Один OR-запрос на весь батч вместо цикла по каждому ID
            $orConds = [];
            $params = [];
            foreach ($chunk as $id) {
                $orConds[] = '`social_url` LIKE ?';
                $params[] = '%' . $id . '%';
            }
            
            $sql = "SELECT id FROM {$this->table}
                    WHERE deleted_at IS NULL AND (" . implode(' OR ', $orConds) . ")";

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare search by social_url: ' . $this->db->error);
            }
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $foundAccountIds[] = (int)$row['id'];
            }
            $stmt->close();
        }
        
        return ['ids' => $foundAccountIds];
    }
    
    /**
     * Обновление статусов батчами. Исключает удалённые.
     */
    public function updateStatus(array $ids, string $status): int {
        if (empty($ids)) {
            throw new Exception('Не найдено ID для обновления');
        }
        if (trim($status) === '') {
            throw new Exception('Статус не может быть пустым');
        }
        
        $hasUpdatedAt = true;
        try {
            require_once __DIR__ . '/ColumnMetadata.php';
            $metadata = ColumnMetadata::getInstance($this->db);
            $hasUpdatedAt = $metadata->columnExists('updated_at');
        } catch (Exception $e) {}
        
        $updateTimestamp = $hasUpdatedAt ? ', updated_at = CURRENT_TIMESTAMP' : '';
        $totalAffected = 0;
        
        // Батчим UPDATE, чтобы не превысить max_allowed_packet
        for ($i = 0; $i < count($ids); $i += self::UPDATE_BATCH_SIZE) {
            $chunk = array_slice($ids, $i, self::UPDATE_BATCH_SIZE);
            if (empty($chunk)) continue;
            
            $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
            $sql = "UPDATE {$this->table} SET status = ? $updateTimestamp 
                    WHERE deleted_at IS NULL AND id IN ($placeholders)";
            
            $params = array_merge([$status], $chunk);
            $types = 's' . str_repeat('i', count($chunk));
            
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare update: ' . $this->db->error);
            }
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to execute update: ' . $stmt->error);
            }
            $totalAffected += $stmt->affected_rows;
            $stmt->close();
        }
        
        return $totalAffected;
    }
    
    /**
     * Полный цикл: парсинг → поиск → обновление (в транзакции)
     * 
     * @param string $text Входной текст с ID
     * @param string $status Новый статус
     * @param array $options ['enable_like' => bool]
     */
    public function processTransfer(string $text, string $status, array $options = []): array {
        $enableLike = !empty($options['enable_like']);
        
        // 1. Парсинг
        $parseResult = $this->parseText($text);
        
        if (empty($parseResult['ids'])) {
            $hint = !empty($parseResult['unparsed']) 
                ? ' Пример нераспознанной строки: "' . mb_substr($parseResult['unparsed'][0], 0, 50) . '"'
                : '';
            throw new Exception('Не найдено ни одного валидного ID в тексте.' . $hint);
        }
        
        // 2. Поиск (если были URL — автоматически включаем поиск по social_url)
        $hadUrls = !empty($parseResult['urls']);
        $searchResult = $this->findAccounts($parseResult['ids'], $enableLike, $hadUrls, $parseResult['line_tokens']);
        
        if (empty($searchResult['ids'])) {
            throw new Exception('Ни один из распознанных ID не найден в базе данных.');
        }
        
        // 3. Обновление в транзакции
        $this->db->begin_transaction();
        try {
            // Audit log — ДО updateStatus: старые значения читаются из БД,
            // после обновления они бы совпали с новыми и не записались вовсе.
            $auditLogger = null;
            try {
                require_once __DIR__ . '/AuditLogger.php';
                $auditLogger = AuditLogger::getInstance();
                if ($auditLogger->isEnabled()) {
                    $auditLogger->beginAction(
                        'mass_transfer',
                        $this->table,
                        'Массовый перенос в статус «' . $status . '» (' . count($searchResult['ids']) . ' акк.)'
                    );
                    $auditLogger->logBulkChange($searchResult['ids'], 'status', null, $status, null, $this->table);
                }
            } catch (Exception $e) {}

            $affected = $this->updateStatus($searchResult['ids'], $status);

            if ($auditLogger !== null) {
                try { $auditLogger->finishAction($affected); } catch (Exception $e) {}
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
        
        return [
            'success' => true,
            'affected' => $affected,
            'statistics' => [
                'parsed_ids' => count($parseResult['ids']),
                'total_lines' => $parseResult['total_lines'],
                'unparsed_lines' => count($parseResult['unparsed']),
                'matched_by_id_soc' => $searchResult['matched_by_id_soc'],
                'matched_by_url' => $searchResult['matched_by_url'],
                'total_found' => $searchResult['total']
            ],
            'status' => $status
        ];
    }
}
