/**
 * Поле «статус: выбери из списка или впиши свой» (assets/js/modules/status-picker.js).
 *
 * Запуск: node --test tests/status-picker.test.cjs
 *
 * Проверяется чистая логика без браузера: как набранный текст превращается в
 * статус, который уйдёт на сервер, и что увидит человек под полем. Поведение
 * самого выпадающего списка проверяется на стенде в браузере.
 *
 * Главное, что стережёт тест, — данные. Статус, отличающийся от существующего
 * только регистром («PERECHEK_NEW» при живом «perechek_new»), не должен
 * создаваться вторым: коллация MySQL регистронезависимая, в фильтре и счётчиках
 * они склеятся, а в базе будут жить двумя написаниями.
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

/**
 * Загрузить модули так же, как на странице: constants.js → status-filter.js →
 * status-picker.js. При загрузке модуль не имеет права трогать DOM.
 */
function load(withFilter) {
  const ctx = vm.createContext({ window: {}, console });
  vm.runInContext(fs.readFileSync('assets/js/modules/constants.js', 'utf8'), ctx);
  if (withFilter !== false) {
    vm.runInContext(fs.readFileSync('assets/js/modules/status-filter.js', 'utf8'), ctx);
  }
  vm.runInContext(fs.readFileSync('assets/js/modules/status-picker.js', 'utf8'), ctx);
  const api = ctx.window.DashboardStatusPicker;
  assert.ok(api, 'модуль должен объявить window.DashboardStatusPicker');
  return api.logic;
}

const EXISTING = ['perechek_new', 'Used_Chek_valid', 'pokupal_farm_7_days', 'king', 'used'];

// ── Что уйдёт на сервер ─────────────────────────────────────────────────

test('пусто и одни пробелы — статуса нет', () => {
  const l = load();
  assert.equal(l.resolve('', EXISTING).kind, 'empty');
  assert.equal(l.resolve('   ', EXISTING).kind, 'empty');
  assert.equal(l.resolve(null, EXISTING).kind, 'empty');
});

test('точное совпадение — существующий статус', () => {
  const r = load().resolve('king', EXISTING);
  assert.equal(r.kind, 'existing');
  assert.equal(r.value, 'king');
});

test('пробелы по краям срезаются, лишние пробелы внутри схлопываются', () => {
  const l = load();
  assert.equal(l.resolve('  king ', EXISTING).value, 'king');
  assert.equal(l.resolve('новый   статус', EXISTING).value, 'новый статус');
});

test('отличие только в регистре — берётся существующее написание, второй статус не создаётся', () => {
  const r = load().resolve('PERECHEK_NEW', EXISTING);
  assert.equal(r.kind, 'case');
  assert.equal(r.value, 'perechek_new');
});

test('если в базе уже живут оба написания, точное побеждает', () => {
  const r = load().resolve('PERECHEK_NEW', EXISTING.concat(['PERECHEK_NEW']));
  assert.equal(r.kind, 'existing');
  assert.equal(r.value, 'PERECHEK_NEW');
});

test('незнакомое имя — новый статус', () => {
  const r = load().resolve('invalid_15_11', EXISTING);
  assert.equal(r.kind, 'new');
  assert.equal(r.value, 'invalid_15_11');
  assert.equal(r.similar, null);
});

test('новый, но похож на существующий (пробел вместо подчёркивания) — подсказка, а не подмена', () => {
  const r = load().resolve('perechek new', EXISTING);
  assert.equal(r.kind, 'new');
  assert.equal(r.value, 'perechek new');
  assert.equal(r.similar, 'perechek_new');
});

test('кириллица, цифры, дефис, подчёркивание и пробел разрешены — как на сервере', () => {
  const r = load().resolve('Невалид-2 после_проверки', EXISTING);
  assert.equal(r.kind, 'new');
});

