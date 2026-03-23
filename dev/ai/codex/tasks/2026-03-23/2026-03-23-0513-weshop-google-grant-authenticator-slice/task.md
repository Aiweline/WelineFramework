# Task: weshop google grant authenticator slice

- Task ID: 2026-03-23-0513-weshop-google-grant-authenticator-slice
- Started: 2026-03-23 05:13
- Status: in_progress
- Owner: Codex
- Source: continue after commit 2d3df085

## Goal

- Extract the remaining Google-code login branch from `AuthGrantService` into a focused authenticator so the grant coordinator no longer does service lookup and raw array parsing for Google login.

## Scope

- In scope:
- add a dedicated Google grant authenticator service
- refactor `AuthGrantService::issueGoogleCodeToken()` to delegate to it
- add unit coverage for both the new service and the grant coordinator Google path
- Out of scope:
- browser login flow or callback controller changes
- theme/UI updates

## Constraints

- Keep the change small and service-layer only.
- Preserve the existing unified auth response contract and 2FA behavior.

## Related Plans

- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `app/code/WeShop/Auth/Service/AuthGrantService.php`
- `app/code/WeShop/GoogleAuth/Service/GoogleLoginService.php`
- `app/code/WeShop/Auth/Test/Unit/Service/`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
