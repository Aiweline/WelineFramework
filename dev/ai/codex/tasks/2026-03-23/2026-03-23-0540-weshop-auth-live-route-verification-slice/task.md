# Task: weshop auth live route verification slice

- Task ID: 2026-03-23-0540-weshop-auth-live-route-verification-slice
- Started: 2026-03-23 05:40
- Status: completed
- Owner: Codex
- Source: continue after commit 391ec807; runtime port corrected to 9982

## Goal

- Verify the live unified auth routes against the real runtime on port `9982` and fix any framework-level pre-auth gating that blocks the public auth endpoints before they reach `WeShop_Auth`.

## Scope

- In scope:
- runtime verification for `/api/weshop/rest/v1/auth/*` on port `9982`
- the smallest framework/API observer fix needed to allow the public WeShop auth endpoints through
- focused unit coverage for the whitelist behavior
- Out of scope:
- broader auth business logic refactors
- browser E2E and non-auth WeShop modules

## Constraints

- Respect the existing dirty worktree and keep changes tightly scoped.
- Prefer a small whitelist/observer fix over a large API security rewrite.

## Related Plans

- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/Weline/Api/Observer/ApiControllerInitBefore.php`
- `app/code/Weline/Acl/Observer/RouteBefore.php`
- `app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php`
- `app/code/WeShop/Auth/Api/Rest/V1/Auth.php`
- `generated/routers/frontend_rest_api.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
