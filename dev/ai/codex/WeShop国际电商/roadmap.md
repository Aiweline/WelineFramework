# Roadmap

## Wave 1

- Unified auth actor model and challenge flow
- GoogleAuth module foundation
- Storefront customer password login, registration, and password reset hardening
- Unified auth API entrypoints

## Wave 2

- Backend login extension point
- Backend Google binding and login
- 2FA orchestration for storefront, backend, and API token issuance
- Legacy API compatibility proxies

## Wave 3

- Theme compatibility checks and warnings
- Backend IA and menu-resource completion
- Core commerce transaction-chain completion
- Test-matrix expansion and CI hardening

## Current Execution Notes

- Checkout payment methods must be provided through `w_query`, not hardcoded in controllers or theme layouts.
- Checkout order-summary calculation must stay on the real `shipping.calculateShipping` and `tax.calculateTax` path instead of zero-value cart placeholders.
- `default` theme cart/checkout summary rows should keep fine-grained hook hosts so later `Price/Tax/Store` slices can extend without replacing the whole block.
- Shipping carrier providers must satisfy one concrete contract before DHL/FedEx can be treated as production-ready.
- `Filters` now uses clean storefront AJAX routes (`filters/filter`, `filters/options`, `filters/counts`) instead of legacy `/weshop/.../frontend/...` paths.
- Category pages should source filter payloads from a dedicated service so themes render against assigned data instead of recomputing filter state inline.
- New frontend-facing slices should avoid adding extra `Frontend` path layers when new route files are introduced; existing legacy controllers can be refactored in place.
- `default` theme checkout, account center, recommendations, and related storefront layouts should prefer rendering controller/page `content` through shared layout shells instead of duplicating business UI in layout files.
- Storefront account-center slices should aggregate `orders + wishlist + recently viewed + guess-you-may-like` through dedicated services, not inline controller queries.
- `default` theme account center must keep the security-card hook and discovery-card hook so Google login, 2FA, membership, wishlist, and recommendation modules can inject safely.
- `default` product detail should keep first-class tab slots for both `Review` and `QA`, with module-owned hook rendering instead of hardcoding those modules into theme packages.
- `default` account center should keep a dedicated order/after-sales card slot so `RMA`, `Invoice`, `Subscription`, and similar modules can inject follow-up actions without editing theme packages.
- When a theme layout is missing required hooks or slots, WeShop should patch the `default` theme where possible and later surface compatibility warnings rather than coupling modules to one theme implementation.
- Payment wave priority is:
  - `manual_transfer`
  - `cash_on_delivery`
  - `paypal`
  - `alipay` / `wechatpay` scaffolded but disabled by default until gateway completion
