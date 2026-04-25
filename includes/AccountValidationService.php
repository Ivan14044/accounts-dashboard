<?php
/**
 * AccountValidationService — проверка аккаунтов через NPPR Services API
 *
 * Scheme:
 *   1. prepareItems(rows)  — extract FB IDs from id_soc_account/social_url/cookies
 *   2. checkItems(items)   — bulk check via NPPR /services/fbchecker
 *
 * NPPR API (https://npprservices.pro/apidoc):
 *   POST /services/fbchecker
 *   Body: { token: "<64char>", accs: ["id1","id2",...] }
 *   Response: {
 *     balance: int,
 *     duplicates: array,
 *     active: { "<acc>": "<fbid>", ... },
 *     banned: array,
 *     notFound: array,
 *     withoutToken: array
 *   }
 *
 * Token storage: ENV NPPR_API_TOKEN, fallback — file <project>/nppr_token.txt (gitignored).
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';

class AccountValidationService
{
    /** FB ID regex: starts with 10 or 61, then 10-23 alphanumeric chars */
    private const FB_ID_PATTERN = '/\b(10|61)[0-9A-Za-z]{10,23}\b/';

    // ────────────────────────────────────────────────────────
    // Step 1: Prepare
    // ────────────────────────────────────────────────────────

    /**
     * Extract FB IDs from rows, split into items (with IDs) and skipped (without).
     */
    public static function prepareItems(array $rows): array
    {
        $items   = [];
        $skipped = [];

        foreach ($rows as $row) {
            $id    = (int)($row['id'] ?? 0);
            $login = trim((string)($row['login'] ?? ''));
            $fbIds = self::extractFbIds($row);

            if (empty($fbIds)) {
                $skipped[] = ['id' => $id, 'login' => $login];
            } else {
                $items[] = ['id' => $id, 'login' => $login, 'fb_ids' => $fbIds];
            }
        }

        return ['items' => $items, 'skipped' => $skipped];
    }

    /** Extract unique FB IDs from id_soc_account, social_url and cookies fields */
    private static function extractFbIds(array $row): array
    {
        $ids = [];
        $sources = [
            trim((string)($row['id_soc_account'] ?? '')),
            trim((string)($row['social_url'] ?? '')),
            (string)($row['cookies'] ?? ''),
        ];

        foreach ($sources as $text) {
            if ($text !== '' && preg_match_all(self::FB_ID_PATTERN, $text, $m)) {
                foreach ($m[0] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    // ────────────────────────────────────────────────────────
    // Step 2: Check
    // ────────────────────────────────────────────────────────

    /**
     * Check items. An account is valid if at least one of its FB IDs is valid.
     */
    public static function checkItems(array $items): array
    {
        $valid   = [];
        $invalid = [];
        $skipped = [];

        // Collect all unique FB IDs
        $allFbIds = [];
        foreach ($items as $item) {
            $fbIds = $item['fb_ids'] ?? [];
            if (empty($fbIds)) {
                $skipped[] = ['id' => $item['id'] ?? 0, 'login' => $item['login'] ?? ''];
                continue;
            }
            foreach ($fbIds as $fbId) {
                $allFbIds[$fbId] = true;
            }
        }

        // Bulk check via NPPR
        $results = self::checkFbIdsBulk(array_keys($allFbIds));

        // Map results back to items
        foreach ($items as $item) {
            $fbIds = $item['fb_ids'] ?? [];
            if (empty($fbIds)) continue;

            $isValid = false;
            foreach ($fbIds as $fbId) {
                if (isset($results[$fbId]) && $results[$fbId] === true) {
                    $isValid = true;
                    break;
                }
            }

            $entry = ['id' => $item['id'] ?? 0, 'login' => $item['login'] ?? ''];
            $isValid ? ($valid[] = $entry) : ($invalid[] = $entry);
        }

        return ['valid' => $valid, 'invalid' => $invalid, 'skipped' => $skipped];
    }

    /**
     * Check FB IDs in batches via NPPR fbchecker bulk API.
     * Все суб-батчи выполняются ПАРАЛЛЕЛЬНО через curl_multi.
     * Ретраи неудачных батчей — тоже параллельно.
     *
     * @return array [fb_id => bool]
     */
    private static function checkFbIdsBulk(array $fbIds): array
    {
        $fbIds = array_values(array_unique(array_filter(array_map('strval', $fbIds))));
        if (empty($fbIds)) return [];

        $token = Config::getNpprToken();
        if ($token === '') {
            Logger::warning('NPPR token is not configured', [
                'env'  => Config::NPPR_TOKEN_ENV,
                'file' => Config::NPPR_TOKEN_FILE,
            ]);
            // Без токена ни один батч не отработает — все считаем невалидными
            $out = [];
            foreach ($fbIds as $fbId) $out[$fbId] = false;
            return $out;
        }

        $batches = array_chunk($fbIds, Config::NPPR_BATCH_SIZE);

        // Fire all batches in parallel
        $batchResults = self::runParallel($batches, $token);

        // Collect failed batch indices and retry them (also in parallel)
        $failedIdx = [];
        foreach ($batchResults as $idx => $res) {
            if ($res === null) $failedIdx[] = $idx;
        }

        for ($attempt = 1; $attempt <= Config::NPPR_RETRY_COUNT && !empty($failedIdx); $attempt++) {
            sleep(Config::NPPR_RETRY_DELAY * $attempt);
            Logger::debug('NPPR fbchecker retry', [
                'attempt' => $attempt,
                'batches' => count($failedIdx),
            ]);

            $retryBatches = [];
            foreach ($failedIdx as $idx) $retryBatches[$idx] = $batches[$idx];

            $retryResults = self::runParallel($retryBatches, $token);

            $newFailed = [];
            foreach ($retryResults as $originalIdx => $res) {
                if ($res !== null) {
                    $batchResults[$originalIdx] = $res;
                } else {
                    $newFailed[] = $originalIdx;
                }
            }
            $failedIdx = $newFailed;
        }

        // Combine all batch results
        $results = [];
        foreach ($batchResults as $idx => $res) {
            if (is_array($res)) {
                foreach ($res as $fbId => $isValid) {
                    $results[$fbId] = $isValid;
                }
            } else {
                // All retries failed — mark batch as false
                Logger::warning('NPPR fbchecker batch failed after retries', ['count' => count($batches[$idx])]);
                foreach ($batches[$idx] as $fbId) {
                    $results[$fbId] = false;
                }
            }
        }

        return $results;
    }

    /**
     * Выполнить несколько запросов к NPPR fbchecker параллельно через curl_multi.
     * Ключи входного массива $batches сохраняются в результате.
     *
     * @param array  $batches [idx => array<string>]
     * @param string $token   NPPR API token
     * @return array [idx => array|null]  массив [fb_id => bool] при успехе, null при ошибке
     */
    private static function runParallel(array $batches, string $token): array
    {
        if (empty($batches)) return [];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($batches as $idx => $batch) {
            $payload = json_encode([
                'token' => $token,
                'accs'  => array_map('strval', array_values($batch)),
            ]);

            $ch = curl_init(Config::NPPR_FBCHECK_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => Config::NPPR_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        // Крутим цикл до завершения всех запросов.
        // Паттерн устойчив к curl_multi_select() == -1 на некоторых системах.
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($running && $status === CURLM_OK) {
            if (curl_multi_select($mh, 1.0) === -1) {
                usleep(100);
            }
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        $results     = [];
        $lastBalance = null;

        foreach ($handles as $idx => $ch) {
            $body     = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($error || $httpCode !== 200 || $body === false || $body === '') {
                Logger::warning('NPPR fbchecker request failed', [
                    'http_code' => $httpCode,
                    'error'     => $error,
                    'batch_idx' => $idx,
                    'body'      => is_string($body) ? substr($body, 0, 500) : '',
                ]);
                $results[$idx] = null;
                continue;
            }

            $json = @json_decode($body, true);
            if (!is_array($json)) {
                Logger::warning('NPPR fbchecker invalid response', [
                    'body'      => substr((string)$body, 0, 500),
                    'batch_idx' => $idx,
                ]);
                $results[$idx] = null;
                continue;
            }

            // NPPR возвращает ошибки в формате { error: "..." } или { message: "..." }
            if (isset($json['error']) || (!isset($json['active']) && !isset($json['banned']) && !isset($json['notFound']))) {
                Logger::warning('NPPR fbchecker API error', [
                    'batch_idx' => $idx,
                    'body'      => substr((string)$body, 0, 500),
                ]);
                $results[$idx] = null;
                continue;
            }

            if (isset($json['balance'])) {
                $lastBalance = (int)$json['balance'];
            }

            $batchResult = [];

            // active: {acc => fb_id} — валидные
            if (isset($json['active']) && is_array($json['active'])) {
                foreach ($json['active'] as $acc => $_fbId) {
                    $key = (string)$acc;
                    if ($key !== '') $batchResult[$key] = true;
                }
            }

            // banned: array of acc strings — невалидные (забанены)
            if (isset($json['banned']) && is_array($json['banned'])) {
                foreach ($json['banned'] as $k => $v) {
                    // На случай если NPPR вернёт ассоциативный массив (как для других чекеров)
                    $acc = is_string($k) ? $k : (string)$v;
                    if ($acc !== '' && !isset($batchResult[$acc])) {
                        $batchResult[$acc] = false;
                    }
                }
            }

            // notFound: array of acc strings — невалидные (не найдены)
            if (isset($json['notFound']) && is_array($json['notFound'])) {
                foreach ($json['notFound'] as $k => $v) {
                    $acc = is_string($k) ? $k : (string)$v;
                    if ($acc !== '' && !isset($batchResult[$acc])) {
                        $batchResult[$acc] = false;
                    }
                }
            }

            // ID, не попавшие ни в одну категорию (теоретически таких быть не должно) — false
            foreach ($batches[$idx] as $fbId) {
                $key = (string)$fbId;
                if (!isset($batchResult[$key])) {
                    $batchResult[$key] = false;
                }
            }

            $results[$idx] = $batchResult;
        }

        curl_multi_close($mh);

        if ($lastBalance !== null) {
            Logger::debug('NPPR fbchecker balance', ['balance' => $lastBalance]);
        }

        return $results;
    }
}
