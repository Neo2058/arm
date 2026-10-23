const { test } = require('node:test');
const assert = require('node:assert/strict');
const { DEFAULT_API_URL, resolveApiUrl, loginErrorMessage } = require('../api-config.cjs');

test('default and legacy production addresses use the certified host before sending credentials', () => {
  for (const value of [undefined, '', 'https://xn---15-7edz.xn--p1ai/api/mobile', 'https://тч-15.рф/api/mobile/', ` ${DEFAULT_API_URL}/ `]) {
    assert.equal(resolveApiUrl(value), DEFAULT_API_URL);
  }
});

test('custom development and unrelated servers remain intact', () => {
  for (const value of ['http://10.0.2.2:8000/api/mobile', 'https://api.example.org/api/mobile', 'https://xn---15-7edz.xn--p1ai.other.example/api/mobile']) {
    assert.equal(resolveApiUrl(value), value);
  }
});

test('network and authentication failures are distinguished', () => {
  assert.match(loginErrorMessage({ code: 'ERR_NETWORK' }), /защищённое соединение/);
  assert.match(loginErrorMessage({ response: { status: 422 } }), /email или пароль/);
  assert.match(loginErrorMessage({ response: { status: 429 } }), /много попыток/);
  assert.match(loginErrorMessage({ code: 'AUTH_STORAGE_FAILED' }), /сохранить вход/);
  assert.match(loginErrorMessage({ code: 'INVALID_AUTH_RESPONSE' }), /неожиданный ответ/);
});

test('internal server details are not displayed to users', () => {
  const text = loginErrorMessage({ response: { status: 500, data: { message: 'private stack and SQL details' } } });
  assert.match(text, /Сервер временно/);
  assert.doesNotMatch(text, /SQL|stack/);
});
