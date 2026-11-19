/**
 * Карточка аккаунта (assets/js/account-view.js): генератор кодов 2FA.
 *
 * Запуск: node --test tests/account-view.test.cjs
 *
 * Код 2FA, который не совпадает с кодом Фейсбука, хуже его отсутствия —
 * человек решит, что ключ неверный. Поэтому сверяемся с проверочными
 * векторами самого стандарта (RFC 6238, приложение B, SHA-1) и с известной
 * парой «ключ base32 → код».
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function load() {
  const ctx = vm.createContext({ window: {}, globalThis: {}, console });
  vm.runInContext(fs.readFileSync('assets/js/account-view.js', 'utf8'), ctx);
  const api = ctx.window.DashboardAccountView;
  assert.ok(api, 'модуль должен объявить window.DashboardAccountView');
  assert.equal(typeof api.init, 'function');
  return api.logic;
}

const subtle = globalThis.crypto.subtle;

test('RFC 6238, приложение B: все проверочные векторы SHA-1 (8 цифр)', async () => {
  const { totp } = load();
  const key = new Uint8Array(Buffer.from('12345678901234567890', 'ascii'));
  const vectors = [
    [59, '94287082'],
    [1111111109, '07081804'],
    [1111111111, '14050471'],
    [1234567890, '89005924'],
    [2000000000, '69279037'],
    [20000000000, '65353130'],
  ];
  for (const [sec, want] of vectors) {
    assert.equal(await totp(key, sec * 1000, subtle, 8), want, `время ${sec}`);
  }
});

test('ключ base32 с пробелами в нижнем регистре даёт тот же код', async () => {
  const { totp, base32Decode } = load();
  // base32 от '12345678901234567890' — тот же ключ RFC, 6 цифр = хвост 8-значного
  const k1 = base32Decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
  const k2 = base32Decode('gezd gnbv gy3t qojq gezd gnbv gy3t qojq');
  assert.equal(Buffer.from(k1).toString('ascii'), '12345678901234567890');
  assert.equal(await totp(k1, 59000, subtle), '287082');
  assert.equal(await totp(k2, 59000, subtle), '287082');
});

test('чужие символы и пустота — не ключ', () => {
  const { base32Decode } = load();
  assert.equal(base32Decode(''), null);
  assert.equal(base32Decode('ABC1'), null);
  assert.equal(base32Decode(null), null);
});

test('таймер и разбивка кода', () => {
  const { secondsLeft, groupCode } = load();
  assert.equal(secondsLeft(0), 30);
  assert.equal(secondsLeft(29999), 1);
  assert.equal(secondsLeft(30000), 30);
  assert.equal(groupCode('123456'), '123 456');
  assert.equal(groupCode('12345678'), '12345678');
});
