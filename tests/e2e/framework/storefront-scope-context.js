/**
 * Explicit storefront Scope stack for Playwright e2e.
 *
 * Architecture: pathway helpers MUST read resolveStorefrontOrigin() — never mutate
 * process.env.WELINE_E2E_STOREFRONT_ORIGIN as cross-file truth (B2B pollution smell).
 *
 * @typedef {{ origin: string, b2b?: boolean }} StorefrontScope
 */

// Keep Worker listen port: nginx:443/FPM is not the primary local acceptance plane (WLS :9555).
const DEFAULT_STOREFRONT_ORIGIN = 'https://p05113ef3.test.weline.com:9555';

/** @type {StorefrontScope[]} */
const _stack = [];

/**
 * @param {string} raw
 * @returns {string}
 */
function normalizeOrigin(raw) {
  return String(raw || '').trim().replace(/\/$/, '');
}

/**
 * @param {string} origin
 * @param {{ allowB2b?: boolean }} [opts]
 * @returns {boolean}
 */
function isUsableOrigin(origin, opts = {}) {
  const o = normalizeOrigin(origin);
  if (!o) return false;
  if (/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/i.test(o)) return false;
  if (/e2e-b2b-commerce/i.test(o) && !opts.allowB2b) return false;
  return true;
}

/**
 * @param {StorefrontScope} scope
 * @returns {StorefrontScope}
 */
function pushScope(scope) {
  const origin = normalizeOrigin(scope && scope.origin);
  if (!origin) {
    throw new Error('storefront-scope-context.pushScope: origin required');
  }
  const entry = {
    origin,
    b2b: !!(scope && scope.b2b),
  };
  _stack.push(entry);
  return entry;
}

/**
 * @returns {StorefrontScope|null}
 */
function popScope() {
  return _stack.pop() || null;
}

/**
 * @returns {StorefrontScope|null}
 */
function currentScope() {
  return _stack.length ? _stack[_stack.length - 1] : null;
}

/**
 * @returns {void}
 */
function clearScopes() {
  _stack.length = 0;
}

/**
 * @param {StorefrontScope} scope
 * @param {() => (any|Promise<any>)} fn
 * @returns {Promise<any>}
 */
async function withScope(scope, fn) {
  pushScope(scope);
  try {
    return await fn();
  } finally {
    popScope();
  }
}

/**
 * Resolve public HTTPS storefront origin for pathway navigation.
 * Stack top wins; otherwise default Host. Legacy env is read-only fallback
 * (must not be written by pathway helpers).
 *
 * @returns {string}
 */
function resolveStorefrontOrigin() {
  const top = currentScope();
  if (top && isUsableOrigin(top.origin, { allowB2b: !!top.b2b })) {
    return top.origin;
  }

  const forced = normalizeOrigin(
    process.env.WELINE_E2E_STOREFRONT_ORIGIN || process.env.WELINE_E2E_BASE_URL || '',
  );
  const b2bEnv = String(process.env.WELINE_E2E_B2B_SCOPE || '').trim() === '1';
  if (isUsableOrigin(forced, { allowB2b: b2bEnv })) {
    return forced;
  }

  const target = normalizeOrigin(process.env.PLAYWRIGHT_TARGET_ORIGIN || '');
  if (isUsableOrigin(target, { allowB2b: false })) {
    return target;
  }

  return DEFAULT_STOREFRONT_ORIGIN;
}

module.exports = {
  DEFAULT_STOREFRONT_ORIGIN,
  pushScope,
  popScope,
  currentScope,
  clearScopes,
  withScope,
  resolveStorefrontOrigin,
  normalizeOrigin,
  isUsableOrigin,
};
