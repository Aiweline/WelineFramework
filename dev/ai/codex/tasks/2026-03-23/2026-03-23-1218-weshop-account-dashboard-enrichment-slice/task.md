# Task: weshop account dashboard enrichment slice

- Task ID: 2026-03-23-1218-weshop-account-dashboard-enrichment-slice
- Started: 2026-03-23 12:18
- Status: in_progress
- Owner: Codex
- Source: user request

## Goal

- Enrich the storefront account dashboard so the `default` theme shows a production-usable personal center with service-driven data for orders, wishlist, recently viewed products, and "guess you may like" recommendations.

## Scope

- In scope:
- Refactor `WeShop\Customer\Controller\Frontend\Account\Index` into a thin controller using `CustomerContextInterface`
- Add a dashboard data service that aggregates orders, wishlist, recently viewed products, and recommendations
- Update `app/design/WeShop/default/frontend/pages/customer/index.phtml` to render the new account-center discovery sections
- Update WeShop account hook/docs and high-level roadmap/acceptance docs for account-center layout coverage
- Out of scope:
- Full wishlist page refactor, backend customer management, and unrelated checkout/payment/auth slices

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme` internals
- Keep new storefront compatibility inside WeShop modules and the `default` theme
- Follow task workspace logging and stage only this slice's whitelist when committing

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`

## Related Files

- `app/code/WeShop/Customer/Controller/Frontend/Account/Index.php`
- `app/code/WeShop/Customer/Service/AccountDashboardDataService.php`
- `app/code/WeShop/Customer/Api/CustomerContextInterface.php`
- `app/code/WeShop/Wishlist/Service/WishlistService.php`
- `app/code/WeShop/RecentlyViewed/Service/RecentlyViewedService.php`
- `app/code/WeShop/Product/Service/ProductRecommendationService.php`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- `app/code/WeShop/Customer/hook.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
