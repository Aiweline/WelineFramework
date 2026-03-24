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
- `WeShop_Search` storefront/default-theme/search-engine completion was committed as `09624067` (`feat(weshop): complete search storefront slice`).
- `WeShop_Analytics` backend/config/default-theme injection completion was committed as `25688436` (`feat(weshop): harden analytics provider config`).
- `WeShop_Order` storefront/default-theme/account-center/API completion was revalidated and committed as `a4b3053b` (`feat(weshop): complete order storefront account flows`).
- `WeShop_Invoice` storefront/backend/admin/API/default-theme completion is now implemented and validated locally; white-list commit is the next immediate step.

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
- Latest uncommitted Analytics slice:
- `app/code/WeShop/Analytics/Controller/Backend/Analytics/Index.php`
- `app/code/WeShop/Analytics/Controller/Backend/Analytics/Save.php`
- `app/code/WeShop/Analytics/Service/AnalyticsConfigService.php`
- `app/code/WeShop/Analytics/Service/AnalyticsAdminPageDataService.php`
- `app/code/WeShop/Analytics/Service/AnalyticsSnippetService.php`
- `app/code/WeShop/Analytics/Service/PixelDispatcher.php`
- `app/code/WeShop/Analytics/Provider/GoogleAnalytics.php`
- `app/code/WeShop/Analytics/Provider/FacebookPixel.php`
- `app/code/WeShop/Analytics/Observer/AddToCartPixel.php`
- `app/code/WeShop/Analytics/Observer/AddToWishlistPixel.php`
- `app/code/WeShop/Analytics/Observer/CustomerLoginPixel.php`
- `app/code/WeShop/Analytics/Observer/CustomerRegisterPixel.php`
- `app/code/WeShop/Analytics/Observer/OrderCreatedPixel.php`
- `app/code/WeShop/Analytics/Observer/OrderPaidPixel.php`
- `app/code/WeShop/Analytics/etc/backend/menu.xml`
- `app/code/WeShop/Analytics/etc/env.php`
- `app/code/WeShop/Analytics/extends/module/Weline_Framework/Query/AnalyticsQueryProvider.php`
- `app/code/WeShop/Analytics/view/backend/templates/analytics/index.phtml`
- `app/code/WeShop/Analytics/view/hooks/Weline_Theme/frontend/layouts/base/head-after.phtml`
- `app/code/WeShop/Analytics/Test/Unit/Controller/Backend/Analytics/IndexTest.php`
- `app/code/WeShop/Analytics/Test/Unit/Controller/Backend/Analytics/SaveTest.php`
- `app/code/WeShop/Analytics/Test/Unit/Query/AnalyticsQueryProviderTest.php`
- `app/code/WeShop/Analytics/Test/Unit/Service/AnalyticsAdminPageDataServiceTest.php`
- `app/code/WeShop/Analytics/Test/Unit/Service/AnalyticsConfigServiceTest.php`
- `app/code/WeShop/Analytics/Test/Unit/Service/AnalyticsSnippetServiceTest.php`
- `app/code/WeShop/Analytics/Test/Unit/View/FrontendHeadHookTemplateTest.php`
- `app/code/WeShop/Base/etc/theme-compatibility.php`
- `tests/e2e/specs/backend/weshop-analytics.spec.js`
- Latest committed Order slice:
- `app/code/WeShop/Order/Api/Rest/V1/Order.php`
- `app/code/WeShop/Order/Controller/Frontend/Order/Cancel.php`
- `app/code/WeShop/Order/Controller/Frontend/Order/OrderList.php`
- `app/code/WeShop/Order/Controller/Frontend/Order/RetryPayment.php`
- `app/code/WeShop/Order/Controller/Frontend/Order/View.php`
- `app/code/WeShop/Order/Service/OrderAdminPageDataService.php`
- `app/code/WeShop/Order/Service/OrderDetailPageDataService.php`
- `app/code/WeShop/Order/Service/OrderListPageDataService.php`
- `app/code/WeShop/Order/Service/OrderService.php`
- `app/code/WeShop/Order/Test/Unit/Api/Rest/V1/OrderTest.php`
- `app/code/WeShop/Order/Test/Unit/Controller/Frontend/Order/OrderListTest.php`
- `app/code/WeShop/Order/Test/Unit/Controller/Frontend/Order/RetryPaymentTest.php`
- `app/code/WeShop/Order/Test/Unit/Controller/Frontend/Order/ViewTest.php`
- `app/code/WeShop/Order/Test/Unit/Query/OrderQueryProviderTest.php`
- `app/code/WeShop/Order/Test/Unit/Service/OrderDetailPageDataServiceTest.php`
- `app/code/WeShop/Order/Test/Unit/Service/OrderListPageDataServiceTest.php`
- `app/code/WeShop/Order/Test/Unit/Service/OrderServiceTest.php`
- `app/code/WeShop/Order/Test/Unit/View/DefaultThemeOrderPageTest.php`
- `app/code/WeShop/Order/Test/Unit/View/OrderAccountOrdersCardsHookTest.php`
- `app/code/WeShop/Order/extends/module/Weline_Framework/Query/OrderQueryProvider.php`
- `app/code/WeShop/Order/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`
- `app/code/WeShop/Order/view/hooks/Weline_Theme/frontend/layouts/account/content-after.phtml`
- `app/code/WeShop/Order/view/templates/Frontend/Order/OrderList/index.phtml`
- `app/code/WeShop/Order/view/templates/Frontend/Order/View/index.phtml`
- `app/design/WeShop/default/frontend/pages/order/index.phtml`
- `app/design/WeShop/default/frontend/pages/order/order-list.phtml`
- `app/design/WeShop/default/frontend/pages/order/view.phtml`
- Latest uncommitted Invoice slice:
- `app/code/WeShop/Invoice/Api/Rest/V1/Invoice.php`
- `app/code/WeShop/Invoice/Controller/Backend/Invoice/Index.php`
- `app/code/WeShop/Invoice/Controller/Backend/Invoice/Issue.php`
- `app/code/WeShop/Invoice/Controller/Backend/Invoice/View.php`
- `app/code/WeShop/Invoice/Controller/Frontend/Invoice/Index.php`
- `app/code/WeShop/Invoice/Controller/Index.php`
- `app/code/WeShop/Invoice/Service/InvoiceAdminPageDataService.php`
- `app/code/WeShop/Invoice/Service/InvoicePageDataService.php`
- `app/code/WeShop/Invoice/Service/InvoiceService.php`
- `app/code/WeShop/Invoice/Test/Unit/Api/Rest/V1/InvoiceTest.php`
- `app/code/WeShop/Invoice/Test/Unit/Controller/Backend/Invoice/IndexTest.php`
- `app/code/WeShop/Invoice/Test/Unit/Controller/Backend/Invoice/IssueTest.php`
- `app/code/WeShop/Invoice/Test/Unit/Controller/Backend/Invoice/ViewTest.php`
- `app/code/WeShop/Invoice/Test/Unit/Controller/Frontend/Invoice/IndexTest.php`
- `app/code/WeShop/Invoice/Test/Unit/Controller/IndexTest.php`
- `app/code/WeShop/Invoice/Test/Unit/Service/InvoiceAdminPageDataServiceTest.php`
- `app/code/WeShop/Invoice/Test/Unit/Service/InvoicePageDataServiceTest.php`
- `app/code/WeShop/Invoice/Test/Unit/View/DefaultThemeInvoicePageTest.php`
- `app/code/WeShop/Invoice/etc/backend/menu.xml`
- `app/code/WeShop/Invoice/view/backend/templates/invoice/index.phtml`
- `app/code/WeShop/Invoice/view/backend/templates/invoice/view/index.phtml`
- `app/code/WeShop/Invoice/view/templates/Frontend/Invoice/Index/index.phtml`
- `app/design/WeShop/default/frontend/pages/invoice/index.phtml`
- `tests/e2e/specs/frontend/weshop-invoice.spec.js`

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
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Analytics/Test/Unit --colors=never`
- `php -l app/code/WeShop/Analytics/Service/AnalyticsConfigService.php`
- `php -l app/code/WeShop/Analytics/Service/AnalyticsAdminPageDataService.php`
- `php -l app/code/WeShop/Analytics/Service/AnalyticsSnippetService.php`
- `php -l app/code/WeShop/Analytics/extends/module/Weline_Framework/Query/AnalyticsQueryProvider.php`
- `php -l app/code/WeShop/Analytics/Controller/Backend/Analytics/Index.php`
- `php -l app/code/WeShop/Analytics/Controller/Backend/Analytics/Save.php`
- `php -l app/code/WeShop/Analytics/Service/PixelDispatcher.php`
- `php -l app/code/WeShop/Analytics/Provider/GoogleAnalytics.php`
- `php -l app/code/WeShop/Analytics/Provider/FacebookPixel.php`
- `php tests/e2e/framework/preflight-refresh.php`
- `php bin/w server:reload --no-wait`
- `node tests/e2e/start.js tests/e2e/specs/backend/weshop-analytics.spec.js`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Order/Test/Unit --colors=never`
- `php -l app/code/WeShop/Order/Api/Rest/V1/Order.php`
- `php -l app/code/WeShop/Order/Controller/Frontend/Order/OrderList.php`
- `php -l app/code/WeShop/Order/Controller/Frontend/Order/View.php`
- `php -l app/code/WeShop/Order/Service/OrderListPageDataService.php`
- `php -l app/code/WeShop/Order/Service/OrderDetailPageDataService.php`
- `php tests/e2e/framework/preflight-refresh.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Invoice/Test/Unit --colors=never`
- `php -l app/code/WeShop/Invoice/Controller/Index.php`
- `php -l app/code/WeShop/Invoice/Test/Unit/Controller/IndexTest.php`
- `php tests/e2e/framework/preflight-refresh.php`
- `php bin/w reflection:compile`
- `php bin/w server:reload --no-wait`
- `curl.exe -k -I https://127.0.0.1:9982/invoice`
- `node tests/e2e/start.js tests/e2e/specs/frontend/weshop-invoice.spec.js`
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
- `WeShop_Order` storefront pages now have strong unit/default-theme coverage, but there is still no dedicated browser e2e for authenticated order list/detail/cancel/retry-payment flows.
- `app/code/WeShop/Order/i18n/*.csv` remain intentionally outside the commit boundary because they are still mixed with broader parser-generated drift; translation cleanup must be handled in a separate bounded pass.
- `app/code/WeShop/Invoice/i18n/*.csv` are also intentionally outside the current commit boundary for the same reason; parser-generated translation drift must be cleaned in a separate pass instead of mixed into the module checkpoint.

