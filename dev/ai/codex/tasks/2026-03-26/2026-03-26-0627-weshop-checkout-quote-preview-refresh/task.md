# Task: WeShop checkout quote preview refresh

- Task ID: 2026-03-26-0627-weshop-checkout-quote-preview-refresh
- Started: 2026-03-26 06:27
- Status: completed
- Owner: Codex
- Source: Continue after dynamic method refresh: update checkout summary preview so shipping, tax, and grand total react to address and shipping method changes in default theme.

## Goal

- Extend the existing checkout methods refresh flow so the visible summary preview reacts to shipping address and shipping method changes.
- Keep the rules path inside `WeShop_Checkout` services and the `default` theme only, without touching theme modules.

## Scope

- In scope:
- reusing `/checkout/methods` to return a preview `cart_summary`
- exposing stable summary DOM anchors in the default checkout page
- focused unit coverage for service/controller/template contracts
- Out of scope:
- logged-in browser fixture creation
- theme-module changes
- cross-theme compatibility work beyond `default`

## Constraints

- Runtime verification targets `9982`
- Retry-payment flow must keep persisted order totals instead of recomputing a new quote
- Dynamic preview must use the effective selected shipping method after fallback/prioritization

## Related Plans

- 2026-03-26 checkout closure wave after method refresh

## Related Files

- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Methods.php`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/MethodsTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
