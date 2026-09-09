const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function setup(fetch) {
  const elements = Object.fromEntries(['resultsFeedback', 'accountsTableSection', 'tableLoading'].map(id => [id, {
    textContent: '', attributes: {}, style: { removeProperty() {} }, classList: { add() {}, remove() {} },
    setAttribute(k, v) { this.attributes[k] = v; }
  }]));
  const context = {
    document: { getElementById: id => elements[id], querySelector: () => null, createElement: () => ({}) },
    window: { location: { search: '' } }, fetch, AbortController, URLSearchParams,
    setTimeout, clearTimeout, console: { debug() {}, error() {} }
  };
  vm.runInNewContext(fs.readFileSync('assets/js/modules/dashboard-refresh.js', 'utf8'), context);
  return { elements, refresh: context.window.refreshDashboardData };
}

test('HTTP and application errors release busy state and retain error feedback', async () => {
  for (const response of [{ ok: false, status: 500 }, { ok: true, json: async () => ({ success: false }) }]) {
    const { elements, refresh } = setup(async () => response);
    await refresh();
    assert.equal(elements.accountsTableSection.attributes['aria-busy'], 'false');
    assert.match(elements.resultsFeedback.textContent, /Не удалось/);
  }
});

test('an obsolete request cannot dismiss the current loading state', async () => {
  const pending = [];
  const { elements, refresh } = setup(() => new Promise(resolve => pending.push(resolve)));
  const first = refresh();
  const second = refresh({ light: true });
  pending[0]({ ok: false, status: 500 });
  await first;
  assert.equal(elements.accountsTableSection.attributes['aria-busy'], 'true');
  pending[1]({ ok: false, status: 503 });
  await second;
  assert.equal(elements.accountsTableSection.attributes['aria-busy'], 'false');
});
