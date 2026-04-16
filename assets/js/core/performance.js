/**
 * Утилиты для оптимизации производительности
 * 
 * Батчинг DOM операций, дебаунсинг, троттлинг, requestIdleCallback
 */

/**
 * Выполнить callback в период простоя главного потока (requestIdleCallback с fallback)
 * @param {Function} callback - Функция для отложенного выполнения
 */
function scheduleIdle(callback) {
  if (typeof requestIdleCallback !== 'undefined') {
    requestIdleCallback(callback, { timeout: 100 });
  } else {
    setTimeout(callback, 0);
  }
}

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

/**
 * Замер FPS при скролле/анимации: считает вызовы requestAnimationFrame за 1–2 сек, выводит средний FPS.
 * Запуск: window.__MEASURE_FPS__ = true или URL ?fps=1. По умолчанию не выполняется.
 */
function measureFPS(durationMs) {
  durationMs = durationMs || 2000;
  var frames = 0;
  var start = typeof performance !== 'undefined' ? performance.now() : Date.now();

  function tick() {
    frames++;
    if (typeof performance !== 'undefined' ? performance.now() - start < durationMs : (Date.now() - start) < durationMs) {
      requestAnimationFrame(tick);
    } else {
      var elapsed = (typeof performance !== 'undefined' ? performance.now() : Date.now()) - start;
      var fps = Math.round((frames / elapsed) * 1000);
      if (typeof console !== 'undefined' && console.log) {
        console.log('[FPS] ' + frames + ' frames in ' + (elapsed / 1000).toFixed(2) + 's → ~' + fps + ' FPS');
      }
      var el = document.getElementById('fps-measure-output');
      if (el) {
        el.textContent = 'FPS: ' + fps + ' (' + frames + ' frames, ' + (elapsed / 1000).toFixed(2) + 's)';
      }
    }
  }
  requestAnimationFrame(tick);
}

function runFPSMeasureIfRequested() {
  var urlFlag = false;
  try {
    var params = new URLSearchParams(window.location.search);
    urlFlag = params.get('fps') === '1' || params.get('fps') === 'true';
  } catch (_) {}
  if (window.__MEASURE_FPS__ === true || urlFlag) {
    var overlay = document.createElement('div');
    overlay.id = 'fps-measure-output';
    overlay.setAttribute('aria-live', 'polite');
    overlay.style.cssText = 'position:fixed;bottom:8px;right:8px;z-index:9999;background:rgba(0,0,0,0.8);color:#0f0;padding:6px 10px;font-family:monospace;font-size:12px;border-radius:4px;';
    overlay.textContent = 'FPS: measuring...';
    document.body.appendChild(overlay);
    measureFPS(2000);
  }
}

// Создаем глобальный экземпляр BatchUpdater
const batchUpdater = new BatchUpdater();

// Экспортируем для использования
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    scheduleIdle,
    debounce,
    throttle,
    batchDOM,
    BatchUpdater,
    batchUpdater,
    measurePerformance,
    lazyLoad
  };
} else {
  window.scheduleIdle = scheduleIdle;
  window.measureFPS = measureFPS;
  // Canonical источник debounce/throttle — новые модули должны использовать их отсюда.
  // Остаются локальные дубликаты в dashboard.js/quick-search.js/table-module.js —
  // не трогаем без тестов, новый код берёт window.debounce.
  if (typeof window.debounce !== 'function') window.debounce = debounce;
  if (typeof window.throttle !== 'function') window.throttle = throttle;
  window.batchDOM = window.batchDOM || batchDOM;
  window.performanceUtils = {
    scheduleIdle,
    debounce,
    throttle,
    batchDOM,
    BatchUpdater,
    batchUpdater,
    measurePerformance,
    lazyLoad,
    measureFPS
  };
  if (document.readyState === 'complete') {
    runFPSMeasureIfRequested();
  } else {
    window.addEventListener('load', runFPSMeasureIfRequested);
  }
}
