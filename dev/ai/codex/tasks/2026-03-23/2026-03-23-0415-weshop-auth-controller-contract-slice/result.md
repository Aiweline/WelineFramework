# Result - weshop auth controller contract slice

## Outcome

- Completed in commit `7efbf68c test(weshop): lock auth controller grant contracts`.

## Changed Files

- `app/code/WeShop/Auth/Api/Rest/V1/Auth.php`
- `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php`
- `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0415-weshop-auth-controller-contract-slice/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0415-weshop-auth-controller-contract-slice/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0415-weshop-auth-controller-contract-slice/progress.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0415-weshop-auth-controller-contract-slice/result.md`

## Verification

- `php -l app/code/WeShop/Auth/Api/Rest/V1/Auth.php`
- `php -l app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php`
- `php -l app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php`
  - `OK (5 tests, 15 assertions)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit`
  - `OK (16 tests, 72 assertions)`
- Non-blocking: PHPUnit still reports `1` deprecation warning in this repository.

## Remaining Risks

- This slice stays at unit-test level; no `http:req` or browser-level verification was run here.
- `AuthGrantService` still contains compatibility-layer `ObjectManager` usage for backend and Google flows, which should be reduced in a later service-level cleanup slice.
- Broader unified auth API work remains, especially endpoint response contract tests and cross-module API authentication middleware behavior.
- The commit includes unrelated pre-staged `Weline_Server` changes from the dirty repository index; they were not authored as part of this WeShop task and should not be interpreted as part of the intended WeShop white-list.

## Next Resume Step

- Commit this slice, then continue upward into grant-service contract tightening or HTTP-level auth API verification.
