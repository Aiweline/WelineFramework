const test = require('node:test');
const assert = require('node:assert/strict');

const { resolveAdminSessionCookieNames } = require('./runtime');

test('admin session bootstrap reuses the website-scoped cookie name emitted by the target host', () => {
  assert.deepEqual(
    resolveAdminSessionCookieNames('WELINE_SESSID_19810', [
      { name: 'WELINE_SESSID_19810_w27', domain: 'p05113ef3.weline.test' },
      { name: 'WELINE_SESSID_19810_w91', domain: 'another.weline.test' },
      { name: 'UNRELATED_COOKIE', domain: 'p05113ef3.weline.test' },
    ], ['p05113ef3.weline.test']),
    ['WELINE_SESSID_19810', 'WELINE_SESSID_19810_w27'],
  );
});

test('admin session bootstrap accepts a parent-domain scoped cookie without hardcoding a website id', () => {
  assert.deepEqual(
    resolveAdminSessionCookieNames('WELINE_SESSID', [
      { name: 'WELINE_SESSID_storefront', domain: '.weline.test' },
    ], ['shop.weline.test']),
    ['WELINE_SESSID', 'WELINE_SESSID_storefront'],
  );
});

test('admin session bootstrap accepts the server-emitted family name after proxy port rewriting', () => {
  assert.deepEqual(
    resolveAdminSessionCookieNames('WELINE_SESSID_9518', [
      { name: 'WELINE_SESSID_3999_w1', domain: '127.0.0.1' },
    ], ['127.0.0.1']),
    ['WELINE_SESSID_9518', 'WELINE_SESSID_3999_w1'],
  );
});
