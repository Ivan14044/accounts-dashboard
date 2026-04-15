<?php
/**
 * AccountValidationService — проверка аккаунтов через check.fb.tools
 *
 * Scheme:
 *   1. prepareItems(rows)  — extract FB IDs from id_soc_account/social_url/cookies
 *   2. checkItems(items)   — bulk check via check.fb.tools API
 *
 * check.fb.tools API:
 *   POST /api/check/account
 *   Body: { inputData: ["id1","id2",...], checkFriends: false, userLang: "ru" }
 *   Response: { data: [{ account, uid, status: { name: "valid"|"invalid" } }], info: {...} }
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

        // Bulk check via check.fb.tools
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
     * Check FB IDs in batches via check.fb.tools bulk API.
     *
     * @return array [fb_id => bool]
     */
    private static function checkFbIdsBulk(array $fbIds): array
    {
        $fbIds     = array_values(array_unique(array_filter(array_map('strval', $fbIds))));
        $results   = [];
        $batchSize = Config::FB_CHECK_BATCH_SIZE;

        for ($offset = 0; $offset < count($fbIds); $offset += $batchSize) {
            $batch = array_slice($fbIds, $offset, $batchSize);

            $batchResults = self::callCheckApi($batch);

            if ($batchResults === null) {
                // Retry with delay
                for ($attempt = 1; $attempt <= Config::FB_CHECK_RETRY_COUNT; $attempt++) {
                    sleep(Config::FB_CHECK_RETRY_DELAY * $attempt);
                    Logger::debug('check.fb.tools retry', [
                        'attempt' => $attempt,
                        'count'   => count($batch),
                    ]);
                    $batchResults = self::callCheckApi($batch);
                    if ($batchResults !== null) break;
                }
            }

            if (is_array($batchResults)) {
                foreach ($batchResults as $fbId => $isValid) {
                    $results[$fbId] = $isValid;
                }
            } else {
                // All retries failed — mark batch as false
                Logger::warning('check.fb.tools batch failed after retries', ['count' => count($batch)]);
                foreach ($batch as $fbId) {
                    $results[$fbId] = false;
                }
            }
        }

        return $results;
    }

    /**
     * Single API call to check.fb.tools.
     *
     * @param array $fbIds List of FB IDs to check
     * @return array|null [fb_id => bool] on success, null on error
     */
    private static function callCheckApi(array $fbIds): ?array
    {
        $payload = json_encode([
            'inputData'    => array_map('strval', array_values($fbIds)),
            'checkFriends' => false,
            'userLang'     => 'ru',
        ]);

        $ch = curl_init(Config::FB_CHECK_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => Config::FB_CHECK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200 || $body === false) {
            Logger::warning('check.fb.tools request failed', [
                'http_code' => $httpCode,
                'error'     => $error,
            ]);
            return null;
        }

        $json = @json_decode($body, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            Logger::warning('check.fb.tools invalid response', [
                'body' => substr((string)$body, 0, 500),
            ]);
            return null;
        }

        $results = [];
        foreach ($json['data'] as $entry) {
            $account = (string)($entry['account'] ?? '');
            $status  = (string)($entry['status']['name'] ?? '');
            if ($account !== '') {
                $results[$account] = ($status === 'valid');
            }
        }

        return $results;
    }
}
