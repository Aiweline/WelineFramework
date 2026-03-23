# WeShop_Customer::frontend::account::orders::cards (B2B)

Use this hook to surface the enterprise B2B card inside the customer account center.

## Placement

- Template slot: `WeShop_Customer::frontend::account::orders::cards`
- Host template: `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- Recommended target: order and after-sales discovery cards

## Default implementation

- `app/code/WeShop/B2B/view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`

## Data contract

The default card reads the company summary provided by `WeShop\B2B\Service\CompanyService`:

- `company_summary.total`
- `company_summary.primary_status`
- `company_summary.status_breakdown`

Templates should render safely when these values are absent.
