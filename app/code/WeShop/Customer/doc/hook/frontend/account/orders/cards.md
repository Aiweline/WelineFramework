# WeShop_Customer::frontend::account::orders::cards

Use `WeShop_Customer::frontend::account::orders::cards` to inject order-adjacent storefront account cards such as returns, invoices, subscriptions, and other after-sales journeys.

## Placement

- Template: `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- Area: storefront customer account center
- Section: after the recent order snapshot so modules can surface next-step actions without editing theme packages

## Guidelines

- Prefer compact cards that summarize the module state and link to the module-owned storefront page.
- Reuse existing WeShop account layout styling patterns so injected cards remain visually consistent.
- Do not assume a specific theme beyond the hook slot itself; modules should still degrade gracefully when custom themes remove the slot.
