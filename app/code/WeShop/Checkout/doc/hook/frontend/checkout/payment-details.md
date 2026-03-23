# Checkout Payment Details

- Hook: `WeShop_Checkout::frontend::partials::checkout::payment-details`
- Purpose: show the active payment method guidance panel after the payment method radios.
- Data:
  - `payment_methods`
  - `cart_summary`
- Theme guidance: when a child theme customizes checkout, preserve this host or provide an equivalent slot for payment guidance content.
