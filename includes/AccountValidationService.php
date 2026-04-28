<?php
/**
 * AccountValidationService — проверка аккаунтов через acctool.top checker API
 *
 * API (без токена):
 *   POST https://checker.acctool.top/check
 *   Body: { lines: ["id1", "id2", ...] }
 *   Response: { results: [{ full_line: "id1", id: "extracted_id", status: "Active|Blocked|Invalid ID" }] }
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

    /**
     * Extract unique FB IDs that belong to THIS account (own ID only).
     * Sources, in priority:
     *   1. id_soc_account — direct, authoritative
     *   2. social_url     — usually facebook.com/profile.php?id=... or vanity link
     *   3. cookies        — ONLY the c_user value (authenticated user's FB ID).
     *      Не сканируем cookies регуляркой целиком: куки содержат десятки чужих
     *      FB-ID (реклама, друзья, посты, трекеры). Они возвращают «not found»
     *      от acctool и душат API параллельными запросами.
     */
    private static function extractFbIds(array $row): array
    {
        $ids = [];

        $idSoc = trim((string)($row['id_soc_account'] ?? ''));
        if ($idSoc !== '' && preg_match_all(self::FB_ID_PATTERN, $idSoc, $m)) {
            foreach ($m[0] as $id) $ids[$id] = true;
        }

        $socialUrl = trim((string)($row['social_url'] ?? ''));
        if ($socialUrl !== '' && preg_match_all(self::FB_ID_PATTERN, $socialUrl, $m)) {
            foreach ($m[0] as $id) $ids[$id] = true;
        }

        $cookies = (string)($row['cookies'] ?? '');
        if ($cookies !== '') {
            $cUser = self::extractCUserFromCookies($cookies);
            if ($cUser !== null) {
                $ids[$cUser] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Extract c_user from cookies. Supports two formats:
     *   1. JSON array: [{"name":"c_user","value":"123..."}, ...]
     *   2. Cookie-string: "c_user=123...; xs=...; ..."
     *
     * Hot-path optimization: stripos() ранний return для всех cookies без c_user
     * (например, гугловые/трекерные cookies). На больших батчах это экономит
     * мегабайты regex-сканирования и сотни json_decode вызовов.
     */
    private static function extractCUserFromCookies(string $cookies): ?string
    {
        if ($cookies === '' || stripos($cookies, 'c_user') === false) {
            return null;
        }

        $trim = ltrim($cookies);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $decoded = json_decode($cookies, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cookie) {
                    if (is_array($cookie)
                        && isset($cookie['name'], $cookie['value'])
                        && strcasecmp((string)$cookie['name'], 'c_user') === 0
                    ) {
                        $val = (string)$cookie['value'];
                        if (preg_match('/^[0-9]{8,23}$/', $val)) {
                            return $val;
                        }
                    }
                }
            }
        }

        if (preg_match('/(?:^|[\s;])c_user=([0-9]{8,23})\b/i', $cookies, $m)) {
            return $m[1];
        }

        return null;
    }

    // ────────────────────────────────────────────────────────
    // Step 2: Check
    // ────────────────────────────────────────────────────────

    /**
     * Check items. An account is valid if at least one of its FB IDs is active.
     */
    public static function checkItems(array $items): array
    {
        $valid   = [];
        $invalid = [];
        $skipped = [];

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

        $results = self::checkFbIdsBulk(array_keys($allFbIds));

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
     * Check FB IDs in batches via acctool.top bulk API.
     * Sub-batches run in parallel via curl_multi. Failed batches are retried.
     *
     * @return array [fb_id => bool]
     */
    private static function checkFbIdsBulk(array $fbIds): array
    {
        $fbIds = array_values(array_unique(array_filter(array_map('strval', $fbIds))));
        if (empty($fbIds)) return [];

        $batches      = array_chunk($fbIds, Config::ACCTOOL_BATCH_SIZE);
        $batchResults = self::runParallel($batches);

        $failedIdx = [];
        foreach ($batchResults as $idx => $res) {
            if ($res === null) $failedIdx[] = $idx;
        }

        for ($attempt = 1; $attempt <= 2 && !empty($failedIdx); $attempt++) {
            // 300ms / 600ms — короткая пауза перед retry. Раньше было sleep(1)/sleep(2),
            // но это «мёртвая» задержка для пользователя: 30с timeout + 1с sleep + retry...
            // Большинство сетевых сбоев восстанавливаются мгновенно, секундная пауза не
            // даёт реального преимущества, только ухудшает UX.
            usleep($attempt * 300000);
            Logger::debug('acctool checker retry', ['attempt' => $attempt, 'batches' => count($failedIdx)]);

            $retryBatches = [];
            foreach ($failedIdx as $idx) $retryBatches[$idx] = $batches[$idx];

            $retryResults = self::runParallel($retryBatches);
            $newFailed    = [];
            foreach ($retryResults as $originalIdx => $res) {
                if ($res !== null) {
                    $batchResults[$originalIdx] = $res;
                } else {
                    $newFailed[] = $originalIdx;
                }
            }
            $failedIdx = $newFailed;
        }

        $results = [];
        foreach ($batchResults as $idx => $res) {
            if (is_array($res)) {
                foreach ($res as $fbId => $isValid) {
                    $results[$fbId] = $isValid;
                }
            } else {
                Logger::warning('acctool checker batch failed after retries', ['count' => count($batches[$idx])]);
                foreach ($batches[$idx] as $fbId) {
                    $results[$fbId] = false;
                }
            }
        }

        return $results;
    }

    /**
     * Run batches in parallel via curl_multi.
     *
     * @param array $batches [idx => array<string>]
     * @return array [idx => array<fb_id, bool>|null]
     */
    private static function runParallel(array $batches): array
    {
        if (empty($batches)) return [];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($batches as $idx => $batch) {
            $payload = json_encode(['lines' => array_map('strval', array_values($batch))]);

            $ch = curl_init(Config::ACCTOOL_CHECK_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => Config::ACCTOOL_TIMEOUT,
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

        $results = [];

        foreach ($handles as $idx => $ch) {
            $body     = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($error || $httpCode !== 200 || $body === false || $body === '') {
                Logger::warning('acctool checker request failed', [
                    'http_code' => $httpCode,
                    'error'     => $error,
                    'batch_idx' => $idx,
                    'body'      => is_string($body) ? substr($body, 0, 500) : '',
                ]);
                $results[$idx] = null;
                continue;
            }

            $json = @json_decode($body, true);
            if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
                Logger::warning('acctool checker invalid response', [
                    'body'      => substr((string)$body, 0, 500),
                    'batch_idx' => $idx,
                ]);
                $results[$idx] = null;
                continue;
            }

            $batchResult = [];
            foreach ($json['results'] as $item) {
                $line   = (string)($item['full_line'] ?? '');
                $status = (string)($item['status']    ?? '');
                if ($line !== '') {
                    $batchResult[$line] = (strtolower($status) === 'active');
                }
            }

            // IDs absent from response → false
            foreach ($batches[$idx] as $fbId) {
                $key = (string)$fbId;
                if (!isset($batchResult[$key])) {
                    $batchResult[$key] = false;
                }
            }

            $results[$idx] = $batchResult;
        }

        curl_multi_close($mh);

        return $results;
    }
}
