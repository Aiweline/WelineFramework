# Task: weshop auth logout revocation slice

- Task ID: 2026-03-23-0442-weshop-auth-logout-revocation-slice
- Started: 2026-03-23 04:42
- Status: in_progress
- Owner: Codex
- Source: continue after auth api path contract commit dc4ba4f7
- Follows: `dev/ai/codex/tasks/2026-03-23/2026-03-23-0431-weshop-auth-api-path-and-http-slice/`

## Goal

- Make unified auth logout revoke the whole active token pair for the actor, so a refresh token cannot stay valid after logout.

## Scope

- In scope:
  - tighten `WeShopAuthTokenService::revoke()` semantics
  - add focused unit coverage for access-token and refresh-token initiated logout
  - re-run the `WeShop/Auth` unit suite
- Out of scope:
  - controller or UI changes
  - broader auth-grant refactors

## Constraints

- Use the task workspace only; do not write mutable state back into `dev/ai/codex/ACTIVE.md`.
- Keep the change small and service-level.

## Related Plans

- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`

## Related Files

- `app/code/WeShop/Auth/Service/WeShopAuthTokenService.php`
- `app/code/WeShop/Auth/Test/Unit/Service/WeShopAuthTokenServiceTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
