const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('either per-page control synchronizes both controls and refreshes once with filters intact', () => {
  for (const index of [0, 1]) {
    const controls = [0, 1].map(() => ({ value: '50', matches: () => true }));
    const handlers = {};
    let refreshes = 0;
    const window = { location: { href: 'https://example.test/index.php?status=ready&table=accounts&page=4&per_page=50' }, refreshDashboardData() { refreshes++; } };
    vm.runInNewContext(fs.readFileSync('assets/js/pagination.js', 'utf8'), {
      window, URL, history: { replaceState(a, b, url) { window.location.href = url; } },
      document: { addEventListener(event, fn) { handlers[event] = fn; }, querySelectorAll() { return controls; } }
    });
    controls[index].value = '25';
    handlers.change({ target: controls[index] });
    assert.deepEqual(controls.map(c => c.value), ['25', '25']);
    const params = new URL(window.location.href).searchParams;
    assert.equal(params.get('status'), 'ready');
    assert.equal(params.get('table'), 'accounts');
    assert.equal(params.get('page'), '1');
    assert.equal(params.get('per_page'), '25');
    assert.equal(refreshes, 1);
  }
});

test('column autosave persists selection and reports inline without success toasts', () => {
  const values = new Map();
  const status = { textContent: '' };
  const toasts = [];
  const column = { getAttribute: () => 'email' };
  const context = {
    window: {}, document: { querySelectorAll: () => [column], getElementById: () => status },
    localStorage: { setItem(k, v) { values.set(k, v); } },
    LS_KEY_COLUMNS: 'columns', LS_KEY_KNOWN_COLS: 'known',
    showToast: (...args) => toasts.push(args), logger: { error() {} }
  };
  vm.runInNewContext(fs.readFileSync('assets/js/modules/columns-cards-settings.js', 'utf8'), context);
  context.window.saveSettings();
  assert.equal(values.get('columns'), '["email"]');
  assert.equal(toasts.length, 0);
  assert.match(status.textContent, /сохранены/i);
  context.localStorage.setItem = () => { throw new Error('quota'); };
  context.window.saveSettings();
  assert.match(status.textContent, /не удалось/i);
});
