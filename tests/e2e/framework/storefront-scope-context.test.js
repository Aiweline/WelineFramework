/**
 * Node self-test: Scope stack push B2B → resolve channel → pop → default.
 * Run: node tests/e2e/framework/storefront-scope-context.test.js
 */
const assert = require('assert');
const {
  pushScope,
  popScope,
  clearScopes,
  resolveStorefrontOrigin,
  DEFAULT_STOREFRONT_ORIGIN,
} = require('./storefront-scope-context');

clearScopes();
delete process.env.WELINE_E2E_STOREFRONT_ORIGIN;
delete process.env.WELINE_E2E_BASE_URL;
delete process.env.WELINE_E2E_B2B_SCOPE;

assert.strictEqual(resolveStorefrontOrigin(), DEFAULT_STOREFRONT_ORIGIN, 'default origin');

pushScope({ origin: 'https://p05113ef3.test.weline.com/e2e-b2b-commerce/web', b2b: true });
assert.ok(
  /e2e-b2b-commerce/.test(resolveStorefrontOrigin()),
  'B2B push must win',
);

popScope();
assert.strictEqual(resolveStorefrontOrigin(), DEFAULT_STOREFRONT_ORIGIN, 'after pop back to default');

// Pollution guard: env B2B without flag must be ignored
process.env.WELINE_E2E_STOREFRONT_ORIGIN = 'https://p05113ef3.test.weline.com/e2e-b2b-commerce/web';
assert.strictEqual(
  resolveStorefrontOrigin(),
  DEFAULT_STOREFRONT_ORIGIN,
  'env B2B without stack/flag ignored',
);
delete process.env.WELINE_E2E_STOREFRONT_ORIGIN;

console.log('storefront-scope-context.test.js PASS');
