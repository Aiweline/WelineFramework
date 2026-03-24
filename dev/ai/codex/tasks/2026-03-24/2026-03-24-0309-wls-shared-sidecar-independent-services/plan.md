# Plan - wls shared sidecar independent services

## Outcome

- WLS shared Session/Memory services are treated as instance-independent shared infrastructure with reliable ensure/reuse behavior.
- Instance startup no longer falls back to loose “occupied port means maybe reusable / otherwise auto-switch port” logic for shared services.
- Related WLS runtime logs carry instance-identifying process tags so multi-instance debugging is readable.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Add shared-service registry/ensure/probe flow and replace the old shared-state startup resolution path
- [x] Align orchestrator adoption/runtime metadata with instance-independent shared services
- [x] Add instance names to relevant WLS process tags and shared-service logs
- [x] Update focused unit tests and run validation
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / live shared-service probe if practical
- [ ] E2E / browser flow

## Verification Notes

- Browser E2E is not applicable to this slice because the change is confined to WLS shared-sidecar startup/probe/runtime behavior.