## Next Resume Step

- White-list commit the validated `WeShop_Invoice` slice, then choose the next bounded module wave candidate and continue with the same white-list/TDD flow.

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
- The Analytics slice is validated locally but not yet white-list committed.
- Checkout layout variants `checkout_page_1..4` still need the same async submit tightening if we want every alternate layout to be functionally equivalent, not only host-compatible.
- `ThemeCompatibilityService` still does not separately model the page-level checkout hosts (`payment-methods` / `payment-details`) for warning generation.
- Parallel audits are in flight for `WeShop_Analytics` and `WeShop_Order`; whichever returns the cleaner bounded slice will be next after the Search checkpoint commit.
- `php bin/w setup:upgrade -m WeShop_Analytics --yes` is not yet a trustworthy green signal because a repo-wide unrelated `Weline\SystemConfig\Model\SystemConfig.php` parse error still surfaces during the upgrade pipeline after the Analytics registry work.

## Update 2026-03-24 20:20

- The Search slice was materially strengthened beyond the earlier checkpoint:
- fixed the live `/search` fallback chain by correcting `WeShop_Product` query-builder usage for keyword OR search, price filters, and suggestions
- fixed `WeShop_Search\Model\SearchHistory::recordSearch()` so keyword history updates no longer drop the `keyword` field or accidentally insert broken rows
- improved Search storefront output so the heading placeholder renders correctly and duplicate popular keywords are collapsed
- rewrote the Search backend engine form so OpenSearch is configurable, hidden panels disable their inputs, and connection testing is available per engine
- live `https://127.0.0.1:9982/search?q=bag` now returns `200 OK` and renders the expected search page instead of the earlier `500`

