# Plan - weshop auth api path and http slice

## Outcome

- The WeShop planning docs use the framework-correct frontend REST URL shape and the task records the current runtime probe limitation.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Verify the framework's actual frontend REST URL structure from docs, env, router output, and parser code
- [x] Update the WeShop planning docs to the correct URL shape
- [x] Run lightweight runtime probes and record the result
- [ ] Commit the slice and update result.md with the commit hash

## Verification Targets

- [x] Route / generated router inspection
- [x] Route / integration / http:req
- [ ] Unit / phpunit
- [ ] E2E / browser flow
