# Task: WeShop checkout dynamic method refresh

- Task ID: 2026-03-26-0542-weshop-checkout-dynamic-method-refresh-2
- Started: 2026-03-26 05:42
- Status: in_progress
- Owner: Codex
- Source: Continue after checkout summary/address fixes: add dynamic shipping/payment refresh when checkout shipping address changes on the default theme.

## Goal

- Add an address-aware checkout methods refresh path so the default-theme checkout can reload shipping and payment methods when the shipping address selection changes.
- Reuse existing checkout page-data and provider contracts instead of introducing a parallel rules path.

## Scope

- In scope:
  - `CheckoutPageDataService` dynamic method payload support
  - clean route `/checkout/methods` controller alias and JSON contract
  - `default` theme checkout JS refresh behavior
  - focused unit and storefront verification
- Out of scope:
  - logged-in multi-address end-to-end account fixture setup
  - recalculating the visible order-summary totals on the checkout page
  - changes to `WeShop_Theme` or `Weline_Theme`

## Constraints

- Runtime verification must target `9982`
- Controller stays thin; shipping/payment filtering continues to come from existing services/query providers
- Keep mutable task state inside this workspace only

## Related Plans

- 2026-03-26 checkout closure wave after address-context alignment

## Related Files

- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Controller/Frontend/Checkout/Methods.php`
- `app/code/WeShop/Checkout/Controller/Methods.php`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/code/WeShop/Checkout/Test/Unit/Controller/Frontend/Checkout/MethodsTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php`
- `tests/e2e/specs/frontend/weshop-order-checkout-clean-routes.spec.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
