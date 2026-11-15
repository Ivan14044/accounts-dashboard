/**
 * Обновление таблицы после действия: сначала строки, счётчики — следом.
 * (assets/js/modules/dashboard-refresh.js, assets/js/table-module.js)
 *
 * Запуск: node --test tests/refresh-two-phase.test.cjs
 *
 * Жалоба владельца (15.11.2026): после любого действия долго висит
 * «Загрузка данных…». Замер на рабочей панели: обновление ждало пересчёта
 * счётчиков карточек по всей базе (после своей правки — 1,1–1,3 с ответа
 * сервера против 0,3 с без него) и скачивания 2,2 МБ полных кук.
 *
 * Тест стережёт:
 *   1) таблица запрашивается лёгким запросом (light=1), и «Загрузка» снимается
 *      по нему — не дожидаясь счётчиков;
 *   2) счётчики приходят вторым запросом (stats_only=1) с тем же фильтром и
 *      ложатся на карточки и в список статусов;
 *   3) счётчики устаревшего обновления не затирают свежие;
 *   4) обрезанное сервером значение не выдаётся за полное — копирование и
 *      правка идут за ним на сервер.
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function el(extra) {
  return Object.assign({
    textContent: '', attributes: {}, style: { removeProperty() {} },
    classList: { add() {}, remove() {}, contains() { return false; } },
    setAttribute(k, v) { this.attributes[k] = v; },
    removeAttribute(k) { delete this.attributes[k]; },
  }, extra || {});
}

/** Карточка статуса: ровно то, что модуль читает у настоящей. */
function statusCard(status) {
  const value = el();
  return {
    status, value,
    getAttribute: n => (n === 'data-status' ? status : null),
    querySelector: s => (s === '.stat-value' ? value : null),
  };
}

function setup(search) {
  const requests = [];
  const fetch = (url, opts) => new Promise((resolve, reject) => {
    const req = { url, resolve, reject, signal: opts && opts.signal };
    if (req.signal) req.signal.addEventListener('abort', () => {
      const e = new Error('aborted'); e.name = 'AbortError'; reject(e);
    });
    requests.push(req);
  });
  const cards = [statusCard('king'), statusCard('used')];
  const total = el();
  const elements = {
    resultsFeedback: el(), accountsTableSection: el(), tableLoading: el(), statsRow: el(),
  };
  const counted = [];
  const window = {
    location: { search },
    DashboardStatusFilter: { updateCounts: by => counted.push(by) },
  };
  const context = {
    document: {
      getElementById: id => elements[id] || null,
      querySelector: sel => (sel === '[data-card="total"] .stat-value' ? total : null),
      querySelectorAll: sel => (sel === '.stat-card[data-card^="status:"]' ? cards : []),
      createElement: () => el(),
    },
    window, fetch, AbortController, URLSearchParams,
    requestAnimationFrame: () => 0, setTimeout, clearTimeout,
    console: { debug() {}, error() {}, warn() {} },
  };
  vm.runInNewContext(fs.readFileSync('assets/js/modules/dashboard-refresh.js', 'utf8'), context);
  return { refresh: window.refreshDashboardData, requests, elements, cards, total, counted };
}

const tick = () => new Promise(r => setTimeout(r, 0));
const json = body => ({ ok: true, json: async () => Object.assign({ success: true }, body) });
const tableBody = { rows: [], filteredTotal: 26, page: 1, pages: 1, totals: { all: 26 }, byStatus: [] };
const statsBody = { statsOnly: true, totals: { all: 146397 }, byStatus: { king: 26, used: 700 }, filteredTotal: 26 };

