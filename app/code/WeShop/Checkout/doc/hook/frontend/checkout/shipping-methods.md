# Checkout Shipping Methods Hook

- Hook: `WeShop_Checkout::frontend::partials::checkout::shipping-methods`
- Purpose: render checkout shipping method radios from normalized `shipping_methods` page data.
- Expected template data:
  - `shipping_methods`
- Theme guidance: keep this host in checkout pages so shipping providers and checkout extensions can stay injectable without editing theme core layouts.
