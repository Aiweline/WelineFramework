# Task: weshop auth api path and http slice

- Task ID: 2026-03-23-0431-weshop-auth-api-path-and-http-slice
- Started: 2026-03-23 04:31
- Status: in_progress
- Owner: Codex
- Source: continue after controller contract commit 7efbf68c
- Follows: `dev/ai/codex/tasks/2026-03-23/2026-03-23-0415-weshop-auth-controller-contract-slice/`

## Goal

- Reconcile the WeShop auth API path planning docs with the framework's real REST route shape, and record the current runtime verification gap.

## Scope

- In scope:
  - confirm the framework's actual frontend REST URL structure
  - compare generated WeShop auth routes with the planning docs
  - update the WeShop planning docs to the framework-correct URL shape
  - attempt lightweight runtime probes and record the result
- Out of scope:
  - new auth business logic beyond the path-contract reconciliation
  - theme work
  - full browser e2e

## Constraints

- Use the task workspace only; do not write mutable state back into `dev/ai/codex/ACTIVE.md`.
- Follow framework routing conventions instead of inventing a parallel URL structure.

## Related Plans

- `dev/ai/codex/WeShop国际电商/README.md`
- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`

## Related Files

- `generated/routers/frontend_rest_api.php`
- `app/etc/env.php`
- `app/code/Weline/Framework/doc/2-快速开始/03-自定义控制器.md`
- `app/code/Weline/Framework/doc/3-开发/API接口开发规范.md`
- `app/code/Weline/Framework/Http/Url.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
