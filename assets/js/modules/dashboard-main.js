/**
 * Главный модуль инициализации дашборда
 * Импортирует и инициализирует все необходимые модули
 */

// Импортируем модули
import Toast from '../../toast.js';
import { initTableModule } from '../../table-module.js';
import { copyToClipboard, fallbackCopyTextToClipboard, showToast } from './utils.js';

// Инициализация при загрузке DOM
document.addEventListener('DOMContentLoaded', () => {
  console.log('📦 [DASHBOARD-MAIN] Инициализация модулей...');
  
  // Инициализируем Toast (уже инициализирован при импорте, но проверяем)
  if (typeof window.Toast === 'undefined') {
    window.Toast = Toast;
  }
  
  // Инициализируем TableModule
  const tableModule = initTableModule();
  if (tableModule) {
    console.log('✅ [DASHBOARD-MAIN] TableModule инициализирован');
  }
  
  // Утилиты уже доступны через window.* (из utils.js)
  console.log('✅ [DASHBOARD-MAIN] Все модули инициализированы');
});

// Экспортируем для использования в других модулях
export { Toast, Utils };
