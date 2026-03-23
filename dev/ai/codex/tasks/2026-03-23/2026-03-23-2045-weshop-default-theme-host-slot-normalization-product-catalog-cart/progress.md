# Progress - weshop default-theme host slot normalization product-catalog-cart

- 2026-03-23 20:45 Created the task workspace.
- 2026-03-25 00:18 Resumed the task after the storefront audit highlighted the biggest `default` theme compatibility gap: product and cart pages were still missing the modern hook hosts used by newer WeShop storefront modules.
- 2026-03-25 00:22 Patched `app/design/WeShop/default/frontend/pages/product/view.phtml` to expose:
  - `WeShop_Product::frontend::product::detail::after-add-to-cart`
  - `WeShop_Product::frontend::product::add-to-cart::options-popup`
  while preserving the older `WeShop_Product::detail::after_add_to_cart` host already present in the theme.
- 2026-03-25 00:27 Patched `app/design/WeShop/default/frontend/pages/cart/index.phtml` to expose the modern `WeShop_Cart::frontend::layouts::cart::*` and `WeShop_Cart::frontend::partials::cart::*` hosts around the existing header, items, summary, coupon, express-checkout, sidebar, and continue-shopping sections without removing the current legacy hooks.
- 2026-03-25 00:31 Added template-level regression tests:
  - `WeShop\Product\Test\Unit\View\DefaultThemeProductHookHostTest`
  - `WeShop\Cart\Test\Unit\View\DefaultThemeCartHookHostTest`
- 2026-03-25 00:33 Validation:
  - `php -l app/design/WeShop/default/frontend/pages/product/view.phtml`
  - `php -l app/code/WeShop/Product/Test/Unit/View/DefaultThemeProductHookHostTest.php`
  - `php vendor/bin/phpunit --no-coverage app/code/WeShop/Product/Test/Unit/View/DefaultThemeProductHookHostTest.php --colors=never`
  - `php -l app/design/WeShop/default/frontend/pages/cart/index.phtml`
  - `php -l app/code/WeShop/Cart/Test/Unit/View/DefaultThemeCartHookHostTest.php`
  - `php vendor/bin/phpunit --no-coverage app/code/WeShop/Cart/Test/Unit/View/DefaultThemeCartHookHostTest.php --colors=never`
  - all targeted checks passed; one existing PHPUnit deprecation remains.
