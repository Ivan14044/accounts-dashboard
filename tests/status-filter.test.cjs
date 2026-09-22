/**
 * Логика списка статусов: закреплённые, недавние, умный поиск, подписи и
 * счётчики (assets/js/modules/status-filter.js).
 *
 * Запуск: node --test tests/status-filter.test.cjs
 *
 * Здесь проверяется только чистая логика — без браузера. Поведение в живом
 * списке (перенос строк, анимация, «только этот» по щелчку) проверяется на
 * стенде в браузере; инварианты разметки стережёт tests/test_status_filter_markup.php.
 *
 * Названия статусов в примерах — настоящие, с рабочей панели (сентябрь 2026):
 * на них и видно, зачем нужен каждый вариант поиска.
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const SOURCE = 'assets/js/modules/status-filter.js';

/**
 * Загрузить модуль в чистом контексте. При загрузке модуль не имеет права
 * трогать DOM — только объявить window.DashboardStatusFilter. Ключ хранения
 * живёт в constants.js, поэтому он грузится первым — как на странице.
 */
function load(context) {
  const ctx = vm.createContext(Object.assign({ window: {}, console }, context || {}));
  vm.runInContext(fs.readFileSync('assets/js/modules/constants.js', 'utf8'), ctx);
  vm.runInContext(fs.readFileSync(SOURCE, 'utf8'), ctx);
  const api = ctx.window.DashboardStatusFilter;
  assert.ok(api, 'модуль должен объявить window.DashboardStatusFilter');
  return api;
}

const logic = () => load().logic;

/** Удобная обёртка: совпадает ли статус с тем, что набрал человек. */
function finds(name, query) {
  const l = logic();
  return l.matchesQuery(name, l.queryVariants(query));
}

// ── Поиск ────────────────────────────────────────────────────────────────

test('поиск: пробел, подчёркивание и дефис — одно и то же', () => {
  assert.equal(finds('perechek_new', 'perechek new'), true);
  assert.equal(finds('perechek_new', 'perechek-new'), true);
  assert.equal(finds('perechek_new', 'PERECHEK_NEW'), true);
  assert.equal(finds('used_rk_inwork', '  used   rk '), true);
});

test('поиск: набрано в русской раскладке — всё равно находит', () => {
  assert.equal(finds('pokupal', 'зщлгзфд'), true);
  assert.equal(finds('king', 'лштп'), true);
  assert.equal(finds('perechek_new', 'зукусрул'), true);
});

test('поиск: можно набирать русскими буквами по звучанию', () => {
  assert.equal(finds('pokupal_farm_7_days', 'покупал'), true);
  assert.equal(finds('perechek_new', 'перечек'), true);
  assert.equal(finds('nuzhen_telefon', 'нужен телефон'), true);
  assert.equal(finds('yaroslav_rychnoy_farm_spam', 'рычной'), true);
  assert.equal(finds('donor_ne_privyazalsya', 'привязался'), true);
  assert.equal(finds('zamena_pochta', 'почта'), true);
});

test('поиск: чужое не находит, пустой запрос показывает всё', () => {
  assert.equal(finds('captcha', 'perechek'), false);
  assert.equal(finds('captcha', 'покупал'), false);
  const l = logic();
  assert.deepEqual(Array.from(l.queryVariants('')), []);
  assert.deepEqual(Array.from(l.queryVariants('   ')), []);
  assert.equal(l.matchesQuery('captcha', []), true);
});

// ── Закреплённые ─────────────────────────────────────────────────────────

test('закрепление: новый статус встаёт в конец, повторный щелчок открепляет', () => {
  const l = logic();
  let s = l.emptyState();
  s = l.togglePin(s, 'perechek_new');
  s = l.togglePin(s, 'pokupal');
  s = l.togglePin(s, 'used_rk');
  assert.deepEqual(Array.from(s.pinned), ['perechek_new', 'pokupal', 'used_rk']);
  s = l.togglePin(s, 'pokupal');
  assert.deepEqual(Array.from(s.pinned), ['perechek_new', 'used_rk']);
});

test('закрепление: исходное состояние не меняется, пустое имя игнорируется', () => {
  const l = logic();
  const before = { pinned: ['a'], recent: ['b'] };
  const after = l.togglePin(before, 'c');
  assert.deepEqual(before.pinned, ['a']);
  assert.deepEqual(Array.from(after.pinned), ['a', 'c']);
  assert.deepEqual(Array.from(after.recent), ['b']);
  assert.deepEqual(Array.from(l.togglePin(before, '').pinned), ['a']);
});

// ── Недавние ─────────────────────────────────────────────────────────────

test('недавние: последний открытый — первым, без повторов', () => {
  const l = logic();
  let s = l.emptyState();
  s = l.recordVisit(s, ['king']);
  s = l.recordVisit(s, ['captcha']);
  s = l.recordVisit(s, ['king']);
  assert.deepEqual(Array.from(s.recent), ['king', 'captcha']);
});

