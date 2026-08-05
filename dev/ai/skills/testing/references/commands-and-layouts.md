# Weline Test Commands And Layouts

## Contents

- PHP test layout and commands
- JavaScript unit commands
- Playwright layout and commands
- Runtime-dependent validation
- Authoritative repository references

## PHP Tests

Framework test infrastructure lives under `app/code/Weline/Framework/Test/` in the `Weline\Framework\Test` namespace. Business modules may keep their own tests under `Test/`, `UnitTest/`, or an established module-local convention.

If a module has no existing convention, prefer:

```text
app/code/Vendor/Module/
└── Test/
    ├── Unit/
    │   └── Service/FooServiceTest.php
    ├── Integration/
    │   └── FooFlowIntegrationTest.php
    └── phpunit.xml
```

Use `app/bootstrap_phpunit.php` for framework-backed PHPUnit. Pure units may extend `PHPUnit\Framework\TestCase`; framework-aware tests may use `Weline\Framework\Test\TestCore`.

Prefer the shared runner and keep its scope focused:

```bash
php bin/w phpunit:run --module=Vendor_Module
php bin/w phpunit:run --name=FooServiceTest
php bin/w phpunit:run --name=FooServiceTest::testNormalizeReturnsExpectedValue
php bin/w phpunit:run --module=Vendor_Module --coverage
```

Use `--pest` only when the target suite is Pest-based. A module-local PHPUnit configuration may be used when that module already owns one.

## JavaScript Unit Tests

Shared frontend unit tests use Vitest under `tests/unit`:

```bash
cd tests/unit
npm run test:run
npm run test:coverage
```

Use `npm test` only when an interactive watch process is actually wanted. Keep DOM behavior deterministic under the configured test environment.

## Playwright E2E

Supported module E2E locations include:

```text
app/code/Vendor/Module/test/e2e/backend/
app/code/Vendor/Module/test/e2e/frontend/
```

The collector recognizes the repository's established `test|Test|tests|Tests` and `e2e|E2E` variants. Follow the target module's existing convention and import helpers from `tests/e2e/framework`; use stable `moduleDescribe`, `moduleCase`, and case IDs.

Run E2E through the framework entry:

```bash
php bin/w e2e:run --list-modules
php bin/w e2e:run --module=Vendor_Module --project=chromium
php bin/w e2e:run --module=Vendor_Module --case-id=BACKEND-SMOKE-001 --project=chromium
php bin/w e2e:run app/code/Vendor/Module/test/e2e/backend/Vendor_Module-smoke-backend.spec.js --project=chromium --workers=1
```

Do not run Playwright directly from the repository root. Run it manually from `tests/e2e` only when diagnosing the runner itself.

## Runtime-Dependent Validation

Choose an unused integer port at least `9502` and a unique instance name:

```bash
php bin/w server:start ai-test-{unique-id} -p {available-port}
php bin/w setup:upgrade --route
php bin/w e2e:run --module=Vendor_Module --project=chromium --headless --workers=1
php bin/w server:stop ai-test-{unique-id}
```

Use `server:reload {instance}` after ordinary code changes and `server:start {instance} -r` after master/startup changes. If manual acceptance is needed, report the live URL, instance name, port, status, and stop command, then stop it after acceptance.

## Repository References

Read only the reference relevant to the task:

- `tests/unit/README.md`
- `tests/e2e/README.md`
- `app/code/Weline/Framework/Test/README.md`
- `app/code/Weline/Framework/Test/AUTOMATED_TESTING.md`
- Existing tests and runner configuration in the target module
