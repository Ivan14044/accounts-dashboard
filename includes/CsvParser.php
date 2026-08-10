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
     * Что случилось при последнем разборе: сколько строк прочитано и что было
     * пропущено. Раньше эти числа уходили только в Logger, и пользователь не
     * узнавал, что часть файла не доехала.
     *
     * @var array
     */
    private $lastStats = array();

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

            // Определяем, осталась ли строка «недочитанной» — то есть открыто ли
            // закавыченное поле, внутрь которого попал перенос строки.
            //
            // ВАЖНО (баг, найденный 2026-08-10): кавычка открывает поле, только
            // если стоит В НАЧАЛЕ поля — в начале строки или сразу после
            // разделителя. Кавычка внутри незакавыченного поля (`user3";active`)
            // по RFC 4180 — обычный символ, и fgetcsv на PHP >= 7.4 так её и
            // читает. Раньше здесь любая кавычка включала режим «внутри поля»,
            // и ридер склеивал строки до следующей кавычки или до конца файла:
            // файл из 10 строк с одной лишней кавычкой в третьей давал 3 строки
            // на PHP 7.3 и 10 на PHP 8.2. Прод старее 7.4, то есть при импорте
            // такого CSV все строки после кавычки молча пропадали.
            // Разбор полей (parseCsvLine) это правило соблюдал всегда —
            // расходилась только склейка строк.
            $len = strlen($raw);
            $i = 0;
            $inQ = false;
            $atFieldStart = true;
            while ($i < $len) {
                $ch = $raw[$i];
                if ($inQ) {
                    if ($ch === '"') {
                        // Заглядываем вперёд: "" — экранированная кавычка
                        if ($i + 1 < $len && $raw[$i + 1] === '"') {
                            $i += 2;
                            continue;
                        }
                        $inQ = false;
                        $atFieldStart = false;
                    }
                } elseif ($ch === '"' && $atFieldStart) {
                    $inQ = true;
                    $atFieldStart = false;
                } elseif ($ch === $delimiter) {
                    $atFieldStart = true;
                } elseif ($ch === "\n" || $ch === "\r") {
                    // Перенос вне кавычек — начало новой строки, значит и нового поля
                    $atFieldStart = true;
                } else {
                    $atFieldStart = false;
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

        // Перекодировка ДО всего остального: разделитель, заголовки и данные
        // читаются из одного дескриптора, и все три должны видеть UTF-8.
        $original = $handle;
        $handle = $this->toUtf8Stream($handle);
        $converted = ($handle !== $original);
        if ($converted) {
            fclose($original);
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
     * Размер пробы, по которой определяется кодировка файла.
     * 64 КБ хватает, чтобы наткнуться на кириллицу в любом реальном CSV,
     * и при этом не читать в память весь файл.
     */
    const ENCODING_PROBE_BYTES = 65536;

    /**
     * Возвращает дескриптор, из которого читается UTF-8.
     *
     * Excel в русской локали по умолчанию сохраняет CSV в CP1251 — это самый
     * вероятный путь, а не экзотика. Раньше перекодировки не было вовсе (поле
     * $encoding и сеттер setEncoding() существовали, но нигде не использовались),
     * и такой файл импортировался с отчётом «Создано: 1, Ошибок: 0», записывая
     * в базу мусор: «Пётр» превращался в «ϸ» — 4 символа в 1.
     *
     * Определяем по пробе с начала файла: валидный UTF-8 не трогаем вовсе,
     * иначе считаем файл CP1251 и перекодируем. Однобайтовая CP1251 переводится
     * в UTF-8 без потерь для любого байта, поэтому «угадали неверно» здесь не
     * приводит к отказу — только к тому же результату, что и раньше.
     *
     * ГРАБЛИ, из-за которых проба обрезается аккуратно: если резать пробу по
     * фиксированной длине, многобайтовый символ UTF-8 разорвётся на границе,
     * mb_check_encoding скажет «не UTF-8», и ВАЛИДНЫЙ файл будет перекодирован
     * как CP1251 — то есть починка сама испортила бы данные. Поэтому перед
     * проверкой отбрасываем незавершённый хвост.
     *
     * Перекодируем в php://temp: до 2 МБ он живёт в памяти, дальше сам уходит на
     * диск, так что 20-мегабайтный файл не съест память. Читать чанками
     * безопасно: CP1251 однобайтовая, символ на границе чанка не разорвётся.
     *
     * @param resource $handle Исходный дескриптор, открытый на чтение
     * @return resource Тот же дескриптор (если файл уже UTF-8) или новый с UTF-8
     */
    private function toUtf8Stream($handle) {
        $probe = fread($handle, self::ENCODING_PROBE_BYTES);
        rewind($handle);

        if ($probe === false || $probe === '' || self::looksLikeUtf8($probe)) {
            return $handle;
        }

        $out = @fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'r+');
        if ($out === false) {
            // Не смогли создать буфер — работаем как раньше. Это хуже, чем
            // перекодировать, но лучше, чем уронить импорт целиком.
            if (class_exists('Logger')) {
                Logger::warning('CsvParser: не удалось создать буфер перекодировки, читаем как есть');
            }
            return $handle;
        }

        while (!feof($handle)) {
            $chunk = fread($handle, self::ENCODING_PROBE_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, mb_convert_encoding($chunk, 'UTF-8', 'Windows-1251'));
        }
        rewind($out);

        if (class_exists('Logger')) {
            Logger::info('CsvParser: файл перекодирован из CP1251 в UTF-8');
        }

        return $out;
    }

    /**
     * Похожа ли проба на валидный UTF-8.
     *
     * Проба обрезана по границе байтов, поэтому последний символ может быть
     * незавершённым. Прежде чем выносить вердикт, отбрасываем до трёх последних
     * байт: если хоть один вариант валиден — файл считаем UTF-8.
     *
     * @param string $probe Первые байты файла
     * @return bool true — UTF-8, перекодировать не нужно
     */
    private static function looksLikeUtf8($probe) {
        for ($cut = 0; $cut <= 3; $cut++) {
            $candidate = $cut === 0 ? $probe : substr($probe, 0, -$cut);
            if ($candidate === '' || $candidate === false) {
                break;
            }
            if (mb_check_encoding($candidate, 'UTF-8')) {
                return true;
            }
        }
        return false;
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
            if (empty(array_filter($values, function($v) { return trim((string)$v) !== ''; }))) {
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

        // Достигли предела — но был ли в файле ещё хвост? Пробуем прочитать одну
        // строку сверх предела: если она есть, файл обрезан. Раньше цикл просто
        // останавливался, и пользователь не узнавал, что залилось не всё.
        $truncated = false;
        if ($lineNum >= $this->maxRows) {
            $extra = $this->readCsvRow($handle, $delimiter);
            if ($extra !== false && !empty(array_filter($extra, function ($v) { return trim((string)$v) !== ''; }))) {
                $truncated = true;
            }
        }

        $this->lastStats = array(
            'rows_parsed'      => count($data),
            'skipped_comments' => $skippedComments,
            'skipped_empty'    => $skippedEmpty,
            'adjusted_columns' => $skippedMismatch,
            'truncated'        => $truncated,
            'max_rows'         => $this->maxRows,
        );

        if (class_exists('Logger')) {
            Logger::info('CSV Parser: Парсинг завершён', $this->lastStats + ['total_lines_read' => $lineNum]);
        }

        return $data;
    }

    /**
     * Статистика последнего разбора: сколько строк прочитано и что пропущено.
     *
     * Ключи: rows_parsed, skipped_comments, skipped_empty, adjusted_columns,
     * truncated (bool), max_rows.
     *
     * Нужна, чтобы импорт мог показать пользователю, что часть файла не доехала.
     * До 2026-08-10 эти числа существовали только в логе: строка с логином,
     * начинающимся на «#», выбрасывалась как комментарий, файл длиннее предела
     * обрезался, а отчёт говорил «Ошибок: 0».
     *
     * @return array Пустой массив, если parse() ещё не вызывали
     */
    public function getLastStats(): array {
        return $this->lastStats;
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
