<?php
/**
 * Сервис для массового переноса аккаунтов в другой статус
 * 
 * УПРОЩЕННАЯ ЛОГИКА:
 * 1. Парсит ID из текста (формат: (10|61)[0-9A-Za-z]{10,23})
 * 2. Ищет точное совпадение в колонке id_soc_account
 * 3. Для не найденных - ищет в social_url (паттерн Facebook URL)
 * 4. Обновляет статус найденных аккаунтов
 * 
 * @version 4.0 - Упрощение функционала
 * @date 2025-11-11
 */

require_once __DIR__ . '/AccountsService.php';

class MassTransferService {
    private $db;
    private $table = 'accounts';
    
    // Константы для лимитов
    const MAX_INPUT_SIZE = 20 * 1024 * 1024; // 20MB
    const MAX_LINES = 50000;
    const MAX_BATCH_SIZE = 5000; // Размер батча для поиска по id_soc_account
    const MAX_URL_BATCH_SIZE = 50; // Меньший батч для LIKE запросов (избегаем гигантских SQL)
    
    // Регулярка для парсинга ID аккаунтов
    // Формат: начинается с 10 или 61, затем 10-23 символов (буквы/цифры)
    const ID_PATTERN = '/\b(10|61)[0-9A-Za-z]{10,23}\b/';
    
    // Паттерн Facebook URL для извлечения ID
    const FB_URL_PATTERN = '/facebook\.com\/profile\.php\?id=([0-9A-Za-z]+)/i';
    
    /**
     * Конструктор
     */
    public function __construct() {
        global $mysqli;
        $this->db = $mysqli;
    }
    
