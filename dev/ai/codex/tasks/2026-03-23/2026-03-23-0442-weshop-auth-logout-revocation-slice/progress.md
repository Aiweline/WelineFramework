# Progress - weshop auth logout revocation slice

- 2026-03-23 04:42 Created the task workspace.
- 2026-03-23 12:50 Audited the current `WeShopAuthTokenService::revoke()` behavior and confirmed the current implementation only revoked the single provided token record, which left the paired refresh token usable after logout.
- 2026-03-23 12:52 Added failing unit coverage in `app/code/WeShop/Auth/Test/Unit/Service/WeShopAuthTokenServiceTest.php` for both access-token-initiated and refresh-token-initiated logout.
  - The red run confirmed the old implementation still attempted per-record `setData(revoked_at)->save()` instead of revoking the actor's active token pair.
- 2026-03-23 12:53 Updated `app/code/WeShop/Auth/Service/WeShopAuthTokenService.php` so `revoke(token)` resolves the actor from the provided token and then revokes all active tokens for that actor.
- 2026-03-23 12:54 Validation completed:
  - `php -l` passed for the touched service and test file
  - `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Service/WeShopAuthTokenServiceTest.php`
    - result: `5 tests, 23 assertions`
  - `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit`
    - result: `18 tests, 78 assertions`
  - non-blocking: PHPUnit still reports `1` deprecation warning in this repo