### Additional Changed Files

- `app/code/WeShop/Product/Extends/module/Weline_Framework/Query/ProductQueryProvider.php`
- `app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php`
- `app/code/WeShop/Search/Model/SearchHistory.php`
- `app/code/WeShop/Search/Service/SearchPageDataService.php`
- `app/code/WeShop/Search/Test/Unit/Model/SearchHistoryTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchPageDataServiceTest.php`
- `app/code/WeShop/Search/Test/Unit/View/BackendSearchEngineFormContractTest.php`
- `app/code/WeShop/Search/view/templates/Backend/Engine/form.phtml`
- `app/code/WeShop/Search/view/templates/Frontend/Search/index.phtml`
- `app/code/WeShop/Search/i18n/en_US.csv`
- `app/code/WeShop/Search/i18n/zh_Hans_CN.csv`
- `app/design/WeShop/default/frontend/pages/search/index.phtml`

### Additional Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Search/Test/Unit app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php --colors=never`
- `php -l app/code/WeShop/Search/view/templates/Backend/Engine/form.phtml`
- `php tests/e2e/framework/preflight-refresh.php`
- `curl.exe -k -I "https://127.0.0.1:9982/search?q=bag"`
- `curl.exe -k "https://127.0.0.1:9982/search?q=bag" --max-time 20`

