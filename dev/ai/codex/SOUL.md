# Codex Soul for WelineFramework

This file is the durable project identity for Codex agents working in this
workspace. Read it before making architectural or behavioral claims.

## Project Identity

WelineFramework is a PHP 8.4 modular web framework with a Magento-like module
layout and a custom long-running server runtime.

Core shape:

- Framework code lives under `app/code/Weline/*`.
- Business and extension modules live under `app/code/{Vendor}/{Module}`.
- Main web path is `index.php -> pub/index.php -> app/bootstrap.php`.
- Main CLI path is `bin/w -> app/bootstrap.php -> Weline\Framework\Console\Cli`.
- Composer autoload maps `Weline\Framework\` to
  `app/code/Weline/Framework/`, `Weline\` to `app/code/Weline/`, and the empty
  namespace to `app/code/` plus `generated/code/`.
- Generated code and runtime state are outputs, not design sources.

## Architectural Spine

The framework favors convention over configuration:

- Modules register through `register.php`.
- Routes are discovered from controllers and module conventions; do not add
  `routes.xml`.
- ORM schema is declared through PHP attributes such as `#[Table]`, `#[Col]`,
  and `#[Index]`.
- Schema sync is done with `php bin/w setup:upgrade`.
- Events, hooks, and extension points decouple modules.
- Controllers should stay thin; services carry workflow logic; models carry
  persistence and entity behavior.

## Runtime Soul

Weline has two important execution modes:

- Traditional FPM/web mode through `pub/index.php`.
- WLS, the Weline long-running server, through `php bin/w server:*` commands.

WLS is not a simple dev server. It has master, worker, dispatcher, shared
services, IPC, runtime metadata, certificate loading, process recovery, and
Windows-specific dispatcher behavior. Treat WLS bugs as lifecycle and runtime
state problems until evidence says otherwise.

Key WLS paths:

- `app/code/Weline/Server`
- `app/code/Weline/Server/Console/Server`
- `app/code/Weline/Server/Service`
- `app/code/Weline/Server/Dispatcher`
- `app/code/Weline/Server/IPC`
- `var/server/instances`
- `var/server/config`
- `var/log/wls`

## Operating Values

Prefer durable framework fixes over local patches:

- Read the real stack trace, runtime JSON, logs, and command output.
- Patch the responsible layer, not only the immediate symptom.
- Keep changes small, reversible, and aligned with existing module patterns.
- Verify with targeted commands before claiming completion.
- Preserve unrelated dirty worktree changes.
- Avoid broad source scans through nested `node_modules`, `vendor`, or
  generated output unless the task explicitly requires it.

## Documentation Sources

Start with these before reading large source trees:

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/diagrams/00-INDEX.txt`
- `dev/ai/diagrams/01-framework-overview.txt`
- `dev/ai/diagrams/02-wls-architecture.txt`
- `dev/ai/diagrams/03-routing-system.txt`
- `dev/ai/diagrams/04-orm-database.txt`
- `dev/ai/diagrams/05-event-system.txt`
- `dev/ai/diagrams/06-module-lifecycle.txt`
- `dev/ai/diagrams/07-request-flow.txt`
- `dev/ai/diagrams/08-module-docs-index.txt`

Some existing docs display mojibake in PowerShell. Do not propagate corrupted
text into new documentation. Prefer ASCII paths, code symbols, and direct source
facts when the prose encoding is unclear.

## Non-Negotiables

- Do not edit `generated/` as source.
- Do not add new dependencies without explicit user request.
- Do not use `sleep`, `die`, or `exit` in WLS request/worker paths.
- Do not leave WLS test instances running.
- Do not test on the default WLS port or reuse default instance names for
  isolated experiments.
- Do not use JS `alert` or `confirm` in app UI.
- Do not hardcode user-visible text; use framework i18n patterns.
- Do not use `<?= ?>` inside `<w:*>` template attributes.
- Do not place `declare(strict_types=1)` in `.phtml` templates.

