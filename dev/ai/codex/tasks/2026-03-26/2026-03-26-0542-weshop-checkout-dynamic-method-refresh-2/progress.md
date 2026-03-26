# Progress - WeShop checkout dynamic method refresh

- 2026-03-26 13:24 Created the task workspace for the checkout dynamic method refresh slice.
- 2026-03-26 13:31 Audited the current checkout stack and confirmed there was no reusable `/checkout/methods`-style endpoint; only initial page-data build and final place-order existed.
- 2026-03-26 13:46 Added address-aware dynamic method payload support to `CheckoutPageDataService`, plus a new clean-route JSON controller pair for `/checkout/methods`.
- 2026-03-26 13:58 Updated the default-theme checkout page to keep stable host containers for shipping/payment sections and refresh those sections via AJAX when saved-address or inline address inputs change.
- 2026-03-26 14:10 Added focused controller/service/view coverage and extended the guest clean-route browser smoke to hit the new `/checkout/methods` endpoint.
- 2026-03-26 14:13 Revalidated syntax, focused PHPUnit, and `specs/frontend/weshop-order-checkout-clean-routes.spec.js` against `https://127.0.0.1:9982`.

- 2026-03-26 05:42 Created the task workspace.
