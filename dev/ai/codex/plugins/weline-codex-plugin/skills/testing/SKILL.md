---
name: testing
description: WelineFramework testing guide and routing skill. Use when the user asks how to write tests, where module tests live, how to run PHPUnit/Pest/Vitest/Playwright, or how to choose unit, integration, HTTP smoke, Browser smoke, and E2E coverage. Does not authorize creating test artifacts unless the user explicitly asks.
version: 1.0.0
---

# Role

This plugin skill is the Codex-facing copy of `dev/ai/skills/testing/SKILL.md`.
Use it as the central WelineFramework testing guide.

Reading this skill does **not** authorize writing tests. Create or update unit
tests, test cases, E2E specs, fixtures, test data, or regression cases only when
the current user request explicitly asks for that work.

# Decision Matrix

- Pure PHP logic, normalizers, value decisions, and service branches: PHPUnit unit test.
- Real framework services, ORM, ObjectManager, DB persistence, module discovery, or filesystem integration: PHPUnit integration test.
- Browser-side utility JS without live WLS dependency: Vitest under `tests/unit` or near the JS source.
- Route registration, controller reachability, backend/API smoke: `php bin/w http:request ...`.
- Browser-visible layout, text, navigation, or interaction: Codex Browser smoke when the local route can be served.
- Full user journey, auth, cookies, redirects, browser state, or multi-step regression: Playwright E2E through `php bin/w e2e:run`.

# PHP Tests In Modules

Prefer this module layout:

```text
app/code/Vendor/Module/
└── Test/
    ├── Unit/
    │   └── Service/FooServiceTest.php
    ├── Integration/
    │   └── FooFlowIntegrationTest.php
    └── phpunit.xml
```

Existing modules may use lowercase `test/Unit` or `tests/unit`; follow the
target module when it already has a convention. If none exists, prefer
`Test/Unit` and `Test/Integration` for PHP tests.

Bootstrap and base classes:

- Global bootstrap: `app/bootstrap_phpunit.php`.
- Pure unit tests may extend `PHPUnit\Framework\TestCase` directly.
- Tests that need framework context may extend `Weline\Framework\UnitTest\TestCore`.
- Integration tests that create DB rows, files, queue items, cache keys, or config entries must clean them up in `tearDown()`.

Focused commands:

```bash
php bin/w phpunit:run --module=Vendor_Module
php bin/w phpunit:run --name=FooServiceTest
php bin/w phpunit:run --name=FooServiceTest::testNormalizeReturnsExpectedValue
php bin/w phpunit:run --module=Vendor_Module --coverage
```

# Frontend JS Unit Tests

Frontend JavaScript unit tests use Vitest under `tests/unit`.

```bash
cd tests/unit
npm test
npm run test:run
npm run test:coverage
```

Keep DOM tests deterministic with `happy-dom`. Do not add long-running watch
commands in automated validation unless the user explicitly asks.

# Playwright E2E In Modules

Prefer this E2E layout:

```text
app/code/Vendor/Module/
└── test/
    └── e2e/
        ├── backend/
        │   └── Vendor_Module-smoke-backend.spec.js
        └── frontend/
            └── vendor-module-frontend.spec.js
```

Always import the shared helper:

```js
const {
  test,
  expect,
  gotoFrontend,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');
```

Run through the framework command:

```bash
php bin/w e2e:run --list-modules
php bin/w e2e:run --module=Vendor_Module --project=chromium
php bin/w e2e:run --module=Vendor_Module --case-id=BACKEND-SMOKE-001 --project=chromium
php bin/w e2e:run app/code/Vendor/Module/test/e2e/backend/Vendor_Module-smoke-backend.spec.js --project=chromium --workers=1
```

Do not run Playwright directly from the repository root.

# WLS Runtime Rules

When tests or validation depend on WLS:

```bash
php bin/w server:start -p 9502 -n ai-test-{unique-id}
php bin/w setup:upgrade --route
php bin/w e2e:run --module=Vendor_Module --project=chromium --headless --workers=1
php bin/w server:stop -n ai-test-{unique-id}
```

Rules:

- Never use default WLS port `9501` for AI validation.
- Use unique `ai-test-*` instance names.
- Stop every dedicated test instance before finishing.
- If runtime or browser automation is blocked, report the blocker; do not claim visible behavior was verified.

# Quality Checklist

- The user explicitly requested the test artifact or test execution.
- The test lives in the owning module or shared test harness location.
- The test name states the behavior or regression being protected.
- Assertions check behavior, not only object existence.
- Fixtures and DB rows are deterministic and cleaned up.
- E2E cases use `moduleDescribe` / `moduleCase` with stable case ids.
- Verification evidence is real command output, Browser evidence, or a stated blocker.

# Hard Boundaries

- Production browser business APIs must use Weline bin-query / `Weline.Api.*`; never copy Playwright harness-only `fetch` patterns into app code.
- Do not update generated code under `generated/`.
- Do not add `routes.xml`.
- Do not write test docs that tell agents to use production port `9501`.