### Refined Next Steps

- White-list commit the validated `Payment/Checkout` slice.
- White-list commit the validated `Search + Product search fallback` slice.
- Use the finished sidecar audits to choose the next bounded module between `ThemeCompatibilityService` checkout host coverage and the stronger of `WeShop_Analytics` / `WeShop_Order`.

## Update 2026-03-24 21:35

- Corrected checkpoint state: the `Payment/Checkout` slice has already been cleanly committed as `d727ba11` (`feat(weshop): harden checkout payment flow`), so the next code checkpoint is now only the Search/Product slice.
- Reconciled the Search backend engine form to the contract-guarded version after discovering the working tree still had an older panel-toggle implementation on disk.
- The final Search/Product checkpoint is validated locally and ready for white-list commit.

### Additional Changed Files

- `app/code/WeShop/Product/Extends/module/Weline_Framework/Query/ProductQueryProvider.php`
- `app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php`
- `app/code/WeShop/Search/Controller/Backend/Engine/Index.php`
- `app/code/WeShop/Search/Engine/OpenSearchEngine.php`
- `app/code/WeShop/Search/Model/SearchEngineConfig.php`
- `app/code/WeShop/Search/Service/SearchEngineEnvConfig.php`
- `app/code/WeShop/Search/Service/SearchEngineFactory.php`
- `app/code/WeShop/Search/Service/SearchPageDataService.php`
- `app/code/WeShop/Search/Setup/InstallData.php`
- `app/code/WeShop/Search/Test/Unit/Engine/OpenSearchEngineTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchEngineEnvConfigTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchPageDataServiceTest.php`
- `app/code/WeShop/Search/Test/Unit/View/BackendSearchEngineFormContractTest.php`
- `app/code/WeShop/Search/etc/env.php`
- `app/code/WeShop/Search/view/templates/Backend/Engine/form.phtml`

### Additional Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Search/Test/Unit app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php --colors=never`
- `php -l app/code/WeShop/Search/view/templates/Backend/Engine/form.phtml`
- `php tests/e2e/framework/preflight-refresh.php`
- `curl.exe -k -I "https://127.0.0.1:9982/search?q=bag"`
- `curl.exe -k "https://127.0.0.1:9982/search/suggest?q=bag&limit=3"`
- `node tests/e2e/start.js tests/e2e/specs/frontend/weshop-search.spec.js`

### Updated Risks / Next Steps

- `app/code/WeShop/Search/README.md` and `app/code/WeShop/Search/env/*` remain intentionally outside the commit boundary until they are validated as a coherent install/runtime story.
- `ThemeCompatibilityService` still needs the page-level checkout host coverage follow-up identified earlier.
- The next implementation slice should come from the completed `WeShop_Analytics` / `WeShop_Order` audits once the Search/Product checkpoint is committed.

## Update 2026-03-24 21:59

- `WeShop_Search` + product fallback is now committed as `01a3a545` (`feat(weshop): stabilize search engine configuration`).
- The checkout-theme warning gap identified by the earlier audit is now fixed and committed as `b78557b1` (`fix(weshop): scan page and layout theme hosts`).
- Active focus has moved to the next module wave: local integration will choose between the in-flight `Order` and `Analytics` worker slices once their code is ready.

### Additional Changed Files

- `app/code/WeShop/Base/Service/ThemeCompatibilityService.php`
- `app/code/WeShop/Base/Test/Unit/Service/ThemeCompatibilityServiceTest.php`

### Additional Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Base/Test/Unit/Service/ThemeCompatibilityServiceTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Base/Test/Unit/Plugin/Theme/ThemeEditorCompatibilityPluginTest.php --colors=never`
- `php -l app/code/WeShop/Base/Service/ThemeCompatibilityService.php`
- `php -l app/code/WeShop/Base/etc/theme-compatibility.php`
- `php tests/e2e/framework/preflight-refresh.php`

### Updated Risks / Next Steps

