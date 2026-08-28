<?php
/**
 * Сборка FilterBuilder из HTTP-запроса и ORDER BY-выражения.
 *
 * Содержит логику маппинга всех GET/POST-фильтров дашборда на
 * {@see FilterBuilder} и единое построение ORDER BY с tie-breaker по `id`
 * (без него LIMIT/OFFSET-пагинация по не-уникальной колонке нестабильна).
 *
 * Подключается в {@see AccountsService} через `use`.
 */
trait AccountsServiceFiltersTrait {
    /**
     * Создание фильтра из GET-параметров
     */
    public function createFilterFromRequest(array $params): FilterBuilder {
        $meta = $this->getColumnMetadata();
        // Имя таблицы обязательно: подзапрос «только избранные» строит условие
        // `<таблица>`.id, и с умолчанием accounts он падал на любой другой
        // таблице (Unknown column 'accounts.id').
        $filter = new FilterBuilder($meta['columns'], $meta['numeric'], self::getNumericLikeColumns(), $this->table);

        // Фильтр по конкретным ID (приоритетный для экспорта выбранных записей)
        if (!empty($params['ids']) && is_array($params['ids'])) {
            $filter->addIdsFilter($params['ids']);
        }

        // Режим «только точное совпадение»: запрещает откат поиска на подстроку.
        // Ставится ДО addSearchFilter и живёт внутри FilterBuilder, потому что
        // сам откат вызывается из двух мест (index.php и refresh.php) — правило
        // должно быть одно на оба.
        $exactParam = isset($params['exact']) && !is_array($params['exact'])
            ? (string)$params['exact']
            : '';
        $filter->setExactSearchOnly($exactParam === '1');

        // Общий поиск
        if (!empty($params['q'])) {
            $filter->addSearchFilter($params['q']);
        }

        // Статусы (множественный выбор)
        $statusArray = [];
        if (isset($params['status'])) {
            if (is_array($params['status'])) {
                $statusArray = $params['status'];
            } elseif (is_string($params['status']) && $params['status'] !== '') {
                $statusArray = explode(',', $params['status']);
            }
        }
        $emptyStatus = !empty($params['empty_status']);
        $filter->addStatusFilter($statusArray, $emptyStatus);

        // Статус marketplace (с поддержкой пустых значений)
        if (!empty($params['status_marketplace'])) {
            if ($params['status_marketplace'] === '__empty__') {
                $filter->addEmptyFilter('status_marketplace');
            } else {
                $filter->addEqualFilter('status_marketplace', $params['status_marketplace']);
            }
        }

        // Фильтр Currency (с поддержкой пустых значений)
        if (!empty($params['currency'])) {
            if ($params['currency'] === '__empty__') {
                $filter->addEmptyFilter('currency');
            } else {
                $filter->addEqualFilter('currency', $params['currency']);
            }
        }

        // Фильтр Geo (с поддержкой пустых значений)
        if (!empty($params['geo'])) {
            if ($params['geo'] === '__empty__') {
                $filter->addEmptyFilter('geo');
            } else {
                $filter->addEqualFilter('geo', $params['geo']);
            }
        }

        // Фильтр Status RK (с поддержкой пустых значений)
        if (!empty($params['status_rk'])) {
            if ($params['status_rk'] === '__empty__') {
                $filter->addEmptyFilter('status_rk');
            } else {
                $filter->addEqualFilter('status_rk', $params['status_rk']);
            }
        }

        // Фильтр Limit RK (диапазон)
        if ($this->metadata->columnExists('limit_rk')) {
            $filter->addRangeFilter('limit_rk',
                $params['limit_rk_from'] ?? null,
                $params['limit_rk_to'] ?? null
            );
        }

        // Email: проверяет колонку email + extra_info_2 (если содержит @)
        $filter->addEmailPresentFilter(!empty($params['has_email']));
        $filter->addNotEmptyFilter('two_fa', !empty($params['has_two_fa']));
        $filter->addNotEmptyFilter('token', !empty($params['has_token']));
        $filter->addNotEmptyFilter('avatar', !empty($params['has_avatar']));
        $filter->addNotEmptyFilter('cover', !empty($params['has_cover']));
        $filter->addNotEmptyFilter('password', !empty($params['has_password']));
        $filter->addNotEmptyFilter('passkey', !empty($params['has_passkey']));

        // Телефон удалён / не удалён.
        // Трёхпозиционный, поэтому значения строковые ('yes'/'no'), а не 1/0:
        // весь маппинг вокруг построен на !empty(), и '0' в нём молча читался
        // бы как «фильтр выключен».
        $phoneRemoved = isset($params['phone_removed']) ? (string)$params['phone_removed'] : '';
        $filter->addPresenceFilter('phone_removed', $phoneRemoved);


        // Фильтр "Fan Page" (quantity_fp > 0)
        $filter->addGreaterThanZeroFilter('quantity_fp', !empty($params['has_fan_page']));

        // Фильтр "полностью заполненные"
        $filter->addFullyFilledFilter(!empty($params['full_filled']));

        // Фильтр "только избранные"
        if (!empty($params['favorites_only'])) {
            // Получаем ID пользователя из сессии
            $userId = $_SESSION['username'] ?? null;
            if ($userId) {
                $filter->addFavoritesFilter($userId, true);
            }
        }

        // Числовые диапазоны
        if ($this->metadata->columnExists('scenario_pharma')) {
            $filter->addRangeFilter('scenario_pharma',
                $params['pharma_from'] ?? null,
                $params['pharma_to'] ?? null
            );
        }

        if ($this->metadata->columnExists('quantity_friends')) {
            $filter->addRangeFilter('quantity_friends',
                $params['friends_from'] ?? null,
                $params['friends_to'] ?? null
            );
        }

        // Диапазон по количеству БМ
        if ($this->metadata->columnExists('bm')) {
            $filter->addRangeFilter('bm',
                $params['bm_from'] ?? null,
                $params['bm_to'] ?? null
            );
        }

        // Фильтр по статусу БМ (has_valid / has_ban / only_valid)
        $bmStatus = $params['bm_status'] ?? '';
        if ($bmStatus !== '' && $bmStatus !== 'any') {
            $filter->addBmStatusFilter($bmStatus);
        }

        // Год создания
        $filter->addYearCreatedFilter(
            $params['year_created_from'] ?? null,
            $params['year_created_to'] ?? null
        );

        return $filter;
    }

