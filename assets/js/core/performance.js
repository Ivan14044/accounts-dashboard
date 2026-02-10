/**
 * Утилиты для оптимизации производительности
 * 
 * Батчинг DOM операций, дебаунсинг, троттлинг
 */

/**
 * Дебаунсинг функции (отложенное выполнение)
 * @param {Function} fn - Функция для дебаунсинга
 * @param {number} delay - Задержка в миллисекундах
 * @returns {Function} - Дебаунсированная функция
 */
function debounce(fn, delay = 300) {
  let timeoutId = null;
  
  return function(...args) {
    const context = this;
    
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => {
      fn.apply(context, args);
    }, delay);
  };
}

/**
 * Троттлинг функции (ограничение частоты выполнения)
 * @param {Function} fn - Функция для троттлинга
 * @param {number} limit - Минимальный интервал между вызовами (мс)
 * @returns {Function} - Троттлированная функция
 */
function throttle(fn, limit = 100) {
  let inThrottle = false;
  let lastResult = null;
  
  return function(...args) {
    const context = this;
    
    if (!inThrottle) {
      lastResult = fn.apply(context, args);
      inThrottle = true;
      
      setTimeout(() => {
        inThrottle = false;
      }, limit);
    }
    
    return lastResult;
  };
}

/**
 * Батчинг DOM операций через requestAnimationFrame
 * @param {Function} fn - Функция для выполнения
 * @returns {Function} - Батчированная функция
 */
function batchDOM(fn) {
  let scheduled = false;
  let callbacks = [];
  
  return function(...args) {
    callbacks.push({ fn, args, context: this });
    
    if (!scheduled) {
      scheduled = true;
      requestAnimationFrame(() => {
        const toExecute = callbacks.slice();
        callbacks = [];
        scheduled = false;
        
        toExecute.forEach(({ fn, args, context }) => {
          fn.apply(context, args);
        });
      });
    }
  };
}

/**
 * Класс для батчинга множественных обновлений
 */
class BatchUpdater {
  constructor() {
    this.pending = new Map();
    this.scheduled = false;
  }
  
  /**
   * Добавить обновление в очередь
   * @param {string} key - Уникальный ключ обновления
   * @param {Function} updater - Функция обновления
   */
  add(key, updater) {
    this.pending.set(key, updater);
    this.schedule();
  }
  
  /**
   * Запланировать выполнение всех обновлений
   */
  schedule() {
    if (this.scheduled) return;
    
    this.scheduled = true;
    requestAnimationFrame(() => {
      const toExecute = Array.from(this.pending.values());
      this.pending.clear();
      this.scheduled = false;
      
      // Выполняем все обновления в одном кадре
      toExecute.forEach(updater => {
        try {
          updater();
        } catch (error) {
          (typeof logger !== 'undefined' ? logger.error : console.error)('BatchUpdater error:', error);
        }
      });
    });
  }
  
  /**
   * Очистить очередь
   */
  clear() {
    this.pending.clear();
    this.scheduled = false;
  }
}

/**
 * Измерение производительности функции
 * @param {string} label - Метка для измерения
 * @param {Function} fn - Функция для измерения
 * @returns {*} - Результат выполнения функции
 */
function measurePerformance(label, fn) {
  const start = performance.now();
  const result = fn();
  const end = performance.now();
  
  if (typeof logger !== 'undefined') {
    logger.debug(`⏱️ ${label}: ${(end - start).toFixed(2)}ms`);
  } else {
    if (typeof logger !== 'undefined') logger.debug(`⏱️ ${label}: ${(end - start).toFixed(2)}ms`);
  }
  
  return result;
}

/**
 * Ленивая загрузка модуля
 * @param {string} modulePath - Путь к модулю
 * @returns {Promise} - Промис с загруженным модулем
 */
function lazyLoad(modulePath) {
  return import(modulePath).catch(error => {
    (typeof logger !== 'undefined' ? logger.error : console.error)(`Failed to load module ${modulePath}:`, error);
    throw error;
  });
}

// Создаем глобальный экземпляр BatchUpdater
const batchUpdater = new BatchUpdater();

// Экспортируем для использования
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    debounce,
    throttle,
    batchDOM,
    BatchUpdater,
    batchUpdater,
    measurePerformance,
    lazyLoad
  };
} else {
  window.performanceUtils = {
    debounce,
    throttle,
    batchDOM,
    BatchUpdater,
    batchUpdater,
    measurePerformance,
    lazyLoad
  };
}