- `app/code/WeShop/Search/README.md`, `app/code/WeShop/Search/env/*`, and multiple unrelated i18n files remain outside the commit boundary and must stay excluded until they are independently validated.
- The next concrete delivery should be a module slice, not more framework-adjacent cleanup; the top two candidates remain `WeShop_Order` storefront/API closure and `WeShop_Analytics` event/admin hardening.

## Update 2026-03-25 02:45

- The pending `Order + Checkout` clean-route hardening is now validated end to end.
- `Order` clean frontend aliases had to move under `WeShop_Frontend` instead of living only inside `WeShop_Order`; otherwise the framework registered them as `weshop_order/order/*` rather than the intended shared `/weshop/order/*`.
- `/weshop/order/list`, `/weshop/order/view`, `/weshop/order/retry-payment`, `/weshop/order/cancel`, `/checkout`, `/checkout/place-order`, and `/checkout/success` all now have verified non-404 runtime behavior, with guest requests landing on login/cart guards instead of fatal errors.
- The `Cancel` clean-route alias regression is fixed: GET no longer throws `Call to undefined method ... Cancel::index()`, and the POST aliases now delegate to the real cancel flow.
- Focused regression coverage now protects both the PHP alias layer and the storefront browser behavior.

### Additional Changed Files

- `app/code/WeShop/Checkout/Controller/Index.php`
- `app/code/WeShop/Checkout/Controller/PlaceOrder.php`
- `app/code/WeShop/Checkout/Controller/Success.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Index.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/PlaceOrder.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Success.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/CleanRouteAliasControllersTest.php`
- `app/code/WeShop/Frontend/Controller/Order/Cancel.php`
- `app/code/WeShop/Frontend/Controller/Order/List/Index.php`
- `app/code/WeShop/Frontend/Controller/Order/RetryPayment.php`
- `app/code/WeShop/Frontend/Controller/Order/View.php`
- `app/code/WeShop/Frontend/Test/Unit/Controller/OrderCleanRouteControllersTest.php`
- `app/code/WeShop/Order/Controller/Frontend/Order/OrderList.php`
- `app/code/WeShop/Order/Controller/Frontend/Order/View.php`
- `app/code/WeShop/Order/Controller/Order/Cancel.php`
- `app/code/WeShop/Order/Controller/Order/List/Index.php`
- `app/code/WeShop/Order/Controller/Order/RetryPayment.php`
- `app/code/WeShop/Order/Controller/Order/View.php`
- `app/code/WeShop/Order/etc/env.php`
- `tests/e2e/specs/frontend/weshop-order-checkout-clean-routes.spec.js`

### Additional Verification

- `php -l app/code/WeShop/Frontend/Controller/Order/Cancel.php`
- `php -l app/code/WeShop/Frontend/Controller/Order/RetryPayment.php`
- `php -l app/code/WeShop/Frontend/Controller/Order/View.php`
- `php -l app/code/WeShop/Frontend/Controller/Order/List/Index.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Checkout/Test/Unit --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Order/Test/Unit --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Frontend/Test/Unit/Controller/OrderCleanRouteControllersTest.php --colors=never`
- `php bin/w reflection:compile`
- `php tests/e2e/framework/preflight-refresh.php`
- `php bin/w server:reload --no-wait`
- `php bin/w route:list | Select-String -Pattern 'weshop/order/'`
- `curl.exe -k -i https://127.0.0.1:9982/weshop/order/list`
- `curl.exe -k -i "https://127.0.0.1:9982/weshop/order/view?id=1"`
- `curl.exe -k -i "https://127.0.0.1:9982/weshop/order/retry-payment?order_id=1"`
- `curl.exe -k -i https://127.0.0.1:9982/weshop/order/cancel`
- `curl.exe -k -i https://127.0.0.1:9982/checkout`
- `curl.exe -k -i https://127.0.0.1:9982/checkout/place-order`
- `curl.exe -k -i https://127.0.0.1:9982/checkout/success`
- `node tests/e2e/start.js tests/e2e/specs/frontend/weshop-order-checkout-clean-routes.spec.js`

### Updated Risks / Next Steps

- The new storefront smoke proves clean-route availability and guest guards, but there is still no reusable storefront `loginAsCustomer` helper or seeded customer/cart/order fixture for a full authenticated checkout-place-order-success-order-list browser chain.
- `WeShop_Order` still has mixed `i18n/*.csv` drift outside this slice and should stay excluded from any white-list commit until those generated files are reconciled independently.
- The next production slice should return to a bounded business module rather than more route plumbing; `WeShop_Invoice` is the nearest commit-ready candidate, followed by the broader unfinished modules such as `Analytics`, `B2B`, `Payment provider expansion`, and the remaining customer/account feature wave.

