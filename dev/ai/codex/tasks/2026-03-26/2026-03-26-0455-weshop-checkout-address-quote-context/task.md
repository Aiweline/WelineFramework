# Task: WeShop checkout address quote context

- Task ID: 2026-03-26-0455-weshop-checkout-address-quote-context
- Started: 2026-03-26 04:55
- Status: in_progress
- Owner: Codex
- Source: Continue after order-summary persistence; resolve saved-address shipping/tax context into checkout page-data and checkout service so shipping methods and quote calculations use real address data.

## Goal

- Make checkout resolve saved customer addresses into real shipping/tax context.
- Ensure both checkout page-data and place-order summary calculations use the same address-derived country/region inputs.

## Scope

- In scope:
  - `WeShop_Checkout::CheckoutService` saved-address normalization for quote calculations
  - `WeShop_Checkout::CheckoutPageDataService` initial shipping context for checkout methods
  - Focused unit + storefront verification for the affected checkout flow
- Out of scope:
  - Theme-module changes
  - New shipping/tax business rules beyond context propagation
  - Wider account/address UX changes

## Constraints

- Runtime verification must target `9982`
- Do not modify `WeShop_Theme` or `Weline_Theme`
- Keep mutable task state inside this workspace only

## Related Plans

- 2026-03-26 WeShop international-commerce closure waves

## Related Files

- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutServiceTest.php`
- `app/code/WeShop/Checkout/Test/Unit/Service/CheckoutPageDataServiceTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