    /**
     * Сборка фильтра для страницы корзины (Trash).
     *
     * Берёт базовый createFilterFromRequest() (поиск, статусы и т.д.) и добавляет
     * trash-специфичные условия:
     *   - диапазон даты удаления (deleted_from / deleted_to → addDateRangeFilter('deleted_at'))
     *   - "только пустые" (only_empty → login И email пусты)
     *   - addDeletedOnly() — гарантирует deleted_at IS NOT NULL (живые записи не попадут).
     *
     * Единый источник для trash.php и всех by-filter эндпоинтов (restore/delete/purge),
     * чтобы UI и бэкенд строили идентичный WHERE.
     */
    public function createTrashFilterFromRequest(array $params): FilterBuilder {
        $filter = $this->createFilterFromRequest($params);

        // Только удалённые (корзина) — ДОБАВЛЯЕМ ПЕРВЫМ.
        // addDeletedOnly() пропускает добавление, если в условиях уже встречается
        // 'deleted_at'. Диапазон по deleted_at содержит эту подстроку, поэтому при
        // обратном порядке токен-страж `deleted_at IS NOT NULL` не попадёт в WHERE,
        // и защитная проверка by-filter операций (restore/delete) ложно сработает.
        $filter->addDeletedOnly();

        // Диапазон даты удаления
        if ($this->metadata->columnExists('deleted_at')) {
            $filter->addDateRangeFilter('deleted_at',
                $params['deleted_from'] ?? null,
                $params['deleted_to'] ?? null
            );
        }

        // "Только пустые" аккаунты (мусор: login и email пусты)
        $filter->addEmptyAccountFilter(!empty($params['only_empty']));

        return $filter;
    }

    /**
     * Построение ORDER BY выражения с правильной обработкой NULL значений
     * Централизованная логика для устранения дублирования
     *
     * @param string $sort Название колонки для сортировки
     * @param string $dir Направление сортировки (ASC/DESC)
     * @return string SQL выражение для ORDER BY
     */
    public function buildOrderBy(string $sort, string $dir = 'ASC'): string {
        $meta = $this->getColumnMetadata();

        // Валидация сортировки
        if (!in_array($sort, $meta['all'], true)) {
            $sort = 'id';
        }
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        // Сортировка по id — всегда простая, чтобы использовать индекс (избегаем filesort на 90k+ строк).
        if ($sort === 'id') {
            return "`id` $dir";
        }

        // Везде ниже добавляем `id` финальным tie-breaker. Без него LIMIT/OFFSET-пагинация по
        // не-уникальной колонке (quantity_friends, year_created, status, created_at и т.д.)
        // нестабильна между батчами: MySQL не гарантирует одинаковый порядок тай-строк между
        // отдельными выполнениями запроса, и export.php перечитывает одни и те же id в разные
        // батчи → дубли в выгрузке.

        // Колонки с числовым типом в БД (INT и т.д.) — простая сортировка для использования индекса.
        if (in_array($sort, $meta['numeric'], true)) {
            return "`$sort` $dir, `id` $dir";
        }

        // Колонки, которые хранятся как строки, но сортируются как числа (TRIM/CAST по строкам, индекс не используется)
        $isNumericLike = in_array($sort, self::getNumericLikeColumns(), true);

        if ($isNumericLike) {
            $numericExpr = "CAST(COALESCE(NULLIF(TRIM(`$sort`), ''), '0') AS UNSIGNED)";
            if ($dir === 'ASC') {
                return "CASE WHEN `$sort` IS NULL OR TRIM(`$sort`) = '' THEN 1 ELSE 0 END, $numericExpr ASC, `id` ASC";
            }
            return "CASE WHEN `$sort` IS NULL OR TRIM(`$sort`) = '' THEN 1 ELSE 0 END DESC, $numericExpr DESC, `id` DESC";
        }

        {
            // Для текстовых полей: NULL и пустые значения идут в конец при ASC, в начало при DESC
            if ($dir === 'ASC') {
                return "(`$sort` IS NULL OR `$sort` = ''), `$sort` ASC, `id` ASC";
            } else {
                return "(`$sort` IS NULL OR `$sort` = '') DESC, `$sort` DESC, `id` DESC";
            }
        }
    }
}
