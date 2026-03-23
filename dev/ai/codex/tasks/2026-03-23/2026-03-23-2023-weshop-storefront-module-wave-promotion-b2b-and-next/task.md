# Task: weshop storefront module wave promotion-b2b-and-next

- Task ID: 2026-03-23-2023-weshop-storefront-module-wave-promotion-b2b-and-next
- Started: 2026-03-23 20:23
- Status: in_progress
- Owner: Codex
- Source: codex chat 2026-03-24

## Goal

- Continue the WeShop storefront completion wave by turning partially implemented modules into production-usable slices that work on clean routes, render correctly in the `default` theme, and stay compatible with the current hook/slot validator.

## Scope

- In scope:
- Promotion storefront hardening and coupon-apply runtime safety.
- B2B storefront hardening with safer customer scoping and hook-name compliance.
- Auditing the next independently shippable WeShop module slices after the recent storefront wave.
- Out of scope:
- Editing `WeShop_Theme` or `Weline_Theme` module source.
- Reverting unrelated dirty worktree changes.

## Constraints

- Prefer task-specific workspaces; do not write new mutable state to `dev/ai/codex/ACTIVE.md`.
- Keep frontend routes clean without extra `frontend` prefixes.
- Use framework-compliant hook names (`[a-z-]+` in component/position), and keep `default` theme as the compatibility baseline.
- Validation is partially blocked by a known global environment issue: unrelated SQLite adapter deprecation paths still break late `setup:upgrade` stages.

## Related Plans

- `dev/ai/codex/tasks/2026-03-24/2026-03-24-2140-weshop-promotion-storefront-slice/`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-2135-weshop-b2b-storefront-slice/`

## Related Files

- `app/code/WeShop/Promotion/`
- `app/code/WeShop/B2B/`
- `app/design/WeShop/default/frontend/pages/`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
