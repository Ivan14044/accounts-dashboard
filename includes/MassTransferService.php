<?php
/**
 * Сервис для массового переноса аккаунтов в другой статус
 * 
 * Логика:
 * 1. Парсит ID из текста (формат: (10|61)[0-9A-Za-z]{10,23} или числа 11+ цифр)
 * 2. Ищет точное совпадение в колонке id_soc_account (мгновенно, по индексу)
 * 3. Если enable_like=true — для ненайденных ищет в social_url батчевым OR-запросом
 * 4. Обновляет статус найденных аккаунтов батчами в транзакции
 * 
 * @version 5.0
 */

require_once __DIR__ . '/AccountsService.php';
require_once __DIR__ . '/Database.php';

class MassTransferService {
    private $db;
    private $table = 'accounts';
    
    const MAX_INPUT_SIZE = 20 * 1024 * 1024; // 20MB
    const MAX_LINES = 50000;
    const MAX_BATCH_SIZE = 5000;
    const MAX_URL_BATCH_SIZE = 50;
    const UPDATE_BATCH_SIZE = 5000;
    
    const ID_PATTERN = '/\b(10|61)[0-9A-Za-z]{10,23}\b/';
    
    public function __construct() {
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
     */
    public function parseText(string $text): array {
        $this->validateInputSize($text);
        
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES);
        }
        
        $ids = [];
        $unparsed = [];
        
        foreach ($lines as $line) {
            $parsed = false;
            
            if (preg_match_all(self::ID_PATTERN, $line, $matches)) {
                foreach ($matches[0] as $id) {
                    $ids[] = $id;
                    $parsed = true;
                }
            }
            
            // Формат: число_строка_число (например 97693208494_H9wZQ30BEX_61571235444141)
            if (!$parsed && preg_match('/^(\d{11,})_[^_]+_(\d{11,})$/', $line, $formatMatches)) {
                if (isset($formatMatches[1])) { $ids[] = $formatMatches[1]; $parsed = true; }
                if (isset($formatMatches[2])) { $ids[] = $formatMatches[2]; $parsed = true; }
            }
            
            // Числовые ID длиной 11+ цифр
            if (!$parsed && preg_match_all('/\d{11,}/', $line, $numericMatches)) {
                foreach ($numericMatches[0] as $numericId) {
                    $ids[] = $numericId;
                    $parsed = true;
                }
            }
            
            if (!$parsed && $line !== '' && count($unparsed) < 50) {
                $unparsed[] = mb_substr($line, 0, 100);
            }
        }
        
        $ids = array_values(array_unique($ids));
        
        return [
            'ids' => $ids,
            'unparsed' => $unparsed,
            'total_lines' => count($lines)
        ];
    }
    
    /**
     * Поиск аккаунтов в БД.
     * @param bool $enableLike Искать ли в social_url для ненайденных (медленно)
     */
    public function findAccounts(array $ids, bool $enableLike = false): array {
        if (empty($ids)) {
            return ['ids' => [], 'matched_by_id_soc' => 0, 'matched_by_url' => 0, 'total' => 0];
        }
        
        // Фаза 1: точный поиск по id_soc_account (быстро, по индексу)
        $result = $this->searchByIdSocAccount($ids);
        $foundIds = $result['ids'];
        $matchedTokens = $result['matched_tokens'];
        $matchedByIdSoc = count($foundIds);
        
        // Фаза 2: поиск в social_url — только если enable_like включён
        $matchedByUrl = 0;
        if ($enableLike) {
            $notFoundIds = array_values(array_filter($ids, function($id) use ($matchedTokens) {
                return !isset($matchedTokens[$id]);
            }));
            
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
            'matched_by_url' => $matchedByUrl,
            'total' => count($foundIds)
        ];
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
            
            $stmt = qprep($this->db, $sql, $chunk);
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
            
            $stmt = qprep($this->db, $sql, $params);
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
        
        // 2. Поиск
        $searchResult = $this->findAccounts($parseResult['ids'], $enableLike);
        
        if (empty($searchResult['ids'])) {
            throw new Exception('Ни один из распознанных ID не найден в базе данных.');
        }
        
        // 3. Обновление в транзакции
        $this->db->begin_transaction();
        try {
            $affected = $this->updateStatus($searchResult['ids'], $status);
            
            // Audit log
            try {
                require_once __DIR__ . '/AuditLogger.php';
                $auditLogger = AuditLogger::getInstance();
                if ($auditLogger->isEnabled()) {
                    $auditLogger->logBulkChange($searchResult['ids'], 'status', null, $status);
                }
            } catch (Exception $e) {}
            
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
