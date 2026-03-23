# Plan - weshop payment backend ia config slice

## Outcome

- Backend users can open a `Payment` management page under `Weline_Backend::payment_group`.
- Payment method runtime settings are editable from backend and the same effective settings are consumed by `PaymentService`.
- This slice remains isolated from Theme-module changes and only uses backend/module-side UI work.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement backend menu, controller(s), runtime config service, and management view
- [x] Add focused tests for payment config merge/save behavior and backend controller flow
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Focused PHPUnit for payment backend config service / controller
- [ ] Backend route smoke on runtime port `9982`
- [x] Record browser/e2e gap if authenticated backend validation cannot be completed in this slice
