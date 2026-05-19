<?php
/**
 * AccountValidationService — проверка аккаунтов через check.fb.tools
 *
 * Scheme:
 *   1. prepareItems(rows)   — извлекаем FB ID из id_soc_account/social_url/cookies (только c_user)
 *   2. checkItems(items)    — bulk check через check.fb.tools /api/check/account
 *   3. JobProgress (опц.)   — streaming прогресс по мере завершения sub-batches
 *
 * check.fb.tools API (без авторизации):
 *   POST https://check.fb.tools/api/check/account
 *   Body: { inputData: ["id1","id2",...], checkFriends: false, userLang: "ru" }
 *   Response: {
 *     data: [
 *       { account: "<acc>", uid: "<fbid>", status: { name: "valid"|"invalid" } },
 *       ...
 *     ],
 *     info: {...}
 *   }
 *
 * Бинарный результат: status.name === "valid" → true, иначе → false.
 *
 * Авторизация не требуется — токен/секрет не нужны. Раньше использовался
 * NPPR Services (платный, с балансом), переехали на check.fb.tools после
 * того как стало нужно только valid/invalid без 5 категорий NPPR.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/JobProgress.php';
require_once __DIR__ . '/AccountFingerprint.php';

class AccountValidationService
{

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
     * Делегирует в AccountFingerprint (общая утилита, переиспользуется dedup-логикой
     * при импорте — см. AccountsRepository::createAccountsBulk).
     */
    private static function extractFbIds(array $row): array
    {
        return AccountFingerprint::extractFbIds($row);
    }

    // ────────────────────────────────────────────────────────
    // Step 2: Check
    // ────────────────────────────────────────────────────────

    /**
     * Check items. An account is valid if at least one of its FB IDs is active.
     *
     * Если передан $jobId — после каждого sub-batch check.fb.tools (curl_multi_info_read)
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
            $batchCount  = (int)ceil(count($allFbIds) / Config::FB_TOOLS_BATCH_SIZE);
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
     * Check FB IDs in batches via check.fb.tools bulk API.
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

        $batches      = array_chunk($fbIds, Config::FB_TOOLS_BATCH_SIZE);
        $batchResults = self::runParallel($batches, $onSubBatchDone);

        $failedIdx = [];
        foreach ($batchResults as $idx => $res) {
            if ($res === null) $failedIdx[] = $idx;
        }

        for ($attempt = 1; $attempt <= 2 && !empty($failedIdx); $attempt++) {
            // 300ms / 600ms — короткая пауза перед retry. Большинство сетевых
            // сбоев восстанавливаются мгновенно; секундная пауза только ухудшала UX.
            usleep($attempt * 300000);
            Logger::debug('check.fb.tools retry', ['attempt' => $attempt, 'batches' => count($failedIdx)]);

            $retryBatches = [];
            foreach ($failedIdx as $idx) $retryBatches[$idx] = $batches[$idx];

            // На retry callback не вызываем — прогресс уже учтён при первой попытке
            $retryResults = self::runParallel($retryBatches, null);
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
                Logger::warning('check.fb.tools batch failed after retries', ['count' => count($batches[$idx])]);
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
     * @param callable|null $onSubBatchDone Вызывается без аргументов после каждого
     *   завершившегося sub-batch (успех или провал — оба считаются «обработанными»).
     * @return array [idx => array<fb_id, bool>|null]
     */
    private static function runParallel(array $batches, ?callable $onSubBatchDone = null): array
    {
        if (empty($batches)) return [];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($batches as $idx => $batch) {
            $payload = json_encode([
                'inputData'    => array_map('strval', array_values($batch)),
                'checkFriends' => false,
                'userLang'     => 'ru',
            ]);

            $ch = curl_init(Config::FB_TOOLS_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => Config::FB_TOOLS_TIMEOUT,
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
     * Формат ответа check.fb.tools:
     *   {
     *     "data": [
     *       { "account": "<acc>", "uid": "<fbid>", "status": { "name": "valid"|"invalid" } },
     *       ...
     *     ],
     *     "info": {...}
     *   }
     *
     * status.name === "valid" → true, всё остальное → false.
     *
     * @return array|null [fb_id => bool] или null при сетевой/парс ошибке
     */
    private static function parseHandleResult($ch, array $batch, $batchIdx): ?array
    {
        $body     = curl_multi_getcontent($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        if ($error || $httpCode !== 200 || $body === false || $body === '') {
            Logger::warning('check.fb.tools request failed', [
                'http_code' => $httpCode,
                'error'     => $error,
                'batch_idx' => $batchIdx,
                'body'      => is_string($body) ? substr($body, 0, 500) : '',
            ]);
            return null;
        }

        $json = @json_decode($body, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            Logger::warning('check.fb.tools invalid response', [
                'body'      => substr((string)$body, 0, 500),
                'batch_idx' => $batchIdx,
            ]);
            return null;
        }

        $batchResult = [];
        foreach ($json['data'] as $entry) {
            if (!is_array($entry)) continue;
            $account = (string)($entry['account'] ?? '');
            $status  = (string)($entry['status']['name'] ?? '');
            if ($account !== '') {
                $batchResult[$account] = ($status === 'valid');
            }
        }

        // ID, не попавшие в response data — отмечаем как невалидные.
        // Теоретически таких быть не должно (API всегда возвращает запись на каждый input),
        // но защищаемся на случай частичного ответа.
        foreach ($batch as $fbId) {
            $key = (string)$fbId;
            if (!isset($batchResult[$key])) {
                $batchResult[$key] = false;
            }
        }

        return $batchResult;
    }
}
