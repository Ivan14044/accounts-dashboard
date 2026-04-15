/**
 * СТИКИ СКРОЛЛБАР - Новая реализация с нуля
 * Простая, быстрая и надежная синхронизация горизонтального скролла
 */
(function() {
  'use strict';

  class StickyScrollbar {
    constructor(options = {}) {
      this.options = {
        debug: options.debug || false,
        ...options
      };
      
      // Элементы
      this.wrapper = null;
      this.scrollbar = null;
      this.content = null;
      this.tableWrap = null;
      this.table = null;
      
      // Состояние
      this.isActive = false;
      this.lastScrollLeft = 0;
      
      // Флаги для предотвращения циклической синхронизации
      this.syncingFromSticky = false;
      this.syncingFromTable = false;
      
      // Наблюдатели
      this.resizeObserver = null;
      this.rafId = null;
      // Throttle ResizeObserver: один update за период (FPS)
      this._resizeScheduled = false;
      this._resizeDelay = 60;
      this._resizeTimerId = null;
      
      this.init();
    }
    
    /**
     * Инициализация
     */
    init() {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => this.setup());
      } else {
        this.setup();
      }
    }
    
    /**
     * Настройка компонента
     */
    setup() {
      // Поиск элементов
      this.wrapper = document.getElementById('stickyScrollbarWrapper');
      this.scrollbar = document.getElementById('stickyScrollbar');
      this.content = document.getElementById('stickyScrollbarContent');
      this.tableWrap = document.getElementById('tableWrap');
      this.table = document.getElementById('accountsTable');
      
      // Проверка наличия элементов
      if (!this.wrapper || !this.scrollbar || !this.content || !this.tableWrap || !this.table) {
        if (this.options.debug) {
          (typeof logger !== 'undefined' ? logger.warn : console.warn)('StickyScrollbar: Не найдены необходимые элементы', {
            wrapper: !!this.wrapper,
            scrollbar: !!this.scrollbar,
            content: !!this.content,
            tableWrap: !!this.tableWrap,
            table: !!this.table
          });
        }
        return;
      }
      
      // Привязка событий
      this.attachEvents();
      
      // Настройка наблюдателей
      this.setupObservers();
      
      // Первичное обновление
      this.update();
      
      // Экспорт для внешнего использования
      window.updateStickyScrollbar = () => this.update();
      
      if (this.options.debug) {
        if (typeof logger !== 'undefined') logger.debug('StickyScrollbar: Инициализация завершена');
      }
    }
    
    /**
     * Привязка событий скролла (один RAF на кадр — меньше layout thrashing, лучше FPS)
     */
    attachEvents() {
      const self = this;
      let scrollSyncRafId = null;
      let scrollSyncSource = null;

      function runScrollSync() {
        scrollSyncRafId = null;
        const src = scrollSyncSource;
        scrollSyncSource = null;
        if (!self.tableWrap || !self.scrollbar) return;
        if (src === 'table') {
          self.syncingFromSticky = true;
          const left = self.tableWrap.scrollLeft;
          self.scrollbar.scrollLeft = left;
          self.lastScrollLeft = left;
          self.syncingFromSticky = false;
        } else if (src === 'scrollbar') {
          self.syncingFromTable = true;
          const left = self.scrollbar.scrollLeft;
          self.tableWrap.scrollLeft = left;
          self.lastScrollLeft = left;
          self.syncingFromTable = false;
        }
      }

      function scheduleScrollSync(source) {
        scrollSyncSource = source;
        if (scrollSyncRafId !== null) return;
        scrollSyncRafId = requestAnimationFrame(runScrollSync);
      }

      // Сохраняем ссылки для removeEventListener в destroy()
      this._onScrollbarScroll = () => {
        if (this.syncingFromTable) return;
        this.syncingFromSticky = true;
        scheduleScrollSync('scrollbar');
        queueMicrotask(() => { this.syncingFromSticky = false; });
      };
      this._onTableScroll = () => {
        if (this.syncingFromSticky) return;
        this.syncingFromTable = true;
        scheduleScrollSync('table');
        queueMicrotask(() => { this.syncingFromTable = false; });
      };
      let resizeTimer;
      this._onWindowResize = () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => this.update(), 100);
      };

      this.scrollbar.addEventListener('scroll', this._onScrollbarScroll, { passive: true });
      this.tableWrap.addEventListener('scroll', this._onTableScroll, { passive: true });
      window.addEventListener('resize', this._onWindowResize, { passive: true });
    }
    
    /**
     * Настройка ResizeObserver для отслеживания размеров таблицы
     */
    setupObservers() {
      if (typeof ResizeObserver === 'undefined') return;
      const self = this;
      
      this.resizeObserver = new ResizeObserver(() => {
        if (self._resizeScheduled) return;
        self._resizeScheduled = true;
        if (self._resizeTimerId) clearTimeout(self._resizeTimerId);
        self._resizeTimerId = setTimeout(function() {
          self._resizeScheduled = false;
          self._resizeTimerId = null;
          if (self.rafId) cancelAnimationFrame(self.rafId);
          self.rafId = requestAnimationFrame(function() {
            self.update();
            self.rafId = null;
          });
        }, self._resizeDelay);
      });
      
      // Наблюдаем за таблицей и контейнером
      this.resizeObserver.observe(this.table);
      this.resizeObserver.observe(this.tableWrap);
    }
    
    /**
     * Обновление размеров и видимости скроллбара
     */
    update() {
      if (!this.table || !this.tableWrap || !this.content || !this.wrapper) {
        return;
      }
      // Все чтения в начале (батчинг для FPS — меньше layout thrashing)
      const tableWidth = this.table.scrollWidth;
      const containerWidth = this.tableWrap.clientWidth;
      const needsScrollbar = tableWidth > containerWidth;
      const tableScrollLeft = needsScrollbar ? this.tableWrap.scrollLeft : 0;

      if (needsScrollbar) {
        this.content.style.width = tableWidth + 'px';
        if (!this.isActive) {
          this.wrapper.classList.add('active');
          this.isActive = true;
        }
        if (this.scrollbar && Math.abs(this.scrollbar.scrollLeft - tableScrollLeft) > 1) {
          this.scrollbar.scrollLeft = tableScrollLeft;
          this.lastScrollLeft = tableScrollLeft;
        }
      } else {
        if (this.isActive) {
          this.wrapper.classList.remove('active');
          this.isActive = false;
        }
      }

      if (this.options.debug) {
        if (typeof logger !== 'undefined') logger.debug('StickyScrollbar: update', {
          tableWidth,
          containerWidth,
          needsScrollbar,
          scrollLeft: tableScrollLeft
        });
      }
    }
    
    /**
     * Синхронизация позиции скролла
     */
    syncPosition() {
      if (!this.tableWrap || !this.scrollbar) return;
      
      const tableScrollLeft = this.tableWrap.scrollLeft;
      
      // Синхронизируем только если позиции отличаются
      if (Math.abs(this.scrollbar.scrollLeft - tableScrollLeft) > 1) {
        this.scrollbar.scrollLeft = tableScrollLeft;
        this.lastScrollLeft = tableScrollLeft;
      }
    }
    
    /**
     * Очистка ресурсов
     */
    destroy() {
      // Удаляем event listeners
      if (this.scrollbar && this._onScrollbarScroll) {
        this.scrollbar.removeEventListener('scroll', this._onScrollbarScroll);
      }
      if (this.tableWrap && this._onTableScroll) {
        this.tableWrap.removeEventListener('scroll', this._onTableScroll);
      }
      if (this._onWindowResize) {
        window.removeEventListener('resize', this._onWindowResize);
      }

      // Отключаем наблюдателей
      if (this.resizeObserver) {
        this.resizeObserver.disconnect();
        this.resizeObserver = null;
      }
      if (this._resizeTimerId) {
        clearTimeout(this._resizeTimerId);
        this._resizeTimerId = null;
      }
      // Отменяем RAF
      if (this.rafId) {
        cancelAnimationFrame(this.rafId);
        this.rafId = null;
      }

      // Удаляем метод обновления
      if (window.updateStickyScrollbar) {
        delete window.updateStickyScrollbar;
      }
    }
  }
  
  // Создаем глобальный экземпляр
  let instance = null;
  
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      instance = new StickyScrollbar();
    });
  } else {
    instance = new StickyScrollbar();
  }
  
  // Экспорт для внешнего доступа
  window.StickyScrollbar = StickyScrollbar;
  
})();
