<?php
/**
 * Делегирующие методы статистики.
 *
 * Прокидывают вызовы в {@see StatisticsService} — счётчики по статусам,
 * валютам, гео, daily-totals, уникальные значения фильтров. Логика подсчёта
 * (кэш, UNION-запросы и т.д.) живёт там.
 *
 * Подключается в {@see AccountsService} через `use`.
 */
trait AccountsServiceStatsTrait {
    /**
     * Получение статистики (общая и по статусам)
     * Делегирует работу в StatisticsService
     */
    public function getStatistics(FilterBuilder $filter = null): array {
        return $this->statistics->getStatistics($filter);
    }

    /**
     * Кумулятивный total по дням для sparkline на дашборде.
     * Делегирует работу в StatisticsService
     */
    public function getDailyTotals(int $days = 7): array {
        return $this->statistics->getDailyTotals($days);
    }

    /**
     * Получение всех уникальных значений фильтров одним запросом
     * Делегирует работу в StatisticsService
     *
     * @return array Ассоциативный массив с ключами: status, status_marketplace, currency, geo, status_rk
     */
    public function getUniqueFilterValues(): array {
        return $this->statistics->getUniqueFilterValues();
    }

    /**
     * Получение списка уникальных статусов
     * Делегирует работу в StatisticsService
     */
    public function getUniqueStatuses(): array {
        return $this->statistics->getUniqueStatuses();
    }

    /**
     * Получение списка уникальных статусов marketplace с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueMarketplaceStatuses(): array {
        return $this->statistics->getUniqueMarketplaceStatuses();
    }

    /**
     * Получение всех счётчиков пустых значений фильтров одним запросом.
     * Ключи: status_marketplace, currency, geo, status_rk.
     */
    public function getEmptyFilterCounts(): array {
        return $this->statistics->getEmptyFilterCounts();
    }

    /**
     * Получение количества записей с пустым статусом marketplace
     * Делегирует работу в StatisticsService
     */
    public function getEmptyMarketplaceStatusCount(): int {
        return $this->statistics->getEmptyMarketplaceStatusCount();
    }

    /**
     * Получение списка уникальных валют (Currency) с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueCurrencies(): array {
        return $this->statistics->getUniqueCurrencies();
    }

    /**
     * Получение количества записей с пустой валютой
     * Делегирует работу в StatisticsService
     */
    public function getEmptyCurrencyCount(): int {
        return $this->statistics->getEmptyCurrencyCount();
    }

    /**
     * Получение списка уникальных значений geo с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueGeos(): array {
        return $this->statistics->getUniqueGeos();
    }

    /**
     * Получение количества записей с пустым geo
     * Делегирует работу в StatisticsService
     */
    public function getEmptyGeoCount(): int {
        return $this->statistics->getEmptyGeoCount();
    }

    /**
     * Получение списка уникальных значений status_rk с подсчетом
     * Делегирует работу в StatisticsService
     */
    public function getUniqueStatusRk(): array {
        return $this->statistics->getUniqueStatusRk();
    }

    /**
     * Получение количества записей с пустым status_rk
     * Делегирует работу в StatisticsService
     */
    public function getEmptyStatusRkCount(): int {
        return $this->statistics->getEmptyStatusRkCount();
    }
}
