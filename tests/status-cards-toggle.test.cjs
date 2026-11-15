/**
 * Кнопка «Свернуть статусы» над карточками статусов
 * (assets/js/modules/status-cards-toggle.js).
 *
 * Запуск: node --test tests/status-cards-toggle.test.cjs
 *
 * Чистая логика без браузера: как читается запомненное состояние, сколько
 * карточек спрятано под кнопкой и что на ней написано. Поведение на странице
 * (без мелькания при загрузке, тема, телефон) проверяется на стенде в браузере.
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function load() {
  const ctx = vm.createContext({ window: {}, console });
  vm.runInContext(fs.readFileSync('assets/js/modules/constants.js', 'utf8'), ctx);
  vm.runInContext(fs.readFileSync('assets/js/modules/status-cards-toggle.js', 'utf8'), ctx);
  const api = ctx.window.DashboardStatusCards;
  assert.ok(api, 'модуль должен объявить window.DashboardStatusCards');
  assert.equal(typeof api.init, 'function');
  return { api, logic: api.logic, window: ctx.window };
}

/** Карточка-заглушка: ровно то, что модуль читает у настоящей. */
function card(id, opts) {
  opts = opts || {};
  return {
    getAttribute: (n) => (n === 'data-card' ? id : null),
    hasAttribute: (n) => n === 'hidden' && !!opts.hiddenAttr,
    classList: { contains: (c) => c === 'hidden' && !!opts.hiddenClass },
  };
}

test('ключ запоминания объявлен в constants.js и совпадает с ключом в модуле', () => {
  const { logic, window } = load();
  assert.equal(window.LS_KEY_STATUS_CARDS_COLLAPSED, 'dashboard_status_cards_collapsed_v1');
  assert.equal(logic.STORAGE_KEY, window.LS_KEY_STATUS_CARDS_COLLAPSED);
});

test('свёрнуто только при сохранённой единице; пусто, мусор и сбой хранилища — развёрнуто', () => {
  const { logic } = load();
  assert.equal(logic.parseStored('1'), true);
  assert.equal(logic.parseStored('0'), false);
  assert.equal(logic.parseStored(null), false);
  assert.equal(logic.parseStored(''), false);
  assert.equal(logic.parseStored('true'), false);
  assert.equal(logic.parseStored(undefined), false);
});

test('под кнопкой считаются только карточки статусов, спрятанные пользователем не в счёт', () => {
  const { logic } = load();
  const cards = [
    card('status:king'),
    card('status:used'),
    card('status:old', { hiddenClass: true }),
    card('status:gone', { hiddenAttr: true }),
    card('status:in_settings'),
  ];
  assert.equal(logic.countStatusCards(cards, null), 3);
  // Карточка уже в списке скрытых, но скрипт скрытия ещё не успел её пометить
  assert.equal(logic.countStatusCards(cards, new Set(['status:in_settings'])), 2);
});

test('общие карточки, «Пустые статусы» и свои карточки не считаются — их кнопка не прячет', () => {
  const { logic } = load();
  const cards = [card('total'), card('empty_status'), card('custom:email_twofa'), card('status:king'), card(null)];
  assert.equal(logic.countStatusCards(cards, null), 1);
  assert.equal(logic.countStatusCards([], null), 0);
  assert.equal(logic.countStatusCards(null, null), 0);
});

test('надпись: развёрнуто — «Свернуть статусы», свёрнуто — «Показать статусы» и число', () => {
  const { logic } = load();
  const open = logic.describe(false, 72);
  assert.equal(open.expanded, 'true');
  assert.match(open.title, /Свернуть/);
  const shut = logic.describe(true, 1234);
  assert.equal(shut.expanded, 'false');
  assert.match(shut.title, /Показать/);
  assert.equal(shut.count, '1,234');
});
