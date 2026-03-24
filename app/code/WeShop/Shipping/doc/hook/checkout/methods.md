# Checkout Shipping Slot

- Hook: `WeShop_Shipping::frontend::layouts::checkout::methods`
- Purpose: provide an extension slot in checkout shipping section for shipping modules to inject custom blocks (carrier notices, pickup selectors, quote widgets).
- Expected page data:
  - `shipping_methods`
- Theme guidance: keep this host when customizing checkout so shipping-related modules remain injectable.
