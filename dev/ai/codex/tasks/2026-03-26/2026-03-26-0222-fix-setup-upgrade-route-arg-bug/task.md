# Task: fix setup-upgrade route arg bug

- Task ID: 2026-03-26-0222-fix-setup-upgrade-route-arg-bug
- Started: 2026-03-26 02:22
- Status: completed
- Owner: Codex
- Source: codex:user:解决发现的问题

## Goal

- Fix the `setup:upgrade --route` CLI regression so the command accepts the documented `--route` flag and completes the route-only upgrade flow.

## Scope

- In scope:
- `Weline\Framework\Setup\Console\Setup\Upgrade` argument validation for `setup:upgrade`
- Focused regression test coverage for prefixed CLI option keys
- Real command verification for `php bin/w setup:upgrade --route`
- Out of scope:
- Refactoring the shared CLI argument parser
- Broader `setup:upgrade` behavior changes unrelated to option validation

## Constraints

- Keep the fix narrow because the workspace has many unrelated dirty changes.
- Do not change global CLI parsing semantics unless required for this bug.

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php`
- `app/code/Weline/Framework/Test/Unit/Setup/Console/Setup/UpgradeArgsValidationTest.php`
- `memory/2026-03-26.md`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