## Update 2026-03-25 03:41

- The pending `WeShop_Subscription` slice is now validation-complete and ready for a white-list checkpoint commit.
- Clean storefront aliases now exist for `/subscription`, `/subscription/view`, `/subscription/pause`, and `/subscription/cancel`, so the module no longer depends on legacy `Frontend/Subscription/*` paths to reach its main guest/login guards.
- The Subscription customer-account order card now reads host data (`subscription_count`) instead of creating its own session/service lookups, and `AccountDashboardDataService` now provides that count.
- Backend IA is now aligned with the planned customer grouping: the Subscription menu root is under `Weline_Backend::customer_group`, and the backend list/detail/plan controllers are thin service-backed adapters.
- The default-theme subscription page contract is now explicit in `theme-compatibility.php`, so missing `WeShop_Subscription` hook hosts can be detected by the existing compatibility warning pipeline.

### Additional Changed Files

- `app/code/WeShop/Base/etc/theme-compatibility.php`
- `app/code/WeShop/Customer/Service/AccountDashboardDataService.php`
- `app/code/WeShop/Customer/Test/Unit/Service/AccountDashboardDataServiceTest.php`
- `app/code/WeShop/Subscription/Controller/Backend/Plan/Index.php`
- `app/code/WeShop/Subscription/Controller/Backend/Subscription/Index.php`
- `app/code/WeShop/Subscription/Controller/Backend/Subscription/View.php`
- `app/code/WeShop/Subscription/Controller/Cancel.php`
- `app/code/WeShop/Subscription/Controller/Index.php`
- `app/code/WeShop/Subscription/Controller/Pause.php`
- `app/code/WeShop/Subscription/Controller/View.php`
- `app/code/WeShop/Subscription/Service/SubscriptionAdminPageDataService.php`
- `app/code/WeShop/Subscription/Service/SubscriptionDetailPageDataService.php`
- `app/code/WeShop/Subscription/Service/SubscriptionPlanAdminPageDataService.php`
- `app/code/WeShop/Subscription/Test/Unit/Controller/CleanRouteAliasControllersTest.php`
- `app/code/WeShop/Subscription/Test/Unit/Service/SubscriptionAdminPageDataServiceTest.php`
- `app/code/WeShop/Subscription/Test/Unit/Service/SubscriptionDetailPageDataServiceTest.php`
- `app/code/WeShop/Subscription/Test/Unit/Service/SubscriptionPlanAdminPageDataServiceTest.php`
- `app/code/WeShop/Subscription/Test/Unit/View/AccountOrderCardHookTemplateTest.php`
- `app/code/WeShop/Subscription/doc/hook/frontend/account/orders-cards.md`
- `app/code/WeShop/Subscription/etc/backend/menu.xml`
- `app/code/WeShop/Subscription/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`
- `app/design/WeShop/default/frontend/pages/subscription/index.phtml`
- `tests/e2e/specs/frontend/weshop-subscription.spec.js`

### Additional Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Subscription/Test/Unit --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Customer/Test/Unit/Service/AccountDashboardDataServiceTest.php --colors=never`
- `php tests/e2e/framework/preflight-refresh.php`
- `php bin/w reflection:compile`
- `php bin/w server:reload --no-wait`
- `curl.exe -k -o NUL -s -w "%{http_code} %{redirect_url}" https://127.0.0.1:9982/subscription`
- `curl.exe -k -o NUL -s -w "%{http_code} %{redirect_url}" https://127.0.0.1:9982/subscription/view`
- `cd tests/e2e && node start.js specs/frontend/weshop-subscription.spec.js`

### Updated Risks / Next Steps

- `app/code/WeShop/Subscription/i18n/*.csv` remains dirty from broader parser-generated drift and must stay outside the checkpoint commit.
- The worker-created `app/code/WeShop/Subscription/Test/Unit/Controller/IndexTest.php` and `app/code/WeShop/Subscription/Test/Unit/Controller/ViewTest.php` are redundant with the broader clean-route alias test; they can stay out of the commit boundary without reducing coverage.
- The next bounded module after this checkpoint should return to a business domain slice such as `RMA`, `Compliance`, or the account-center host completion wave rather than expanding the Subscription scope further.
