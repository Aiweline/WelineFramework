# Result - weshop-auth-theme-live-acceptance

- Status: in_progress

## Summary

- Current checkpoint:
- live auth probes on port `9982` are confirmed against the real REST prefix `api123`
- frontend password login on `/customer/account/login` no longer throws the auth/profile schema SQL error; invalid credentials now return the expected JSON failure payload
- Weline customer login now bridges email-style sign-in into `WeShop\Customer\Service\CustomerWebAuthService`, preserving remember-duration and 2FA challenge handoff behavior
- default-theme account/product/checkout compatibility guards are covered by focused unit tests
- current live HTML on `9982` is still not a reliable oracle for default-theme login rendering because that runtime is serving the `WeShop/motor` theme, not `WeShop/default`

## Verification

- `php vendor/bin/phpunit --no-coverage app/code/Weline/Customer/Test/Unit app/code/WeShop/Customer/Test/Unit/Service app/code/WeShop/GoogleAuth/Test/Unit/Controller/Frontend/Auth app/code/WeShop/GoogleAuth/Test/Unit/Sticker app/code/WeShop/Checkout/Test/Unit/View/CheckoutPaymentDynamicHookTemplateTest.php app/code/WeShop/Payment/Test/Unit --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Product/Test/Unit/View/DefaultThemeProductHookHostTest.php app/code/WeShop/Customer/Test/Unit/View/DefaultThemeAccountHookHostTest.php app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php app/code/WeShop/Checkout/Test/Unit/View/CheckoutPaymentDynamicHookTemplateTest.php --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- `php bin/w command:collect`
- `php bin/w server:reload weshop-acceptance`
- live probes on `http://127.0.0.1:9982`:
  - `GET /customer/account/login`
  - `POST /customer/account/login`
  - `GET /wishlist`
  - `GET /recently-viewed`
  - `GET /compare`
  - `POST /compare/add`
  - `POST /compare/remove`

## Remaining

- keep progressing the remaining WeShop module wave after this checkpoint commit
- complete a browser-level acceptance pass once a runtime using `WeShop/default` is available or the active theme can be switched safely for verification
- continue default-theme hook/slot completion for account-center discovery, recommendations, and checkout payment/shipping hosts in parallel

## Frontend Google Login Slice (2026-03-24 08:56)

- Root cause confirmed: `/customer/account/login` renders `Weline_Customer::templates/frontend/account/login.phtml`, so default-theme login hook hosts do not execute on this route.
- Implemented a `WeShop_GoogleAuth` Sticker-based injection into the Weline customer login template and switched the injected content to direct rendering of a shared Google provider template (`login-provider-button.phtml`) to avoid nested hook-tag parsing fragility.
- The shared provider template is now used by both:
  - `WeShop_Social::frontend::partials::login::buttons` hook rendering.
  - Sticker-injected frontend login section for the Weline customer login route.

### Changed Files (This Slice)

- `app/code/WeShop/GoogleAuth/extends/module/Weline_Sticker/Weline/Customer/view/templates/frontend/account/login.phtml`
- `app/code/WeShop/GoogleAuth/view/hooks/WeShop_Social/frontend/partials/login/buttons.phtml`
- `app/code/WeShop/GoogleAuth/view/templates/Frontend/Auth/login-provider-button.phtml`
- `app/code/WeShop/GoogleAuth/Test/Unit/Sticker/FrontendLoginStickerTest.php`

### Validation (This Slice)

- `php -l` on all touched GoogleAuth files: passed.
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/GoogleAuth/Test/Unit/Sticker/FrontendLoginStickerTest.php --colors=never`: passed (`2` tests / `8` assertions; existing repo PHPUnit deprecation notice remains).
- `php bin/w extends:rebuild`: passed.
- `php bin/w command:collect`: passed (`4` Sticker files, no conflicts).
- Live `GET http://127.0.0.1:9982/customer/account/login`: provider section now renders under login form.

### Runtime Notes

- In this local shell/runtime, the shared Google provider template still renders an empty string unless `GoogleOAuthService::isConfigured()` sees valid `google_auth.client_id/client_secret` values.
- The currently running `weshop-acceptance` storefront on `9982` is serving the `WeShop/motor` theme, so default-theme login/body markup changes are not directly visible in that live response even after reload. Default-theme compatibility in this slice is therefore enforced through view tests and compiled-template inspection, not by claiming live `WeShop/default` rendering on this runtime.
