const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Target {
  constructor() { this.listeners = new Map(); this.classList = { add() {}, remove() {} }; }
  setAttribute(name, value) { this[name] = value; }
  addEventListener(type, fn) { if (!this.listeners.has(type)) this.listeners.set(type, new Set()); this.listeners.get(type).add(fn); }
  removeEventListener(type, fn) { this.listeners.get(type)?.delete(fn); }
  dispatch(type) { for (const fn of [...(this.listeners.get(type) || [])]) fn(); }
  count(type) { return this.listeners.get(type)?.size || 0; }
}
function autoFixture({ denied = false, reduced = false } = {}) {
  const button = new Target(); const top = new Target(); const document = new Target(); const window = new Target();
  const elements = { autoRefreshToggle: button, scrollToTop: top }; const timers = new Map(); let next = 0, refreshes = 0, aborts = 0, toasts = 0;
  document.visibilityState = 'visible'; document.querySelector = () => document.editing ? {} : null; window.refreshController = { abort() { aborts++; } };
  window.matchMedia = () => ({ matches: reduced }); window.scrollTo = options => { window.lastScroll = options; };
  const context = { window, document, getElementById: id => elements[id], localStorage: { getItem() { if (denied) throw Error('denied'); return null; }, setItem() { if (denied) throw Error('denied'); } }, setInterval(fn) { timers.set(++next, fn); return next; }, clearInterval(id) { timers.delete(id); }, refreshDashboardData() { refreshes++; }, showToast() { toasts++; }, requestAnimationFrame(fn) { fn(); } };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/modules/auto-refresh.js'), 'utf8'), context);
  return { window, document, button, top, elements, timers, tick() { for (const fn of timers.values()) fn(); }, get refreshes() { return refreshes; }, get aborts() { return aborts; }, get toasts() { return toasts; } };
}
test('start/restart and stop own exactly one visibility listener', () => {
  const f = autoFixture(); f.window.startAutoRefresh(); f.document.visibilityState = 'hidden'; f.tick();
  f.window.startAutoRefresh(); assert.equal(f.document.count('visibilitychange'), 1);
  f.tick(); f.document.visibilityState = 'visible'; f.document.dispatch('visibilitychange'); assert.equal(f.refreshes, 1);
  f.window.stopAutoRefresh(); assert.equal(f.document.count('visibilitychange'), 0); assert.equal(f.timers.size, 0);
});
test('repeated initialization keeps a single toggle action', () => {
  const f = autoFixture(); f.window.initializeAutoRefresh(); f.window.initializeAutoRefresh(); f.button.dispatch('click');
  assert.equal(f.timers.size, 1); assert.equal(f.button.count('click'), 1);
});
test('denied storage does not break initialization or start/stop', () => {
  const f = autoFixture({ denied: true }); assert.doesNotThrow(() => f.window.initializeAutoRefresh());
  assert.doesNotThrow(() => f.window.startAutoRefresh()); assert.equal(f.timers.size, 1);
  assert.doesNotThrow(() => f.window.stopAutoRefresh()); assert.equal(f.timers.size, 0);
});
test('stop cleans up after toggle is removed and leaves manual request alone', () => {
  const f = autoFixture(); f.window.startAutoRefresh(); delete f.elements.autoRefreshToggle; f.window.stopAutoRefresh();
  assert.equal(f.timers.size, 0); assert.equal(f.document.count('visibilitychange'), 0); assert.equal(f.aborts, 0);
});
test('stop never aborts a shared manual refresh request', () => {
  const f = autoFixture(); f.window.startAutoRefresh(); f.window.stopAutoRefresh(); assert.equal(f.aborts, 0);
});
for (const reduced of [false, true]) test(`scroll to top respects reduced motion: ${reduced}`, () => {
  const f = autoFixture({ reduced }); f.window.initScrollToTop(); f.top.dispatch('click'); assert.equal(f.window.lastScroll.behavior, reduced ? 'instant' : 'smooth');
});