test('недавние: выбор сразу многих статусов список не засоряет', () => {
  const l = logic();
  const s = l.recordVisit({ pinned: [], recent: ['king'] }, ['a', 'b', 'c', 'd']);
  assert.deepEqual(Array.from(s.recent), ['king']);
  const s2 = l.recordVisit({ pinned: [], recent: ['king'] }, ['a', 'b']);
  assert.deepEqual(Array.from(s2.recent), ['a', 'b', 'king']);
  assert.deepEqual(Array.from(l.recordVisit(s2, []).recent), ['a', 'b', 'king']);
});

test('недавние: память ограничена, старые вытесняются', () => {
  const l = logic();
  let s = l.emptyState();
  for (let i = 0; i < 40; i++) s = l.recordVisit(s, ['s' + i]);
  assert.equal(s.recent.length, l.RECENT_STORE_LIMIT);
  assert.equal(s.recent[0], 's39');
});

// ── Раскладка по разделам ────────────────────────────────────────────────

test('разделы: закреплённые по порядку, недавние без закреплённых, остальные по алфавиту', () => {
  const l = logic();
  const all = ['account_locked', 'captcha', 'checkpoint', 'king', 'perechek_new', 'pokupal', 'used_rk'];
  const s = { pinned: ['pokupal', 'perechek_new'], recent: ['perechek_new', 'king', 'captcha'] };
  const sec = l.computeSections(all, s, 5);
  // Array.from: массивы модуля созданы в другом контексте vm, и строгое
  // сравнение споткнулось бы о чужой прототип Array, а не о содержимое.
  assert.deepEqual(Array.from(sec.pinned, p => p.status), ['pokupal', 'perechek_new']);
  assert.deepEqual(Array.from(sec.recent), ['king', 'captcha']);
  assert.deepEqual(Array.from(sec.rest), ['account_locked', 'checkpoint', 'used_rk']);
});

test('разделы: опустевший закреплённый статус не пропадает молча', () => {
  const l = logic();
  const sec = l.computeSections(['king'], { pinned: ['king', 'perechek_new_inwork'], recent: [] }, 5);
  assert.deepEqual(Array.from(sec.pinned, p => [p.status, p.missing]), [['king', false], ['perechek_new_inwork', true]]);
});

test('разделы: недавних не больше лимита, исчезнувшие недавние не показываются', () => {
  const l = logic();
  const all = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
  const sec = l.computeSections(all, { pinned: [], recent: ['gone', 'g', 'f', 'e', 'd', 'c', 'b'] }, 5);
  assert.deepEqual(Array.from(sec.recent), ['g', 'f', 'e', 'd', 'c']);
  assert.deepEqual(Array.from(sec.rest), ['a', 'b']);
});

// ── Хранение ─────────────────────────────────────────────────────────────

function memoryStorage() {
  const m = new Map();
  return {
    getItem: k => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => { m.set(k, String(v)); },
    raw: m,
  };
}

test('хранение: у каждой таблицы свой список', () => {
  const l = logic();
  const st = memoryStorage();
  assert.equal(l.saveState(st, 'accounts', { pinned: ['king'], recent: [] }), true);
  assert.equal(l.saveState(st, 'accounts_backup', { pinned: ['captcha'], recent: [] }), true);
  assert.deepEqual(Array.from(l.loadState(st, 'accounts').pinned), ['king']);
  assert.deepEqual(Array.from(l.loadState(st, 'accounts_backup').pinned), ['captcha']);
  assert.notEqual(l.storageKey('accounts'), l.storageKey('accounts_backup'));
  assert.equal(l.storageKey(''), l.storageKey('accounts'));
});

test('хранение: испорченные данные и запрет браузера не ломают список', () => {
  const l = logic();
  const st = memoryStorage();
  st.setItem(l.storageKey('accounts'), '{не json');
  assert.deepEqual(JSON.parse(JSON.stringify(l.loadState(st, 'accounts'))), { pinned: [], recent: [] });

  const denied = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('QuotaExceeded'); } };
  assert.deepEqual(JSON.parse(JSON.stringify(l.loadState(denied, 'accounts'))), { pinned: [], recent: [] });
  assert.equal(l.saveState(denied, 'accounts', { pinned: ['king'], recent: [] }), false);
  assert.deepEqual(JSON.parse(JSON.stringify(l.loadState(null, 'accounts'))), { pinned: [], recent: [] });
});

test('хранение: мусор в сохранённом списке отбрасывается', () => {
  const l = logic();
  const st = memoryStorage();
  st.setItem(l.storageKey('accounts'), JSON.stringify({ pinned: ['king', '', 5, null, 'king', 'captcha'], recent: 'не массив' }));
  const s = l.loadState(st, 'accounts');
  assert.deepEqual(Array.from(s.pinned), ['king', 'captcha']);
  assert.deepEqual(Array.from(s.recent), []);
});

