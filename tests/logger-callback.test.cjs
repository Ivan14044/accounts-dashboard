const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

test('detached error callbacks preserve logger context and level filtering', () => {
  const output = [];
  const context = { window: { location: { hostname: 'example.test' } },
    localStorage: { getItem: () => null },
    console: { error: (...args) => output.push(args), warn: (...args) => output.push(args) } };
  vm.runInNewContext(fs.readFileSync('assets/js/core/logger.js', 'utf8'), context);
  const { error, warn } = context.window.logger;
  assert.doesNotThrow(() => error('Request failed', 503));
  warn('Suppressed in production');
  assert.deepEqual(output, [['Request failed', 503]]);
});
