# Task: weshop-payment-checkout-dynamic-methods-slice

- Task ID: 2026-03-23-0741-weshop-payment-checkout-dynamic-methods-slice
- Started: 2026-03-23 07:41
- Status: completed
- Owner: Codex
- Source: Continue WeShop module-by-module completion with dynamic checkout payment support

## Goal

- Complete the WeShop `Payment` + `Checkout` slice so checkout can render dynamic payment methods via `w_query`, place orders through a real service flow, and work in the `default` theme layout shell without modifying `WeShop_Theme` or `Weline_Theme`.

## Scope

- In scope:
- `WeShop_Payment` payment method registry, provider enablement metadata, and `w_query` provider
- `WeShop_Checkout` checkout page data preparation and real `placeOrder()` workflow
- `default` theme checkout page + checkout layout variants rendering controller `content` first so dynamic payment UI works in every checkout variant
- Task and plan docs for this payment/checkout slice
- Out of scope:
- Full PayPal/Alipay/WeChat live gateway integration
- Theme editor compatibility warning system
- Other commerce modules outside the payment/checkout slice

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme`
- Frontend cross-module reads must use `w_query`
- Keep controllers thin and move page orchestration into services
- Leave extension points for international storefront rollout and more payment providers
- Do not rely on shared mutable `dev/ai/codex/ACTIVE.md`

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/WeShop国际电商/admin-ia.md`

## Related Files

- `app/code/WeShop/Payment/Service/PaymentService.php`
- `app/code/WeShop/Payment/extends/module/Weline_Framework/Query/PaymentQueryProvider.php`
- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Index.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/PlaceOrder.php`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_1.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_2.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_3.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_4.phtml`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
