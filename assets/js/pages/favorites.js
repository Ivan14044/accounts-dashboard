/**
 * pages/favorites.js — поведение страницы «Избранное», собранной на ui.css.
 *
 * Почему не переиспользован assets/js/favorites.js: тот модуль написан под
 * старую разметку и правит иконки через классы FontAwesome
 * (`icon.className = 'fas fa-star'`), а на этой странице иконки — инлайновый
 * SVG. Он бы молча ломал кнопку. Старый модуль остаётся у страниц, которые
 * ещё не переехали на новую систему.
 *
 * Что делает: снимает аккаунт с избранного через тот же API, что и раньше
 * (DELETE /api/favorites, id и CSRF-токен в теле запроса), убирает строку из
 * таблицы и показывает уведомление. Ошибку не проглатывает: строка возвращается на место, а
 * человек видит текст ошибки — «не получилось» не должно выглядеть как
 * «получилось».
 */
(function () {
  'use strict';

  var table = document.getElementById('favoritesTable');
  if (!table) return;

  function csrf() {
    return (window.DashboardConfig && window.DashboardConfig.csrfToken) || '';
  }

  function notify(text, tone) {
    if (window.UI && window.UI.toast) {
      window.UI.toast(text, tone);
    }
  }

  /** Счётчик в шапке пришёл с сервера — после удаления его надо поправить,
   *  иначе «Всего: 14» будет висеть над тринадцатью строками. */
  function decreaseTotal() {
    var el = document.querySelector('[data-fav-total]');
    if (!el) return;
    var n = parseInt(String(el.textContent).replace(/\D/g, ''), 10);
    if (!isNaN(n) && n > 0) el.textContent = String(n - 1);
  }

  /** Если строк не осталось — таблица заменяется пустым состоянием. */
  function renderEmptyIfNeeded() {
    var body = table.tBodies[0];
    if (!body || body.rows.length) return;

    var wrap = table.closest('.ui-table-wrap');
    if (!wrap) return;

    wrap.innerHTML =
      '<div class="ui-table__empty">' +
      '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5z"/></svg>' +
      '<p class="ui-h2" style="margin-bottom:var(--ui-2)">Пока пусто</p>' +
      '<p>Все аккаунты убраны из избранного.</p>' +
      '</div>';
  }

  table.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.favorite-btn') : null;
    if (!btn) return;

    e.preventDefault();

    var id = parseInt(btn.getAttribute('data-account-id'), 10);
    if (!id) return;

    var row = btn.closest('tr');
    if (!row || btn.disabled) return;

    btn.disabled = true;
    // Строку прячем сразу — так действие ощущается мгновенным. Если сервер
    // ответит ошибкой, вернём её на место (см. catch ниже).
    row.style.display = 'none';

    // Контракт проверен по api/routes/favorites.php, а не взят по памяти:
    // адрес без id, id и CSRF-токен идут в теле. Токена нет — сервер ответит 403.
    fetch('api/favorites', {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ account_id: id, csrf: csrf() })
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json().catch(function () { return {}; });
      })
      .then(function (data) {
        if (data && data.success === false) {
          throw new Error(data.error || 'Не удалось убрать из избранного');
        }
        row.remove();
        decreaseTotal();
        renderEmptyIfNeeded();
        notify('Аккаунт #' + id + ' убран из избранного', 'ok');
      })
      .catch(function (err) {
        row.style.display = '';
        btn.disabled = false;
        notify(err.message || 'Не удалось убрать из избранного', 'danger');
      });
  });
})();
