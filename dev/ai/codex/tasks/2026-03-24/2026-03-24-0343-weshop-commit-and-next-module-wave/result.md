# Result - weshop-commit-and-next-module-wave

## Outcome

- In progress.
- Current checkpoint plan: commit the validated WLS shared-state runtime slice first, then continue the WeShop module wave in a cleaner state.
- WLS runtime slice was committed as `babdddfe` (`feat(wls): decouple shared state services`).
- `WeShop_GiftCard` backend/admin completion is implemented, validated, and committed as `b73e8173` (`feat(weshop): add gift card admin management`).
- `WeShop_Membership` backend/admin completion is implemented, validated, and committed as `3397ff41` (`feat(weshop): add membership admin management`).
- `WeShop_B2B` storefront account-card/company-lookup consistency is fixed and committed as `766935d7` (`fix(weshop): align b2b email lookups`).
- `WeShop_Affiliate` backend/admin completion is implemented, validated, and committed as `478b66c8` (`feat(weshop): add affiliate admin management`).
- `WeShop_B2B` backend/admin completion is implemented and validated locally; white-list commit is the next immediate step.
- `WeShop_Search` storefront/default-theme/search-engine completion is implemented and re-validated locally; white-list commit is now the next immediate step for this slice.

## Changed Files

- Committed:
- `babdddfe` `feat(wls): decouple shared state services`
- `b73e8173` `feat(weshop): add gift card admin management`
- `3397ff41` `feat(weshop): add membership admin management`
- `766935d7` `fix(weshop): align b2b email lookups`
- `478b66c8` `feat(weshop): add affiliate admin management`
- Completed in latest WeShop commit:
- `app/code/WeShop/GiftCard/Controller/Backend/GiftCard/Index.php`
- `app/code/WeShop/GiftCard/Controller/Backend/GiftCard/Save.php`
- `app/code/WeShop/GiftCard/Service/GiftCardAdminPageDataService.php`
- `app/code/WeShop/GiftCard/Service/GiftCardService.php`
- `app/code/WeShop/GiftCard/etc/backend/menu.xml`
- `app/code/WeShop/GiftCard/view/backend/templates/gift-card/index.phtml`
- `app/code/WeShop/GiftCard/Test/Unit/Controller/Backend/GiftCard/IndexTest.php`
- `app/code/WeShop/GiftCard/Test/Unit/Controller/Backend/GiftCard/SaveTest.php`
- `app/code/WeShop/GiftCard/Test/Unit/Service/GiftCardAdminPageDataServiceTest.php`
- `app/code/WeShop/Membership/Controller/Backend/Membership/Index.php`
- `app/code/WeShop/Membership/Controller/Backend/Membership/Save.php`
- `app/code/WeShop/Membership/Service/MembershipAdminPageDataService.php`
- `app/code/WeShop/Membership/Service/MembershipService.php`
- `app/code/WeShop/Membership/Test/Unit/Controller/Backend/Membership/IndexTest.php`
- `app/code/WeShop/Membership/Test/Unit/Controller/Backend/Membership/SaveTest.php`
- `app/code/WeShop/Membership/Test/Unit/Service/MembershipAdminPageDataServiceTest.php`
- `app/code/WeShop/Membership/etc/backend/menu.xml`
- `app/code/WeShop/Membership/view/backend/templates/membership/index.phtml`
- `app/code/WeShop/B2B/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`
- `app/code/WeShop/B2B/Test/Unit/View/AccountOrdersCardEmailLookupTest.php`
- `app/code/WeShop/Affiliate/Controller/Backend/Affiliate/Index.php`
- `app/code/WeShop/Affiliate/Controller/Backend/Affiliate/Save.php`
- `app/code/WeShop/Affiliate/Service/AffiliateAdminPageDataService.php`
- `app/code/WeShop/Affiliate/Service/AffiliateService.php`
- `app/code/WeShop/Affiliate/Test/Unit/Controller/Backend/Affiliate/IndexTest.php`
- `app/code/WeShop/Affiliate/Test/Unit/Controller/Backend/Affiliate/SaveTest.php`
- `app/code/WeShop/Affiliate/Test/Unit/Service/AffiliateAdminPageDataServiceTest.php`
- `app/code/WeShop/Affiliate/etc/backend/menu.xml`
- `app/code/WeShop/Affiliate/view/backend/templates/affiliate/index.phtml`
- Latest uncommitted B2B slice:
- `app/code/WeShop/B2B/Controller/Backend/Company/Index.php`
- `app/code/WeShop/B2B/Controller/Backend/Company/Save.php`
- `app/code/WeShop/B2B/Service/CompanyAdminPageDataService.php`
- `app/code/WeShop/B2B/Service/CompanyService.php`
- `app/code/WeShop/B2B/Test/Unit/Controller/Backend/Company/IndexTest.php`
- `app/code/WeShop/B2B/Test/Unit/Controller/Backend/Company/SaveTest.php`
- `app/code/WeShop/B2B/Test/Unit/Service/CompanyAdminPageDataServiceTest.php`
- `app/code/WeShop/B2B/etc/backend/menu.xml`
- `app/code/WeShop/B2B/view/backend/templates/company/index.phtml`
- Latest uncommitted Search slice:
- `app/code/WeShop/Search/Controller/Index.php`
- `app/code/WeShop/Search/Controller/Suggest.php`
- `app/code/WeShop/Search/Controller/Frontend/Search/Index.php`
- `app/code/WeShop/Search/Engine/AlgoliaEngine.php`
- `app/code/WeShop/Search/Engine/ElasticsearchEngine.php`
- `app/code/WeShop/Search/Model/SearchHistory.php`
- `app/code/WeShop/Search/Service/SearchService.php`
- `app/code/WeShop/Search/Test/Unit/Controller/IndexAliasTest.php`
- `app/code/WeShop/Search/Test/Unit/Controller/SuggestAliasTest.php`
- `app/code/WeShop/Search/Test/Unit/Controller/Frontend/Search/IndexTest.php`
- `app/code/WeShop/Search/Test/Unit/Engine/AlgoliaEngineTest.php`
- `app/code/WeShop/Search/Test/Unit/Engine/ElasticsearchEngineTest.php`
- `app/code/WeShop/Search/Test/Unit/Model/SearchHistoryTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchServiceTest.php`
- `app/code/WeShop/Search/view/templates/Frontend/Search/index.phtml`
- `app/design/WeShop/default/frontend/pages/search/index.phtml`
- `tests/e2e/specs/frontend/weshop-search.spec.js`
- `app/code/WeShop/Search/i18n/en_US.csv`
- `app/code/WeShop/Search/i18n/zh_Hans_CN.csv`

