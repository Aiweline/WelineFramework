# Result - fix setup-upgrade btree index blocker

## Outcome

- Completed. The real `BTREE` schema blocker is fixed, `setup:upgrade --yes` completes again, and the AI site workbench E2E flow is green on the current workspace.

## Changed Files

- Schema / upgrade hardening:
  - `app/code/WeShop/GiftCard/Model/GiftCard.php`
  - `app/code/Weline/Framework/Database/Schema/IndexDefinition.php`
  - `app/code/Weline/Framework/Test/Unit/Database/Schema/IndexDefinitionTest.php`
  - `app/code/Weline/Framework/Database/Compiler/MysqlCompiler.php`
  - `app/code/Weline/Framework/Database/Compiler/PgsqlCompiler.php`
  - `app/code/Weline/Framework/Database/Compiler/SqliteCompiler.php`
  - `app/code/Weline/Framework/Database/test/Unit/DatabaseAstCompilerRegressionTest.php`
  - `app/code/Weline/Backend/Setup/EnsureAdmin.php`
  - `app/code/Weline/Backend/Setup/Upgrade.php`
  - `app/code/Weline/Backend/test/Unit/Setup/EnsureAdminTest.php`
  - `app/code/GuoLaiRen/Blog/Setup/Db/Migration/blog_post_summary_source_keyword_text_20250318-v1.0.2.php`
  - `app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php`
- Hook / theme / checkout compatibility:
  - `app/code/WeShop/Shipping/hook.php`
  - `app/code/WeShop/Shipping/doc/hook/checkout/methods.md`
  - `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
  - `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_1.phtml`
  - `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_2.phtml`
  - `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_3.phtml`
  - `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_4.phtml`
  - `app/code/WeShop/Base/etc/theme-compatibility.php`
  - `app/code/WeShop/Base/Test/Unit/Service/ThemeCompatibilityServiceTest.php`
  - `app/code/WeShop/Customer/doc/hook/frontend/account/quick-links/after.md`
  - `app/code/WeShop/Customer/doc/hook/frontend/account/recommendations/before.md`
  - `app/code/WeShop/Customer/doc/hook/frontend/account/recommendations/after.md`
- E2E runner / spec stabilization:
  - `tests/e2e/start.js`
  - `tests/e2e/framework/preflight-refresh.php`
  - `tests/e2e/framework/preflight-refresh.js`
  - `tests/e2e/specs/backend/ai-site-workbench.spec.js`

## Verification

- `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/Unit/Database/Schema/IndexDefinitionTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Database/test/Unit/DatabaseAstCompilerRegressionTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Backend/test/Unit/Setup/EnsureAdminTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Base/Test/Unit/Service/ThemeCompatibilityServiceTest.php --colors=never`
- `php bin/w setup:upgrade --yes`
- `cd tests/e2e && node start.js specs/backend/ai-site-workbench.spec.js`

## Remaining Risks

- `setup:upgrade` still prints unrelated non-fatal i18n empty-CSV warnings.
- Upgrade completion can still log a best-effort WLS reload failure for a stale `default` control port, but it does not fail the command.

## Next Resume Step

- If this area regresses again, rerun full `setup:upgrade --yes` first, then the focused AI site workbench E2E spec. The preflight runner is now part of the supported path and should be kept in sync with route/hook/taglib registration changes.
