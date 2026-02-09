/**
 * Главный модуль инициализации дашборда
 * Координирует инициализацию всех модулей дашборда
 */

class DashboardMain {
  constructor() {
    this.selection = null;
    this.filters = null;
    this.stats = null;
    this.modals = null;
    this.initialized = false;
  }
  
  /**
   * Инициализация всех модулей дашборда
   */
  init() {
    if (this.initialized) {
      if (typeof logger !== 'undefined') {
        logger.warn('⚠️ Dashboard уже инициализирован');
      }
      return;
    }
    
    try {
      // Инициализация модуля выбора строк
      if (typeof window.DashboardSelection !== 'undefined') {
        window.DashboardSelection.init();
        this.selection = window.DashboardSelection;
        if (typeof logger !== 'undefined') {
          logger.debug('✅ Модуль выбора строк инициализирован');
        }
      } else {
        if (typeof logger !== 'undefined') {
          logger.warn('⚠️ Модуль DashboardSelection не найден');
        }
      }
      
      // Инициализация модуля фильтров
      if (typeof window.DashboardFilters !== 'undefined') {
        window.DashboardFilters.init();
        this.filters = window.DashboardFilters;
        if (typeof logger !== 'undefined') {
          logger.debug('✅ Модуль фильтров инициализирован');
        }
      } else {
        if (typeof logger !== 'undefined') {
          logger.warn('⚠️ Модуль DashboardFilters не найден');
        }
      }
      
      // Инициализация модуля статистики
      if (typeof window.DashboardStats !== 'undefined') {
        window.DashboardStats.init();
        this.stats = window.DashboardStats;
        if (typeof logger !== 'undefined') {
          logger.debug('✅ Модуль статистики инициализирован');
        }
      } else {
        if (typeof logger !== 'undefined') {
          logger.warn('⚠️ Модуль DashboardStats не найден');
        }
      }
      
      // Инициализация модуля модальных окон
      if (typeof window.DashboardModals !== 'undefined') {
        window.DashboardModals.init();
        this.modals = window.DashboardModals;
        if (typeof logger !== 'undefined') {
          logger.debug('✅ Модуль модальных окон инициализирован');
        }
      } else {
        if (typeof logger !== 'undefined') {
          logger.warn('⚠️ Модуль DashboardModals не найден');
        }
      }
      
      // Инициализация таблицы (если доступна)
      if (window.tableModule && typeof window.tableModule.init === 'function') {
        window.tableModule.init();
        if (typeof logger !== 'undefined') {
          logger.debug('✅ Модуль таблицы инициализирован');
        }
      }
      
      this.initialized = true;
      
      if (typeof logger !== 'undefined') {
        logger.debug('✅ Dashboard полностью инициализирован');
      }
    } catch (error) {
      if (typeof logger !== 'undefined') {
        logger.error('❌ Ошибка инициализации Dashboard:', error);
      }
      console.error('Dashboard initialization error:', error);
    }
  }
  
  /**
   * Получение модуля выбора строк
   */
  getSelection() {
    return this.selection;
  }
  
  /**
   * Получение модуля фильтров
   */
  getFilters() {
    return this.filters;
  }
  
  /**
   * Получение модуля статистики
   */
  getStats() {
    return this.stats;
  }
  
  /**
   * Получение модуля модальных окон
   */
  getModals() {
    return this.modals;
  }
  
  /**
   * Проверка, инициализирован ли дашборд
   */
  isInitialized() {
    return this.initialized;
  }
}

// Инициализация при загрузке DOM
document.addEventListener('DOMContentLoaded', () => {
  window.dashboard = new DashboardMain();
  window.dashboard.init();
});

// Экспорт для глобального использования
window.DashboardMain = DashboardMain;
