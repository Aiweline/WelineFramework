# Progress - fix setup-upgrade route arg bug

- 2026-03-26 02:22 Created the task workspace.
- 2026-03-26 10:20 Reproduced the defect with `php bin/w setup:upgrade --route`; the command failed inside `Upgrade::validateSupportedArgs()` even though `--route` was listed as supported.
- 2026-03-26 10:24 Traced the root cause to the CLI parser storing valueless options in both normalized and prefixed forms, while `Upgrade::validateSupportedArgs()` only accepted normalized keys.
- 2026-03-26 10:28 Updated `Upgrade::validateSupportedArgs()` to accept either the raw key or its normalized form, and added `normalizeArgKey()` to keep the fix scoped to this command.
- 2026-03-26 10:30 Added `UpgradeArgsValidationTest` to cover accepted normalized and prefixed keys plus rejection of unknown prefixed options.
- 2026-03-26 10:31 Verified syntax for both touched PHP files and passed focused PHPUnit (`2 tests / 3 assertions`, plus one existing PHPUnit deprecation notice).
- 2026-03-26 10:32 Re-ran `php bin/w setup:upgrade --route`; the command completed successfully and finished the route-only upgrade flow instead of failing at argument validation.