test('запрещённые знаки отсекаются до отправки, с понятным текстом', () => {
  const l = load();
  ['bad.status', 'a/b', 'x;DROP', 'эмодзи 🙂', 'a"b', "a'b", 'a,b'].forEach((s) => {
    const r = l.resolve(s, EXISTING);
    assert.equal(r.kind, 'invalid', s);
    assert.match(r.error, /букв|цифр/, s);
  });
});

test('длиннее 50 знаков — ошибка (в базе колонка на 50 знаков)', () => {
  const l = load();
  assert.equal(l.resolve('a'.repeat(50), EXISTING).kind, 'new');
  const r = l.resolve('я'.repeat(51), EXISTING);
  assert.equal(r.kind, 'invalid');
  assert.match(r.error, /50/);
});

test('существующий статус с «запрещённым» знаком всё равно выбирается — он уже в базе', () => {
  const r = load().resolve('old.status', EXISTING.concat(['old.status']));
  assert.equal(r.kind, 'existing');
});

// ── Список подсказок ────────────────────────────────────────────────────

test('пустой запрос — весь список как есть', () => {
  const l = load();
  assert.deepEqual(Array.from(l.suggest(EXISTING, '')), EXISTING);
});

test('совпадения: сначала точное, потом начинающиеся с запроса, потом остальные', () => {
  const l = load();
  const list = ['used_rk', 'king_used', 'used', 'reused'];
  assert.deepEqual(Array.from(l.suggest(list, 'used')), ['used', 'used_rk', 'king_used', 'reused']);
});

test('поиск понимает русскую раскладку и транслит — как фильтр статусов', () => {
  const l = load();
  assert.deepEqual(Array.from(l.suggest(EXISTING, 'зщлгзфд')), ['pokupal_farm_7_days']);
  assert.deepEqual(Array.from(l.suggest(EXISTING, 'перечек')), ['perechek_new']);
});

test('без модуля фильтра статусов поиск всё равно работает (обычное вхождение)', () => {
  const l = load(false);
  assert.deepEqual(Array.from(l.suggest(EXISTING, 'CHEK')), ['perechek_new', 'Used_Chek_valid']);
});

test('недавние идут первыми, только те, что есть в списке, без повторов', () => {
  const l = load();
  const out = l.suggest(EXISTING, '', ['king', 'gone_status', 'king', 'used']);
  assert.deepEqual(Array.from(out), ['king', 'used', 'perechek_new', 'Used_Chek_valid', 'pokupal_farm_7_days']);
});

test('недавние: запомнить применённый, новый наверх, не больше лимита', () => {
  const l = load();
  let r = [];
  for (let i = 0; i < 12; i++) r = l.remember(r, 's' + i);
  r = l.remember(r, 's5');
  assert.equal(r[0], 's5');
  assert.equal(r.length, 8);
  assert.equal(r.filter((x) => x === 's5').length, 1);
});

// ── Число аккаунтов рядом со статусом ──────────────────────────────────

test('число рядом со статусом — как в фильтре статусов: «14,319», нет данных — 0', () => {
  const l = load();
  const counts = { perechek_new: 14319, king: 6, '': 436 };
  assert.equal(l.countLabel(counts, 'perechek_new'), '14,319');
  assert.equal(l.countLabel(counts, 'king'), '6');
  assert.equal(l.countLabel(counts, 'нет_такого'), '0');
  assert.equal(l.countLabel(null, 'king'), '0');
  assert.equal(l.countLabel({ king: 'мусор' }, 'king'), '0');
});

test('свежие числа после обновления таблицы добавляют статусы, которых не было в списке', () => {
  const l = load();
  const out = l.mergeStatuses(['king', 'used'], { king: 3, used: 0, invalid_15_11: 252, '': 10 });
  assert.deepEqual(Array.from(out), ['invalid_15_11', 'king', 'used']);
});

test('статус с нулём не выкидывается из списка: в него всё ещё можно переносить', () => {
  const l = load();
  assert.deepEqual(Array.from(l.mergeStatuses(['king', 'used'], { king: 5 })), ['king', 'used']);
});
