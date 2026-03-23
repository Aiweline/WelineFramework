# Result - weshop auth live route verification slice

## Outcome

- Completed: the live WeShop auth routes on port `9982` now bypass the framework pre-auth gate and reach the intended controllers.

## Changed Files

- `app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php`
- `app/code/Weline/Framework/Test/Unit/Http/PublicApiAuthRouteMatcherTest.php`
- `app/code/Weline/Api/Observer/ApiControllerInitBefore.php`
- `app/code/Weline/Api/test/Unit/Observer/ApiControllerInitBeforeTest.php`
- `app/code/Weline/Acl/Observer/RouteBefore.php`
- `app/code/Weline/Acl/Test/Unit/Observer/RouteBeforeTest.php`

## Verification

- `php -l app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php`
- `php -l app/code/Weline/Api/Observer/ApiControllerInitBefore.php`
- `php -l app/code/Weline/Acl/Observer/RouteBefore.php`
- `php -l app/code/Weline/Acl/Test/Unit/Observer/RouteBeforeTest.php`
- `php -l app/code/Weline/Framework/Test/Unit/Http/PublicApiAuthRouteMatcherTest.php`
- `php -l app/code/Weline/Api/test/Unit/Observer/ApiControllerInitBeforeTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/Weline/Framework/Test/Unit/Http/PublicApiAuthRouteMatcherTest.php`
  - `OK (3 tests, 3 assertions)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/Weline/Acl/Test/Unit/Observer/RouteBeforeTest.php`
  - `OK (1 test, 1 assertion)`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/Weline/Api/test/Unit/Observer/ApiControllerInitBeforeTest.php`
  - `OK (4 tests, 8 assertions)`
- `php bin/w http:req "api/weshop/rest/v1/auth/token" --port=9982 --https -m=POST -d="grant_type=magic"`
  - live result: `422 Authentication failed.` from `WeShop\Auth\Api\Rest\V1\Auth::postToken()`
- `php bin/w http:req "api/weshop/rest/v1/auth/challenge/verify" --port=9982 --https -m=POST`
  - live result: `422 Challenge verification failed.` from `WeShop\Auth\Api\Rest\V1\Auth\Challenge::postVerify()`

## Remaining Risks

- The legacy framework auth endpoints `api/rest/v1/auth/login` and `api/rest/v1/backend/auth/login` currently return `404` in this runtime; that is outside the WeShop slice but may need a separate framework audit if they are still expected to be available.
- The frontend API whitelist table is currently not the mechanism enforcing this bypass for WeShop auth; the behavior now relies on the shared matcher in both pre-auth observers.
- Browser e2e for Google login / 2FA is still pending in broader WeShop work.

## Next Resume Step

- Commit this slice, then continue upward into Google login + 2FA acceptance or into the next WeShop auth/API contract slice.
