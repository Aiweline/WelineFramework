# P02 Stage1 Site Design Page Contracts

## Background Snapshot

Stage1 is the only stage allowed to define site direction, brand direction, page list, and SEO basis before human confirmation.

## Goal

Emit Site Brief, Design Manifest, and Page Contract under `plan_workbench.contracts`.

## Non-goals

Do not implement Block Plan in this atom. Do not change confirmation UI yet.

## Touch Points

- `AiSiteExecutionBlueprintService`
- Stage1 prompt/schema sanitation
- Contract helpers

## Implementation Steps

1. Extend Stage1 schema instructions to require three contracts.
2. Fill each contract with `contract_meta`, permissions, frozen fields, and QA gates.
3. Preserve existing `execution_blueprint` and `markdown`.
4. Sanitize prompt-like or missing fields into structured errors.
5. Add tests with mocked AI output.

## Acceptance

- Stage1 output contains the three new contracts and old fields still exist.

## Rollback

Stop reading/writing `plan_workbench.contracts`.
