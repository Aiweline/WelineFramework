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
- New frontend-facing slices should avoid adding extra `Frontend` path layers when new route files are introduced; existing legacy controllers can be refactored in place.
- `default` theme checkout, account center, recommendations, and related storefront layouts should prefer rendering controller/page `content` through shared layout shells instead of duplicating module business UI in the layout file.
- Storefront account-center slices should aggregate `orders + wishlist + recently viewed + guess-you-may-like` through dedicated services, not inline controller queries.
- `RecentlyViewed` should own a clean storefront route (`/recently-viewed`), a removable history page, and a logged-in product-view recorder instead of relying on account-center-only summaries.
- `default` theme account center must keep the security-card hook and discovery-card hook so Google login, 2FA, membership, wishlist, and recommendation modules can inject safely.
- Account-center quick links should prefer clean storefront routes such as `wishlist` and `recently-viewed` whenever those slices already provide bridge controllers.
- `Compare` should now follow the same storefront slice pattern as `Wishlist` and `RecentlyViewed`: clean route, guest-safe add/remove responses, dedicated page-data service, and account-center discovery/quick-link entry.
- `default` theme category cards need reusable product-card hooks and action slots so compare, affiliate, membership, and badge-like modules can enter the card without editing theme modules.
- When a theme layout is missing required hooks or slots, WeShop should patch the `default` theme where possible and later surface compatibility warnings rather than coupling modules to one theme implementation.
- Payment wave priority is:
  - `manual_transfer`
  - `cash_on_delivery`
  - `paypal`
  - `alipay` / `wechatpay` scaffolded but disabled by default until gateway completion
