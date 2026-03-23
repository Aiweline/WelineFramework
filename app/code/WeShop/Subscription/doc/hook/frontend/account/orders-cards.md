# WeShop_Customer::frontend::account::orders::cards (Subscription implementation)

- Area: `frontend`
- Purpose: expose subscription management entry inside the customer account order/after-sales card area.
- Implementation template: `app/code/WeShop/Subscription/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`

## Behavior

- Renders a subscription summary card with total subscription count.
- Links directly to clean route `subscription`.
- Keeps compatibility with shared customer account host without modifying host theme templates.
