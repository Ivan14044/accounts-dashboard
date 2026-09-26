/**
 * mobile-touch.js — помощники для пальца: жест «смахнуть окно вниз» и
 * нажатие по всей ячейке с галочкой.
 *
 * 1. Шторки.
 * На ширине до 575px core-touch.css (раздел 3) превращает любое окно Bootstrap
 * (.modal) в панель, выезжающую снизу. Этот файл добавляет к ней привычный
 * жест: потянул за шапку или «ручку» вниз — шторка едет за пальцем; отпустил
 * дальше порога — закрывается штатным Modal.hide(), иначе возвращается на место.
 *
 * Чего здесь осознанно НЕТ:
 *  - логики окон. Закрытие идёт через bootstrap.Modal, поэтому все подписки на
 *    hide/hidden у страниц отрабатывают как при нажатии на крестик;
 *  - жеста из середины содержимого: там палец листает список, и перехват
 *    ломал бы прокрутку. Тянуть можно за шапку (её зона ≥ 44px);
 *  - зависимостей: подписка одна на документ (делегирование), поэтому работает
 *    и для окон, которые страница создаёт позже.
 * Анимация — только transform, раскладка страницы не пересчитывается.
 *
 * 2. Галочка строки таблицы — квадрат 18–24px, в него трудно попасть пальцем.
 *    На сенсорном экране нажатие в любое место её ячейки равно нажатию на
 *    саму галочку: вызываем input.click(), поэтому срабатывают те же
 *    обработчики change, что и раньше (выбор, счётчики, панель действий).
 *    Мышь это не затрагивает — слушаем только pointerType === 'touch'.
 *    Главную таблицу (#accountsTable) пропускаем: там щелчок по строке и так
 *    отмечает её, и наш второй щелчок снял бы отметку обратно.
 *
 * 3. Экранная клавиатура. Пока фокус в поле ввода на сенсорном экране, на
 *    body висит класс kb-open — core-touch.css прячет на это время нижние
 *    плавающие панели (вкладки, панель выбранных, «Наверх»), иначе они
 *    всплывают над клавиатурой и закрывают поле. Высоту клавиатуры
 *    (разницу между окном и видимой областью, visualViewport) кладём в
 *    переменную --kb-h: шторка поднимается над клавиатурой, а поле в фокусе
 *    докручивается в видимую часть.
 */
(function () {
  'use strict';

  if (window.MobileTouch) return;

  var PHONE = window.matchMedia ? window.matchMedia('(max-width: 575.98px)') : null;
  var CLOSE_RATIO = 0.25;   // доля высоты шторки, после которой отпускание закрывает
  var CLOSE_MIN_PX = 90;    // …но не меньше этого, чтобы случайное касание не закрыло

  var drag = null;

  /** Шапка, «ручка» (верхние 28px шторки) или пустой фон вокруг — за них можно тянуть. */
  function grabTarget(target) {
    if (!PHONE || !PHONE.matches || !target || !target.closest) return null;
    var content = target.closest('.modal.show .modal-content');
    if (!content) return null;
    if (target.closest('input, textarea, select, button, a, [contenteditable="true"], .dropdown-menu')) return null;
    return content;
  }

  function onStart(e) {
    if (e.touches.length !== 1) return;
    var content = grabTarget(e.target);
    if (!content) return;
    var t = e.touches[0];
    var rect = content.getBoundingClientRect();
    var inHandle = (t.clientY - rect.top) <= 28;
    var inHeader = !!e.target.closest('.modal-header');
    if (!inHandle && !inHeader) return;
    drag = { content: content, startY: t.clientY, dy: 0, height: rect.height };
    content.style.transition = 'none';
  }

  function onMove(e) {
    if (!drag) return;
    var dy = e.touches[0].clientY - drag.startY;
    drag.dy = dy > 0 ? dy : 0;
    drag.content.style.transform = 'translate3d(0,' + drag.dy + 'px,0)';
    if (e.cancelable) e.preventDefault();
  }

  function onEnd() {
    if (!drag) return;
    var d = drag;
    drag = null;
    var threshold = Math.max(CLOSE_MIN_PX, d.height * CLOSE_RATIO);
    d.content.style.transition = 'transform .2s ease';
    if (d.dy > threshold) {
      var modalEl = d.content.closest('.modal');
      var inst = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getInstance(modalEl) : null;
      if (inst) {
        d.content.style.transform = 'translate3d(0,100%,0)';
        modalEl.addEventListener('hidden.bs.modal', function reset() {
          modalEl.removeEventListener('hidden.bs.modal', reset);
          d.content.style.transform = '';
          d.content.style.transition = '';
        });
        inst.hide();
        return;
      }
    }
    d.content.style.transform = '';
    setTimeout(function () { d.content.style.transition = ''; }, 220);
  }

  document.addEventListener('touchstart', onStart, { passive: true });
  document.addEventListener('touchmove', onMove, { passive: false });
  document.addEventListener('touchend', onEnd, { passive: true });
  document.addEventListener('touchcancel', onEnd, { passive: true });

  // ── 2. Нажатие по ячейке с галочкой ──
  var lastPointer = '';
  document.addEventListener('pointerdown', function (e) { lastPointer = e.pointerType; }, true);
  document.addEventListener('click', function (e) {
    if (lastPointer !== 'touch') return;
    var t = e.target;
    if (!t || !t.closest || t.matches('input, label, a, button')) return;
    var cell = t.closest('td.checkbox-cell, th.checkbox-cell, td.ac-cell--checkbox, th.ac-cell--checkbox, td.trash-check-cell, th.trash-check-cell');
    // В главной таблице нажатие по любому месту строки уже отмечает её
    // (handleDocumentClick в dashboard-init.js) — второй щелчок снял бы отметку.
    if (!cell || cell.closest('#accountsTable')) return;
    var box = cell.querySelector('input[type="checkbox"]');
    if (box && !box.disabled) box.click();
  });

  // ── 3. Экранная клавиатура ──
  var FIELD = 'input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]):not([type="range"]), textarea, select, [contenteditable="true"]';
  var coarse = window.matchMedia ? window.matchMedia('(pointer: coarse)') : null;
  document.addEventListener('focusin', function (e) {
    if (coarse && coarse.matches && e.target && e.target.matches && e.target.matches(FIELD)) {
      document.body.classList.add('kb-open');
    }
  });
  document.addEventListener('focusout', function () {
    // Фокус мог перейти в соседнее поле — проверяем после смены
    setTimeout(function () {
      var a = document.activeElement;
      if (!(a && a.matches && a.matches(FIELD))) document.body.classList.remove('kb-open');
    }, 0);
  });
  var vv = window.visualViewport;
  if (vv) {
    var onResize = function () {
      var kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
      document.documentElement.style.setProperty('--kb-h', kb + 'px');
      var a = document.activeElement;
      if (kb > 0 && a && a.closest && a.closest('.modal.show') && a.matches(FIELD)) {
        a.scrollIntoView({ block: 'nearest' });
      }
    };
    vv.addEventListener('resize', onResize);
  }

  window.MobileTouch = { isPhone: function () { return !!(PHONE && PHONE.matches); } };
})();
