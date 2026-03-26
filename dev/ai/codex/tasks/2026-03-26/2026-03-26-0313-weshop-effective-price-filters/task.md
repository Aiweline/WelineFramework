# Task: WeShop effective price filters

- Task ID: 2026-03-26-0313-weshop-effective-price-filters
- Started: 2026-03-26 03:13
- Status: completed
- Owner: Codex
- Source: Continue next base-layer slice after price-rule closure; align filters/search stats with effective discounted price semantics on default theme and unified browse APIs.

## Goal

- Align filter/search fallback price semantics with the normalized effective-price contract from
  the previous `WeShop_Price` slice.
- Make `ProductQueryProvider` compute search fallback filtering, range stats, and range counts
  from effective discounted price instead of persisted base `price`.
- Keep the existing search document contract (`price=current`, `original_price=base`) and avoid
  theme-module changes.

## Scope

- In scope:
- `app/code/WeShop/Product/Extends/module/Weline_Framework/Query/ProductQueryProvider.php`
- `app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php`
- Focused runtime smoke on `9982` for `filters` and `search`
- Out of scope:
- Search index schema changes or reworking external engine field names
- `WeShop_Theme` / `Weline_Theme`
- Tax/shipping/checkout rules outside price-filter semantics

## Constraints

- Reuse the existing `PriceService` resolver; do not add a second price rule system.
- Runtime/browser verification must target `9982`.
- Keep mutable state in this task workspace only; do not use `ACTIVE.md`.
- The worktree is already dirty; do not revert unrelated user or parallel-agent changes.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`

## Related Files

- `app/code/WeShop/Product/Extends/module/Weline_Framework/Query/ProductQueryProvider.php`
- `app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php`
- `app/code/WeShop/Filters/Provider/PriceFilterProvider.php`
- `app/code/WeShop/Search/Service/SearchService.php`
- `app/code/WeShop/Search/Engine/ElasticsearchEngine.php`
- `tests/e2e/specs/frontend/weshop-filters.spec.js`
- `tests/e2e/specs/frontend/weshop-search.spec.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