    /**
     * Валидация размера входных данных
     * 
     * @param string $text Входной текст
     * @throws Exception Если размер превышает лимит
     */
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
     * Извлекает ID из текста используя паттерн (10|61)[0-9A-Za-z]{10,23}
     * 
     * @param string $text Входной текст
     * @return array Массив с ID
     */
    public function parseText(string $text): array {
        $this->validateInputSize($text);
        
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        
        // Ограничиваем количество строк
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES);
        }
        
        $ids = [];         // Все найденные ID
        $unparsed = [];    // Нераспознанные строки (для отладки)
        
        foreach ($lines as $line) {
            $parsed = false;
            
            // Извлекаем все ID формата (10|61)[0-9A-Za-z]{10,23}
            if (preg_match_all(self::ID_PATTERN, $line, $matches)) {
                foreach ($matches[0] as $id) {
                    $ids[] = $id;
                    $parsed = true;
                }
            }
            
            // Если не нашли по основному паттерну, пробуем извлечь ID из формата: число_строка_число
            // Например: 97693208494_H9wZQ30BEX_61571235444141
            // Извлекаем первое число (11+ цифр) и последнее число (11+ цифр)
            if (!$parsed && preg_match('/^(\d{11,})_[^_]+_(\d{11,})$/', $line, $formatMatches)) {
                // Добавляем оба числа как потенциальные ID
                if (isset($formatMatches[1])) {
                    $ids[] = $formatMatches[1];
                    $parsed = true;
                }
                if (isset($formatMatches[2])) {
                    $ids[] = $formatMatches[2];
                    $parsed = true;
                }
            }
            
            // Если всё ещё не распознано, пробуем извлечь все числовые ID длиной 11+ цифр
            // Ищем числа длиной 11+ цифр (например, 61571235444141, 97693208494)
            if (!$parsed && preg_match_all('/\d{11,}/', $line, $numericMatches)) {
                foreach ($numericMatches[0] as $numericId) {
                    $ids[] = $numericId;
                    $parsed = true;
                }
            }
            
            // Сохраняем нераспознанные строки для отладки (макс 50)
            if (!$parsed && $line !== '' && count($unparsed) < 50) {
                $unparsed[] = mb_substr($line, 0, 100);
            }
        }
        
        // Удаляем дубликаты
        $ids = array_values(array_unique($ids));
        
        return [
            'ids' => $ids,
            'unparsed' => $unparsed,
            'total_lines' => count($lines)
        ];
    }
    
    /**
     * Поиск аккаунтов в БД по извлеченным ID
     * Логика:
     * 1. Сначала ищем точное совпадение в id_soc_account
     * 2. Для не найденных ищем в social_url (парсим ID из Facebook URL)
     * 
     * @param array $ids Массив ID для поиска
     * @return array Результаты поиска
     */
    public function findAccounts(array $ids): array {
        if (empty($ids)) {
            return [
                'ids' => [],
                'matched_by_id_soc' => 0,
                'matched_by_url' => 0,
                'total' => 0
            ];
        }
        
        $foundIds = [];
        $matchedTokens = []; // Токены, найденные в id_soc_account
        
        // 1. Точный поиск по id_soc_account
        $result = $this->searchByIdSocAccount($ids);
        $foundIds = $result['ids'];
        $matchedTokens = $result['matched_tokens'];
        $matchedByIdSoc = count($foundIds);
        
        // 2. Для не найденных токенов - поиск в social_url
        $notFoundIds = array_filter($ids, function($id) use ($matchedTokens) {
            return !isset($matchedTokens[$id]);
        });
        
        $matchedByUrl = 0;
        if (!empty($notFoundIds)) {
            $result = $this->searchBySocialUrl($notFoundIds);
            $foundIds = array_merge($foundIds, $result['ids']);
            $matchedByUrl = count($result['ids']);
        }
        
        // Удаляем дубликаты
        $foundIds = array_values(array_unique(array_map('intval', $foundIds)));
        
        return [
            'ids' => $foundIds,
            'matched_by_id_soc' => $matchedByIdSoc,
            'matched_by_url' => $matchedByUrl,
            'total' => count($foundIds)
        ];
    }
    
    /**
     * Точный поиск по колонке id_soc_account
     * 
     * @param array $ids Массив ID для поиска
     * @return array Результаты поиска
     */
    private function searchByIdSocAccount(array $ids): array {
        $foundIds = [];
        $matchedTokens = [];
        
        // Разбиваем на батчи для избежания слишком длинных запросов
        for ($i = 0; $i < count($ids); $i += self::MAX_BATCH_SIZE) {
            $chunk = array_slice($ids, $i, self::MAX_BATCH_SIZE);
            if (empty($chunk)) continue;
            
            $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
            $sql = "SELECT id, id_soc_account 
                    FROM {$this->table} 
                    WHERE id_soc_account IN ($placeholders)";
            
            $stmt = qprep($this->db, $sql, $chunk);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $foundIds[] = (int)$row['id'];
                
                // Запоминаем найденные ID, чтобы не искать их в social_url
                if (!empty($row['id_soc_account'])) {
                    $matchedTokens[$row['id_soc_account']] = true;
                }
            }
            
            $stmt->close();
        }
        
        return [
            'ids' => $foundIds,
            'matched_tokens' => $matchedTokens
        ];
    }
    
    /**
     * Поиск по колонке social_url
     * Извлекает ID из Facebook URL формата: https://www.facebook.com/profile.php?id=XXXXX
     * 
     * Оптимизировано: использует меньшие батчи для LIKE запросов, чтобы избежать гигантских SQL
     * 
     * @param array $ids Массив ID для поиска
     * @return array Результаты поиска
     */
    private function searchBySocialUrl(array $ids): array {
        $foundAccountIds = [];
        
        // Используем меньшие батчи для LIKE запросов (избегаем гигантских SQL с тысячами OR)
        for ($i = 0; $i < count($ids); $i += self::MAX_URL_BATCH_SIZE) {
            $chunk = array_slice($ids, $i, self::MAX_URL_BATCH_SIZE);
            if (empty($chunk)) continue;
            
            // Для каждого ID выполняем отдельный запрос (медленнее, но безопаснее)
            // Используем подготовленные запросы для безопасности
            foreach ($chunk as $id) {
                // Используем подготовленный запрос для безопасности
                $sql = "SELECT id 
                        FROM {$this->table} 
                        WHERE social_url LIKE ? 
                        LIMIT 1";
                
                // Формируем паттерн для LIKE (используем prepared statement, экранирование не нужно)
                $pattern = '%facebook.com/profile.php?id=' . $id . '%';
                $stmt = $this->db->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('s', $pattern);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($row = $result->fetch_assoc()) {
                        $foundAccountIds[] = (int)$row['id'];
                    }
                    
                    $stmt->close();
                }
            }
        }
        
        return ['ids' => $foundAccountIds];
    }
    
    /**
     * Обновление статусов найденных аккаунтов
     * 
     * @param array $ids Массив ID для обновления
     * @param string $status Новый статус
     * @return int Количество обновленных записей
     */
    public function updateStatus(array $ids, string $status): int {
        if (empty($ids)) {
            throw new Exception('Не найдено ID для обновления');
        }
        
        if (trim($status) === '') {
            throw new Exception('Статус не может быть пустым');
        }
        
        // Используем AccountsService для обновления
        $service = new AccountsService();
        
        return $service->updateStatus($ids, $status);
    }
    
    /**
     * Полный цикл: парсинг -> поиск -> обновление
     * 
     * @param string $text Входной текст с ID
     * @param string $status Новый статус
     * @param array $options Опции обработки (не используются, для совместимости)
     * @return array Детальная статистика
     */
    public function processTransfer(string $text, string $status, array $options = []): array {
        // 1. Парсинг текста
        $parseResult = $this->parseText($text);
        
        // Проверка, что хоть что-то распознано
        if (empty($parseResult['ids'])) {
            $hint = !empty($parseResult['unparsed']) 
                ? ' Пример нераспознанной строки: "' . mb_substr($parseResult['unparsed'][0], 0, 50) . '"'
                : '';
            throw new Exception('Не найдено ни одного валидного ID в тексте.' . $hint);
        }
        
        // 2. Поиск аккаунтов в БД
        $searchResult = $this->findAccounts($parseResult['ids']);
        
        // Проверка, что хоть что-то найдено
        if (empty($searchResult['ids'])) {
            throw new Exception('Ни один из распознанных ID не найден в базе данных.');
        }
        
        // 3. Обновление статусов
        $affected = $this->updateStatus($searchResult['ids'], $status);
        
        // 4. Формирование детальной статистики
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