## Verification

- `php -l app/code/Weline/Server/Service/SharedStateServiceManager.php`
- `php -l app/code/Weline/Server/Service/SharedStateServiceRegistry.php`
- `php -l app/code/Weline/Server/Console/Server/Start.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php --colors=never`
- `php -l app/code/WeShop/GiftCard/Service/GiftCardService.php`
- `php -l app/code/WeShop/GiftCard/Service/GiftCardAdminPageDataService.php`
- `php -l app/code/WeShop/GiftCard/Controller/Backend/GiftCard/Index.php`
- `php -l app/code/WeShop/GiftCard/Controller/Backend/GiftCard/Save.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/GiftCard/Test/Unit --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Search/Test/Unit --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- `php bin/w server:reload --no-wait`
- `curl.exe -k -i https://127.0.0.1:9982/search`
- `curl.exe -k -i "https://127.0.0.1:9982/search/suggest?q=bag&limit=3"`
- `node tests/e2e/start.js tests/e2e/specs/frontend/weshop-search.spec.js`
- `php -l app/code/WeShop/Membership/Service/MembershipService.php`
- `php -l app/code/WeShop/Membership/Service/MembershipAdminPageDataService.php`
- `php -l app/code/WeShop/Membership/Controller/Backend/Membership/Index.php`
- `php -l app/code/WeShop/Membership/Controller/Backend/Membership/Save.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Membership/Test/Unit --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/B2B/Test/Unit/View/AccountOrdersCardEmailLookupTest.php app/code/WeShop/B2B/Test/Unit/Service/CompanyPageDataServiceTest.php app/code/WeShop/B2B/Test/Unit/Controller/Frontend/B2B/IndexTest.php --colors=never`
- `php -l app/code/WeShop/B2B/Service/CompanyService.php`
- `php -l app/code/WeShop/B2B/Service/CompanyAdminPageDataService.php`
- `php -l app/code/WeShop/B2B/Controller/Backend/Company/Index.php`
- `php -l app/code/WeShop/B2B/Controller/Backend/Company/Save.php`
- `php -l app/code/WeShop/B2B/view/backend/templates/company/index.phtml`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/B2B/Test/Unit --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- `php -l app/code/WeShop/Affiliate/Service/AffiliateService.php`
- `php -l app/code/WeShop/Affiliate/Service/AffiliateAdminPageDataService.php`
- `php -l app/code/WeShop/Affiliate/Controller/Backend/Affiliate/Index.php`
- `php -l app/code/WeShop/Affiliate/Controller/Backend/Affiliate/Save.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Affiliate/Test/Unit --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`

