# Checkout Layout Payment Content

- Hook: `WeShop_Checkout::frontend::layouts::checkout::payment-content`
- Purpose: render a reusable payment section for default-theme checkout layout variants.
- Data:
  - `payment_methods`
  - `cart_summary`
- Theme guidance: prefer extending the base checkout layout and keep this host available so payment content can still be injected across theme variants.
