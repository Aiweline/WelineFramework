# Task: WeShop order summary persistence

- Task ID: 2026-03-26-0402-weshop-order-summary-persistence
- Started: 2026-03-26 04:02
- Status: completed
- Owner: Codex
- Source: Continue after effective-price filter closure; persist checkout subtotal/shipping/discount/tax into order lifecycle so success and retry flows remain correct without transient checkout context.

## Goal

- Persist checkout-computed `subtotal`, `shipping`, `discount`, and `tax` into the order record.
- Keep order success and retry-payment flows correct even when transient checkout/session context
  is missing.
- Close the immediate tax/order lifecycle gap without widening into unrelated checkout UI work.

## Scope

- In scope:
- `app/code/WeShop/Order/Model/Order.php`
- `app/code/WeShop/Order/Service/OrderService.php`
- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/OrderSuccessPageDataService.php`
- Focused unit coverage for checkout/order/success summary behavior
- Targeted schema upgrade for `WeShop_Order`
- Focused checkout/order storefront smoke on `9982`
- Out of scope:
- New checkout UI flows or full logged-in browser automation for order success
- Shipping quote UX improvements and saved-address shipping-method refresh
- Theme-module changes

## Constraints

- Runtime/browser verification must target `9982`.
- Schema changes must go through model attributes plus `php bin/w setup:upgrade WeShop_Order --yes`.
- Keep mutable state in this task workspace only; do not use `ACTIVE.md`.
- The worktree is already dirty; do not revert unrelated user or parallel-agent changes.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`

## Related Files

- `app/code/WeShop/Order/Model/Order.php`
- `app/code/WeShop/Order/Service/OrderService.php`
- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/OrderSuccessPageDataService.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/OrderSuccessPageDataServiceTest.php`
- `app/code/WeShop/Order/Test/Unit/Service/OrderServiceTest.php`
- `tests/e2e/specs/frontend/weshop-order-checkout-clean-routes.spec.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
