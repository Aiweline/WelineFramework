# Task: weshop auth token endpoint contract slice

- Task ID: 2026-03-23-0506-weshop-auth-token-endpoint-contract-slice
- Started: 2026-03-23 05:06
- Status: completed
- Owner: Codex
- Source: continue after commit 6d098b75

## Goal

- Lock the controller-level contract for the unified auth token endpoint so `postToken()` and challenge exchange behavior stay stable across password, Google, refresh, and integration grants.

## Scope

- In scope:
- add failing controller tests for `postToken()` grant dispatch and `postExchange()` validation
- make the smallest controller changes needed to satisfy those contracts
- record whether a lightweight `http:req` probe is possible in this environment
- Out of scope:
- deeper auth service refactors beyond controller contract needs
- browser E2E work

## Constraints

- Prefer controller tests first because runtime HTTP probes have been flaky in this repo.
- Keep the endpoint response shape aligned with the existing unified auth payload contract.

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
- Completed in commit `2d3df085`.
