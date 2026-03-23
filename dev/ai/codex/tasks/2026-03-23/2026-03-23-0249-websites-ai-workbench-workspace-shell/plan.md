# Plan - websites ai workbench workspace shell

## Outcome

- `Weline_Websites` owns a basic resumable AI site-building workspace with session state, recent sessions, provider context, and lightweight workbench APIs.
- The unified AI site-building entry lets admins create or resume workbench sessions without losing the fast one-shot build path.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement Websites controller/service/template changes for the minimal workspace shell
- [x] Wire recent sessions and create-workspace actions into the unified entry page
- [x] Run focused syntax/setup validation
- [x] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
