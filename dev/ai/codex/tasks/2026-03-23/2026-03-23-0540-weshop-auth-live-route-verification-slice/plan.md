# Plan - weshop auth live route verification slice

## Outcome

- The live auth endpoints on `https://127.0.0.1:9982/api/weshop/rest/v1/auth/*` reach the WeShop auth controllers instead of being blocked by the framework API pre-auth observer.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Write failing observer/unit coverage
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [ ] E2E / browser flow
