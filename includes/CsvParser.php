<?php
/**
 * CSV Parser - класс для парсинга CSV файлов
 * 
 * Предоставляет функциональность для чтения и нормализации CSV файлов
 * с поддержкой различных разделителей, кодировок и BOM.
 */
class CsvParser {
    private $delimiter = ';';
    private $maxRows = 10000;
    private $encoding = 'UTF-8';

    /** @var bool PHP 7.4+ supports empty escape in fgetcsv/fputcsv */
    private $supportsEmptyEscape;
    
    /**
     * Конструктор
     * 
     * @param int $maxRows Максимальное количество строк для чтения
     * @param string $delimiter Разделитель по умолчанию
     */
    public function __construct(int $maxRows = 10000, string $delimiter = ';') {
        $this->maxRows = $maxRows;
        $this->delimiter = $delimiter;
        $this->supportsEmptyEscape = PHP_VERSION_ID >= 70400;
    }
    
    /**
     * Wrapper around fgetcsv that disables backslash escape on PHP 7.4+
     * to correctly handle fields containing literal backslashes (e.g. JSON cookies).
     *
     * On PHP < 7.4 where escape='' is not supported, uses a custom RFC 4180
     * parser via fgets() to avoid fgetcsv treating \ as an escape character.
     */
    private function readCsvRow($handle, string $delimiter) {
        if ($this->supportsEmptyEscape) {
            return fgetcsv($handle, 0, $delimiter, '"', '');
        }
        return $this->readCsvRowManual($handle, $delimiter);
    }

    /**
     * Manual RFC 4180 CSV row reader for PHP < 7.4.
     * Reads raw lines with fgets() and parses fields manually.
     * Treats backslash as a literal character (no escape semantics).
     * Only "" (doubled quote) is recognized as an escaped quote inside quoted fields.
     * Handles multi-line quoted fields (fields containing newlines).
     *
     * @param resource $handle File handle
     * @param string $delimiter Field delimiter
     * @return array|false Array of fields, or false on EOF
     */
    private function readCsvRowManual($handle, string $delimiter) {
        if (feof($handle)) {
            return false;
        }

        // Accumulate raw line(s) — quoted fields may span multiple lines
        $raw = '';
        $inQuotes = false;

        while (($chunk = fgets($handle)) !== false) {
            $raw .= $chunk;

            // Count unescaped quotes to determine if we're inside a quoted field
            $quoteCount = 0;
            $len = strlen($raw);
            $i = 0;
            $inQ = false;
            while ($i < $len) {
                $ch = $raw[$i];
                if ($inQ) {
                    if ($ch === '"') {
                        // Look ahead: "" means escaped quote, otherwise end of field
                        if ($i + 1 < $len && $raw[$i + 1] === '"') {
                            $i += 2; // skip ""
                            continue;
                        }
                        $inQ = false;
                    }
                } else {
                    if ($ch === '"') {
                        $inQ = true;
                    }
                }
                $i++;
            }

            if (!$inQ) {
                break; // complete row
            }
            // Still inside a quoted field — read next line
        }

        if ($raw === '' || $raw === false) {
            return false;
        }

        // Remove trailing newline(s)
        $raw = rtrim($raw, "\r\n");

        if ($raw === '') {
            return [''];
        }

        // Parse fields from the raw line
        return $this->parseCsvLine($raw, $delimiter);
    }