test('таблица — лёгким запросом, и «Загрузка» снимается, не дожидаясь счётчиков', async () => {
  const t = setup('?status%5B%5D=king');
  const done = t.refresh();
  assert.equal(t.requests.length, 1);
  const q = new URL(t.requests[0].url, 'https://x/').searchParams;
  assert.equal(q.get('light'), '1');
  assert.equal(q.get('stats_only'), null);
  assert.deepEqual(q.getAll('status[]'), ['king']);

  t.requests[0].resolve(json(tableBody));
  await done; // обновление закончилось, хотя счётчиков ещё нет
  assert.equal(t.elements.accountsTableSection.attributes['aria-busy'], 'false');
  // лёгкий ответ не трогает карточки: в нём «всего» = найденное по фильтру
  assert.equal(t.total.textContent, '');
});

test('счётчики приходят вторым запросом с тем же фильтром и ложатся на карточки', async () => {
  const t = setup('?status%5B%5D=king');
  const done = t.refresh();
  t.requests[0].resolve(json(tableBody));
  await done;
  await tick();
  assert.equal(t.requests.length, 2, 'после таблицы уходит запрос счётчиков');
  const q = new URL(t.requests[1].url, 'https://x/').searchParams;
  assert.equal(q.get('stats_only'), '1');
  assert.equal(q.get('light'), null);
  assert.deepEqual(q.getAll('status[]'), ['king']);
  assert.equal(t.elements.statsRow.attributes['aria-busy'], 'true');

  t.requests[1].resolve(json(statsBody));
  await tick(); await tick();
  assert.deepEqual(t.cards.map(c => c.value.textContent), ['26', '700']);
  assert.equal(t.total.textContent, '146397');
  assert.equal(t.counted.length, 1);
  assert.equal(t.elements.statsRow.attributes['aria-busy'], 'false');
});

test('счётчики устаревшего обновления не затирают свежие', async () => {
  const t = setup('');
  const first = t.refresh();
  t.requests[0].resolve(json(tableBody));
  await first; await tick();
  const oldStats = t.requests[1];

  const second = t.refresh(); // человек сделал следующее действие
  oldStats.resolve(json({ statsOnly: true, totals: { all: 1 }, byStatus: { king: 1, used: 1 } }));
  await tick(); await tick();
  assert.deepEqual(t.cards.map(c => c.value.textContent), ['', ''], 'старые числа не легли');

  t.requests[2].resolve(json(tableBody));
  await second; await tick();
  t.requests[3].resolve(json(statsBody));
  await tick(); await tick();
  assert.deepEqual(t.cards.map(c => c.value.textContent), ['26', '700']);
});

test('явное лёгкое обновление счётчики не запрашивает', async () => {
  const t = setup('');
  const done = t.refresh({ light: true });
  t.requests[0].resolve(json(tableBody));
  await done; await tick();
  assert.equal(t.requests.length, 1);
});

test('сбой таблицы — счётчики не запрашиваются, ошибка видна', async () => {
  const t = setup('');
  const done = t.refresh();
  t.requests[0].resolve({ ok: false, status: 500 });
  await done; await tick();
  assert.equal(t.requests.length, 1);
  assert.match(t.elements.resultsFeedback.textContent, /Не удалось/);
});

test('обрезанное сервером значение не выдаётся за полное', () => {
  const window = { location: { search: '' } };
  const context = {
    document: { readyState: 'complete', getElementById: () => null, querySelector: () => null },
    window, URLSearchParams, console,
  };
  vm.runInNewContext(fs.readFileSync('assets/js/table-module.js', 'utf8'), context);
  assert.equal(typeof window.TableModule, 'function', 'класс доступен как window.TableModule');
  const tm = new window.TableModule(null);
  tm.rowsById = new Map([['7', { id: 7, full_cookies: 'c_user=1', cookies: 'short', two_fa: null, _clipped: ['full_cookies'] }]]);
  assert.equal(tm.getRowValue(7, 'full_cookies'), null, 'обрезок → null, CellValues пойдёт на сервер');
  assert.equal(tm.getRowValue(7, 'cookies'), 'short');
  assert.equal(tm.getRowValue(7, 'two_fa'), '');
});
