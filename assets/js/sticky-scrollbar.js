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
          console.warn('StickyScrollbar: Не найдены необходимые элементы', {
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
        console.log('StickyScrollbar: Инициализация завершена');
      }
    }
    
    /**
     * Привязка событий скролла
     */
    attachEvents() {
      // Синхронизация: sticky -> table (пользователь скроллит sticky)
      this.scrollbar.addEventListener('scroll', () => {
        // Пропускаем если синхронизация идет от таблицы
        if (this.syncingFromTable) return;
        
        // Устанавливаем флаг
        this.syncingFromSticky = true;
        
        // Мгновенная синхронизация напрямую
        const scrollLeft = this.scrollbar.scrollLeft;
        
        // Синхронизируем только если позиции отличаются
        if (Math.abs(this.tableWrap.scrollLeft - scrollLeft) > 0.1) {
          this.tableWrap.scrollLeft = scrollLeft;
          this.lastScrollLeft = scrollLeft;
        }
        
        // Сбрасываем флаг асинхронно для предотвращения циклов
        // Используем микротаск для немедленного сброса после текущего стека
        queueMicrotask(() => {
          this.syncingFromSticky = false;
        });
      }, { passive: true });
      
      // Синхронизация: table -> sticky (пользователь скроллит table)
      this.tableWrap.addEventListener('scroll', () => {
        // Пропускаем если синхронизация идет от sticky
        if (this.syncingFromSticky) return;
        
        // Устанавливаем флаг
        this.syncingFromTable = true;
        
        // Мгновенная синхронизация напрямую
        const scrollLeft = this.tableWrap.scrollLeft;
        
        // Синхронизируем только если позиции отличаются
        if (Math.abs(this.scrollbar.scrollLeft - scrollLeft) > 0.1) {
          this.scrollbar.scrollLeft = scrollLeft;
          this.lastScrollLeft = scrollLeft;
        }
        
        // Сбрасываем флаг асинхронно для предотвращения циклов
        // Используем микротаск для немедленного сброса после текущего стека
        queueMicrotask(() => {
          this.syncingFromTable = false;
        });
      }, { passive: true });
      
      // Обновление при ресайзе окна
      let resizeTimer;
      window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => this.update(), 100);
      }, { passive: true });
    }
    
    /**
     * Настройка ResizeObserver для отслеживания размеров таблицы
     */
    setupObservers() {
      if (typeof ResizeObserver === 'undefined') return;
      
      this.resizeObserver = new ResizeObserver(() => {
        // Отменяем предыдущий RAF
        if (this.rafId) {
          cancelAnimationFrame(this.rafId);
        }
        
        // Используем RAF для батчинга обновлений
        this.rafId = requestAnimationFrame(() => {
          this.update();
          this.rafId = null;
        });
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
      
      // Получаем размеры
      const tableWidth = this.table.scrollWidth;
      const containerWidth = this.tableWrap.clientWidth;
      
      // Проверяем, нужен ли скроллбар
      const needsScrollbar = tableWidth > containerWidth;
      
      if (needsScrollbar) {
        // Устанавливаем ширину контента для корректной прокрутки
        this.content.style.width = tableWidth + 'px';
        
        // Показываем скроллбар
        if (!this.isActive) {
          this.wrapper.classList.add('active');
          this.isActive = true;
        }
        
        // Синхронизируем позицию скролла
        this.syncPosition();
      } else {
        // Скрываем скроллбар
        if (this.isActive) {
          this.wrapper.classList.remove('active');
          this.isActive = false;
        }
      }
      
      if (this.options.debug) {
        console.log('StickyScrollbar: update', {
          tableWidth,
          containerWidth,
          needsScrollbar,
          scrollLeft: this.tableWrap.scrollLeft
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
      // Отключаем наблюдателей
      if (this.resizeObserver) {
        this.resizeObserver.disconnect();
        this.resizeObserver = null;
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
