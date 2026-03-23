# Plan - ai site workbench e2e and provider lane fix

## Outcome

- Provider-lane links stay on the active Websites AI workbench route instead of collapsing to the backend root.
- Local fake-mode quick build can drive the full AI site workbench flow for demo / E2E without real registrar side effects.
- A targeted Playwright spec verifies the hub, workspace, theme/visual-edit progression, and fake quick-build milestones.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [x] E2E / browser flow
