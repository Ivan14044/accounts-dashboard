const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(response) {
  const main = { dataset: { listPage: 'trash', page: '2' }, style: {}, inert: false,
    setAttribute() {}, removeAttribute() {}, querySelectorAll: () => [],
    getBoundingClientRect: () => ({ height: 300 }), replaceWith(next) { state.main = next; } };
  const next = { ...main, dataset: { listPage: 'trash', page: '1', listConfig: '{"filteredTotal":0}' } };
  const state = { main, events: [], url: '', notices: [] };
  const window = { location: { href: 'https://example.test/trash.php?page=2&q=abc' },
    history: { replaceState(_, __, url) { state.url = String(url); } },
    matchMedia: () => ({ matches: true }), scrollX: 0, scrollY: 200, scrollTo() {},
    dispatchEvent(e) { state.events.push(e.type); }, showToast(text) { state.notices.push(text); } };
  const document = { querySelector: () => state.main, getElementById: () => null,
    createElement: () => ({ style: {}, setAttribute() {}, addEventListener() {}, append() {}, remove() {} }),
    body: { append() {} } };
  const context = { window, document, URL, CustomEvent: class { constructor(type) { this.type = type; } },
    DOMParser: class { parseFromString() { return { querySelector: () => response.valid === false ? null : next }; } },
    fetch: async () => response, setTimeout, clearTimeout, AbortController, console };
  const file = 'assets/js/list-refresh.js';
  if (fs.existsSync(file)) vm.runInNewContext(fs.readFileSync(file, 'utf8'), context);
  return { state, window, main, next };
}

test('refresh replaces list and server totals, clamps page and preserves filters', async () => {
  const { state, window, next } = setup({ ok: true, text: async () => '<main></main>' });
  assert.equal(typeof window.refreshAccountList, 'function');
  assert.equal(await window.refreshAccountList('trash'), true);
  assert.equal(state.main, next);
  assert.equal(window.TrashConfig.filteredTotal, 0);
  assert.equal(state.url, 'https://example.test/trash.php?page=1&q=abc');
  assert.deepEqual(state.events, ['account-list:updated']);
});

test('failed refresh and expired login retain current list and release busy state', async () => {
  for (const response of [{ ok: false, status: 500 }, { ok: true, valid: false, text: async () => '<form>Login</form>' }]) {
    const { state, window, main } = setup(response);
    assert.equal(typeof window.refreshAccountList, 'function');
    assert.equal(await window.refreshAccountList('trash'), false);
    assert.equal(state.main, main);
    assert.equal(main.inert, false);
    assert.equal(state.events.length, 0);
  }
});
