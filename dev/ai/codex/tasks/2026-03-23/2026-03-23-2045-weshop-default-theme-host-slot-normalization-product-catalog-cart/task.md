# Task: weshop default-theme host slot normalization product-catalog-cart

- Task ID: 2026-03-23-2045-weshop-default-theme-host-slot-normalization-product-catalog-cart
- Started: 2026-03-23 20:45
- Status: in_progress
- Owner: Codex
- Source: codex chat 2026-03-24

## Goal

- Normalize the `default` theme's storefront host hooks for the highest-value user paths so WeShop modules can inject into product/cart experiences without relying on outdated slot names or losing compatibility with existing layouts.

## Scope

- In scope:
- Product detail host compatibility for compare/wishlist/cart popup injection.
- Cart page host compatibility for the modern `WeShop_Cart::frontend::*` contract while preserving legacy page hooks.
- Template-level regression tests that lock the required hook hosts into the `default` theme.
- Out of scope:
- Replacing the existing default-theme storefront markup with module-owned fallback hook templates.
- Editing `WeShop_Theme` or `Weline_Theme` module source.
- Full `Catalog + Filters` host migration in the same commit if it would require a broader layout rewrite.

## Constraints

- Keep the current `default` theme UI intact; add compatibility hosts rather than rewriting the entire page.
- Preserve legacy hook names already used by existing templates while adding the newer contract names.
- Avoid destructive changes in the dirty worktree.

## Related Plans

- `dev/ai/codex/tasks/2026-03-23/2026-03-23-2023-weshop-storefront-module-wave-promotion-b2b-and-next/`

## Related Files

- `app/design/WeShop/default/frontend/pages/product/view.phtml`
- `app/design/WeShop/default/frontend/pages/cart/index.phtml`
- `app/code/WeShop/Product/Test/Unit/View/`
- `app/code/WeShop/Cart/Test/Unit/View/`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
