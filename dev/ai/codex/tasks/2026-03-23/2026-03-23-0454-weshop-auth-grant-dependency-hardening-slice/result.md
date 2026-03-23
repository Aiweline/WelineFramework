# Result - weshop auth grant dependency hardening slice

## Outcome

- Implementation complete and ready to commit.

## Changed Files

- `app/code/WeShop/Auth/Service/AuthGrantService.php`
- `app/code/WeShop/Auth/Service/BackendPasswordAuthenticator.php`
- `app/code/WeShop/Auth/Service/IntegrationCredentialAuthenticator.php`
- `app/code/WeShop/Auth/Test/Unit/Service/AuthGrantServiceTest.php`
- `app/code/WeShop/Auth/Test/Unit/Service/BackendPasswordAuthenticatorTest.php`
- `app/code/WeShop/Auth/Test/Unit/Service/IntegrationCredentialAuthenticatorTest.php`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/progress.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice/result.md`

## Verification

- `php -l app/code/WeShop/Auth/Service/BackendPasswordAuthenticator.php`
- `php -l app/code/WeShop/Auth/Service/IntegrationCredentialAuthenticator.php`
- `php -l app/code/WeShop/Auth/Service/AuthGrantService.php`
- `php -l app/code/WeShop/Auth/Test/Unit/Service/AuthGrantServiceTest.php`
- `php -l app/code/WeShop/Auth/Test/Unit/Service/BackendPasswordAuthenticatorTest.php`
- `php -l app/code/WeShop/Auth/Test/Unit/Service/IntegrationCredentialAuthenticatorTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Service/AuthGrantServiceTest.php`
  - `OK (2 tests, 16 assertions)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Service/BackendPasswordAuthenticatorTest.php`
  - `OK (2 tests, 3 assertions)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Service/IntegrationCredentialAuthenticatorTest.php`
  - `OK (2 tests, 3 assertions)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit`
  - `OK (24 tests, 100 assertions)`
- Non-blocking: PHPUnit still reports `1` repository deprecation warning.

## Remaining Risks

- Google-code grant still uses a compatibility `ObjectManager` lookup because that path crosses the optional Google auth module boundary and needs a separate design pass.
- This slice remains at the unit level; HTTP auth-route verification is still pending.

## Next Resume Step

- Commit this slice, then continue upward into HTTP-level auth route verification for `/api/weshop/rest/v1/auth/*`.
