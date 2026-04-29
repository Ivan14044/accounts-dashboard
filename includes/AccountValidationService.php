<?php
/**
 * AccountValidationService — проверка аккаунтов через NPPR Services API
 *
 * Scheme:
 *   1. prepareItems(rows)   — извлекаем FB ID из id_soc_account/social_url/cookies (только c_user)
 *   2. checkItems(items)    — bulk check через NPPR /services/fbchecker
 *   3. JobProgress (опц.)   — streaming прогресс по мере завершения sub-batches
 *
 * NPPR API (https://npprservices.pro/apidoc):
 *   POST https://npprservices.pro/api/services/fbchecker
 *   Body: { token: "<64char>", accs: ["id1","id2",...] }
 *   Response: {
 *     balance: int,
 *     duplicates: array,
 *     active:   { "<acc>": "<fbid>", ... },     // валидные
 *     banned:   ["<acc>", ...],                 // невалидные (забанены)
 *     notFound: ["<acc>", ...],                 // невалидные (не найдены)
 *     withoutToken: array
 *   }
 *
 * Token storage: ENV NPPR_API_TOKEN, fallback — файл <root>/.nppr_token (gitignored).
 * При деплое (.github/workflows/deploy.yml) GitHub Secret записывается в файл.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/JobProgress.php';

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
     *      от NPPR (notFound) и душат API параллельными запросами без выгоды.
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
     *
     * Если передан $jobId — после каждого sub-batch NPPR (curl_multi_info_read)
     * пишем инкрементальный прогресс в JobProgress. Фронт читает его через
     * polling /progress, поэтому ползунок «Проверено N/M» движется ВНУТРИ
     * одного /check запроса, а не только между ними.
     */
    public static function checkItems(array $items, ?string $jobId = null): array
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

        // Готовим callback прогресса: каждое завершение sub-batch продвигает
        // счётчик checked на пропорциональную долю items. Используем
        // накопительный расчёт, чтобы сумма точно равнялась count($items).
        $progressCb = null;
        if ($jobId !== null && $jobId !== '' && !empty($allFbIds)) {
            $totalItems  = count($items);
            $batchCount  = (int)ceil(count($allFbIds) / Config::NPPR_BATCH_SIZE);
            $completed   = 0;
            $progressCb  = function () use (&$completed, $totalItems, $batchCount, $jobId) {
                $completed++;
                $totalAfter   = (int)round($completed * $totalItems / max(1, $batchCount));
                $totalBefore  = (int)round(($completed - 1) * $totalItems / max(1, $batchCount));
                $delta        = $totalAfter - $totalBefore;
                if ($delta > 0) {
                    JobProgress::update($jobId, ['checked' => $delta]);
                }
            };
        }

        $results = self::checkFbIdsBulk(array_keys($allFbIds), $progressCb);

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

        // Также пишем accumulated valid/invalid — фронт может их использовать
        // для живого ratio-бара (опционально).
        if ($jobId !== null && $jobId !== '') {
            JobProgress::update($jobId, [
                'valid'   => count($valid),
                'invalid' => count($invalid),
                'skipped' => count($skipped),
            ]);
        }

        return ['valid' => $valid, 'invalid' => $invalid, 'skipped' => $skipped];
    }

    /**
     * Check FB IDs in batches via NPPR fbchecker bulk API.
     * Sub-batches run in parallel via curl_multi. Failed batches are retried.
     *
     * @param callable|null $onSubBatchDone Вызывается каждый раз когда sub-batch
     *   завершается (curl_multi_info_read). Используется для streaming прогресса.
     * @return array [fb_id => bool]
     */
    private static function checkFbIdsBulk(array $fbIds, ?callable $onSubBatchDone = null): array
    {
        $fbIds = array_values(array_unique(array_filter(array_map('strval', $fbIds))));
        if (empty($fbIds)) return [];

        $token = Config::getNpprToken();
        if ($token === '') {
            Logger::warning('NPPR token is not configured', [
                'env'  => Config::NPPR_TOKEN_ENV,
                'file' => Config::NPPR_TOKEN_FILE,
            ]);
            // Без токена ни один батч не отработает — все считаем невалидными.
            // Прогресс-callback всё равно вызываем, чтобы UI не висел.
            $batchCount = (int)ceil(count($fbIds) / Config::NPPR_BATCH_SIZE);
            for ($i = 0; $i < $batchCount; $i++) {
                if ($onSubBatchDone !== null) {
                    try { $onSubBatchDone(); } catch (\Throwable $e) {}
                }
            }
            $out = [];
            foreach ($fbIds as $fbId) $out[$fbId] = false;
            return $out;
        }

        $batches      = array_chunk($fbIds, Config::NPPR_BATCH_SIZE);
        $batchResults = self::runParallel($batches, $token, $onSubBatchDone);

        $failedIdx = [];
        foreach ($batchResults as $idx => $res) {
            if ($res === null) $failedIdx[] = $idx;
        }

        for ($attempt = 1; $attempt <= 2 && !empty($failedIdx); $attempt++) {
            // 300ms / 600ms — короткая пауза перед retry. Большинство сетевых
            // сбоев восстанавливаются мгновенно; секундная пауза только ухудшала UX.
            usleep($attempt * 300000);
            Logger::debug('NPPR fbchecker retry', ['attempt' => $attempt, 'batches' => count($failedIdx)]);

            $retryBatches = [];
            foreach ($failedIdx as $idx) $retryBatches[$idx] = $batches[$idx];

            // На retry callback не вызываем — прогресс уже учтён при первой попытке
            $retryResults = self::runParallel($retryBatches, $token, null);
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
                Logger::warning('NPPR fbchecker batch failed after retries', ['count' => count($batches[$idx])]);
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
     * Парсит каждый sub-batch ПО МЕРЕ завершения (curl_multi_info_read) и
     * вызывает $onSubBatchDone — это даёт streaming прогресс пользователю
     * вместо ожидания всех sub-batches сразу.
     *
     * @param array $batches [idx => array<string>]
     * @param string $token NPPR API token
     * @param callable|null $onSubBatchDone Вызывается без аргументов после каждого
     *   завершившегося sub-batch (успех или провал — оба считаются «обработанными»).
     * @return array [idx => array<fb_id, bool>|null]
     */
    private static function runParallel(array $batches, string $token, ?callable $onSubBatchDone = null): array
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

        $results    = [];
        $processed  = []; // idx → true когда уже обработали (защита от двойного вызова callback)

        $drainCompleted = function () use ($mh, &$handles, &$batches, &$results, &$processed, $onSubBatchDone) {
            while (($info = curl_multi_info_read($mh)) !== false) {
                if (!isset($info['handle'])) continue;
                $handle = $info['handle'];

                // Найти idx по handle
                $foundIdx = null;
                foreach ($handles as $idx => $ch) {
                    if ($ch === $handle) { $foundIdx = $idx; break; }
                }
                if ($foundIdx === null || isset($processed[$foundIdx])) continue;
                $processed[$foundIdx] = true;

                $results[$foundIdx] = self::parseHandleResult($handle, $batches[$foundIdx], $foundIdx);

                if ($onSubBatchDone !== null) {
                    try { $onSubBatchDone(); } catch (\Throwable $e) {
                        Logger::warning('progress callback threw', ['err' => $e->getMessage()]);
                    }
                }
            }
        };

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            $drainCompleted();
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($running && $status === CURLM_OK) {
            if (curl_multi_select($mh, 1.0) === -1) {
                usleep(100);
            }
            do {
                $status = curl_multi_exec($mh, $running);
                $drainCompleted();
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        // Подбираем хвост (на случай если какой-то handle не был замечен через info_read)
        foreach ($handles as $idx => $ch) {
            if (!isset($processed[$idx])) {
                $results[$idx] = self::parseHandleResult($ch, $batches[$idx], $idx);
                if ($onSubBatchDone !== null) {
                    try { $onSubBatchDone(); } catch (\Throwable $e) {}
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $results;
    }

    /**
     * Парсит ответ одного curl handle. Вынесено из runParallel чтобы можно было
     * вызывать ПО МЕРЕ завершения каждого sub-batch.
     *
     * Формат ответа NPPR (см. https://npprservices.pro/apidoc):
     *   {
     *     "balance": int,
     *     "active":   { "<acc>": "<fbid>", ... },  // валидные → true
     *     "banned":   ["<acc>", ...],              // забанены → false
     *     "notFound": ["<acc>", ...],              // не найдены → false
     *     "withoutToken": [...],
     *     "duplicates": [...]
     *   }
     *
     * @return array|null [fb_id => bool] или null при сетевой/парс ошибке
     */
    private static function parseHandleResult($ch, array $batch, $batchIdx): ?array
    {
        $body     = curl_multi_getcontent($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        if ($error || $httpCode !== 200 || $body === false || $body === '') {
            Logger::warning('NPPR fbchecker request failed', [
                'http_code' => $httpCode,
                'error'     => $error,
                'batch_idx' => $batchIdx,
                'body'      => is_string($body) ? substr($body, 0, 500) : '',
            ]);
            return null;
        }

        $json = @json_decode($body, true);
        if (!is_array($json)) {
            Logger::warning('NPPR fbchecker invalid response', [
                'body'      => substr((string)$body, 0, 500),
                'batch_idx' => $batchIdx,
            ]);
            return null;
        }

        // NPPR ошибки приходят как { error: "..." } или { message: "..." }.
        // Если нет ни одной из ожидаемых категорий — это API-ошибка.
        if (isset($json['error']) ||
            (!isset($json['active']) && !isset($json['banned']) && !isset($json['notFound']))
        ) {
            Logger::warning('NPPR fbchecker API error', [
                'batch_idx' => $batchIdx,
                'body'      => substr((string)$body, 0, 500),
            ]);
            return null;
        }

        // Полезно для мониторинга — видно когда баланс заканчивается
        if (isset($json['balance'])) {
            Logger::debug('NPPR fbchecker balance', [
                'balance'   => (int)$json['balance'],
                'batch_idx' => $batchIdx,
            ]);
        }

        $batchResult = [];

        // active: { "acc" => "fb_id" } — валидные
        if (isset($json['active']) && is_array($json['active'])) {
            foreach ($json['active'] as $acc => $_fbId) {
                $key = (string)$acc;
                if ($key !== '') $batchResult[$key] = true;
            }
        }

        // banned/notFound: либо обычный массив строк, либо ассоциативный.
        // Защищаемся от обоих форматов на всякий случай.
        $invalidLists = ['banned', 'notFound'];
        foreach ($invalidLists as $listKey) {
            if (isset($json[$listKey]) && is_array($json[$listKey])) {
                foreach ($json[$listKey] as $k => $v) {
                    $acc = is_string($k) ? $k : (string)$v;
                    if ($acc !== '' && !isset($batchResult[$acc])) {
                        $batchResult[$acc] = false;
                    }
                }
            }
        }

        // ID, не попавшие ни в одну категорию — false (теоретически таких быть не должно)
        foreach ($batch as $fbId) {
            $key = (string)$fbId;
            if (!isset($batchResult[$key])) {
                $batchResult[$key] = false;
            }
        }

        return $batchResult;
    }
}
