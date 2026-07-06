---
name: testing
description: WelineFramework testing guide and routing skill. Use when the user asks how to write tests, where module tests live, how to run PHPUnit/Pest/Vitest/Playwright, or how to choose unit, integration, HTTP smoke, Browser smoke, and E2E coverage. Does not authorize creating test artifacts unless the user explicitly asks.
version: 1.0.0
---

# Role

This is the central WelineFramework testing guide for Codex. It explains how to write and run tests in modules, and how to route test work to the narrower unit or E2E skills.

Use it for questions such as:

- "How do we write tests in a module?"
- "Where should unit tests and E2E specs live?"
- "How do I run PHPUnit, Pest, Vitest, or Playwright here?"
- "Which validation level should this change use?"

Reading this skill does **not** by itself authorize writing tests. Repository policy still applies: create or update unit tests, test cases, E2E specs, fixtures, test data, or regression cases only when the current user request explicitly asks for that work.

# Source Material

Read these first when the task needs exact commands or examples:

- `AI-ENTRY.md`
- `dev/ai/global-constraints.md`
- `tests/e2e/README.md`
- `tests/unit/README.md`
- `app/code/Weline/Framework/UnitTest/README.md`
- Existing tests in the target module.

Load specialist skills only when needed:

- `dev/ai/skills/单元测试工程师-单元测试覆盖/SKILL.md` for explicit PHPUnit/Pest unit-test authoring or execution.
- `dev/ai/skills/单元测试工程师-测试数据与回归/SKILL.md` for explicit fixtures, datasets, or regression input design.
- `dev/ai/skills/E2E自动化工程师-端到端流程测试/SKILL.md` for explicit E2E spec authoring or Playwright execution.
- `dev/ai/skills/E2E自动化工程师-路由与UI冒烟验证/SKILL.md` for route, HTTP, and lightweight UI smoke validation.

# Testing Decision Matrix

Use the narrowest proof that matches the risk:

- Pure PHP logic, normalizers, value decisions, and service branches: PHPUnit unit test.
- Real framework services, ORM, ObjectManager, DB persistence, module discovery, or filesystem integration: PHPUnit integration test.
- Browser-side utility JS without live WLS dependency: Vitest under `tests/unit` or near the JS source.
- Route registration, controller reachability, backend/API smoke: `php bin/w http:request ...`.
- Browser-visible layout, text, navigation, or interaction: Codex in-app Browser smoke when the local route can be served.
- Full user journey, auth, cookies, redirects, browser state, or multi-step regression: Playwright E2E through `php bin/w e2e:run`.

Do not use a heavier layer to hide an easier local assertion. Do not use a unit test to claim a browser flow works.

# PHP Tests In Modules

Prefer this module layout for PHP tests:

```text
app/code/Vendor/Module/
└── Test/
    ├── Unit/
    │   └── Service/FooServiceTest.php
    ├── Integration/
    │   └── FooFlowIntegrationTest.php
    └── phpunit.xml              # optional, useful for module-local runs
```

Existing modules also use lowercase `test/Unit` or `tests/unit`. When adding new tests, follow the target module's existing convention. If no convention exists, prefer `Test/Unit` and `Test/Integration` for PHP tests.

PHPUnit bootstrap:

- Global bootstrap: `app/bootstrap_phpunit.php`.
- It defines test constants such as `ENV_TEST`, sets stable language defaults, and loads `app/bootstrap.php`.
- Tests that need framework context may extend `Weline\Framework\UnitTest\TestCore`.
- Pure unit tests may extend `PHPUnit\Framework\TestCase` directly.

Unit-test pattern:

```php
<?php

declare(strict_types=1);

namespace Vendor\Module\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vendor\Module\Service\FooService;

final class FooServiceTest extends TestCase
{
    public function testNormalizeReturnsExpectedValue(): void
    {
        $service = new FooService();

        self::assertSame('abc', $service->normalize(' ABC '));
    }
}
```

Integration-test pattern:

```php
<?php

declare(strict_types=1);

namespace Vendor\Module\Test\Integration;

use PHPUnit\Framework\TestCase;
use Vendor\Module\Model\Thing;
use Weline\Framework\Manager\ObjectManager;

final class ThingPersistenceTest extends TestCase
{
    private array $createdIds = [];

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $model = ObjectManager::getInstance(Thing::class)->clear()->load($id);
            if ($model && $model->getId()) {
                $model->delete();
            }
        }
    }

    public function testSaveAndReloadThing(): void
    {
        $model = ObjectManager::getInstance(Thing::class);
        $model->setData('name', '测试项')->save();
        $this->createdIds[] = $model->getId();

        $loaded = ObjectManager::getInstance(Thing::class)
            ->clear()
            ->find($model->getId())
            ->fetch();

        self::assertSame('测试项', $loaded->getData('name'));
    }
}
```

