<?php
/**
 * Сервис проверки Facebook-аккаунтов через NPPR Services API
 * (https://npprservices.pro/api/services/fbchecker).
 *
 * Использует token-based валидацию: NPPR при checkToken=1 находит
 * access_token внутри строки и реально аутентифицируется в FB,
 * что отлавливает ban/checkpoint, недоступный анонимной проверке.
 *
 * Конфигурация: env-переменная NPPR_TOKEN (64 символа).
 */

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';

class FbCheckerService {
    const NPPR_URL          = 'https://npprservices.pro/api/services/fbchecker';
    const BATCH_SIZE        = 50;
    const TIMEOUT_SEC       = 30;
    const CONNECT_TIMEOUT   = 10;
    const RETRY_COUNT       = 1;
    const RETRY_DELAY_SEC   = 2;

    /** @var string */
    private $token;

    public function __construct() {
        $this->token = (string)(getenv('NPPR_TOKEN') ?: ($_ENV['NPPR_TOKEN'] ?? ''));
    }

    public function isConfigured(): bool {
        return $this->token !== '';
    }

    /**
     * Извлекает первый FB ID (10... или 61... + 10–23 символа) из строки.
     */
    public static function extractFbId(string $line): ?string {
        if ($line === '') return null;
        if (preg_match('/\b(?:10|61)[0-9A-Za-z]{10,23}\b/', $line, $m)) {
            return $m[0];
        }
        return null;
    }

    /**
     * Строит "плотную" строку для NPPR: pipe-separated fields.
     * NPPR парсит токен/ID из произвольной строки, но мы кладём
     * максимум данных, чтобы повысить шанс матча.
     */
    public function buildAccountString(array $row): string {
        $fbId = '';
        if (!empty($row['social_url'])) {
            $fbId = self::extractFbId((string)$row['social_url']) ?? '';
        }
        if ($fbId === '' && !empty($row['cookies'])) {
            $fbId = self::extractFbId((string)$row['cookies']) ?? '';
        }
        if ($fbId === '' && !empty($row['token'])) {
            $fbId = self::extractFbId((string)$row['token']) ?? '';
        }

        $parts = [
            $fbId,
            $row['password']        ?? '',
            $row['email']           ?? ($row['login'] ?? ''),
            $row['email_password']  ?? '',
            $row['first_name']      ?? '',
            $row['last_name']       ?? '',
            $row['social_url']      ?? '',
            $row['birth_day']       ?? '',
            $row['birth_month']     ?? '',
            $row['birth_year']      ?? '',
            $row['token']           ?? '',
            $row['ads_id']          ?? '',
            $row['cookies']         ?? '',
            $row['user_agent']      ?? '',
            $row['two_fa']          ?? '',
        ];

        // Защита: внутрь полей мог попасть '|' / '\n' — заменяем,
        // чтобы строка осталась single-line и не сломала парсер NPPR.
        $clean = array_map(static function ($v) {
            return str_replace(["\r", "\n", '|'], [' ', ' ', '/'], (string)$v);
        }, $parts);

        return implode('|', $clean);
    }

    /**
     * Проверяет массив строк через NPPR. Дедупит по содержимому.
     *
     * @param string[] $lines
     * @return array {
     *   results:   array<int, array{line:string,status:string}>  // те же ключи и порядок что во входе
     *   breakdown: array<string,int>
     *   balance:   int|null
     * }
     */
    public function checkLines(array $lines): array {
        $count = count($lines);
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[$i] = ['line' => (string)($lines[$i] ?? ''), 'status' => 'error'];
        }
        if ($count === 0) {
            return ['results' => $results, 'breakdown' => $this->emptyBreakdown(), 'balance' => null];
        }
        if (!$this->isConfigured()) {
            Logger::error('FbCheckerService: NPPR_TOKEN is not configured');
            return ['results' => $results, 'breakdown' => $this->emptyBreakdown(), 'balance' => null];
        }

