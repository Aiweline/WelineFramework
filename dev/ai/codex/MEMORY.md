# Codex Repo Context Map for WelineFramework

Last refreshed: 2026-07-04.

This is a repo-local context map for Codex. It is not the same as Codex
generated memories under `~/.codex/memories`, and it must not override
`AGENTS.md`, `AI-ENTRY.md`, `dev/ai/global-constraints.md`, or Codex
system/developer instructions.

## Workspace

- Current macOS root: `/Users/weline/Project/Official/框架`
- Historical Windows core root: `E:\WelineFramework\DEV-workspace`
- Shell in this thread: `zsh`
- Composer package: `aiweline/weline-framework`
- Project type: PHP 8.4 framework/application project
- Primary app tree: `app/code`
- Generated output: `generated`
- Runtime/log output: `var`
- Public web root: `pub`
- CLI entry: `bin/w`

The worktree may already be dirty. Inspect `git status --short` before editing
when the task involves code or commits, and preserve unrelated user changes.

## Read Order For Future Codex Sessions

1. `AGENTS.md`
2. `AI-ENTRY.md`
3. `dev/ai/global-constraints.md`
4. `dev/ai/codex/SOUL.md`
5. `dev/ai/codex/USER.md`
6. This file
7. `dev/ai/skills/_index.md`
8. Relevant module `README.md` or `doc/README.md`
9. Target source code

Do not default-load task history under `dev/ai/codex/tasks/**` or
`dev/ai/archive/**` unless restoring a task or checking history.

## Entry Points

Web:

- `index.php`
- `pub/index.php`
- `app/bootstrap.php`

CLI:

- `bin/w`
- `Weline\Framework\Console\Cli`

Bootstrap facts:

- `app/bootstrap.php` defines `BP` if absent.
- It loads `app/autoload.php`.
- It loads common functions from `app/code/Weline/Framework/Common/functions.php`.
- It initializes exception handling with `Weline\Framework\Exception\ExceptionBootstrap`.
- It initializes tracing with `Weline\Framework\Log\Context\TraceContext`.
- Web mode calls `Weline\Framework\App::runWithRuntime()`.

## Composer And Autoload

- PHP requirement: `^8.4`.
- Path repository: `app/code/Weline/*`.
- Autoload classmap includes `extend/` and `generated/code/`.
- PSR-4 mappings:
  - `Weline\Framework\` -> `app/code/Weline/Framework/`
  - `Weline\` -> `app/code/Weline/`
  - empty namespace -> `app/code/`, `generated/code/`
- Test stack includes PHPUnit 10 and Pest 2 in root `composer.json`.

## Module Layout

Observed top-level vendors under `app/code` include:

- `Agent`
- `Aiweline`
- `Weline`
- `WelineTools`
- `WeShop`

Common module shape:

- `register.php`
- `etc/`
- `Controller/`
- `Console/`
- `Model/`
- `Service/`
- `Observer/`
- `Setup/`
- `view/`
- `i18n/`
- `Test/Unit/` or `test/Unit/`
- `test/e2e/` or `Test/e2e/`
- `doc/` or `README.md`

Before working inside a module, check its local `README.md` or `doc/README.md`
if present.

`app/code/GuoLaiRen` has moved to the release target repository
`E:\公司\远程\src\weline`; this source repository no longer supports
GuoLaiRen vendor module development, verification, deployment, or skill
maintenance.

## High-Signal Core Areas

Framework core:

- `app/code/Weline/Framework/App`
- `app/code/Weline/Framework/Console`
- `app/code/Weline/Framework/Database`
- `app/code/Weline/Framework/Event`
- `app/code/Weline/Framework/Http`
- `app/code/Weline/Framework/Router`
- `app/code/Weline/Framework/Setup`
- `app/code/Weline/Framework/View`
- `app/code/Weline/Framework/System`

WLS runtime:

- `app/code/Weline/Server`
- `app/code/Weline/Server/Console/Server`
- `app/code/Weline/Server/Service`
- `app/code/Weline/Server/Dispatcher`
- `app/code/Weline/Server/IPC`
- `var/server/instances`
- `var/server/config`
- `var/log/wls`

Queue:

- `app/code/Weline/Queue`
- `app/code/Weline/Queue/QueueInterface.php`
- `app/code/Weline/Queue/Queue/AbstractQueue.php`
- `app/code/Weline/Queue/Helper/Helper.php`
- `app/code/Weline/Queue/Model/Queue.php`
- `app/code/Weline/Queue/Controller/Backend/Queue.php`

WeShop ecommerce:

- `app/code/WeShop`
- High-signal modules include `Product`, `Catalog`, `Cart`, `Checkout`,
  `Order`, `Payment`, `Shipping`, `Customer`, `Inventory`, `Search`, `Store`,
  and `Theme`.

## ORM Rules

- Models extend the framework database model base class.
- Schema is attribute-driven with `#[Table]`, `#[Col]`, and `#[Index]`.
- After model schema edits, run `php bin/w setup:upgrade`.
- Query chains must end with execution calls such as `fetch()` or
  `fetchArray()`.
