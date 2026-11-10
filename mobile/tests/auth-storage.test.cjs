const { test } = require('node:test');
const assert = require('node:assert/strict');
const { isPublicAuthRequest, withTimeout } = require('../src/auth-storage.cjs');

test('login POST is a public auth request and other calls are not', () => {
  assert.equal(isPublicAuthRequest({ method: 'post', url: '/login' }), true);
  assert.equal(isPublicAuthRequest({ method: 'POST', url: '/login/' }), true);
  assert.equal(isPublicAuthRequest({ method: 'post', url: 'login' }), true);
  assert.equal(isPublicAuthRequest({ method: 'post', url: 'https://www.xn---15-7edz.xn--p1ai/api/mobile/login' }), true);
  assert.equal(isPublicAuthRequest({ method: 'get', url: '/login' }), false);
  assert.equal(isPublicAuthRequest({ method: 'post', url: '/logout' }), false);
  assert.equal(isPublicAuthRequest({ method: 'post', url: '/me' }), false);
  assert.equal(isPublicAuthRequest({ method: 'post', url: '/chats/1/messages' }), false);
});

test('withTimeout resolves a fast promise and rejects a hung one', async () => {
  assert.equal(await withTimeout(Promise.resolve('ok'), 50), 'ok');
  await assert.rejects(() => withTimeout(new Promise(() => {}), 20), { code: 'TIMEOUT' });
});
