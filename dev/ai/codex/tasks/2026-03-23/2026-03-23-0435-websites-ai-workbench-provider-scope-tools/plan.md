# Plan - websites ai workbench provider scope tools

## Outcome

- `Weline_Websites` exposes a provider workbench contract that lets other modules supply provider-specific initial scope/provider state and provider-specific workbench tools.
- The unified Websites workspace consumes provider metadata instead of relying on controller hardcoded provider routing/tool labels.
- `PageBuilder` is upgraded as the first concrete example of a module that both seeds its scope and contributes workbench tools to the Websites AI workspace.

## Steps

- [ ] Clarify scope, affected files, and risks
- [ ] Add provider workbench contract and normalization service
- [ ] Refactor Websites controller/template to consume provider-driven workbench config
- [ ] Upgrade built-in providers and first external provider example
- [ ] Add or update tests / verification
- [ ] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [ ] Unit / phpunit
- [ ] Route / integration / http:req
- [ ] E2E / browser flow
