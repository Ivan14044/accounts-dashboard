<?php
/**
 * Чтение аккаунтов на уровне сервиса.
 *
 * Делегирует {@see AccountsRepository}, добавляя валидацию сортировки и
 * подготовку данных для check.fb.tools (selected/page/filter).
 *
 * Подключается в {@see AccountsService} через `use`.
 */
trait AccountsServiceQueryTrait {
    /**
     * Получение списка аккаунтов с фильтрами и пагинацией
     * Делегирует работу в AccountsRepository
     *
     * @param FilterBuilder $filter Фильтр
     * @param string $sort Колонка для сортировки
     * @param string $dir Направление сортировки
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @param bool|null $includeDeleted Включать ли удалённые записи (для корзины)
     */
    public function getAccounts(FilterBuilder $filter, string $sort = 'id', string $dir = 'ASC', int $limit = 100, int $offset = 0, $includeDeleted = false, ?array $columns = null): array {
        $meta = $this->getColumnMetadata();

        // Валидация сортировки
        if (!in_array($sort, $meta['all'], true)) {
            $sort = 'id';
        }
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        // Используем централизованную логику построения ORDER BY
        $orderBy = $this->buildOrderBy($sort, $dir);

        // Приводим к bool, если передан null
        if ($includeDeleted === null) {
            $includeDeleted = false;
        }
        $includeDeleted = (bool)$includeDeleted;

        // Делегируем в репозиторий ($columns — необязательное ограничение колонок выборки)
        return $this->repository->getAccounts($filter, $orderBy, $limit, $offset, $includeDeleted, $columns);
    }

    /**
     * Подсчет количества записей с фильтрами
     * Делегирует работу в AccountsRepository
     *
     * @param FilterBuilder $filter Фильтр
     * @param bool|null $includeDeleted Включать ли удалённые записи
     */
    public function getAccountsCount(FilterBuilder $filter, $includeDeleted = false): int {
        // Приводим к bool, если передан null
        if ($includeDeleted === null) {
            $includeDeleted = false;
        }
        $includeDeleted = (bool)$includeDeleted;

        // Делегируем в репозиторий
        return $this->repository->getAccountsCount($filter, $includeDeleted);
    }

    /**
     * Получение одной записи аккаунта по ID
     * Делегирует работу в AccountsRepository
     */
    public function getAccountById(int $id): ?array {
        return $this->repository->getAccountById($id);
    }

    /**
     * Получение записей для проверки валидности (id, login, id_soc_account, cookies)
     * scope: selected|page — по ids; filter — по query с пагинацией
     *
     * @param string $scope selected|page|filter
     * @param array $ids Массив ID (для selected/page)
     * @param string $query Строка query-параметров (для filter)
     * @param int $limit
     * @param int $offset
     * @return array ['rows' => [...], 'total' => int] total только для filter
     */
    public function getAccountsForValidation(string $scope, array $ids, string $query, int $limit = 2000, int $offset = 0): array {
        if ($scope === 'filter') {
            parse_str($query, $params);
            $filter = $this->createFilterFromRequest($params);
            // Пустой фильтр = все аккаунты (пользователь сознательно выбрал "Все")
            $total = $this->repository->getAccountsCount($filter, false);
            $rows = $this->repository->getAccountsByFilterForValidation($filter, 'id ASC', $limit, $offset);
            return ['rows' => $rows, 'total' => $total];
        }
        // selected | page
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return ['rows' => [], 'total' => 0];
        }
        $rows = $this->repository->getAccountsByIdsForValidation($ids);
        return ['rows' => $rows, 'total' => count($rows)];
    }

    /**
     * Быстрый COUNT для validate/preview — возвращает только число записей
     * без тяжёлой выборки cookies. Для selected/page — count(ids), для filter
     * — SQL COUNT с использованием существующих индексов.
     */
    public function getValidationCount(string $scope, array $ids, string $query): int {
        if ($scope === 'filter') {
            parse_str($query, $params);
            $filter = $this->createFilterFromRequest($params);
            return $this->repository->getAccountsCount($filter, false);
        }
        $ids = array_filter(array_map('intval', $ids));
        return count($ids);
    }
}