Integration-test rules:

- Use real framework services only when the behavior cannot be isolated.
- Track every row, file, queue item, cache key, or config entry created by the test.
- Clean up in `tearDown()` and keep cleanup tolerant of partial failures.
- Do not treat `website_id = 0` as empty or invalid in test data.
- Do not depend on unrelated ambient runtime state if a local fixture is enough.

# PHP Test Commands

Prefer the framework runner:

```bash
php bin/w phpunit:run --module=Vendor_Module
php bin/w phpunit:run --name=FooServiceTest
php bin/w phpunit:run --name=FooServiceTest::testNormalizeReturnsExpectedValue
php bin/w phpunit:run --module=Vendor_Module --coverage
```

The command defaults to PHPUnit in current help text and supports Pest with `--pest`. When working with class-based module tests, keep the command focused by module, class, method, group, or testsuite.

If a module has its own `Test/phpunit.xml` or `tests/phpunit.xml`, it may also support direct PHPUnit from that directory, but `php bin/w phpunit:run` is the preferred shared entry.

# Frontend JS Unit Tests

Frontend JavaScript unit tests use Vitest under `tests/unit`.

Common commands:

```bash
cd tests/unit
npm test
npm run test:run
npm run test:coverage
```

Rules:

- Keep DOM tests deterministic with `happy-dom`.
- Prefer behavior assertions over implementation internals.
- Do not add long-running watch commands in automated validation unless the user explicitly asks.
- Do not add production browser requests with native `fetch`, XHR, axios, or jQuery Ajax. Production business APIs must use Weline bin-query / `Weline.Api.*`. Playwright or Vitest may use harness-level request helpers only inside tests.

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

The E2E collector scans active modules under:

- `test/e2e/**`
- `Test/e2e/**`
- `test/E2E/**`
- `Test/E2E/**`
- `tests/e2e/**`
- `Tests/e2e/**`

Always import the shared helper from `tests/e2e/framework`:

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

Backend smoke pattern:

```js
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Vendor_Module';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

moduleDescribe(test, MODULE, 'backend smoke', () => {
  moduleCase(test, { module: MODULE, id: 'BACKEND-SMOKE-001' }, 'admin page renders', async ({ page }) => {
    await loginAsAdmin(page);
    await gotoBackend(page, buildModuleBackendRoute(MODULE, 'foo'), { timeout: 30000 });

    await expect(page.locator('body')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
  });
});
```

Frontend smoke pattern:

```js
const { test, expect, gotoFrontend, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Vendor_Module';

moduleDescribe(test, MODULE, 'frontend smoke', () => {
  moduleCase(test, { module: MODULE, id: 'FRONTEND-SMOKE-001' }, 'frontend page renders', async ({ page }) => {
    await gotoFrontend(page, '/vendor/module/index', { timeout: 60000, settleMs: 800 });

    await expect(page.locator('body')).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/Fatal error|WLS Runtime Error|ParseError/i);
  });
});
```

E2E command patterns:

```bash
php bin/w e2e:run --list-modules
php bin/w e2e:run --module=Vendor_Module --project=chromium
php bin/w e2e:run --module=Vendor_Module --case-id=BACKEND-SMOKE-001 --project=chromium
php bin/w e2e:run app/code/Vendor/Module/test/e2e/backend/Vendor_Module-smoke-backend.spec.js --project=chromium --workers=1
```

Do not run Playwright directly from the repository root. Use `php bin/w e2e:run`, or manually run from `tests/e2e` only when diagnosing the runner itself.

# WLS Runtime Rules For Test Work

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
- Use `server:reload` for ordinary code changes and `server:restart -r` for master/startup/runtime-level changes.
- If runtime or browser automation is blocked, report the blocker; do not claim visible behavior was verified.

# Test Quality Checklist

Before delivering test work:

- The user explicitly requested the test artifact or test execution.
- The test lives in the owning module or the shared test harness location.
- The test name states the behavior or regression being protected.
- Assertions check behavior, not only object existence.
- Fixtures and DB rows are deterministic and cleaned up.
- E2E cases use `moduleDescribe` / `moduleCase` with stable case ids.
- Commands are focused enough to be repeatable by another agent.
- Verification evidence is real command output, Browser evidence, or a stated blocker.

# What Not To Do

- Do not create tests to validate an unrelated ordinary bug fix unless the user asked for tests.
- Do not leave generated reports, screenshots, or runtime output in source directories unless the test framework already owns that location.
- Do not update generated code under `generated/`.
- Do not use `routes.xml`.
- Do not write test docs that tell agents to use production port `9501`.
- Do not copy Playwright harness-only `fetch` patterns into production browser code.
- Do not add broad fixtures or large E2E journeys when one focused case protects the risk.
