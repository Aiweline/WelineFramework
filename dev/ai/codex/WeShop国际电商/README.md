# WeShop International Commerce

This directory is the planning anchor for the full WeShop production-closure effort.

Current priority order:

1. Auth and unified API auth foundation
2. Frontend/backend Google login plus 2FA orchestration
3. `default` theme compatibility and missing slot/hook warnings
4. Backend IA, menu resources, and module management closure
5. Test closure and continuous expansion

Current closed slices:

- Checkout/payment methods are rendered through `w_query()` driven hooks instead of hardcoded theme logic.
- Checkout order summary now derives shipping and tax from real query-provider calls:
  - `w_query('shipping', 'calculateShipping', ...)`
  - `w_query('tax', 'calculateTax', ...)`
- `default` theme cart/checkout summary areas now expose finer-grained hook hosts for subtotal/shipping/tax/discount/grand-total extensions.
- Shipping carrier providers now satisfy the same service contract instead of diverging from `ShippingProviderInterface`.
- `Filters` category integration now closes through a dedicated page-data service:
  - category pages always assign `filters`, `applied_filters`, `clear_all_url`, and `filtered_product_ids`
  - `WeShop_Filters::frontend::partials::filters::container` now has a real implementation
  - storefront filter AJAX now uses clean routes `filters/filter`, `filters/options`, and `filters/counts`

API path notes:

- WeShop frontend REST APIs follow `/{rest_frontend_prefix}/{module_router}/rest/v1/...`
- In the current environment the externally reachable auth contract is:
  - `https://127.0.0.1:9982/api123/api/rest/v1/weshop/auth/*`
