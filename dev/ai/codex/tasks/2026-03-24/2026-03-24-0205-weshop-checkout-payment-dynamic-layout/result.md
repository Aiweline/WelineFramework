# Result - weshop-checkout-payment-dynamic-layout

- Status: ready_to_commit

## Summary

- Checkout payment rendering is now hook-driven in `WeShop_Checkout` instead of being hardcoded only inside the default page template.
- The checkout page-data layer now normalizes `w_query()` payment results into storefront-friendly metadata so layouts can render payment flows, badges, and guidance consistently.
- Default theme checkout layout variants now expose the payment section through the new checkout hook host instead of the previous collapsed placeholder block.

## Changed Files

- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/hook.php`
- `app/code/WeShop/Checkout/doc/hook/frontend/checkout/payment-methods.md`
- `app/code/WeShop/Checkout/doc/hook/frontend/checkout/payment-details.md`
- `app/code/WeShop/Checkout/doc/hook/frontend/layouts/checkout/payment-content.md`
- `app/code/WeShop/Checkout/view/hooks/WeShop_Checkout/frontend/partials/checkout/payment-methods.phtml`
- `app/code/WeShop/Checkout/view/hooks/WeShop_Checkout/frontend/partials/checkout/payment-details.phtml`
- `app/code/WeShop/Checkout/view/hooks/WeShop_Checkout/frontend/layouts/checkout/payment-content.phtml`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_1.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_2.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_3.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_4.phtml`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php`
- `app/code/WeShop/Checkout/i18n/en_US.csv`
- `app/code/WeShop/Checkout/i18n/zh_Hans_CN.csv`

## Verification

- `php -l app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `php -l app/code/WeShop/Checkout/hook.php`
- `php -l app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `php -l app/code/WeShop/Checkout/view/hooks/WeShop_Checkout/frontend/partials/checkout/payment-methods.phtml`
- `php -l app/code/WeShop/Checkout/view/hooks/WeShop_Checkout/frontend/partials/checkout/payment-details.phtml`
- `php -l app/code/WeShop/Checkout/view/hooks/WeShop_Checkout/frontend/layouts/checkout/payment-content.phtml`
- `php -l app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `php -l app/design/WeShop/default/frontend/layouts/checkout/checkout_page_1.phtml`
- `php -l app/design/WeShop/default/frontend/layouts/checkout/checkout_page_2.phtml`
- `php -l app/design/WeShop/default/frontend/layouts/checkout/checkout_page_3.phtml`
- `php -l app/design/WeShop/default/frontend/layouts/checkout/checkout_page_4.phtml`
- `php vendor/bin/phpunit app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php --colors=never`
- `php vendor/bin/phpunit app/code/WeShop/Checkout/Test/Unit/Service app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Payment/Test/Unit/Extends/Module/Weline_Framework/Query/PaymentQueryProviderTest.php --colors=never`
- `php bin/w setup:upgrade -m WeShop_Checkout --yes`

## Notes

- PHPUnit still exits non-zero because the repo environment lacks a code coverage driver; assertions passed.
- `setup:upgrade` no longer fails on checkout hook scanning. It still fails later because of the unrelated SQLite adapter environment issue already seen elsewhere in this repo.
- Live checkout smoke remains blocked because nothing is listening on `127.0.0.1:9982` in this shell.
- Follow-up audit confirmed `WeShop_Payment/extends.php` can stay empty because query providers are discovered by scanning `extends/module/Weline_Framework/Query/*`; no manual registration patch was needed in this slice.

## Remaining

- Integrate the parallel `GiftCard` and `Membership` storefront slices once the workers finish.
- Continue the next module wave after this checkout/payment commit.

## Dynamic Shipping/Payment Follow-Up (2026-03-24 10:28)

- Added shipping query-provider path for checkout so both shipping and payment methods are now query-provider driven in checkout service orchestration.
- Added checkout shipping methods hook template/host and switched default checkout shipping methods rendering from inline loop to hook-based rendering.
- Added shipping query provider and structured shipping method registry in `ShippingService` (checkout-ready `flat_rate/free_shipping/local_pickup` enabled by default, `dhl/fedex` prepared and disabled by default).
- Added focused shipping unit coverage and re-ran checkout/payment/shipping unit suites together.

### Additional Files (Follow-Up)

- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/hook.php`
- `app/code/WeShop/Checkout/view/hooks/WeShop_Checkout/frontend/partials/checkout/shipping-methods.phtml`
- `app/code/WeShop/Checkout/doc/hook/frontend/checkout/shipping-methods.md`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/CheckoutPaymentDynamicHookTemplateTest.php`
- `app/code/WeShop/Shipping/Service/ShippingService.php`
- `app/code/WeShop/Shipping/extends/module/Weline_Framework/Query/ShippingQueryProvider.php`
- `app/code/WeShop/Shipping/hook.php`
- `app/code/WeShop/Shipping/doc/hook/checkout/methods.md`
- `app/code/WeShop/Shipping/Test/Unit/Service/ShippingServiceTest.php`
- `app/code/WeShop/Shipping/Test/Unit/Extends/Module/Weline_Framework/Query/ShippingQueryProviderTest.php`

### Additional Verification (Follow-Up)

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit app/code/WeShop/Payment/Test/Unit app/code/WeShop/Shipping/Test/Unit --colors=never` passed (`30` tests / `186` assertions).
- `php bin/w extends:rebuild` passed, and generated extends registry includes `WeShop_Shipping` query-provider extension.
