const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function test() {
  const scripts = [], errors = [];
  let listener, shows = 0, initialized = 0;
  const modal = { dataset: { uploadScript: 'assets/js/modules/dashboard-upload.js?v=test' }, addEventListener: (name, fn) => { listener = fn; } };
  const context = {
    document: { getElementById: () => modal, createElement: () => ({ remove() {} }), head: { appendChild: script => scripts.push(script) } },
    bootstrap: { Modal: { getOrCreateInstance: () => ({ show() { shows++; } }) } },
    window: { showToast: message => errors.push(message) },
  };
  vm.runInNewContext(fs.readFileSync('assets/js/modules/lazy-upload.js', 'utf8'), context);
  assert.equal(scripts.length, 0, 'initial page must not download upload module');
  let prevented = 0;
  const open = () => listener({ preventDefault() { prevented++; }, relatedTarget: {} });
  open(); open();
  assert.equal(prevented, 2);
  assert.equal(scripts.length, 1, 'concurrent opens share request');
  scripts[0].onerror();
  await new Promise(setImmediate);
  assert.equal(shows, 0, 'failed loading cannot expose unbound file input');
  assert.equal(errors.length, 1, 'failure gives one retry message');
  open();
  assert.equal(scripts.length, 2, 'next open retries failed request');
  context.window.DashboardUpload = { init() { initialized++; } };
  scripts[1].onload();
  await new Promise(setImmediate);
  assert.equal(initialized, 1);
  assert.equal(shows, 1, 'first successful action reopens modal after initialization');
  open();
  assert.equal(scripts.length, 2, 'subsequent opens need no download');
  assert.equal(initialized, 1);
  const bindings = new Map();
  const elements = new Map();
  for (const id of ['uploadAccountsForm', 'uploadAccountsBtn', 'accountsFile', 'cancelImportBtn']) {
    elements.set(id, { addEventListener(name) {
      const key = id + ':' + name;
      bindings.set(key, (bindings.get(key) || 0) + 1);
    } });
  }
  const moduleContext = {
    window: {}, console,
    document: { readyState: 'complete', getElementById: id => elements.get(id) },
    fetch: async () => ({ ok: false }),
  };
  vm.runInNewContext(fs.readFileSync('assets/js/modules/dashboard-upload.js', 'utf8'), moduleContext);
  moduleContext.window.DashboardUpload.init();
  moduleContext.window.DashboardUpload.init();
  assert.equal(bindings.get('accountsFile:change'), 1, 'file selection binds once after late load');
  assert.equal(bindings.get('uploadAccountsForm:submit'), 1);
  assert.equal(bindings.get('uploadAccountsBtn:click'), 1);
  assert.equal(bindings.get('cancelImportBtn:click'), 1);
  console.log('PASS: lazy upload initial load, concurrent opens, retry and initialization');
}
test().catch(error => { console.error(error); process.exitCode = 1; });
