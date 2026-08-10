<?php require_once __DIR__ . '/../../ui/icons.php'; ?>
  <!-- Фильтры (Современный дизайн) -->
  <div class="filters-modern">
    <!-- Заголовок -->
    <div class="filters-modern-header">
      <div class="filters-modern-header-left">
        <div class="filters-modern-icon">
          <?= ui_icon('filter', 14) ?>
        </div>
        <span class="filters-modern-title">Фильтры</span>
        <span class="filters-modern-badge" style="<?= $activeFiltersCount > 0 ? '' : 'display:none' ?>"><?= (int)$activeFiltersCount ?></span>
      </div>
      <div class="filters-modern-actions" id="filtersActionsContainer">
        <div id="savedFiltersContainer" style="display: inline-block; margin-right: 8px;"></div>
        <button class="filters-modern-btn primary" type="button" data-bs-toggle="collapse" data-bs-target="#filtersBody" aria-expanded="true">
          <?= ui_icon('sliders', 14) ?>
          <span class="d-none d-md-inline">Настроить</span>
        </button>
      </div>
    </div>

    <!-- Активные фильтры (Chips) -->
    <div class="active-filters-section <?= $activeFiltersCount > 0 ? 'has-filters' : '' ?>" id="activeFiltersSection">
      <div class="active-filters-header">
        <div class="active-filters-label">Активные фильтры</div>
        <button type="button" class="btn btn-sm btn-outline-danger active-filters-reset-btn" id="resetAllFiltersBtn"
                style="<?= $activeFiltersCount > 0 ? '' : 'display:none' ?>"
                title="Сбросить все фильтры">
          <?= ui_icon('close', 14) ?>Сбросить все
        </button>
      </div>
      <div class="active-filters-list" id="activeFiltersList">
        <?php if ($q !== ''): ?>
        <div class="filter-chip" data-filter="q">
          <?= ui_icon('search', 14) ?>
          <span>Поиск: "<?= e(mb_substr($q, 0, 20)) ?><?= mb_strlen($q) > 20 ? '...' : '' ?>"</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('q')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php foreach ($statusArray as $selectedStatus): ?>
        <div class="filter-chip" data-filter="status" data-status-value="<?= e($selectedStatus) ?>">
          <?= ui_icon('tag', 14) ?>
          <span><?= e($selectedStatus) ?></span>
          <button class="filter-chip-remove" title="Удалить">&times;</button>
        </div>
        <?php endforeach; ?>
        
        <?php if (!empty($emptyStatusParam)): ?>
        <div class="filter-chip" data-filter="status" data-status-value="__empty__">
          <?= ui_icon('alert', 14) ?>
          <span>Пустой статус</span>
          <button class="filter-chip-remove" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if ($hasEmailParam !== ''): ?>
        <div class="filter-chip" data-filter="has_email">
          <?= ui_icon('mail', 14) ?>
          <span>Есть Email</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_email')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if ($hasTwoFaParam !== ''): ?>
        <div class="filter-chip" data-filter="has_two_fa">
          <?= ui_icon('shield', 14) ?>
          <span>Есть 2FA</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_two_fa')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasTokenParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_token">
          <?= ui_icon('key', 14) ?>
          <span>Есть Token</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_token')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasFanPageParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_fan_page">
          <?= ui_icon('flag', 14) ?>
          <span>Есть Fan Page</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_fan_page')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($pharmaFrom) || !empty($pharmaTo)): ?>
        <div class="filter-chip" data-filter="pharma">
          <?= ui_icon('pills', 14) ?>
          <span>Pharma: <?= e($pharmaFrom ?: '0') ?>-<?= e($pharmaTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('pharma')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasAvatarParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_avatar">
          <?= ui_icon('image', 14) ?>
          <span>Есть Аватар</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_avatar')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasPasswordParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_password">
          <?= ui_icon('lock', 14) ?>
          <span>Есть Пароль</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_password')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($ALL_COLUMNS['bm']) && (!empty($bmFrom) || !empty($bmTo))): ?>
        <div class="filter-chip" data-filter="bm_range">
          <?= ui_icon('briefcase', 14) ?>
          <span>БМ: <?= e($bmFrom ?: '0') ?>—<?= e($bmTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('bm_range')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>

        <?php
        // Chip для статуса БМ — показываем если выбран конкретный режим (не any и не пусто)
        $bmStatusLabels = ['has_valid' => 'БМ: есть валидный', 'has_ban' => 'БМ: есть в бане', 'only_valid' => 'БМ: только валидные'];
        if (isset($bmStatus) && $bmStatus !== '' && $bmStatus !== 'any' && isset($bmStatusLabels[$bmStatus])):
        ?>
        <div class="filter-chip" data-filter="bm_status">
          <?= ui_icon('briefcase', 14) ?>
          <span><?= e($bmStatusLabels[$bmStatus]) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('bm_status')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($favoritesOnlyParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="favorites_only">
          <?= ui_icon('star', 14) ?>
          <span>Только избранные</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('favorites_only')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($hasCoverParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="has_cover">
          <?= ui_icon('image', 14) ?>
          <span>Есть Обложка</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('has_cover')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($fullFilledParam ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="full_filled">
          <span>Полностью заполненные</span>
          <button class="filter-chip-remove" onclick="removeFilterChip('full_filled')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($friendsFrom) || !empty($friendsTo)): ?>
        <div class="filter-chip" data-filter="friends">
          <?= ui_icon('users', 14) ?>
          <span>Друзья: <?= e($friendsFrom ?: '0') ?>-<?= e($friendsTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('friends')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($yearCreatedFrom) || !empty($yearCreatedTo)): ?>
        <div class="filter-chip" data-filter="year_created">
          <span>Год: <?= e($yearCreatedFrom ?: '∞') ?>-<?= e($yearCreatedTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('year_created')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($limitRkFrom ?? '') !== '' || ($limitRkTo ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="limit_rk">
          <span>Limit RK: <?= e($limitRkFrom ?: '0') ?>-<?= e($limitRkTo ?: '∞') ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('limit_rk')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($statusMarketplace ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="status_marketplace">
          <?= ui_icon('briefcase', 14) ?>
          <span>Marketplace: <?= e($statusMarketplace) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('status_marketplace')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($currencyFilter ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="currency">
          <span>Currency: <?= e($currencyFilter) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('currency')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($geoFilter ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="geo">
          <?= ui_icon('globe', 14) ?>
          <span>Geo: <?= e($geoFilter === '__empty__' ? 'Не указано' : $geoFilter) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('geo')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (($statusRkFilter ?? '') !== ''): ?>
        <div class="filter-chip" data-filter="status_rk">
          <?= ui_icon('tag', 14) ?>
          <span>Status RK: <?= e($statusRkFilter === '__empty__' ? 'Не указано' : $statusRkFilter) ?></span>
          <button class="filter-chip-remove" onclick="removeFilterChip('status_rk')" title="Удалить">&times;</button>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Тело фильтров -->
    <div id="filtersBody" class="collapse show">
      <div class="filters-modern-body">
        <form method="get" id="filtersForm">
          <!-- Поисковая строка -->
          <div class="search-field-modern">
            <label class="search-field-modern-label">
              <?= ui_icon('search', 14) ?>Поиск по всем полям
            </label>
            <div class="search-input-wrapper">
              <input 
                type="search" 
                name="q" 
                class="search-input-modern" 
                placeholder="логин, email, имя, фамилия, id..." 
                value="<?= e($q) ?>"
                id="modernSearchInput"
                autocomplete="off">
              <?= ui_icon('search', 14) ?>
              <?php if ($q !== ''): ?>
              <button type="button" class="search-input-clear" onclick="clearSearch()" title="Очистить">
                </button>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Статусы (Dropdown) -->
          <div class="form-group-modern mt-4">
            <label class="search-field-modern-label">
              <?= ui_icon('tag', 14) ?>Статус
            </label>
            <div class="dropdown w-100">
              <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                      type="button" 
                      id="statusDropdown" 
                      data-bs-toggle="dropdown" 
                      aria-expanded="false"
                      style="min-height: 40px; border-radius: var(--radius-lg); border-width: 1.5px;">
                <span id="statusDropdownLabel">
                  <?php if (empty($statusArray) && empty($emptyStatusParam)): ?>
                    Все статусы
                  <?php else: ?>
                    <?php 
                    $selectedCount = count($statusArray) + (!empty($emptyStatusParam) ? 1 : 0);
                    ?>
                    Выбрано: <?= $selectedCount ?>
                  <?php endif; ?>
                </span>
              </button>
              <div class="dropdown-menu p-2 status-dropdown-menu" aria-labelledby="statusDropdown" style="min-width: 320px; max-height: 450px; overflow-y: auto;">
                <?php if (count($statuses) > 8): ?>
                <div class="mb-2 px-1">
                  <input type="text" class="form-control form-control-sm" id="statusSearch" placeholder="Поиск статусов..." style="font-size: 0.8rem;">
                </div>
                <?php endif; ?>
                
                <div class="d-flex gap-2 mb-2 pb-2 border-bottom">
                  <button type="button" class="btn btn-sm btn-outline-primary flex-fill" id="selectAllStatusesBtn">
                    Все
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="clearAllStatusesBtn">
                    Очистить
                  </button>
                </div>
                
                <!-- Чекбокс для пустых статусов -->
                <div class="form-check status-checkbox-item mb-2 pb-2 border-bottom">
                  <input class="form-check-input status-checkbox" type="checkbox" value="1" id="status_empty" name="empty_status" <?= ($emptyStatusParam??'')!=='' ? 'checked' : '' ?>>
                  <label class="form-check-label w-100 d-flex justify-content-between align-items-center" for="status_empty">
                    <span><?= ui_icon('alert', 14) ?>Пустой статус</span>
                    <span class="badge bg-warning status-count" data-status="__empty__">
                      <?= isset($byStatus['']) ? number_format($byStatus['']) : 0 ?>
                    </span>
                  </label>
                </div>
                
                <?php foreach ($statuses as $st): ?>
                <div class="form-check status-checkbox-item">
                  <input class="form-check-input status-checkbox" type="checkbox" value="<?= e($st) ?>" id="status_<?= e(preg_replace('/[^a-zA-Z0-9]/', '_', $st)) ?>" name="status[]" <?= in_array($st, $statusArray) ? 'checked' : '' ?>>
                  <label class="form-check-label w-100 d-flex justify-content-between align-items-center" for="status_<?= e(preg_replace('/[^a-zA-Z0-9]/', '_', $st)) ?>">
                    <span><?= e($st) ?></span>
                    <span class="badge bg-secondary status-count" data-status="<?= e($st) ?>">
                      <?= isset($byStatus[$st]) ? number_format($byStatus[$st]) : 0 ?>
                    </span>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Быстрые фильтры (Toggle Switches) -->
          <div class="quick-filters-section">
            <label class="quick-filters-label">
              Быстрые фильтры
            </label>
            <div class="quick-filters-grid">
              <!-- Email -->
              <div class="toggle-switch-wrapper <?= $hasEmailParam !== '' ? 'active' : '' ?>">
                <div class="toggle-switch-label-group">
                  <?= ui_icon('mail', 14) ?>
                  <span class="toggle-switch-label">Email</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_email" value="1" <?= $hasEmailParam !== '' ? 'checked' : '' ?>>
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- 2FA -->
              <div class="toggle-switch-wrapper <?= $hasTwoFaParam !== '' ? 'active' : '' ?>">
                <div class="toggle-switch-label-group">
                  <?= ui_icon('shield', 14) ?>
                  <span class="toggle-switch-label">2FA</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_two_fa" value="1" <?= $hasTwoFaParam !== '' ? 'checked' : '' ?>>
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- Token -->
              <div class="toggle-switch-wrapper <?= ($hasTokenParam ?? '') !== '' ? 'active' : '' ?>">
                <div class="toggle-switch-label-group">
                  <?= ui_icon('key', 14) ?>
                  <span class="toggle-switch-label">Token</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_token" value="1" <?= ($hasTokenParam ?? '') !== '' ? 'checked' : '' ?>>
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- Fan Page -->
              <div class="toggle-switch-wrapper <?= ($hasFanPageParam ?? '') !== '' ? 'active' : '' ?>">
                <div class="toggle-switch-label-group">
                  <?= ui_icon('flag', 14) ?>
                  <span class="toggle-switch-label">Fan Page</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_fan_page" value="1" <?= ($hasFanPageParam ?? '') !== '' ? 'checked' : '' ?>>
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <?php if (isset($ALL_COLUMNS['avatar'])): ?>
              <!-- Avatar -->
              <div class="toggle-switch-wrapper <?= ($hasAvatarParam ?? '') !== '' ? 'active' : '' ?>">
                <div class="toggle-switch-label-group">
                  <?= ui_icon('user', 14) ?>
                  <span class="toggle-switch-label">Avatar</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_avatar" value="1" <?= ($hasAvatarParam ?? '') !== '' ? 'checked' : '' ?>>
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              <?php endif; ?>
              
              <!-- Password -->
              <div class="toggle-switch-wrapper <?= ($hasPasswordParam ?? '') !== '' ? 'active' : '' ?>">
                <div class="toggle-switch-label-group">
                  <?= ui_icon('lock', 14) ?>
                  <span class="toggle-switch-label">Password</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="has_password" value="1" <?= ($hasPasswordParam ?? '') !== '' ? 'checked' : '' ?>>
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
              
              <!-- Избранное -->
              <div class="toggle-switch-wrapper <?= ($favoritesOnlyParam ?? '') !== '' ? 'active' : '' ?>">
                <div class="toggle-switch-label-group">
                  <?= ui_icon('star', 14) ?>
                  <span class="toggle-switch-label">Избранное</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" name="favorites_only" value="1" <?= ($favoritesOnlyParam ?? '') !== '' ? 'checked' : '' ?>>
                  <span class="toggle-switch-slider"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- Дополнительные фильтры (Диапазоны) - Всегда видимые -->
          <?php 
          $hasRangeFilters = isset($ALL_COLUMNS['scenario_pharma']) || 
                            isset($ALL_COLUMNS['quantity_friends']) || 
                            isset($ALL_COLUMNS['year_created']) ||
                            isset($ALL_COLUMNS['limit_rk']) ||
                            isset($ALL_COLUMNS['currency']) ||
                            isset($ALL_COLUMNS['geo']) ||
                            isset($ALL_COLUMNS['status_rk']) ||
                            isset($ALL_COLUMNS['status_marketplace']) ||
                            isset($ALL_COLUMNS['bm']);
          ?>
          
          <?php if ($hasRangeFilters): ?>
          <div class="mt-4">
            <label class="search-field-modern-label mb-3">
              <?= ui_icon('sliders', 14) ?>Дополнительные фильтры
            </label>
            <div class="range-filters-grid">
              <?php if (isset($ALL_COLUMNS['scenario_pharma'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <?= ui_icon('pills', 14) ?>
                  Сценарий фарма
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="pharma_from" placeholder="От" min="0" max="50" step="1" value="<?= e($pharmaFrom) ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="pharma_to" placeholder="До" min="0" max="50" step="1" value="<?= e($pharmaTo) ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['quantity_friends'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  Количество друзей
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="friends_from" placeholder="От" min="0" max="1000" step="1" value="<?= e($friendsFrom) ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="friends_to" placeholder="До" min="0" max="1000" step="1" value="<?= e($friendsTo) ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['bm'])): ?>
              <?php
              $hasBmStatusCols = isset($ALL_COLUMNS['status_bm_1']) || isset($ALL_COLUMNS['status_bm_2'])
                              || isset($ALL_COLUMNS['status_bm_3']) || isset($ALL_COLUMNS['status_bm_4']);
              $currentBmStatus = $bmStatus ?? '';
              ?>
              <div class="range-filter-group range-filter-group--bm">
                <div class="range-filter-label">
                  <?= ui_icon('briefcase', 14) ?>
                  Количество БМ
                </div>
                <div class="bm-filter-row">
                  <div class="range-inputs">
                    <input type="number" id="bm_from" class="range-input-modern" name="bm_from" placeholder="От" min="0" step="1" value="<?= e($bmFrom ?? '') ?>">
                    <span class="range-separator">—</span>
                    <input type="number" id="bm_to" class="range-input-modern" name="bm_to" placeholder="До" min="0" step="1" value="<?= e($bmTo ?? '') ?>">
                  </div>
                  <?php if ($hasBmStatusCols): ?>
                  <div class="bm-status-inline">
                    <label class="bm-status-label"><?= ui_icon('shield', 14) ?> Статус</label>
                    <select id="bm_status" name="bm_status" class="form-select form-select-sm bm-status-select">
                      <option value="any"<?= $currentBmStatus === '' || $currentBmStatus === 'any' ? ' selected' : '' ?>>Любые</option>
                      <option value="has_valid"<?= $currentBmStatus === 'has_valid'  ? ' selected' : '' ?>>Есть валидный</option>
                      <option value="has_ban"<?=   $currentBmStatus === 'has_ban'    ? ' selected' : '' ?>>Есть в бане</option>
                      <option value="only_valid"<?= $currentBmStatus === 'only_valid' ? ' selected' : '' ?>>Только валидные</option>
                    </select>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['year_created'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  Год создания
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="year_created_from" placeholder="От" min="1900" max="2100" step="1" value="<?= e($yearCreatedFrom ?? '') ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="year_created_to" placeholder="До" min="1900" max="2100" step="1" value="<?= e($yearCreatedTo ?? '') ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['limit_rk'])): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  Limit RK
                </div>
                <div class="range-inputs">
                  <input type="number" class="range-input-modern" name="limit_rk_from" placeholder="От" min="0" step="1" value="<?= e($limitRkFrom ?? '') ?>">
                  <span class="range-separator">—</span>
                  <input type="number" class="range-input-modern" name="limit_rk_to" placeholder="До" min="0" step="1" value="<?= e($limitRkTo ?? '') ?>">
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['status_marketplace']) && (!empty($statusesMarketplace) || $emptyMarketplaceStatusCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <?= ui_icon('briefcase', 14) ?>
                  Статус Marketplace
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="statusMarketplaceDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="statusMarketplaceDropdownLabel">
                        <?php 
                        if (($statusMarketplace ?? '') === '') {
                          echo 'Все статусы';
                        } elseif (($statusMarketplace ?? '') === '__empty__') {
                          echo 'Не указан';
                        } else {
                          echo e($statusMarketplace);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu status-marketplace-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все статусы -->
                      <li>
                        <div class="status-marketplace-item <?= ($statusMarketplace ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все статусы</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($statusesMarketplace) + $emptyMarketplaceStatusCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Статусы из базы -->
                      <?php foreach ($statusesMarketplace as $statusMkt => $count): ?>
                      <li>
                        <div class="status-marketplace-item <?= ($statusMarketplace ?? '') === $statusMkt ? 'active' : '' ?>" data-value="<?= e($statusMkt) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($statusMkt) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые статусы -->
                      <?php if ($emptyMarketplaceStatusCount > 0): ?>
                      <li>
                        <div class="status-marketplace-item <?= ($statusMarketplace ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указан</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyMarketplaceStatusCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="status_marketplace" id="statusMarketplaceInput" value="<?= e($statusMarketplace ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['currency']) && (!empty($currenciesList) || $emptyCurrencyCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  Валюта
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="currencyDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="currencyDropdownLabel">
                        <?php 
                        if (($currencyFilter ?? '') === '') {
                          echo 'Все валюты';
                        } elseif (($currencyFilter ?? '') === '__empty__') {
                          echo 'Не указана';
                        } else {
                          echo e($currencyFilter);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu currency-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все валюты -->
                      <li>
                        <div class="currency-item <?= ($currencyFilter ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все валюты</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($currenciesList) + $emptyCurrencyCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Валюты из базы -->
                      <?php foreach ($currenciesList as $code => $count): ?>
                      <li>
                        <div class="currency-item <?= ($currencyFilter ?? '') === $code ? 'active' : '' ?>" data-value="<?= e($code) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($code) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые валюты -->
                      <?php if ($emptyCurrencyCount > 0): ?>
                      <li>
                        <div class="currency-item <?= ($currencyFilter ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указана</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyCurrencyCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="currency" id="currencyInput" value="<?= e($currencyFilter ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['geo']) && (!empty($geosList) || $emptyGeoCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <?= ui_icon('globe', 14) ?>
                  Гео аккаунта
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="geoDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="geoDropdownLabel">
                        <?php 
                        if (($geoFilter ?? '') === '') {
                          echo 'Все geo';
                        } elseif (($geoFilter ?? '') === '__empty__') {
                          echo 'Не указано';
                        } else {
                          echo e($geoFilter);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu geo-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все geo -->
                      <li>
                        <div class="geo-item <?= ($geoFilter ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все geo</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($geosList) + $emptyGeoCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Geo из базы -->
                      <?php foreach ($geosList as $geoValue => $count): ?>
                      <li>
                        <div class="geo-item <?= ($geoFilter ?? '') === $geoValue ? 'active' : '' ?>" data-value="<?= e($geoValue) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($geoValue) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые geo -->
                      <?php if ($emptyGeoCount > 0): ?>
                      <li>
                        <div class="geo-item <?= ($geoFilter ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указано</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyGeoCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="geo" id="geoInput" value="<?= e($geoFilter ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['status_rk']) && (!empty($statusRkList) || $emptyStatusRkCount > 0)): ?>
              <div class="range-filter-group">
                <div class="range-filter-label">
                  <?= ui_icon('tag', 14) ?>
                  Status RK
                </div>
                <div class="range-inputs">
                  <div class="dropdown w-100">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" 
                            type="button" 
                            id="statusRkDropdown" 
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                            style="font-size: 0.875rem;">
                      <span id="statusRkDropdownLabel">
                        <?php 
                        if (($statusRkFilter ?? '') === '') {
                          echo 'Все статусы RK';
                        } elseif (($statusRkFilter ?? '') === '__empty__') {
                          echo 'Не указано';
                        } else {
                          echo e($statusRkFilter);
                        }
                        ?>
                      </span>
                    </button>
                    <ul class="dropdown-menu status-rk-dropdown-menu w-100" style="max-height: 300px; overflow-y: auto;">
                      <!-- Все статусы RK -->
                      <li>
                        <div class="status-rk-item <?= ($statusRkFilter ?? '') === '' ? 'active' : '' ?>" data-value="">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span>Все статусы RK</span>
                            <span class="badge bg-secondary rounded-pill"><?= array_sum($statusRkList) + $emptyStatusRkCount ?></span>
                          </label>
                        </div>
                      </li>
                      
                      <!-- Статусы RK из базы -->
                      <?php foreach ($statusRkList as $statusRkValue => $count): ?>
                      <li>
                        <div class="status-rk-item <?= ($statusRkFilter ?? '') === $statusRkValue ? 'active' : '' ?>" data-value="<?= e($statusRkValue) ?>">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span><?= e($statusRkValue) ?></span>
                            <span class="badge bg-primary rounded-pill"><?= (int)$count ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endforeach; ?>
                      
                      <!-- Пустые статусы RK -->
                      <?php if ($emptyStatusRkCount > 0): ?>
                      <li>
                        <div class="status-rk-item <?= ($statusRkFilter ?? '') === '__empty__' ? 'active' : '' ?>" data-value="__empty__">
                          <label class="w-100 m-0 d-flex justify-content-between align-items-center">
                            <span class="text-muted fst-italic">Не указано</span>
                            <span class="badge bg-secondary rounded-pill"><?= (int)$emptyStatusRkCount ?></span>
                          </label>
                        </div>
                      </li>
                      <?php endif; ?>
                    </ul>
                    <input type="hidden" name="status_rk" id="statusRkInput" value="<?= e($statusRkFilter ?? '') ?>">
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          
          <div class="filters-modern-sep"></div>

          <!-- На странице и кнопка применения -->
          <div class="filters-modern-foot">
            <div class="ui-field" style="max-width:180px">
              <label class="ui-label" for="perPageFilterSelect">Записей на странице</label>
              <select name="per_page" id="perPageFilterSelect" class="ui-select">
                <?php foreach ([25,50,100,200] as $__pp): ?>
                  <option value="<?= $__pp ?>" <?= $perPage===$__pp ? 'selected' : '' ?>><?= $__pp ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="filters-modern-foot__right">
              <button type="submit" class="ui-btn ui-btn--sm" title="Принудительное обновление страницы" id="applyFiltersBtn">
                <?= ui_icon('refresh', 14) ?>Обновить
              </button>
              <span class="ui-muted" style="font-size:11px">Фильтры применяются автоматически</span>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
