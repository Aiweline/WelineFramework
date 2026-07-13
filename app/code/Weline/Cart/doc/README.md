# Weline Cart

## Scope

`Weline_Cart` owns the public storefront cart API and runtime cart session.

- Public Query Provider: `w_query('cart', ...)`
- Browser API: `Weline.Api.resource('cart')`
- Core service: `Weline\Cart\Service\CartService`
- Session storage: `Weline\Cart\Session\CartSession`

## Item Resolution

Cart add/update can optionally resolve the requested item through a catalog-owned snapshot provider before mutating the cart.

- Public contract: `Weline\Cart\Api\CartItemSnapshotProviderInterface`.
- Provider path: `extends/module/Weline_Cart/CartItemSnapshotProvider/{ProviderName}.php`.
- Providers are discovered from the compiled Extends registry; after adding one, run `php bin/w setup:upgrade` before serving traffic.
- A provider returns `null` when it does not own the requested product. The first provider returning an array owns the snapshot.
- Sync add forms may pass source context with `source_app`, `source_module`, `business_module`, `business_code`, `business_name`, and `product_type`; Cart stores these fields on the item and Checkout can carry them into orders.

When the provider returns stock/status fields, Cart blocks unavailable items and caps requested quantity to available stock. Cart does not directly depend on product-module classes; a catalog provider may use its own service or published Query contract to build the snapshot.

## Returned Item Shape

Cart items include both a stable row key and product-facing fields:

- `cart_item_id`: stable cart row identifier, including option-aware rows.
- `item_id`: public item identifier retained for simple product rows.
- `product_id`, `name`, `sku`, `image`, `price`, `original_price`, `qty`, `quantity`, `row_total`.
- `selected_options` for option keys used in cart identity.
- `options` for display labels.
- `in_stock` and `available_stock` when known from the resolver.
- Optional source fields: `source_app`, `source_module`, `business_module`, `business_code`, `business_name`, and `product_type`.

## Boundaries

- Vendor/site modules must not register another public `cart` provider.
- Vendor/site modules can add storefront pages, hooks, recommendations, or fallback forms around Cart, but persistent cart mutation should remain in `Weline_Cart`.
- Payment, shipping, discount, and inventory reservation execution remain outside this module and should connect through their own published contracts.
