# Result - fix setup-upgrade route arg bug

## Outcome

- Fixed the `setup:upgrade --route` regression by teaching `Upgrade` argument validation to accept prefixed option keys like `--route` in addition to normalized keys like `route`.

## Changed Files

- `app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php`
- `app/code/Weline/Framework/Test/Unit/Setup/Console/Setup/UpgradeArgsValidationTest.php`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0222-fix-setup-upgrade-route-arg-bug/task.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0222-fix-setup-upgrade-route-arg-bug/plan.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0222-fix-setup-upgrade-route-arg-bug/progress.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0222-fix-setup-upgrade-route-arg-bug/result.md`

## Verification

- `php -l app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php`
- `php -l app/code/Weline/Framework/Test/Unit/Setup/Console/Setup/UpgradeArgsValidationTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/Unit/Setup/Console/Setup/UpgradeArgsValidationTest.php --colors=never`
- `php bin/w setup:upgrade --route`

## Remaining Risks

- The underlying CLI parser still emits both normalized and prefixed keys for valueless options; this fix intentionally scopes compatibility to `setup:upgrade` rather than changing parser-wide behavior.

## Next Resume Step

- If similar failures appear in other commands, audit their local argument validation instead of assuming the global parser contract is normalized-only.
