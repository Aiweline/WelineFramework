# Result - weshop auth api path and http slice

## Outcome

- Completed in commit `dc4ba4f7 docs(weshop): align auth api path contracts`.

## Changed Files

- `dev/ai/codex/WeShop国际电商/README.md`
- `dev/ai/codex/WeShop国际电商/api-contracts.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`
- `dev/ai/codex/WeShop国际电商/test-matrix.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0431-weshop-auth-api-path-and-http-slice/task.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0431-weshop-auth-api-path-and-http-slice/plan.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0431-weshop-auth-api-path-and-http-slice/progress.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0431-weshop-auth-api-path-and-http-slice/result.md`

## Verification

- Generated route inspection:
  - `generated/routers/frontend_rest_api.php` contains `weshop/rest/v1/auth/token`, `weshop/rest/v1/auth/challenge/verify`, `weshop/rest/v1/auth/logout`, and `weshop/rest/v1/auth/me`
- Framework route inputs inspected:
  - `app/etc/env.php` confirms `router.area_routes.rest_frontend.prefix=api`
  - `app/code/Weline/Framework/Http/Url.php` confirms the area prefix is stripped before relative router lookup
- Runtime probes attempted with `php bin/w http:req`, but local runtime was unstable and did not produce a clean success/401 response for this slice

## Remaining Risks

- Local `http:req` runtime verification is still unstable on port `9981`, so this slice relies on framework-source validation and generated router output more than live responses.
- Broader unified auth API work still remains, especially business-behavior verification above the path-contract level.

## Next Resume Step

- Commit this doc-and-contract reconciliation slice, then continue with the next auth behavior gap.