- Use `find($id)->fetch()` for single-row lookups.
- Do not manually edit generated schema output.
- Treat `website_id = 0` and `code = default` as the valid system default site.

## Testing Map

Central guide:

- `dev/ai/skills/testing/SKILL.md`

PHP tests:

- Prefer module `Test/Unit` and `Test/Integration` for new PHP tests unless the
  module already uses another convention.
- Use `app/bootstrap_phpunit.php` for framework bootstrap.
- Use `PHPUnit\Framework\TestCase` for isolated logic.
- Use `Weline\Framework\UnitTest\TestCore` only when framework context is
  needed.
- Integration tests must clean up rows, files, queue items, cache keys, and
  config entries they create.

E2E tests:

- Module specs live under `test/e2e` or `Test/e2e`.
- Use `php bin/w e2e:run`; do not invoke Playwright from the repo root.
- Use `tests/e2e/framework` helpers such as `gotoFrontend`, `gotoBackend`,
  `loginAsAdmin`, `moduleDescribe`, and `moduleCase`.

Default policy:

- Do not create or update unit tests, fixtures, regression cases, or E2E specs
  unless the user explicitly asks.
- For ordinary development validation, use real entrypoints, HTTP, WLS,
  Browser smoke, existing commands, and documentation checks.

## WLS Rules

- Treat WLS as a long-running process system, not a basic PHP server.
- Runtime metadata under `var/server/instances` and `var/server/config` can be
  as important as process state.
- Logs under `var/log/wls` are high-signal.
- Code changes usually need `server:reload`.
- Master/startup/dispatcher changes usually need `server:restart -r`.
- Use isolated test instances with unique names and ports above the default.
- Never test on default WLS port `9501`.
- Always stop WLS test instances after verification.
- Avoid blocking or terminating functions in WLS worker paths.
- For orphan/default-instance bugs, verify live runtime state before deleting
  metadata or killing by broad process-name prefix.

## Useful Validation Patterns

- PHP syntax: `php -l path/to/file.php`
- Queue scan: `php bin/w queue:collect`
- Schema sync: `php bin/w setup:upgrade`
- Route sync: `php bin/w setup:upgrade --route`
- Frontend route check: `php bin/w http:request /`
- Backend route check: `php bin/w http:request admin -b`
- API route check: `php bin/w http:request rest/v1/module/action -api`
- WLS isolated start: `php bin/w server:start -p 9502 -n ai-test-{id}`
- WLS stop isolated: `php bin/w server:stop -n ai-test-{id}`
- PHPUnit module: `php bin/w phpunit:run --module=Vendor_Module`
- PHPUnit class: `php bin/w phpunit:run --name=ClassNameTest`
- E2E module: `php bin/w e2e:run --module=Vendor_Module --project=chromium`

## Search Notes

Prefer:

- `rg`
- `fd`
- `jq`
- `yq`
- CodeGraphContext for code graph context when useful

Avoid broad recursive scans through:

- `vendor`
- `generated`
- `var`
- nested `node_modules`
- large generated frontend dependency folders

