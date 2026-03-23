# Task: weshop default theme catalog filters checkout hosts

- Task ID: 2026-03-23-2151-weshop-default-theme-catalog-filters-checkout-hosts
- Started: 2026-03-23 21:51
- Status: completed
- Owner: Codex
- Source: follow-up after address commit b1f44a07; normalize default theme hook hosts for catalog/filters/checkout shipping

## Goal

- Normalize the `default` theme storefront host points so catalog, filters, and checkout shipping modules can inject content through canonical WeShop hook names without losing legacy compatibility.

## Scope

- In scope:
- `app/design/WeShop/default/frontend/pages/catalog/category.phtml`
- `app/design/WeShop/default/frontend/layouts/product_list/product_listing_page_{1..4}.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_{1..4}.phtml`
- Unit tests that guard the canonical hook hosts
- Out of scope:
- `WeShop_Theme` and `Weline_Theme` module source
- Runtime behavior changes outside hook-host exposure and fallback rendering

## Constraints

- Keep legacy host support so older modules or themes do not break.
- Only touch WeShop-side theme files and tests.
- Preserve default-theme fallback rendering when no hook content is injected.

## Related Plans

- WeShop international storefront/default-theme compatibility completion wave.

## Related Files

- `app/design/WeShop/default/frontend/pages/catalog/category.phtml`
- `app/design/WeShop/default/frontend/layouts/product_list/product_listing_page_1.phtml`
- `app/design/WeShop/default/frontend/layouts/product_list/product_listing_page_2.phtml`
- `app/design/WeShop/default/frontend/layouts/product_list/product_listing_page_3.phtml`
- `app/design/WeShop/default/frontend/layouts/product_list/product_listing_page_4.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_1.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_2.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_3.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_4.phtml`
- `app/code/WeShop/Catalog/Test/Unit/View/DefaultThemeCategoryHookHostTest.php`
- `app/code/WeShop/Checkout/Test/Unit/View/DefaultThemeCheckoutLayoutHookHostTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
