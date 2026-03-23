# Plan - wls-daemon-time-limit-hardening

## Outcome

- WLS long-running processes survive PHP time-limit defaults, and foreground Masters can still be recognized as alive during stop/reload flows even though their real command line does not contain `--name=...`.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [ ] E2E / browser flow