## Remaining Risks

- The repo still has extensive unrelated dirty files; all further commits must be white-list staged.
- Account-center and B2B page hook hosts are still under-covered by `ThemeCompatibilityService` because the current compatibility manifest/scanner does not inspect those `pages/*` templates.

## Next Resume Step

- Commit the validated `WeShop_B2B` backend/admin slice, then continue into the account-center/B2B theme-compatibility warning gap in `WeShop_Base`.

## Update 2026-03-24 17:35

- Added a new uncommitted `WeShop_Payment` + checkout/default-theme slice on top of the earlier checkpoints.
- This slice makes the payment layer materially more production-facing:
- provider calls now receive normalized method/config context
- `Alipay` and `WeChatPay` no longer return TODO placeholders
- payment callbacks retain parsed body + raw body + content type for XML/form gateway verification
- checkout page-data now resolves payment methods against the runtime currency instead of a hard-coded `USD`
- checkout place-order now passes runtime currency + client IP into payment processing and exposes a top-level `redirect_url`
- the default checkout page now async-submits and redirects either to the payment gateway or to `checkout/success`

### Additional Changed Files

- `app/code/WeShop/Payment/Interface/PaymentProviderInterface.php`
- `app/code/WeShop/Payment/Provider/ProviderContextHelperTrait.php`
- `app/code/WeShop/Payment/Provider/Alipay.php`
- `app/code/WeShop/Payment/Provider/WeChatPay.php`
- `app/code/WeShop/Payment/Provider/PayPal.php`
- `app/code/WeShop/Payment/Provider/ManualTransfer.php`
- `app/code/WeShop/Payment/Provider/CashOnDelivery.php`
- `app/code/WeShop/Payment/Service/PaymentService.php`
- `app/code/WeShop/Payment/extends/module/Weline_Framework/Query/PaymentQueryProvider.php`
- `app/code/WeShop/Payment/Controller/Frontend/Payment/Callback.php`
- `app/code/WeShop/Payment/Test/Unit/Service/PaymentServiceTest.php`
- `app/code/WeShop/Payment/Test/Unit/Provider/AlipayTest.php`
- `app/code/WeShop/Payment/Test/Unit/Provider/WeChatPayTest.php`
- `app/code/WeShop/Payment/Test/Unit/Controller/Frontend/Payment/CallbackTest.php`
- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/PlaceOrder.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/PlaceOrderTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`

### Additional Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Payment/Test/Unit --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- `curl.exe -k -I https://127.0.0.1:9982/`

### Updated Risks / Next Steps

- The payment/checkout slice is validated locally but not yet white-list committed.
- The Search slice is validated locally but not yet white-list committed.
- Checkout layout variants `checkout_page_1..4` still need the same async submit tightening if we want every alternate layout to be functionally equivalent, not only host-compatible.
- `ThemeCompatibilityService` still does not separately model the page-level checkout hosts (`payment-methods` / `payment-details`) for warning generation.
- Parallel audits are in flight for `WeShop_Analytics` and `WeShop_Order`; whichever returns the cleaner bounded slice will be next after the Search checkpoint commit.