// ── Подпись на кнопке и счётчики ─────────────────────────────────────────

test('подпись кнопки: один статус — его имя, иначе число', () => {
  const l = logic();
  assert.equal(l.labelFor([], false), 'Все статусы');
  assert.equal(l.labelFor(['perechek_new'], false), 'perechek_new');
  assert.equal(l.labelFor([], true), 'Пустой статус');
  assert.equal(l.labelFor(['king', 'captcha'], false), 'Выбрано: 2');
  assert.equal(l.labelFor(['king'], true), 'Выбрано: 2');
});

test('счётчик «Пустой статус»: сервер присылает его под пустым ключом', () => {
  // На рабочей панели 22.09.2026: refresh.php отдал byStatus[''] = 436 и не
  // отдал ключа '__empty__', а список показывал после обновления 0.
  const l = logic();
  const byStatus = { '': 436, king: 26 };
  assert.equal(l.statusCountFor(byStatus, '__empty__'), 436);
  assert.equal(l.statusCountFor(byStatus, 'king'), 26);
  assert.equal(l.statusCountFor(byStatus, 'нет_такого'), 0);
  assert.equal(l.statusCountFor(null, 'king'), 0);
});

test('счётчики пишутся с разделителем тысяч, как их рисует сервер', () => {
  const l = logic();
  assert.equal(l.formatCount(14319), '14,319');
  assert.equal(l.formatCount(1024), '1,024');
  assert.equal(l.formatCount(26), '26');
  assert.equal(l.formatCount(0), '0');
  assert.equal(l.formatCount('7632'), '7,632');
  assert.equal(l.formatCount(undefined), '0');
});

// ── Недавние: откуда модуль узнаёт, что статус открыт ────────────────────
// Подписка DashboardRefresh.onAfterRefresh (dashboard-refresh.js) зовёт
// обработчиков и при успехе, и при ошибке, и при отмене. Недавние пишутся
// только когда данные ЭТОГО запроса легли на экран — это и проверяем.

function loadRefresh(fetch, search) {
  const el = () => ({
    textContent: '', attributes: {}, style: { removeProperty() {} }, classList: { add() {}, remove() {} },
    setAttribute(k, v) { this.attributes[k] = v; }
  });
  const elements = { resultsFeedback: el(), accountsTableSection: el(), tableLoading: el() };
  const context = {
    document: {
      getElementById: id => elements[id], querySelector: () => null, querySelectorAll: () => [],
      createElement: () => ({})
    },
    window: { location: { search } }, fetch, AbortController, URLSearchParams,
    requestAnimationFrame: () => 0, setTimeout, clearTimeout, console: { debug() {}, error() {} }
  };
  vm.runInNewContext(fs.readFileSync('assets/js/modules/dashboard-refresh.js', 'utf8'), context);
  const calls = [];
  context.window.DashboardRefresh.onAfterRefresh(info => { calls.push(JSON.parse(JSON.stringify(info))); });
  return { refresh: context.window.refreshDashboardData, calls };
}

const okResponse = { ok: true, json: async () => ({ success: true, rows: [], byStatus: {}, totals: { all: 26 }, filteredTotal: 26 }) };

test('подписчик узнаёт: данные легли на экран и с какими параметрами ушёл запрос', async () => {
  const { refresh, calls } = loadRefresh(async () => okResponse, '?status%5B%5D=king');
  await refresh();
  assert.deepEqual(calls, [{ applied: true, search: '?status%5B%5D=king' }]);
});

test('упавший и отменённый запрос в недавние не попадают', async () => {
  const failed = loadRefresh(async () => ({ ok: false, status: 500 }), '?status%5B%5D=king');
  await failed.refresh();
  assert.deepEqual(failed.calls, [{ applied: false, search: '?status%5B%5D=king' }]);

  // Человек щёлкнул второй статус, пока первый ещё грузился: первый ответ
  // пришёл последним, но на экран не лёг — более свежий запрос его отменил.
  const pending = [];
  const raced = loadRefresh(() => new Promise(resolve => pending.push(resolve)), '?status%5B%5D=king');
  const first = raced.refresh();
  const second = raced.refresh();
  pending[1](okResponse);
  await second;
  pending[0](okResponse);
  await first;
  assert.deepEqual(raced.calls.map(c => c.applied), [true, false]);
});

test('обновление таблицы обновляет счётчики в списке, включая «Пустой статус»', () => {
  const els = ['__empty__', 'king', 'perechek_new', 'perechek_new_inwork'].map(key => ({
    key, textContent: '?', getAttribute: name => (name === 'data-status' ? key : null),
  }));
  const document = { querySelectorAll: sel => (sel === '.status-count' ? els : []) };
  const api = load({ document });
  api.updateCounts({ '': 436, king: 26, perechek_new: 14319 });
  assert.deepEqual(els.map(e => e.textContent), ['436', '26', '14,319', '0']);
});
