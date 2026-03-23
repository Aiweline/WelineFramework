# Result

## Outcome

- Added storefront Google bind/unbind management inside the customer account center.
- Added a new storefront security hook contract so future modules like 2FA can attach to the same account-center security area.
- Added the missing `default` theme customer account page and account layout compatibility aliases so `layoutType = account` now resolves without modifying Theme modules.
- Fixed invalid `parent::__construct()` calls in GoogleAuth storefront controllers.

## Commit

- `9f85fa1d feat(weshop): add storefront google account center`

## Changed Files

- `app/code/WeShop/Customer/Controller/Frontend/Account/Index.php`
- `app/code/WeShop/Customer/hook.php`
- `app/code/WeShop/Customer/doc/hook/frontend/account/security/cards.md`
- `app/code/WeShop/Customer/i18n/en_US.csv`
- `app/code/WeShop/Customer/i18n/zh_Hans_CN.csv`
- `app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Start.php`
- `app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Callback.php`
- `app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/BackendChallenge.php`
- `app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Binding.php`
- `app/code/WeShop/GoogleAuth/view/hooks/WeShop_Customer/frontend/account/security/cards.phtml`
- `app/code/WeShop/GoogleAuth/Test/Unit/Controller/Frontend/Auth/BindingTest.php`
- `app/code/WeShop/GoogleAuth/Test/Unit/Controller/Frontend/Auth/CallbackTest.php`
- `app/code/WeShop/GoogleAuth/Test/Unit/Controller/Frontend/Auth/BackendChallengeTest.php`
- `app/code/WeShop/GoogleAuth/Test/Unit/Controller/Backend/Auth/BindingTest.php`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- `app/design/WeShop/default/frontend/layouts/account/account_page_1.phtml`
- `app/design/WeShop/default/frontend/layouts/account/account_page_2.phtml`
- `app/design/WeShop/default/frontend/layouts/account/account_page_3.phtml`

## Verification

- `php -l` on all touched WeShop auth/account files and templates
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/GoogleAuth/Test/Unit/Controller/Frontend/Auth/BindingTest.php app/code/WeShop/GoogleAuth/Test/Unit/Controller/Frontend/Auth/CallbackTest.php app/code/WeShop/GoogleAuth/Test/Unit/Controller/Frontend/Auth/BackendChallengeTest.php app/code/WeShop/GoogleAuth/Test/Unit/Controller/Backend/Auth/BindingTest.php`
  - Result: `OK`, 8 tests, 48 assertions
- `php bin/w setup:upgrade --yes`
  - Result: passed
- `rg -n "weshop_googleauth/frontend/auth/binding" generated/routers -g "*.php"`
- `rg -n "WeShop_Customer::frontend::account::security::cards" generated -g "*.php"`

## Remaining Risks

- Unified auth REST endpoints are still pending.
- 2FA self-service enablement is still pending.
- `setup:upgrade` still reports unrelated ACL sync and global i18n warnings outside this slice.

## Next Resume Step

- Continue with a new task slice for storefront/backend 2FA self-service enablement, reusing the new account-center security area.
