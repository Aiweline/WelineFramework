# Task: weshop auth controller contract slice

- Task ID: 2026-03-23-0415-weshop-auth-controller-contract-slice
- Started: 2026-03-23 04:15
- Status: in_progress
- Owner: Codex
- Source: continue after auth token area commit 460dd98a
- Follows: `dev/ai/codex/tasks/2026-03-23/2026-03-23-0327-weshop-unified-auth-api-slice/`

## Goal

- Lock down the route-level controller contract for the core WeShop auth API endpoints so `/login`, `/refresh`, `/logout`, `/me`, and `/challenge/verify` behave predictably without relying on callers to pass the right `grant_type`.

## Scope

- In scope:
  - add focused unit tests for the WeShop auth REST controllers
  - fix the smallest controller-level contract gaps found by those tests
  - verify the full `WeShop/Auth` unit suite still passes after the change
- Out of scope:
  - database schema or token storage changes
  - broad grant-service refactors
  - frontend or backend UI changes

## Constraints

- Use the dedicated task workspace only; do not write mutable progress into `dev/ai/codex/ACTIVE.md`.
- Keep changes service-driven and small; controller code should stay thin.
- Reuse existing routes and API response helpers instead of inventing a parallel protocol.

## Related Plans

- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/WeShop/Auth/Api/Rest/V1/Auth.php`
- `app/code/WeShop/Auth/Api/Rest/V1/Auth/Challenge.php`
- `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php`
- `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
