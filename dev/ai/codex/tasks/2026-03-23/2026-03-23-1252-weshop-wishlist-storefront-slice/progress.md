# Progress - weshop wishlist storefront slice

- 2026-03-23 12:52 Created the task workspace.
- 2026-03-23 13:00 Confirmed the slice already had red-to-green unit coverage for `WishlistPageDataService`, `Index`, `Add`, and `Remove`, but the task docs were still template-state.
- 2026-03-23 13:05 Re-ran targeted syntax and PHPUnit checks; unit assertions passed, but live probing on `9982` showed wishlist storefront URLs were still unresolved.
- 2026-03-23 13:12 Fixed the new wishlist controllers to use the current live login route contract (`customer/account/login`), injected `Url` instead of calling a non-existent controller `getUrl()`, and made redirect branches return safely after redirect dispatch.
- 2026-03-23 13:18 Added guest-path controller tests and corrected the redirect test harness so it can run without full controller bootstrapping.
- 2026-03-23 13:24 Added `app/code/WeShop/Wishlist/etc/env.php` and verified module route registration via `generated/routers/frontend_pc.php`.
- 2026-03-23 13:29 Added short-route bridge controllers with explicit `index/post` methods so `/wishlist`, `/wishlist/add`, and `/wishlist/remove` are registered alongside legacy `wishlist/frontend/wishlist/*` routes.
- 2026-03-23 13:33 Live probing then exposed a deeper runtime DI failure: `CustomerContextInterface` had no factory binding.
- 2026-03-23 13:35 Added `CustomerContextInterfaceFactory`, upgraded `WeShop_Customer` and `WeShop_Wishlist`, and re-verified that guest `/wishlist` redirects to the login page and guest add/remove requests return JSON with a valid login `redirect_url`.