    /**
     * Parse a single CSV line (possibly multi-line) into an array of fields.
     * RFC 4180 rules: "" for escaped quotes, no backslash escape.
     *
     * @param string $line Raw CSV line
     * @param string $delimiter Field delimiter
     * @return array Array of field values
     */
    private function parseCsvLine(string $line, string $delimiter): array {
        $fields = [];
        $len = strlen($line);
        $i = 0;

        while ($i <= $len) {
            if ($i === $len) {
                // Trailing delimiter produced an empty final field
                $fields[] = '';
                break;
            }

            if ($line[$i] === '"') {
                // Quoted field
                $i++; // skip opening quote
                $field = '';
                while ($i < $len) {
                    if ($line[$i] === '"') {
                        if ($i + 1 < $len && $line[$i + 1] === '"') {
                            // Escaped quote ""
                            $field .= '"';
                            $i += 2;
                        } else {
                            // Closing quote
                            $i++; // skip closing quote
                            break;
                        }
                    } else {
                        $field .= $line[$i];
                        $i++;
                    }
                }
                $fields[] = $field;
                // Skip delimiter after quoted field
                if ($i < $len && $line[$i] === $delimiter) {
                    $i++;
                    // If delimiter is at end of line, there's one more empty field
                    if ($i === $len) {
                        $fields[] = '';
                    }
                }
            } else {
                // Unquoted field — read until delimiter or end
                $end = strpos($line, $delimiter, $i);
                if ($end === false) {
                    $fields[] = substr($line, $i);
                    break;
                } else {
                    $fields[] = substr($line, $i, $end - $i);
                    $i = $end + 1;
                    // If delimiter is at end of line, there's one more empty field
                    if ($i === $len) {
                        $fields[] = '';
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Парсит CSV файл
     *
     * @param string $filePath Путь к файлу
     * @return array Массив распарсенных строк
     * @throws Exception При ошибках чтения файла
     */
    public function parse(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new Exception('Файл не найден: ' . $filePath);
        }

        $fileSize = filesize($filePath);
        if (class_exists('Logger')) {
            Logger::debug('CsvParser::parse', [
                'file' => basename($filePath),
                'size' => $fileSize,
                'php_version' => PHP_VERSION,
                'empty_escape' => $this->supportsEmptyEscape
            ]);
        }

        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception('Не удалось открыть файл для чтения');
        }

        try {
            // Определяем разделитель автоматически
            $delimiter = $this->detectDelimiter($handle);

            // Читаем заголовки
            $headers = $this->readCsvRow($handle, $delimiter);
            if ($headers === false || empty($headers)) {
                if (class_exists('Logger')) {
                    Logger::warning('CsvParser: headers read failed', [
                        'headers_result' => $headers === false ? 'FALSE' : 'EMPTY',
                        'delimiter' => $delimiter,
                        'file_size' => $fileSize
                    ]);
                }
                fclose($handle);
                return [];
            }
            
            // Нормализуем заголовки
            $normalizedHeaders = $this->normalizeHeaders($headers);

            if (class_exists('Logger')) {
                Logger::debug('CsvParser: headers parsed', [
                    'count' => count($normalizedHeaders),
                    'first_5' => array_slice($normalizedHeaders, 0, 5),
                    'delimiter' => $delimiter
                ]);
            }

            // Читаем данные
            $data = $this->readData($handle, $delimiter, $normalizedHeaders);

            if (class_exists('Logger')) {
                Logger::debug('CsvParser: data parsed', ['rows' => count($data)]);
            }

            fclose($handle);

            return $data;
            
        } catch (Exception $e) {
            fclose($handle);
            throw $e;
        }
    }
    
    /**
     * Определяет разделитель в CSV файле
     * Подсчитывает количество ; и , и выбирает тот, которого больше
     * 
     * @param resource $handle Дескриптор файла
     * @return string Разделитель (';' или ',')
     */
    private function detectDelimiter($handle): string {
        $position = ftell($handle);
        $firstLine = null;
        
        // Ищем первую непустую строку без комментария
        while (($line = fgets($handle)) !== false) {
            if (!$this->isCommentOrEmpty($line)) {
                $firstLine = $line;
                break;
            }
        }
        
        fseek($handle, $position);
        
        if ($firstLine === null) {
            return $this->delimiter;
        }
        
        $semicolonCount = substr_count($firstLine, ';');
        $commaCount = substr_count($firstLine, ',');
        
        return $semicolonCount > $commaCount ? ';' : ',';
    }
    
    /**
     * Нормализует заголовки CSV
     * 
     * @param array $headers Исходные заголовки
     * @return array Нормализованные заголовки
     */
    public function normalizeHeaders(array $headers): array {
        $normalized = [];
        
        foreach ($headers as $header) {
            $normalized[] = self::normalizeHeader($header);
        }
        
        return $normalized;
    }
    
    /**
     * Нормализует один заголовок CSV
     * ВАЖНО: Логика должна быть ИДЕНТИЧНА dashboard-upload.js::normalizeHeader()
     * 
     * @param string $header Исходный заголовок
     * @return string Нормализованный заголовок
     */
    public static function normalizeHeader(string $header): string {
        // 1. Trim
        $clean = trim($header);
        
        // 2. toLowerCase
        $clean = mb_strtolower($clean, 'UTF-8');
        
        // 3. Удалить BOM (везде, не только в начале)
        $clean = str_replace("\xEF\xBB\xBF", '', $clean); // Byte-level UTF-8 BOM
        
        // 4. Удалить все звёздочки
        $clean = str_replace('*', '', $clean);
        
        // 5. Удалить непечатаемые символы (ASCII 0x00-0x1F, 0x7F)
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);
        
        // 6. НЕ заменяем пробелы (чтобы соответствовать JS)
        
        // 7. Финальный trim
        return trim($clean);
    }
    
    /**
     * Читает данные из CSV файла
     * 
     * @param resource $handle Дескриптор файла
     * @param string $delimiter Разделитель
     * @param array $headers Нормализованные заголовки
     * @return array Массив данных
     */
    private function readData($handle, string $delimiter, array $headers): array {
        $data = [];
        $lineNum = 0;
        $skippedEmpty = 0;
        $skippedMismatch = 0;
        $skippedComments = 0;
        
        // Читаем данные построчно
        while (($values = $this->readCsvRow($handle, $delimiter)) !== false && $lineNum < $this->maxRows) {
            $lineNum++;
            
            // Пропускаем пустые строки
            if (empty(array_filter($values, function($v) { return trim($v) !== ''; }))) {
                $skippedEmpty++;
                continue;
            }
            
            // Пропускаем комментарии (проверяем первое значение)
            if (isset($values[0]) && $this->isCommentOrEmpty($values[0])) {
                $skippedComments++;
                continue;
            }
            
            // Если количество колонок не совпадает, подгоняем длину
            $headerCount = count($headers);
            $valueCount = count($values);
            if ($valueCount !== $headerCount) {
                if ($valueCount < $headerCount) {
                    // Дополняем пустыми значениями
                    $values = array_pad($values, $headerCount, '');
                } else {
                    // Обрезаем лишние колонки
                    $values = array_slice($values, 0, $headerCount);
                }
                $skippedMismatch++; // Считаем как "скорректированные"
            }
            
            // Формируем ассоциативный массив
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = isset($values[$index]) ? trim($values[$index]) : '';
            }
            
            $data[] = $row;
        }
        
        if (class_exists('Logger')) {
            Logger::info('CSV Parser: Парсинг завершён', [
                'total_lines_read' => $lineNum,
                'rows_parsed' => count($data),
                'skipped_empty' => $skippedEmpty,
                'skipped_mismatch' => $skippedMismatch,
                'skipped_comments' => $skippedComments
            ]);
        }
        
        return $data;
    }
    
    /**
     * Проверяет, является ли строка пустой или комментарием
     * 
     * @param string $line Строка для проверки
     * @return bool true если строка пустая или комментарий
     */
    private function isCommentOrEmpty(string $line): bool {
        $trimmed = trim($line);
        return $trimmed === '' || strpos($trimmed, '#') === 0;
    }
    
    /**
     * Устанавливает разделитель
     * 
     * @param string $delimiter Новый разделитель
     * @return self
     */
    public function setDelimiter(string $delimiter): self {
        $this->delimiter = $delimiter;
        return $this;
    }
    
    /**
     * Устанавливает максимальное количество строк
     * 
     * @param int $maxRows Максимальное количество строк
     * @return self
     */
    public function setMaxRows(int $maxRows): self {
        $this->maxRows = $maxRows;
        return $this;
    }
    
    /**
     * Устанавливает кодировку
     * 
     * @param string $encoding Кодировка
     * @return self
     */
    public function setEncoding(string $encoding): self {
        $this->encoding = $encoding;
        return $this;
    }
}
