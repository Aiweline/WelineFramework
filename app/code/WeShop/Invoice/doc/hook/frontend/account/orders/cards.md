# WeShop_Customer::frontend::account::orders::cards (Invoice)

Use this hook to inject invoice-related post-purchase entry cards into the customer account center.

## Placement

- Template slot: `WeShop_Customer::frontend::account::orders::cards`
- Host template: `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- Recommended target: order and after-sales follow-up cards

## Default implementation

- `app/code/WeShop/Invoice/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`

## Data contract

This hook can read optional host data:

- `invoice_count`
- `invoice_pending_count`

Templates must still render safely when these values are absent.
