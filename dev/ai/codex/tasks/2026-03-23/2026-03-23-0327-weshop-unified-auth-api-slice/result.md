# Result - weshop unified auth api slice

## Outcome

- Completed in commit `460dd98a feat(weshop): preserve auth token actor area`.

## Changed Files

- `app/code/WeShop/Auth/Model/AuthToken.php`
- `app/code/WeShop/Auth/Service/WeShopAuthTokenService.php`
- `app/code/WeShop/Auth/Test/Unit/Service/WeShopAuthTokenServiceTest.php`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0327-weshop-unified-auth-api-slice/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0327-weshop-unified-auth-api-slice/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0327-weshop-unified-auth-api-slice/progress.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0327-weshop-unified-auth-api-slice/result.md`

## Verification

- Generated route inspection confirms WeShop auth API routes are registered in `generated/routers/frontend_rest_api.php`.
- `php -l app/code/WeShop/Auth/Model/AuthToken.php`
- `php -l app/code/WeShop/Auth/Service/WeShopAuthTokenService.php`
- `php -l app/code/WeShop/Auth/Test/Unit/Service/WeShopAuthTokenServiceTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/Auth/Test/Unit/Service/TwoFactorAccountServiceTest.php app/code/WeShop/Auth/Test/Unit/Controller/Frontend/Auth/TwoFactorTest.php app/code/WeShop/Auth/Test/Unit/Controller/Backend/Security/TwoFactorTest.php app/code/WeShop/Auth/Test/Unit/Service/WeShopAuthTokenServiceTest.php`
  - `OK (11 tests, 57 assertions)`
  - also reports `1` PHPUnit deprecation warning
- `php bin/w setup:upgrade --yes -m WeShop_Auth`
  - completed successfully
  - emitted an existing non-blocking ACL orphan cleanup warning during route/ACL sync
  - emitted unrelated empty-i18n CSV warnings in other modules during repo-wide i18n collection

## Remaining Risks

- `http:req` and browser/e2e verification were not run in this slice because the change is storage/service-level and the local runtime still has known frontend probe instability.
- Existing tokens created before the new `area` column rely on the default fallback of `api`; only newly issued or refreshed tokens carry the precise actor area.
- Broader unified auth API work still remains, including higher-level endpoint contract tests and controller/service cleanup away from compatibility-layer `ObjectManager` usage.

## Next Resume Step

- Commit this slice, then continue upward into auth API controller tests or grant-service contract tightening.
