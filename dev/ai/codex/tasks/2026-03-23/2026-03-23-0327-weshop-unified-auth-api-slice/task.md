# Task: weshop unified auth api slice

- Task ID: 2026-03-23-0327-weshop-unified-auth-api-slice
- Started: 2026-03-23 03:27
- Status: in_progress
- Owner: Codex
- Source: continue after commit e2675b90 for WeShop international commerce
- Follows: `dev/ai/codex/tasks/2026-03-23/2026-03-23-1020-weshop-2fa-self-service-slice/`

## Goal

- Bring the WeShop unified auth token flow closer to the planned API contract by preserving the actor `area` end to end in issued, refreshed, and resolved tokens.
- Add focused TDD coverage for the token service so the unified auth API can safely build `me`, `refresh`, and challenge responses on top of stable actor context.

## Scope

- In scope:
  - inspect the current unified auth API and token service behavior
  - confirm generated auth API routes and compare them with the WeShop planning docs
  - add the smallest correct storage and service changes needed to preserve token `area`
  - add focused unit coverage around the token service contract
- Out of scope:
  - full `/api/rest/v1/weshop/*` endpoint completion for every commerce module
  - frontend or backend theme work
  - broad controller rewrites for customer or Google login flows

## Constraints

- Use the dedicated task workspace only; do not put mutable state back into `dev/ai/codex/ACTIVE.md`.
- Follow TDD, keep services SOLID, and avoid pushing core business logic down into controllers.
- Reuse framework routing and API behavior instead of inventing a second auth protocol.
- Do not modify `WeShop_Theme` or `Weline_Theme`.

## Related Plans

- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/WeShop/Auth/Api/Rest/V1/Auth.php`
- `app/code/WeShop/Auth/Api/Rest/V1/Auth/Challenge.php`
- `app/code/WeShop/Auth/Data/ActorContext.php`
- `app/code/WeShop/Auth/Model/AuthToken.php`
- `app/code/WeShop/Auth/Service/AuthGrantService.php`
- `app/code/WeShop/Auth/Service/WeShopAuthTokenService.php`
- `generated/routers/frontend_rest_api.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