// Minimal DOM boundary: real TableModule and virtualization lifecycle run in the VM.
class Node extends Target {
  constructor(tag = 'tr', id = null) { super(); this.tag = tag; this.id = id; this.children = []; this.style = {}; this.offsetHeight = 48; }
  set innerHTML(html) { this.children = []; this.html = html; if (this.tag === 'table') { const body = new Node('tbody'); body.innerHTML = html; this.appendChild(body); } else { for (const match of html.matchAll(/<tr[^>]*data-id="([^"]+)"/g)) this.appendChild(new Node('tr', match[1])); } }
  get innerHTML() { return this.html || ''; }
  get firstChild() { return this.children[0]; }
  appendChild(node) { if (node.tag === 'fragment') { for (const child of [...node.children]) this.appendChild(child); return; } node.remove(); node.parent = this; this.children.push(node); }
  insertBefore(node) { this.appendChild(node); }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(child => child !== this); this.parent = null; }
  getAttribute(name) { return name === 'data-id' ? this.id : null; }
  hasAttribute() { return false; }
  querySelector(selector) { if (selector === 'tbody') return this.children.find(child => child.tag === 'tbody'); if (selector === 'td') return null; return null; }
  querySelectorAll(selector) { return selector.startsWith('tr') ? this.children.filter(child => child.id !== null) : []; }
  getBoundingClientRect() { return { top: 0 }; }
}
function tableFixture(perPage, count) {
  const window = new Target(); window.location = { search: `?per_page=${perPage}` }; window.innerHeight = 480; window.pageYOffset = 0; window.scheduleIdle = () => {};
  const table = new Node('table'); const tbody = new Node('tbody'); table.appendChild(tbody);
  for (let i = 0; i < count; i++) tbody.appendChild(new Node('tr', String(i)));
  const document = new Target(); document.readyState = 'loading'; document.documentElement = { scrollTop: 0 }; document.getElementById = id => id === 'accountsTable' ? table : null;
  document.createElement = tag => new Node(tag); document.createDocumentFragment = () => new Node('fragment'); document.querySelector = () => ({});
  const frames = []; const context = { window, document, URLSearchParams, requestAnimationFrame: fn => frames.push(fn), setTimeout, clearTimeout };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/table-module.js'), 'utf8'), context); document.dispatch('DOMContentLoaded');
  return { window, table, tbody, module: window.tableModule, flush() { while (frames.length) frames.shift()(); }, update(count) { window.tableModule.updateRows({ columns: ['id'], rows: Array.from({ length: count }, (_, i) => ({ id: i + 1000 })) }); this.flush(); } };
}
for (const [perPage, count, enabled] of [[50, 50, false], [100, 100, false], [200, 200, false], [500, 45, false], [500, 46, true], [500, 500, true]]) {
  test(`virtualization agrees on initial, refresh, AJAX: page ${perPage}, rows ${count}`, () => {
    const f = tableFixture(perPage, count); assert.equal(f.module.virtualScroller.enabled, enabled);
    f.module.virtualScroller.refresh(); assert.equal(f.module.virtualScroller.enabled, enabled);
    f.update(count); assert.equal(f.module.virtualScroller.enabled, enabled);
    if (enabled) assert.ok(f.tbody.querySelectorAll('tr[data-id]').length > 0);
  });
}
test('AJAX replacement of virtual data preserves fresh rows and clears old virtual state', () => {
  const f = tableFixture(500, 500); f.update(500); f.window.location.search = '?per_page=100'; f.update(100);
  assert.equal(f.module.virtualScroller.enabled, false); assert.equal(f.module.virtualScroller.rowsData.length, 0); assert.equal(f.tbody.querySelectorAll('tr[data-id]').length, 100);
  f.update(0); assert.equal(f.module.getRowValue(1000, 'id'), null);
});
test('empty AJAX result clears an active virtual data set', () => {
  const f = tableFixture(500, 500); f.update(500); f.update(0);
  assert.equal(f.module.virtualScroller.enabled, false); assert.equal(f.module.virtualScroller.rowsData.length, 0);
});
function inlineFixture() {
  const f = tableFixture(500, 500); f.update(500);
  const value = new Node(); value.textContent = '1000'; value.tagName = 'SPAN';
  const wrap = new Node(); const row = new Node('tr', '1000'); const cell = new Node('td');
  wrap.getAttribute = name => ({ 'data-row-id': '1000', 'data-field': 'login', 'data-field-type': 'text' }[name] || null);
  wrap.querySelector = () => value; wrap.closest = selector => selector === 'td' ? cell : row;
  for (const node of [wrap, row, cell]) { node.setAttribute = () => {}; node.removeAttribute = () => {}; }
  const button = { closest: () => wrap }; const delayed = [];
  const context = { window: f.window, document: { createElement: tag => new Node(tag) }, getElementById: () => null, setTimeout: fn => delayed.push(fn), fetch: async () => ({ ok: true, text: async () => JSON.stringify({ success: true }) }), showToast() {}, logger: { error() {} } };
  f.window.getTableAwareUrl = url => url;
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/modules/inline-edit.js'), 'utf8'), context);
  f.window.DashboardInlineEdit.handleEditClick(button);
  return { f, wrap, delayed };
}
test('inline editing preserves the full virtual data set on cancel', () => {
  const { f, wrap, delayed } = inlineFixture();
  assert.equal(f.module.virtualScroller.rowsData.length, 500);
  wrap.children[2].dispatch('click');
  // Focus timer belongs to the input, not the lifecycle being checked.
  for (const node of wrap.children) node.focus = () => {};
  for (const fn of delayed.slice(1)) fn();
  assert.equal(f.module.virtualScroller.rowsData.length, 500);
});

test('saved inline edit updates the virtual backing row used on later scroll', async () => {
  const { f, wrap } = inlineFixture();
  f.module.rowsById.get('1000').login = 'old';
  wrap.children[0].value = '9000';
  await [...wrap.children[1].listeners.get('click')][0]();
  assert.equal(f.module.getRowValue(1000, 'login'), '9000');
  assert.equal(String(f.module.virtualScroller.rowsData[0].login), '9000');
});
test('repeated virtual refresh retains full server rows and bounded listeners', () => {
  const f = tableFixture(500, 500);
  for (let i = 0; i < 4; i++) f.module.virtualScroller.refresh();
  assert.equal(f.module.virtualScroller.allRows.length, 500);
  for (let i = 0; i < 4; i++) f.update(500);
  assert.equal(f.window.count('scroll'), 1);
  assert.equal(f.window.count('resize'), 1);
  assert.equal(f.module.virtualScroller.rowsData.length, 500);
  f.window.location.search = '?per_page=200'; f.update(200);
  assert.equal(f.window.count('scroll'), 0);
  assert.equal(f.window.count('resize'), 0);
});

test('auto refresh waits for an open row editor, including visibility catch-up', () => {
  const f = autoFixture(); f.window.startAutoRefresh(); f.document.editing = true;
  f.tick(); assert.equal(f.refreshes, 0);
  f.document.visibilityState = 'hidden'; f.tick();
  f.document.visibilityState = 'visible'; f.document.dispatch('visibilitychange');
  assert.equal(f.refreshes, 0);
  f.document.editing = false; f.tick(); assert.equal(f.refreshes, 1);
});

test('auto-refresh toggles communicate through the button without stacking blocking toasts', () => {
  const f = autoFixture();
  for (let i = 0; i < 10; i++) { f.window.startAutoRefresh(); f.window.stopAutoRefresh(); }
  assert.equal(f.toasts, 0);
  assert.equal(f.button['aria-pressed'], 'false');
});
