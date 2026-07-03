# AD01 Adapter Selector

## Background Snapshot

The architecture uses adapter types such as JSON strict, reasoning strong, copy mature, and rules engine. Existing AiService stays in place for v1.

## Goal

Add a selector that maps stage/role to adapter type and safe call parameters.

## Non-goals

Do not replace model providers. Do not hardcode secrets or new model names.

## Touch Points


- Stage1 and Stage2 service callers later

## Implementation Steps

1. Define v1 adapter type constants.
2. Map Stage1 and Stage2 to strict JSON behavior.
3. Map QA to rules engine behavior.
4. Preserve flags that disable history where already required.
5. Add pure tests for mappings.

## Acceptance

- Callers can request adapter params by stage and role.
- Stage2 no-history guard remains available.

## Rollback

Remove selector and callers can use existing inline params.
