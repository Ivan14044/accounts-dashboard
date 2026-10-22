/**
 * Какие графы попадают в TXT-выгрузку (assets/js/modules/dashboard-export.js).
 *
 * Запуск: node --test tests/export-columns.test.cjs
 *
 * Грабли, ради которых тест написан (22.10.2026): выгрузка брала графы из
 * сохранённого списка `dashboard_visible_columns`, а таблица на экране
 * показывает графы по галочкам в «Настройках колонок». Эти два источника
 * расходятся: список общий на все таблицы, и стоит поменять галочку в другой
 * таблице, как он перезаписывается её графами. Вернулся в основную — графы,
 * которых в той таблице не было, `loadSettings()` показывает как «новые»
 * (галочка стоит, графа видна), но в сохранённом списке их нет. Итог —
 * «Cookies отмечены, а в файле их нет». Воспроизведено на стенде.
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const ALL = ['id', 'login', 'password', 'email', 'token', 'cookies', 'user_agent', 'status'];

function toggle(col, checked) {
  return { checked, getAttribute: (name) => (name === 'data-col' ? col : null) };
}

function load({ toggles, saved, savedRaw, headers }) {
  const document = {
    addEventListener() {},
    getElementById: () => null,
    querySelectorAll(sel) {
      if (sel === '.column-toggle') return toggles || [];
      if (sel === '#accountsTable thead th[data-col]') {
        return (headers || []).map((c) => ({ getAttribute: () => c }));
      }
      return [];
    }
  };
  const localStorage = {
    getItem: (k) => (k !== 'dashboard_visible_columns' ? null : (savedRaw !== undefined ? savedRaw : (saved ? JSON.stringify(saved) : null)))
  };
  const ctx = vm.createContext({ window: {}, document, localStorage, console });
  vm.runInContext(fs.readFileSync('assets/js/modules/dashboard-export.js', 'utf8'), ctx);
  const api = ctx.window.DashboardExport;
  assert.ok(api && typeof api.getExportColumns === 'function', 'нужна DashboardExport.getExportColumns');
  return api;
}

test('графы берутся по галочкам на экране, а не из устаревшего сохранённого списка', () => {
  const api = load({
    toggles: [toggle('id', true), toggle('login', true), toggle('token', true), toggle('cookies', true), toggle('user_agent', false)],
    // список перезаписан другой таблицей: cookies в нём нет
    saved: ['id', 'login', 'password', 'email', 'status']
  });
  assert.deepEqual(Array.from(api.getExportColumns(ALL)), ['login', 'token', 'cookies']);
});

test('снятая галочка в файл не идёт, id в TXT не выгружается', () => {
  const api = load({ toggles: [toggle('id', true), toggle('login', true), toggle('cookies', false)] });
  assert.deepEqual(Array.from(api.getExportColumns(ALL)), ['login']);
});

test('графа, которой нет в таблице, отбрасывается', () => {
  const api = load({ toggles: [toggle('login', true), toggle('ghost', true)] });
  assert.deepEqual(Array.from(api.getExportColumns(ALL)), ['login']);
});

test('без галочек на странице — сохранённый список, затем заголовки таблицы', () => {
  assert.deepEqual(Array.from(load({ saved: ['id', 'login', 'cookies'] }).getExportColumns(ALL)), ['login', 'cookies']);
  assert.deepEqual(Array.from(load({ headers: ['id', 'email', 'token'] }).getExportColumns(ALL)), ['email', 'token']);
});

test('битый сохранённый список не роняет выгрузку', () => {
  const api = load({ savedRaw: '{не json', headers: ['login'] });
  assert.deepEqual(Array.from(api.getExportColumns(ALL)), ['login']);
});
