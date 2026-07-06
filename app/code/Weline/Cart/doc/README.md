# Weline Cart

## Scope

`Weline_Cart` owns the public storefront cart API and runtime cart session.

- Public Query Provider: `w_query('cart', ...)`
- Browser API: `Weline.Api.resource('cart')`
- Core service: `Weline\Cart\Service\CartService`
- Session storage: `Weline\Cart\Session\CartSession`

## Item Resolution

Cart add/update can optionally resolve the requested item through a catalog-style Query Provider before mutating the cart.

- Default resolver: `w_query('product', 'get', ['product_id' => ...], 'frontend')`
- Override per request with `item_provider` / `catalog_provider` and `item_operation` / `catalog_operation`.
- Disable resolver for trusted prebuilt payloads with `item_provider=none`.
- Resolver payloads may return the item in `product`, `item`, `data.product`, `data.item`, or `data`.
- Sync add forms may pass source context with `source_app`, `source_module`, `business_module`, `business_code`, `business_name`, and `product_type`; Cart stores these fields on the item and Checkout can carry them into orders.

When the resolver returns stock/status fields, Cart blocks unavailable items and caps requested quantity to available stock. Cart does not directly depend on any product module classes; cross-module item reads go through `w_query`.

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
