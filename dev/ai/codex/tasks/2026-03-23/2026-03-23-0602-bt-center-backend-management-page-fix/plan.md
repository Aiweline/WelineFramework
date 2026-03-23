# Plan - bt center backend management page fix

## Outcome

- `Weline_Bt_Center` backend menu and management pages resolve through the correct route, render with expected backend structure, and no longer hit 404 through the normal CRUD flow.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Inspect generated/expected backend route mapping and current template/menu URLs
- [x] Implement the smallest correct route + UI fix
- [x] Run focused validation (`setup:upgrade --route`, syntax, route/http checks, and UI spot checks)
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Syntax checks on touched PHP files
- [x] `php bin/w setup:upgrade -m Weline_Bt_Center --yes`
- [x] Focused route validation via `http:req` or equivalent backend request
- [ ] Backend UI spot check for list/form navigation
