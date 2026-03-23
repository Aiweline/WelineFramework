# Plan - weshop unified auth api slice

## Outcome

- Preserve `ActorContext.area` across issue, refresh, and resolve flows in `WeShopAuthTokenService`.
- Cover the new contract with focused unit tests before widening to higher-level API verification.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Write failing unit coverage for token area persistence and resolution
- [x] Implement the smallest correct change in the auth token model and service
- [x] Run focused validation commands
- [x] Commit the slice and update result.md with the commit hash

## Verification Targets

- [x] Route / generated router inspection
- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
