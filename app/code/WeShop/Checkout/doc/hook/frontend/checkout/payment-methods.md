# Checkout Payment Methods

- Hook: `WeShop_Checkout::frontend::partials::checkout::payment-methods`
- Purpose: render the checkout payment method radios from normalized `payment_methods` page data.
- Data:
  - `payment_methods`
  - `customer`
  - `cart_summary`
- Theme guidance: keep this host in checkout pages so payment modules remain injectable without editing theme core layouts.
