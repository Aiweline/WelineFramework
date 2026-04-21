# Codex Memory for WelineFramework

Generated on 2026-04-21 after a read-only survey of the current workspace.
Use this as the local first-pass map before opening large source trees.

## Workspace

- Root: `E:\WelineFramework\DEV-workspace`
- Shell: PowerShell on Windows
- PHP observed: `PHP 8.4.16 (cli)` with Xdebug `3.4.7`
- Composer package: `aiweline/weline-framework`
- Project type: PHP framework/application project
- Primary app tree: `app/code`
- Generated output: `generated`
- Runtime/log output: `var`
- Public web root: `pub`
- CLI entry: `bin/w`

The worktree may already be dirty. Always inspect `git status --short` before
editing and avoid touching unrelated files.

## Read Order for Future Codex Sessions

1. `dev/ai/codex/SOUL.md`
2. `dev/ai/codex/USER.md`
3. `dev/ai/codex/MEMORY.md`
4. `AGENTS.md`
5. `AI-ENTRY.md`
6. `CLAUDE.md`
7. `dev/ai/diagrams/00-INDEX.txt`
8. The relevant module `README.md` or `doc/README.md`
9. Source code

Prefer this directory as Codex's repo-local memory surface. Older docs may be
useful but can contain mojibake in PowerShell output.

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
- It loads common functions from
  `app/code/Weline/Framework/Common/functions.php`.
- It initializes exception handling with
  `Weline\Framework\Exception\ExceptionBootstrap`.
- It initializes tracing with `Weline\Framework\Log\Context\TraceContext`.
- Web mode calls `Weline\Framework\App::runWithRuntime()`.

## Composer and Autoload

Important `composer.json` facts:

- Requires PHP `^8.4`.
- Uses path repository `app/code/Weline/*`.
- Autoload classmap includes `extend/` and `generated/code/`.
- PSR-4 mappings include:
  - `Weline\Framework\` => `app/code/Weline/Framework/`
  - `Weline\` => `app/code/Weline/`
  - empty namespace => `app/code/`, `generated/code/`
- Test stack includes PHPUnit 10 and Pest 2.

## Module Layout

Observed top-level vendors under `app/code`:

- `Agent`
- `Aiweline`
- `GuoLaiRen`
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
- `doc/` or `README.md`

Before working inside a module, check its `README.md` or `doc/README.md` if
present.

## Core Areas

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
- `app/code/Weline/Server/Console/Server/Start.php`
- `app/code/Weline/Server/Console/Server/Stop.php`
- `app/code/Weline/Server/Service/MasterProcess.php`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Dispatcher/Dispatcher.php`
- `app/code/Weline/Server/IPC`
- `app/code/Weline/Server/Service/Provider`

Queue:

- `app/code/Weline/Queue`
- `app/code/Weline/Queue/QueueInterface.php`
- `app/code/Weline/Queue/Queue/AbstractQueue.php`
- `app/code/Weline/Queue/Helper/Helper.php`
- `app/code/Weline/Queue/Model/Queue.php`
- `app/code/Weline/Queue/Controller/Backend/Queue.php`

PageBuilder:

- `app/code/GuoLaiRen/PageBuilder`
- `app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php`
- `app/code/GuoLaiRen/PageBuilder/Service/AiSiteExecutionBlueprintService.php`
- `app/code/GuoLaiRen/PageBuilder/Service/AiSiteVirtualThemePlanService.php`
- `app/code/GuoLaiRen/PageBuilder/Service/AiSiteTaskPlanSseService.php`
- `app/code/GuoLaiRen/PageBuilder/Queue`
- `app/code/GuoLaiRen/PageBuilder/test`
- `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent`

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
- Use `where('id', [1, 2], 'IN')`; do not assume `whereIn()` exists.
- Use `find($id)->fetch()` for single-row lookups.
- Do not manually edit generated schema output.

## WLS Rules

- Treat WLS as a long-running process system, not a basic PHP server.
- Runtime metadata under `var/server/instances` and `var/server/config` can be
  as important as process state.
- Logs under `var/log/wls` are high-signal.
- Code changes usually need `server:reload`.
- Master/startup/dispatcher changes usually need `server:restart -r`.
- Use isolated test instances with unique names and ports above the default.
- Always stop WLS test instances after verification.
- Avoid blocking or terminating functions in WLS worker paths.
- For orphan/default-instance bugs, verify live runtime state before deleting
  metadata or killing by broad process-name prefix.

## PageBuilder Rules

- Queue-driven execution is preferred.
- SSE should stream progress/log state; it should not become a second execution
  engine.
- Stage one and stage two AI outputs should be final user-facing website
  implementation plans.
- Guard against prompt-like outputs, generic advice, markdown-only artifacts, or
  missing structured payloads.
- For large-context OpenAI errors, inspect `Weline\Ai\Service\AiService`
  conversation-history attachment and request payload growth.

## Verification Patterns

Useful targeted checks:

- PHP syntax: `php -l path/to/file.php`
- Queue scan: `php bin/w queue:collect`
- Schema/route sync: `php bin/w setup:upgrade [--route]`
- Route test: `php bin/w http:request / [-b|-api]`
- WLS isolated start: `php bin/w server:start -p 9502 -n ai-test-{id}`
- WLS reload: `php bin/w server:reload`
- WLS stop isolated: `php bin/w server:stop -n ai-test-{id}`
- Targeted PHPUnit: `php vendor/bin/phpunit --no-coverage path/to/Test.php`
- Lightweight WLS PHPUnit sometimes works with:
  `php vendor/bin/phpunit --no-configuration --bootstrap app/autoload.php path/to/Test.php`

Xdebug coverage warnings may produce noise. Separate assertion success from
coverage configuration failure when reporting test results.

## Search Notes

If `rg.exe` fails with `Access is denied`, use:

- `git grep`
- `Get-ChildItem` with narrow paths
- `Select-String`

Avoid broad recursive scans through:

- `vendor`
- `generated`
- `var`
- nested `node_modules`
- large generated frontend dependency folders

## Current Survey Evidence

Commands used for this memory:

- `git status --short`
- `Get-ChildItem -Force`
- `Get-Content README.md`
- `Get-Content AI-ENTRY.md`
- `Get-Content CLAUDE.md`
- `Get-Content composer.json`
- `Get-Content app/bootstrap.php`
- `Get-Content pub/index.php`
- `Get-Content bin/w`
- `Get-ChildItem app/code`
- `Get-ChildItem app/code/*/*`
- targeted `Select-String` in `Weline/Server` and `Weline/Queue`
- `php -v`

Known caveat: one broad recursive `Get-ChildItem app/code -Recurse` hit missing
paths inside nested `node_modules`. Future scans should exclude dependency
folders or use narrower module paths.

