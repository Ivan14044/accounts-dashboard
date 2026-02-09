<!-- Модальное окно создания/редактирования кастомной карточки -->
<div class="modal fade" id="customCardModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fas fa-magic me-2"></i>Создать кастомную карточку статистики
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="customCardForm">
          <!-- Название карточки -->
          <div class="mb-3">
            <label class="form-label fw-semibold small">Название карточки</label>
            <input type="text" class="form-control form-control-sm" id="customCardName" placeholder="Например: Готовые к продаже с Email" required>
          </div>

          <!-- Фильтры -->
          <div class="mb-3">
            <h6 class="mb-2 small">
              <i class="fas fa-filter me-1"></i>Фильтры для подсчета
            </h6>
            
            <!-- Статусы и булевы фильтры -->
            <div class="row mb-2">
              <!-- Статусы (множественный выбор) -->
              <div class="col-md-6 mb-2">
                <label class="form-label small">Статусы</label>
                <select class="form-select form-select-sm" id="customCardStatuses" multiple size="5" style="font-size: 0.875rem;">
                  <?php foreach ($statuses as $st): 
                    $statusCount = isset($byStatus[$st]) ? (int)$byStatus[$st] : 0;
                  ?>
                  <option value="<?= e($st) ?>">
                    <?= e($st) ?> (<?= number_format($statusCount) ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted" style="font-size: 0.7rem;">
                  Ctrl/Cmd+Click для множественного выбора
                </small>
              </div>
              
              <!-- Булевы фильтры -->
              <div class="col-md-6 mb-2">
                <label class="form-label small">Дополнительные условия</label>
                <div class="row g-1">
                  <div class="col-6">
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasEmail">
                      <label class="form-check-label small" for="customHasEmail">Есть Email</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasTwoFa">
                      <label class="form-check-label small" for="customHasTwoFa">Есть 2FA</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasToken">
                      <label class="form-check-label small" for="customHasToken">Есть Token</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasAvatar">
                      <label class="form-check-label small" for="customHasAvatar">Есть Аватар</label>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasCover">
                      <label class="form-check-label small" for="customHasCover">Есть Обложка</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasPassword">
                      <label class="form-check-label small" for="customHasPassword">Есть Пароль</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customHasFanPage">
                      <label class="form-check-label small" for="customHasFanPage">Есть Fan Page</label>
                    </div>
                    <div class="form-check form-check-sm">
                      <input class="form-check-input" type="checkbox" id="customFullFilled">
                      <label class="form-check-label small" for="customFullFilled">Полностью заполненные</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Диапазоны -->
            <div class="row g-2 mb-2">
              <?php if (isset($ALL_COLUMNS['scenario_pharma'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Сценарий фарма (от)</label>
                <input type="number" class="form-control form-control-sm" id="customPharmaFrom" min="0" max="50" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Сценарий фарма (до)</label>
                <input type="number" class="form-control form-control-sm" id="customPharmaTo" min="0" max="50" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['quantity_friends'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Друзья (от)</label>
                <input type="number" class="form-control form-control-sm" id="customFriendsFrom" min="0" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Друзья (до)</label>
                <input type="number" class="form-control form-control-sm" id="customFriendsTo" min="0" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['year_created'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Год (от)</label>
                <input type="number" class="form-control form-control-sm" id="customYearCreatedFrom" min="2000" max="2100" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Год (до)</label>
                <input type="number" class="form-control form-control-sm" id="customYearCreatedTo" min="2000" max="2100" placeholder="До">
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['limit_rk'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Limit RK (от)</label>
                <input type="number" class="form-control form-control-sm" id="customLimitRkFrom" min="0" placeholder="От">
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Limit RK (до)</label>
                <input type="number" class="form-control form-control-sm" id="customLimitRkTo" min="0" placeholder="До">
              </div>
              <?php endif; ?>
            </div>
            
            <!-- Одиночные фильтры -->
            <div class="row g-2 mb-2">
              <?php if (isset($ALL_COLUMNS['status_marketplace'])): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Marketplace</label>
                <select class="form-select form-select-sm" id="customStatusMarketplace">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($statusesMarketplace) && is_array($statusesMarketplace)): ?>
                    <?php foreach ($statusesMarketplace as $st => $count): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['status_rk']) && (!empty($statusRkList) || $emptyStatusRkCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Status RK</label>
                <select class="form-select form-select-sm" id="customStatusRk">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($statusRkList)): ?>
                    <?php foreach ($statusRkList as $st => $count): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['currency']) && (!empty($currenciesList) || $emptyCurrencyCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Валюта</label>
                <select class="form-select form-select-sm" id="customCurrency">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($currenciesList)): ?>
                    <?php foreach ($currenciesList as $code => $count): ?>
                    <option value="<?= e($code) ?>"><?= e($code) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($ALL_COLUMNS['geo']) && (!empty($geosList) || $emptyGeoCount > 0)): ?>
              <div class="col-md-3 col-6">
                <label class="form-label small">Гео аккаунта</label>
                <select class="form-select form-select-sm" id="customGeo">
                  <option value="">— Не выбрано —</option>
                  <?php if (!empty($geosList)): ?>
                    <?php foreach ($geosList as $geo => $count): ?>
                    <option value="<?= e($geo) ?>"><?= e($geo) ?> (<?= number_format((int)$count) ?>)</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Целевой статус -->
          <div class="mb-2">
            <h6 class="mb-2 small">
              <i class="fas fa-tag me-1"></i>Действие при клике
            </h6>
            <div class="mb-2">
              <label class="form-label small">Установить статус</label>
              <select class="form-select form-select-sm" id="customCardTargetStatus">
                <option value="">Не изменять статус</option>
                <?php foreach ($statuses as $st): ?>
                <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
                <option value="__new__">+ Создать новый статус</option>
              </select>
              <div id="newStatusInputGroup" class="mt-1" style="display: none;">
                <input type="text" class="form-control form-control-sm" id="customCardNewStatus" placeholder="Введите название нового статуса">
              </div>
            </div>
          </div>

          <!-- Цвет карточки -->
          <div class="mb-2">
            <label class="form-label small">Цвет карточки</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" class="form-control form-control-color" id="customCardColor" value="#3b82f6" style="width: 60px; height: 35px;">
              <span class="text-muted" style="font-size: 0.75rem;">Выберите цвет для карточки</span>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="saveCustomCardBtn">
          <i class="fas fa-save me-2"></i>Сохранить карточку
        </button>
      </div>
    </div>
  </div>
</div>
