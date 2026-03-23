# Plan - wls direct foreground nonblocking create semantics

## Outcome

- Separate direct Windows foreground launch waiting from display mode so non-blocking callers return immediately without hidden fallback launches.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
