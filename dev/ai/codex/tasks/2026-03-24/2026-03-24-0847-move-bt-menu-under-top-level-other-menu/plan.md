# Plan - Move Bt menu under top-level other menu

## Outcome

- BT backend entry is a direct child of `Weline_Backend::other_tools_group`, so the UI no longer shows an extra nested BT wrapper level.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [x] Route / integration / menu collection
- [ ] E2E / browser flow
