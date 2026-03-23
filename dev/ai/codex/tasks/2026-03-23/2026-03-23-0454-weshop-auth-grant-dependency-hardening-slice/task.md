# Task: weshop auth grant dependency hardening slice

- Task ID: 2026-03-23-0454-weshop-auth-grant-dependency-hardening-slice
- Started: 2026-03-23 04:54
- Status: in_progress
- Owner: Codex
- Source: continue after commit 353577ec

## Goal

- Remove the direct credential lookup logic from `AuthGrantService`, so password and integration grants are delegated to focused collaborators instead of using `ObjectManager` inside the grant coordinator.

## Scope

- In scope:
- introduce small credential-authenticator services for backend password auth and integration API credentials
- refactor `AuthGrantService` to depend on those services while preserving the existing token and 2FA response contract
- add focused unit coverage for the refactored grant flow
- Out of scope:
- frontend controller changes
- Google grant flow redesign
- runtime HTTP or browser verification beyond unit coverage for this slice

## Constraints

- Keep the slice small, TDD-first, and limited to auth service composition.
- Do not touch unrelated WeShop modules or the theme modules.

## Related Plans

- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/WeShop/Auth/Service/AuthGrantService.php`
- `app/code/WeShop/Auth/Service/BackendPasswordAuthenticator.php`
- `app/code/WeShop/Auth/Service/IntegrationCredentialAuthenticator.php`
- `app/code/WeShop/Auth/Test/Unit/Service/`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
