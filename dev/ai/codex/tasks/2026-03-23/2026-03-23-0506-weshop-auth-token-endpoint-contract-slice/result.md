# Result - weshop auth token endpoint contract slice

## Outcome

- Completed in commit `2d3df085 test(weshop): lock auth token endpoint contracts`.

## Changed Files

- `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php`
- `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/progress.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/result.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0506-weshop-auth-token-endpoint-contract-slice/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0506-weshop-auth-token-endpoint-contract-slice/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0506-weshop-auth-token-endpoint-contract-slice/progress.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0506-weshop-auth-token-endpoint-contract-slice/result.md`

## Verification

- `php -l app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php`
- `php -l app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php`
  - `OK (10 tests, 30 assertions)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php`
  - `OK (2 tests, 6 assertions)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit`
  - `OK (31 tests, 121 assertions)`
- `php bin/w http:req "api/weshop/rest/v1/auth/token" -m=POST -d="grant_type=magic"`
  - failed with `cURL error 7` because `127.0.0.1:9981` was not accepting connections
- `php bin/w http:req "api/weshop/rest/v1/auth/challenge/verify" -m=POST`
  - failed with `cURL error 7` because `127.0.0.1:9981` was not accepting connections
- Non-blocking: PHPUnit still reports `1` repository deprecation warning.

## Remaining Risks

- Runtime `http:req` verification may still be limited by the unstable local auth route environment.
- This slice strengthened controller contracts only; it did not add browser or full server-backed API verification.

## Next Resume Step

- Continue in `2026-03-23-0513-weshop-google-grant-authenticator-slice` to finish extracting the remaining Google grant logic out of `AuthGrantService`.
