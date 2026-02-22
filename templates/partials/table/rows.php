<?php
/**
 * Рендер тела таблицы
 */
?>
<tbody id="accountsTableBody">
<?php if (!$rows): ?>
  <?php include __DIR__ . '/empty-state.php'; ?>
<?php else: ?>
  <?php foreach ($rows as $r): ?>
    <tr class="ac-row" data-id="<?= (int)$r['id'] ?>" role="row">
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
            <?php $clip = mb_substr($v, 0, $TOKEN_CLIP, 'UTF-8') . '…'; ?>
            <div class="d-flex align-items-center gap-2">
              <span class="truncate mono" title="Нажмите для просмотра" 
                    data-full="<?= e($v) ?>" data-title="Token">
                <?= e($clip) ?>
              </span>
              <button class="copy-btn" type="button" data-copy-text="<?= e($v) ?>" title="Копировать">
                <i class="fas fa-copy"></i>
              </button>
            </div>
          <?php elseif ($k === 'status'): ?>
            <?php 
            $statusClass = 'badge-default';
            $statusValue = strtolower((string)$v);
            $statusDisplay = (string)$v;
            if ($v === null || $v === '') {
              $statusClass = 'badge-empty-status';
              $statusDisplay = 'Пустой статус';
            } elseif (strpos($statusValue, 'new') !== false) {
              $statusClass = 'badge-new';
            } elseif (strpos($statusValue, 'add_selphi_true') !== false) {
              $statusClass = 'badge-add_selphi_true';
            } elseif (strpos($statusValue, 'error') !== false) {
              $statusClass = 'badge-error_login';
            }
            ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="text">
              <span class="badge <?= $statusClass ?> field-value"><?= e($statusDisplay) ?></span>
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
            <?php $clip = mb_substr($v, 0, $CLIP_LEN, 'UTF-8') . '…'; ?>
            <div class="editable-field-wrap" data-row-id="<?= (int)$r['id'] ?>" data-field="<?= e($k) ?>" data-field-type="<?= e($fieldType) ?>">
              <span class="truncate mono field-value" data-full="<?= e($v) ?>" data-title="<?= e($title) ?>">
                <?= e($clip) ?>
              </span>
              <button type="button" class="field-edit-btn" title="Редактировать">
                <i class="fas fa-edit"></i>
              </button>
              <button type="button" class="copy-btn" data-copy-text="<?= e($v) ?>" title="Копировать"><i class="fas fa-copy"></i></button>
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
        <a class="btn btn-sm btn-outline-primary" href="view.php?id=<?= (int)$r['id'] ?>">
          <i class="fas fa-eye me-1"></i>Открыть
        </a>
      </td>
    </tr>
  <?php endforeach; ?>
<?php endif; ?>
</tbody>
