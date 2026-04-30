<?php
/**
 * Рендер тела таблицы
 */

/**
 * Семантический тон статуса (для цветного риббона на строке и для badge-окраски).
 *
 * @param string|null $status
 * @return string  one of: danger, success, warning, info, muted
 */
if (!function_exists('resolveStatusTone')) {
    function resolveStatusTone(?string $status): string {
        $s = strtolower(trim((string)$status));
        if ($s === '') return 'warning'; // пустой статус — внимание
        // Сначала отсеиваем "invalid_..." — это всегда danger, даже если содержит "valid"
        if (preg_match('/(invalid|error|ban|block|fraud|dead|reject|fail)/', $s)) return 'danger';
        if (preg_match('/(new|valid|active|ready|^ok$|success|done)/', $s))      return 'success';
        if (preg_match('/(check|pending|wait|progress|warm|selphi|review)/', $s)) return 'warning';
        return 'muted';
    }
}
?>
<tbody id="accountsTableBody">
<?php if (!$rows): ?>
  <?php include __DIR__ . '/empty-state.php'; ?>
<?php else: ?>
  <?php foreach ($rows as $r):
    $rowStatus = strtolower(trim((string)($r['status'] ?? '')));
    $rowTone   = resolveStatusTone($rowStatus);
  ?>
    <tr class="ac-row" data-id="<?= (int)$r['id'] ?>" data-status="<?= e($rowStatus) ?>" data-tone="<?= e($rowTone) ?>" role="row">
      <td class="ac-cell ac-cell--checkbox checkbox-cell" data-column="checkbox">
        <div class="form-check">
          <input class="form-check-input row-checkbox" type="checkbox"
                 value="<?= (int)$r['id'] ?>"
                 title="ID записи: <?= (int)$r['id'] ?>">
        </div>
      </td>
      <?php foreach ($ALL_COLUMNS as $k => $title): $v = $r[$k]; $isLong = is_string($v) && (strlen($v) > $CLIP_LEN || in_array($k, $LONG_FIELDS, true)); ?>
        <?php if ($k === 'id'): ?>
          <td class="ac-cell ac-cell--id" data-col="<?= e($k) ?>" data-column="<?= e($k) ?>">
            <span class="fw-bold text-primary">#<?= (int)$v ?></span>
            <button type="button" class="copy-btn" data-copy-text="<?= (int)$v ?>" title="Копировать"><i class="fas fa-copy"></i></button>
          </td>
          <td class="ac-cell ac-cell--favorite favorite-cell text-center" data-column="favorite" data-account-id="<?= (int)$r['id'] ?>">
            <button 
              type="button" 
              class="btn btn-sm btn-link favorite-btn p-0" 
              data-account-id="<?= (int)$r['id'] ?>"
              title="Избранное">
              <i class="far fa-star"></i>
            </button>
          </td>
          <?php continue; ?>
        <?php endif; ?>
        <td class="ac-cell" data-col="<?= e($k) ?>" data-column="<?= e($k) ?>">
          <?php 
          // Определяем тип поля для валидации на фронтенде
          $isNumeric = isset($NUMERIC_COLS) && in_array($k, $NUMERIC_COLS, true);
          $fieldType = $isNumeric ? 'numeric' : 'text';
          ?>
          <?php if (($v === null || $v === '') && $k !== 'password' && $k !== 'email_password' && $k !== 'id' && $k !== 'actions'): ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="<?= e($fieldType) ?>">
              <span class="text-muted field-value">—</span>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button type="button" class="copy-btn" data-copy-text="" title="Копировать"><i class="fas fa-copy"></i></button>
            </div>
          <?php elseif ($k === 'email'): ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="text">
              <a href="mailto:<?= e($v) ?>" class="text-decoration-none field-value">
                <?= e($v) ?>
              </a>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                <i class="fas fa-copy"></i>
              </button>
            </div>
          <?php elseif ($k === 'login'): ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="text">
              <span class="fw-semibold field-value"><?= e((string)$v) ?></span>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                <i class="fas fa-copy"></i>
              </button>
            </div>
          <?php elseif ($k === 'password' || $k === 'email_password'): ?>
            <div class="pw-mask" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>">
              <?php if ($v === null || $v === ''): ?>
                <span class="pw-dots text-muted">(не задан)</span>
              <?php else: ?>
                <span class="pw-dots">••••••••</span>
              <?php endif; ?>
              <span class="pw-text d-none"><?= e((string)$v) ?></span>
              <button type="button" class="pw-toggle" title="Показать/скрыть пароль">
                <i class="fas fa-eye"></i>
              </button>
              <button type="button" class="pw-edit" title="Редактировать пароль">
                <i class="fas fa-edit"></i>
              </button>
              <button type="button" class="copy-btn" data-copy-text="<?= e($v) ?>" title="Копировать пароль">
                <i class="fas fa-copy"></i>
              </button>
            </div>
          <?php elseif ($k === 'token'): ?>
            <?php
              $clip = mb_substr((string)$v, 0, $TOKEN_CLIP, 'UTF-8') . '…';
              // token — heavy: значение из БД уже обрезано preview-лимитом, полное берём AJAX'ом
              $isHeavy = isset($HEAVY_FIELDS) && in_array($k, $HEAVY_FIELDS, true);
            ?>
            <div class="d-flex align-items-center gap-2">
              <span class="truncate mono" title="Нажмите для просмотра"
                    <?php if (!$isHeavy): ?>data-full="<?= e($v) ?>" <?php endif; ?>
                    <?php if ($isHeavy): ?>data-truncated="1" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" <?php endif; ?>
                    data-title="Token">
                <?= e($clip) ?>
              </span>
              <button class="copy-btn" type="button"
                      <?php if (!$isHeavy): ?>data-copy-text="<?= e($v) ?>"<?php else: ?>data-truncated="1" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>"<?php endif; ?>
                      title="Копировать">
                <i class="fas fa-copy"></i>
              </button>
            </div>
          <?php elseif ($k === 'status'): ?>
            <?php
            // Семантический тон + дисплей
            $statusDisplay = ($v === null || $v === '') ? 'Пустой статус' : (string)$v;
            $cellTone      = $rowTone; // используем уже вычисленный для строки
            // Сохраняем legacy classes для backward-compat с существующим CSS
            $legacyClass = 'badge-default';
            if ($v === null || $v === '') {
              $legacyClass = 'badge-empty-status';
            } elseif ($cellTone === 'success') {
              $legacyClass = 'badge-new';
            } elseif ($cellTone === 'danger') {
              $legacyClass = 'badge-error_login';
            } elseif ($cellTone === 'warning') {
              $legacyClass = 'badge-add_selphi_true';
            }
            ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="text">
              <span class="badge <?= $legacyClass ?> field-value" data-tone="<?= e($cellTone) ?>"><?= e($statusDisplay) ?></span>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button type="button" class="copy-btn" data-copy-text="<?= e((string)$v) ?>" title="Копировать"><i class="fas fa-copy"></i></button>
            </div>
          <?php elseif ($k === 'social_url' && preg_match('~^https?://~i', $v)): ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="text">
              <a href="<?= e($v) ?>" target="_blank" rel="noopener" class="text-decoration-none field-value">
                <i class="fas fa-external-link-alt me-2"></i><?= e($v) ?>
              </a>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button type="button" class="copy-btn" data-copy-text="<?= e($v) ?>" title="Копировать"><i class="fas fa-copy"></i></button>
            </div>
          <?php elseif ($isLong): ?>
            <?php
              $clip = mb_substr((string)$v, 0, $CLIP_LEN, 'UTF-8') . '…';
              // Heavy fields (cookies/full_cookies/first_cookie/user_agent) приходят из БД уже
              // обрезанными до preview-лимита. Полное значение берём лениво через AJAX —
              // не дублируем обрезанную preview в data-full / data-copy-text.
              $isHeavy = isset($HEAVY_FIELDS) && in_array($k, $HEAVY_FIELDS, true);
            ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="<?= e($fieldType) ?>">
              <span class="truncate mono field-value"
                    <?php if (!$isHeavy): ?>data-full="<?= e($v) ?>"<?php else: ?>data-truncated="1"<?php endif; ?>
                    data-title="<?= e($title) ?>">
                <?= e($clip) ?>
              </span>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button type="button" class="copy-btn"
                      <?php if (!$isHeavy): ?>data-copy-text="<?= e($v) ?>"<?php else: ?>data-truncated="1"<?php endif; ?>
                      title="Копировать"><i class="fas fa-copy"></i></button>
            </div>
          <?php else: ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="<?= e($fieldType) ?>">
              <span class="field-value"><?= e((string)$v) ?></span>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button type="button" class="copy-btn" data-copy-text="<?= e((string)$v) ?>" title="Копировать"><i class="fas fa-copy"></i></button>
            </div>
          <?php endif; ?>
        </td>
      <?php endforeach; ?>
      <td class="ac-cell ac-cell--actions text-end" data-column="actions">
        <a class="btn-table-open" href="view.php?id=<?= (int)$r['id'] ?>">
          <i class="fas fa-arrow-right"></i> Открыть
        </a>
      </td>
    </tr>
  <?php endforeach; ?>
<?php endif; ?>
</tbody>
