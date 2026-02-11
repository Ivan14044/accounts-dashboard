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
    
    /**
     * Конструктор
     * 
     * @param int $maxRows Максимальное количество строк для чтения
     * @param string $delimiter Разделитель по умолчанию
     */
    public function __construct(int $maxRows = 10000, string $delimiter = ';') {
        $this->maxRows = $maxRows;
        $this->delimiter = $delimiter;
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
        
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception('Не удалось открыть файл для чтения');
        }
        
        try {
            // Определяем разделитель автоматически
            $delimiter = $this->detectDelimiter($handle);
            
            // Читаем заголовки
            $headers = fgetcsv($handle, 0, $delimiter);
            if ($headers === false || empty($headers)) {
                fclose($handle);
                return [];
            }
            
            // Нормализуем заголовки
            $normalizedHeaders = $this->normalizeHeaders($headers);
            
            // Читаем данные
            $data = $this->readData($handle, $delimiter, $normalizedHeaders);
            
            fclose($handle);
            
            return $data;
            
        } catch (Exception $e) {
            fclose($handle);
            throw $e;
        }
    }
    
    /**
     * Определяет разделитель в CSV файле
     * 
     * @param resource $handle Дескриптор файла
     * @return string Разделитель (';' или ',')
     */
    private function detectDelimiter($handle): string {
        $firstLine = fgets($handle);
        
        if ($firstLine === false) {
            rewind($handle);
            return $this->delimiter;
        }
        
        // Пропускаем строки-комментарии
        while ($firstLine !== false && (trim($firstLine) === '' || strpos(trim($firstLine), '#') === 0)) {
            $firstLine = fgets($handle);
        }
        
        $delimiter = strpos($firstLine, ';') !== false ? ';' : ',';
        
        rewind($handle);
        
        return $delimiter;
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
            $clean = mb_strtolower(trim($header), 'UTF-8');
            
            // Удаляем BOM (\xEF\xBB\xBF) и непечатаемые ASCII символы
            $clean = preg_replace('/^\xEF\xBB\xBF/', '', $clean);
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);
            
            // Убираем звёздочки (обозначение обязательных полей в шаблоне)
            $clean = str_replace('*', '', $clean);
            
            // Заменяем пробелы и различные типы тире на подчеркивания
            $clean = str_replace([' ', '-', '—', '–', '\t'], '_', $clean);
            
            // Убираем множественные подчеркивания
            $clean = preg_replace('/_+/', '_', $clean);
            
            // Убираем подчеркивания в начале и конце
            $clean = trim($clean, '_');
            
            $normalized[] = $clean;
        }
        
        return $normalized;
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
        
        // Пропускаем строки-комментарии в заголовках
        while (($rawLine = fgets($handle)) !== false) {
            $trimmed = trim($rawLine);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }
            // Это строка с заголовками, пропускаем её (уже прочитали)
            break;
        }
        
        // Читаем данные построчно
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false && $lineNum < $this->maxRows) {
            $lineNum++;
            
            // Пропускаем пустые строки
            if (empty(array_filter($values, function($v) { return trim($v) !== ''; }))) {
                $skippedEmpty++;
                continue;
            }
            
            // Пропускаем комментарии (строки, начинающиеся с #)
            if (isset($values[0]) && strpos(trim($values[0]), '#') === 0) {
                $skippedComments++;
                continue;
            }
            
            // Если количество колонок не совпадает, пропускаем строку
            if (count($values) !== count($headers)) {
                $skippedMismatch++;
                continue;
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
