# Task: weshop-rma-storefront-slice

- Task ID: 2026-03-23-1628-weshop-rma-storefront-slice
- Started: 2026-03-23 16:28
- Status: in_progress
- Owner: Codex
- Source: user request

## Goal

- Complete a production-usable storefront slice for `WeShop_RMA` so customers can discover return/after-sales workflows from the default storefront without relying on TODO/sample-data fallbacks.

## Scope

- In scope:
- storefront `rma` routes and page rendering under the `default` theme
- thin controller/service-backed customer RMA list and create flows
- order/account discovery entry points that can reuse existing WeShop hooks without editing theme modules
- targeted tests and route/module upgrade verification
- Out of scope:
- backend RMA workflow redesign beyond keeping existing admin endpoints compatible
- shared theme package changes inside `WeShop_Theme` or `Weline_Theme`

## Constraints

- Keep controllers thin and push data shaping into services.
- Do not rely on sample data or controller-level TODO logic for storefront rendering.
- Runtime smoke should target the user's actual port `9982` when a listener is available.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/WeShop/RMA/**`
- `app/design/WeShop/default/frontend/pages/rma/**`
- `app/code/WeShop/Order/**`
- `app/design/WeShop/default/frontend/pages/customer/index.phtml`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