        // Дедуп по line, сохраняем индексы.
        $unique = [];
        $lineToIdxs = [];
        foreach ($results as $idx => $r) {
            $line = $r['line'];
            if (!isset($lineToIdxs[$line])) {
                $lineToIdxs[$line] = [];
                $unique[] = $line;
            }
            $lineToIdxs[$line][] = $idx;
        }

        $balance = null;
        $totalUnique = count($unique);
        for ($i = 0; $i < $totalUnique; $i += self::BATCH_SIZE) {
            $batch = array_slice($unique, $i, self::BATCH_SIZE);
            $batchResp = $this->callNpprBatch($batch);
            if ($batchResp['balance'] !== null) {
                $balance = $batchResp['balance'];
            }
            foreach ($batch as $line) {
                $status = $batchResp['statuses'][$line] ?? 'error';
                foreach ($lineToIdxs[$line] as $idx) {
                    $results[$idx]['status'] = $status;
                }
            }
        }

        $breakdown = $this->emptyBreakdown();
        foreach ($results as $r) {
            if (isset($breakdown[$r['status']])) $breakdown[$r['status']]++;
        }

        return [
            'results'   => $results,
            'breakdown' => $breakdown,
            'balance'   => $balance,
        ];
    }

    private function emptyBreakdown(): array {
        return [
            'active'       => 0,
            'banned'       => 0,
            'notFound'     => 0,
            'withoutToken' => 0,
            'duplicate'    => 0,
            'error'        => 0,
        ];
    }

    /**
     * Один HTTP-вызов в NPPR с одним ретраем.
     *
     * @param string[] $batch
     * @return array{statuses: array<string,string>, balance: int|null}
     */
    private function callNpprBatch(array $batch): array {
        $statuses = [];
        foreach ($batch as $line) $statuses[$line] = 'error';

        $payload = json_encode([
            'token'      => $this->token,
            'accs'       => array_values($batch),
            'checkToken' => 1,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $body = null;
        for ($attempt = 0; $attempt <= self::RETRY_COUNT; $attempt++) {
            if ($attempt > 0) sleep(self::RETRY_DELAY_SEC);

            $ch = curl_init(self::NPPR_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            ]);
            $response = curl_exec($ch);
            $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            if ($response === false || $code !== 200) {
                Logger::error('NPPR batch failed: ' . ($err !== '' ? $err : ('HTTP ' . $code)));
                continue;
            }
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                Logger::error('NPPR returned bad JSON');
                continue;
            }
            $body = $decoded;
            break;
        }

        if (!is_array($body)) {
            return ['statuses' => $statuses, 'balance' => null];
        }

        // active — объект { line => fbId }
        if (!empty($body['active']) && is_array($body['active'])) {
            foreach (array_keys($body['active']) as $line) {
                if (isset($statuses[$line])) $statuses[$line] = 'active';
            }
        }

        $markArr = static function ($arr, $label) use (&$statuses) {
            if (!is_array($arr)) return;
            foreach ($arr as $line) {
                if (is_string($line) && isset($statuses[$line]) && $statuses[$line] !== 'active') {
                    $statuses[$line] = $label;
                }
            }
        };
        $markArr($body['banned']     ?? null, 'banned');
        $markArr($body['notFound']   ?? null, 'notFound');
        $markArr($body['duplicates'] ?? null, 'duplicate');

        // withoutToken — лишь для тех, кому не выставили статус явно
        if (!empty($body['withoutToken']) && is_array($body['withoutToken'])) {
            foreach ($body['withoutToken'] as $line) {
                if (is_string($line) && isset($statuses[$line]) && $statuses[$line] === 'error') {
                    $statuses[$line] = 'withoutToken';
                }
            }
        }

        $balance = isset($body['balance']) ? (int)$body['balance'] : null;
        return ['statuses' => $statuses, 'balance' => $balance];
    }
}
