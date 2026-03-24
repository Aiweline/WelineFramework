# Task: weshop checkout retry product consistency

- Task ID: 2026-03-24-2207-weshop-checkout-retry-product-consistency
- Started: 2026-03-24 22:07
- Status: in_progress
- Owner: Codex
- Source: user: commit changes, continue remaining fixes, ensure e2e and unit tests pass

## Goal

- Close the current coherent WeShop storefront slice around checkout retry-payment reuse, product-list consistency, and default-theme checkout/success page behavior, then checkpoint it cleanly without mixing unrelated repo drift.

## Scope

- In scope:
- `app/code/WeShop/Checkout/Service/*`
- `app/code/WeShop/Checkout/Test/Unit/*`
- `app/code/WeShop/Product/Controller/List/Index.php`
- `app/code/WeShop/Product/Service/*`
- `app/code/WeShop/Product/Test/Unit/*`
- `app/code/WeShop/Frontend/Controller/Product/List/Index.php`
- `app/code/WeShop/Frontend/Test/Unit/Controller/ProductCleanRouteControllersTest.php`
- `app/code/WeShop/Frontend/Test/Unit/View/CleanRouteAliasTemplateProxyTest.php`
- `app/code/WeShop/Product/Test/Unit/View/CleanRouteAliasTemplateProxyTest.php`
- `app/code/WeShop/Product/view/templates/List/Index/index.phtml`
- `app/code/WeShop/Product/view/templates/frontend/product/list/index.phtml`
- `app/code/WeShop/Frontend/view/templates/Product/List/Index/index.phtml`
- `app/code/WeShop/Customer/view/hooks/header-account.phtml`
- `app/design/WeShop/default/frontend/pages/checkout/*`
- `tests/e2e/specs/frontend/weshop-product-clean-route.spec.js`
- `tests/e2e/specs/frontend/weshop-product-list-clean-route.spec.js`
- Out of scope:
- `WeShop_Theme` / `Weline_Theme`
- unrelated framework, AI, CDN, i18n, and temp-file drift in the worktree

## Constraints

- White-list stage only.
- Keep controllers thin, use service/query boundaries, and preserve default-theme hook injection.
- Validate on the user's confirmed acceptance runtime `9982` where live probes are needed.

## Related Plans

- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0343-weshop-commit-and-next-module-wave/`

## Related Files

- `app/code/WeShop/Checkout/Service/CheckoutPageDataService.php`
- `app/code/WeShop/Checkout/Service/CheckoutService.php`
- `app/code/WeShop/Product/Controller/List/Index.php`
- `app/code/WeShop/Product/Service/ProductService.php`
- `app/code/WeShop/Product/Service/ProductRecommendationService.php`
- `app/code/WeShop/Frontend/Controller/Product/List/Index.php`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/design/WeShop/default/frontend/pages/checkout/success.phtml`
- `tests/e2e/specs/frontend/weshop-product-list-clean-route.spec.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
