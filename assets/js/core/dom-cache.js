/**
 * Кеширование DOM элементов для оптимизации производительности
 * 
 * Избегает повторных querySelector/querySelectorAll вызовов,
 * что значительно улучшает производительность при частых обновлениях
 */

class DOMCache {
  constructor() {
    // Кеш для querySelector (одиночные элементы)
    this.elements = new Map();
    
    // Кеш для querySelectorAll (коллекции элементов)
    this.collections = new Map();
    
    // Кеш для getElementById (самый быстрый, но все равно кешируем)
    this.byId = new Map();
    
    // Время последней инвалидации
    this.lastInvalidation = 0;
    
    // TTL для автоматической инвалидации (опционально)
    this.ttl = null;
  }
  
  /**
   * Получить элемент по селектору
   * @param {string} selector - CSS селектор
   * @param {boolean} force - Принудительно обновить кеш
   * @returns {Element|null}
   */
  get(selector, force = false) {
    if (!force && this.elements.has(selector)) {
      return this.elements.get(selector);
    }
    
    const element = document.querySelector(selector);
    if (element) {
      this.elements.set(selector, element);
    }
    return element;
  }
  
  /**
   * Получить элемент по ID (самый быстрый способ)
   * @param {string} id - ID элемента
   * @param {boolean} force - Принудительно обновить кеш
   * @returns {Element|null}
   */
  getById(id, force = false) {
    if (!force && this.byId.has(id)) {
      return this.byId.get(id);
    }
    
    const element = document.getElementById(id);
    if (element) {
      this.byId.set(id, element);
    }
    return element;
  }
  
  /**
   * Получить коллекцию элементов
   * @param {string} selector - CSS селектор
   * @param {boolean} force - Принудительно обновить кеш
   * @returns {NodeList|Array}
   */
  getAll(selector, force = false) {
    if (!force && this.collections.has(selector)) {
      return this.collections.get(selector);
    }
    
    const elements = Array.from(document.querySelectorAll(selector));
    this.collections.set(selector, elements);
    return elements;
  }
  
  /**
   * Получить элемент внутри другого элемента (scoped query)
   * @param {Element} parent - Родительский элемент
   * @param {string} selector - CSS селектор
   * @param {boolean} force - Принудительно обновить кеш
   * @returns {Element|null}
   */
  getIn(parent, selector, force = false) {
    const key = `${parent.id || parent.className || 'parent'}:${selector}`;
    
    if (!force && this.elements.has(key)) {
      return this.elements.get(key);
    }
    
    const element = parent.querySelector(selector);
    if (element) {
      this.elements.set(key, element);
    }
    return element;
  }
  
  /**
   * Инвалидировать кеш (очистить)
   * @param {string|null} selector - Конкретный селектор или null для полной очистки
   */
  invalidate(selector = null) {
    this.lastInvalidation = performance.now();
    
    if (selector) {
      // Инвалидируем конкретный селектор
      this.elements.delete(selector);
      this.collections.delete(selector);
      this.byId.delete(selector);
      
      // Также удаляем все связанные ключи (для scoped queries)
      for (const key of this.elements.keys()) {
        if (key.includes(selector)) {
          this.elements.delete(key);
        }
      }
    } else {
      // Полная очистка
      this.elements.clear();
      this.collections.clear();
      this.byId.clear();
    }
  }
  
  /**
   * Предзагрузка часто используемых элементов
   * @param {Array<string>} selectors - Массив селекторов
   */
  preload(selectors) {
    selectors.forEach(selector => {
      if (!this.elements.has(selector)) {
        this.get(selector);
      }
    });
  }
  
  /**
   * Установить TTL для автоматической инвалидации
   * @param {number} ttl - Время жизни кеша в миллисекундах
   */
  setTTL(ttl) {
    this.ttl = ttl;
    
    if (ttl && !this._ttlInterval) {
      this._ttlInterval = setInterval(() => {
        const now = performance.now();
        if (now - this.lastInvalidation > ttl) {
          this.invalidate();
        }
      }, ttl);
    }
  }
  
  /**
   * Очистка при уничтожении — останавливает TTL-интервал и MutationObserver,
   * чтобы не было утечек при HMR/перезапуске контроллера.
   */
  destroy() {
    if (this._ttlInterval) {
      clearInterval(this._ttlInterval);
      this._ttlInterval = null;
    }
    if (this._mutationObserver) {
      try { this._mutationObserver.disconnect(); } catch (_) {}
      this._mutationObserver = null;
    }
    this.invalidate();
  }
}

// Создаем глобальный экземпляр
const domCache = new DOMCache();

// Автоматическая инвалидация при обновлении DOM (опционально)
if (typeof MutationObserver !== 'undefined') {
  const observer = new MutationObserver(() => {
    // Инвалидируем только при значительных изменениях
    // (не при каждом мелком изменении)
    const now = performance.now();
    if (now - domCache.lastInvalidation > 100) {
      // Инвалидируем только коллекции, одиночные элементы оставляем
      domCache.collections.clear();
    }
  });

  // Сохраняем ссылку на экземпляре, чтобы destroy() мог её отключить.
  domCache._mutationObserver = observer;

  // Наблюдаем только за основными изменениями структуры
  if (document.body) {
    observer.observe(document.body, {
      childList: true,
      subtree: false // Только прямые дети body
    });
  }
}

// Экспортируем для использования
if (typeof module !== 'undefined' && module.exports) {
  module.exports = domCache;
} else {
  window.domCache = domCache;
}
