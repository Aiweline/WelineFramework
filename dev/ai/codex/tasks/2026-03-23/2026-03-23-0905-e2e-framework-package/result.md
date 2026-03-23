# Result - e2e framework package

## Outcome

- Implemented a reusable `tests/e2e/framework` package that hides runtime target/protocol/backend-prefix differences behind a unified proxy entry and runtime helpers.
- The proxy entry now defaults to `https://127.0.0.1:3999`, resolves the live target from WLS instance/config data at runtime, auto-starts WLS on cold start when possible, and keeps health checks red until the real target is reachable.
- Theme preview E2E is now routed through the framework helpers instead of stale hardcoded URLs, and the focused theme preview suite passes end-to-end through the proxy package.

## Changed Files

- `tests/e2e/framework/runtime-info.php`
- `tests/e2e/framework/runtime.js`
- `tests/e2e/framework/proxy-server.js`
- `tests/e2e/playwright.config.js`
- `tests/e2e/start.js`
- `tests/e2e/README.md`
- `app/code/Weline/Theme/Controller/Frontend/ThemePreview/Gateway.php`
- `app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js`
- `app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php`
- Selected existing backend specs that were already migrated earlier in this task to use `tests/e2e/framework`

## Verification

- `node tests/e2e/collect-tests.js`
- `php -l tests/e2e/framework/runtime-info.php`
- `php -l app/code/Weline/Theme/Controller/Frontend/ThemePreview/Gateway.php`
- `node --check tests/e2e/framework/runtime.js`
- `node --check tests/e2e/framework/proxy-server.js`
- `node --check app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js`
- `php tests/e2e/framework/runtime-info.php`
- `php bin/w server:start`
- `npx playwright test theme-override.spec.js --project=chromium --workers=1` from `tests/e2e` -> passed (`2 passed`)

## Remaining Risks

- Some backend/module suites still carry their own timing and assertion debt. A follow-up smoke on `app/code/Weline/Seo/Test/e2e/backend/sitemap-management.spec.js` still fails mainly because the suite keeps a strict 30s per-test timeout around repeated admin login, plus existing JS/public sitemap assertions outside the preview-package changes.
- `loginAsAdmin()` is improved but not yet fully amortized across repeated backend tests; if we want all backend suites to feel “framework-agnostic”, the next slice should likely add reusable authenticated state or suite-level timeout defaults.

## Next Resume Step

- Continue stabilizing backend auth/public-route helpers using the SEO suite as the next proving ground, then re-run one shared backend spec and one module backend spec through the proxy package.
