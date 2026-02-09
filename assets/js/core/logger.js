/**
 * Система логирования с уровнями для оптимизации производительности
 * 
 * В продакшене отключает все логи кроме ошибок, что значительно
 * улучшает производительность (экономит 5-10ms на каждый console.log)
 */

class Logger {
  constructor() {
    // Уровни логирования
    this.levels = {
      DEBUG: 0,
      INFO: 1,
      WARN: 2,
      ERROR: 3,
      NONE: 4
    };
    
    // Определяем уровень из переменной окружения или localStorage
    const envLevel = typeof process !== 'undefined' && process.env?.NODE_ENV;
    const savedLevel = localStorage.getItem('dashboard_log_level');
    
    if (envLevel === 'production' || window.location.hostname !== 'localhost') {
      // Продакшен: только ошибки
      this.currentLevel = this.levels.ERROR;
    } else if (savedLevel !== null) {
      // Из localStorage
      this.currentLevel = parseInt(savedLevel, 10) || this.levels.DEBUG;
    } else {
      // Разработка: все логи
      this.currentLevel = this.levels.DEBUG;
    }
    
    // Кеш для проверки уровня (избегаем лишних проверок)
    this._shouldLog = {
      [this.levels.DEBUG]: this.currentLevel <= this.levels.DEBUG,
      [this.levels.INFO]: this.currentLevel <= this.levels.INFO,
      [this.levels.WARN]: this.currentLevel <= this.levels.WARN,
      [this.levels.ERROR]: this.currentLevel <= this.levels.ERROR
    };
  }
  
  /**
   * Установить уровень логирования
   * @param {number} level - Уровень логирования
   */
  setLevel(level) {
    this.currentLevel = level;
    localStorage.setItem('dashboard_log_level', String(level));
    
    // Обновляем кеш
    this._shouldLog = {
      [this.levels.DEBUG]: this.currentLevel <= this.levels.DEBUG,
      [this.levels.INFO]: this.currentLevel <= this.levels.INFO,
      [this.levels.WARN]: this.currentLevel <= this.levels.WARN,
      [this.levels.ERROR]: this.currentLevel <= this.levels.ERROR
    };
  }
  
  /**
   * Логирование с проверкой уровня
   * @param {number} level - Уровень сообщения
   * @param {string} method - Метод console (log, warn, error)
   * @param {...any} args - Аргументы для логирования
   */
  _log(level, method, ...args) {
    // Быстрая проверка через кеш
    if (!this._shouldLog[level]) {
      return;
    }
    
    // Вызываем соответствующий метод console
    if (typeof console[method] === 'function') {
      console[method](...args);
    } else {
      console.log(...args);
    }
  }
  
  /**
   * Debug логи (только в разработке)
   */
  debug(...args) {
    this._log(this.levels.DEBUG, 'log', ...args);
  }
  
  /**
   * Информационные логи
   */
  info(...args) {
    this._log(this.levels.INFO, 'log', ...args);
  }
  
  /**
   * Предупреждения
   */
  warn(...args) {
    this._log(this.levels.WARN, 'warn', ...args);
  }
  
  /**
   * Ошибки (всегда логируются)
   */
  error(...args) {
    this._log(this.levels.ERROR, 'error', ...args);
  }
  
  /**
   * Группировка логов (только в разработке)
   */
  group(label) {
    if (this.currentLevel <= this.levels.DEBUG && typeof console.group === 'function') {
      console.group(label);
    }
  }
  
  groupEnd() {
    if (this.currentLevel <= this.levels.DEBUG && typeof console.groupEnd === 'function') {
      console.groupEnd();
    }
  }
  
  /**
   * Таблица (только в разработке)
   */
  table(data) {
    if (this.currentLevel <= this.levels.DEBUG && typeof console.table === 'function') {
      console.table(data);
    }
  }
}

// Создаем глобальный экземпляр
const logger = new Logger();

// Экспортируем для использования
if (typeof module !== 'undefined' && module.exports) {
  module.exports = logger;
} else {
  window.logger = logger;
}
