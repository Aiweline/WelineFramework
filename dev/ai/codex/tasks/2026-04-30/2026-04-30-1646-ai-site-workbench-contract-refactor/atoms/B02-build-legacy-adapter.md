# B02 Build Legacy Adapter

## Background Snapshot

Old confirmed task plans must still build even after Build prefers contracts.

## Goal

Use the legacy contract adapter when confirmed task contracts are absent.

## Non-goals

Do not migrate stored old sessions.

## Touch Points

- `AiSiteBuildTaskService`
- Legacy contract adapter
- Build tests

## Implementation Steps

1. Detect missing Stage2 contracts.
2. Convert old `virtual_theme_plan` or task plan fields into temporary contracts.
3. Mark adapter-generated contracts as compatibility mode.
4. Build from the temporary contracts.
5. Add old-session fixture tests.

## Acceptance

- Existing sessions without new contracts still build.

## Rollback

Remove compatibility branch only after old session support is no longer required.

## Implementation Status

Status: done on 2026-04-30.

Implemented:

- When confirmed Stage2 contracts are absent, `AiSiteBuildTaskService` adapts legacy confirmed task plans through `LegacyContractAdapter::adaptStageTwo`.
- Adapter-generated Build blueprints are marked with `contract_source=legacy_contract_adapter`.
- Regression coverage verifies old confirmed sessions still build and carry compatibility-mode `block_task_contract` refs.
