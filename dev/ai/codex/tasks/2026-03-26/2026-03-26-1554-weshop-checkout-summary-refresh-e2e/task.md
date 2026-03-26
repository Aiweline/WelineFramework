# Task: WeShop checkout summary refresh browser coverage

- Task ID: 2026-03-26-1554-weshop-checkout-summary-refresh-e2e
- Started: 2026-03-26 15:54
- Status: completed
- Owner: Codex
- Source: Continue the checkout lane after the quote preview refresh commit by adding browser coverage for the live summary DOM update on the default checkout page.

## Goal

- Add a logged-in storefront browser test that proves checkout summary values update after a checkout methods refresh.
- Keep the test on the `default` storefront flow and runtime `9982`.

## Scope

- In scope:
  - storefront registration/login bootstrap through the public customer flow
  - cart seeding for a logged-in customer
  - checkout summary DOM update assertion after `/checkout/methods` refresh
- Out of scope:
  - backend changes
  - theme-module changes
  - full payment completion

## Constraints

- Use runtime `9982`
- Avoid relying on theme-module internals
- Prefer a stable browser contract over brittle environment-dependent shipping quotes

## Related Files

- `tests/e2e/specs/frontend/weshop-checkout-summary-refresh.spec.js`
- `tests/e2e/framework/index.js`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`

## Resume

- Build the spec, validate it on `9982`, then decide whether to keep extending checkout e2e or move to the next module lane.
