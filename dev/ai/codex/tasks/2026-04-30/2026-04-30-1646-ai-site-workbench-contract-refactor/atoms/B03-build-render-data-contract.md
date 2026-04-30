# B03 Build Render Data Contract

## Background Snapshot

Build output should be traceable to the contracts that produced it.

## Goal

Write a Build Render Data contract around generated render data/theme manifest.

## Non-goals

Do not change renderer behavior unless required for metadata.

## Touch Points

- `AiSiteBuildTaskService`
- Build output persistence
- Contract helpers

## Implementation Steps

1. Wrap build output metadata in a Build Render Data contract.
2. Link to source Block Task Contract ids.
3. Add QA gate placeholders.
4. Preserve existing output fields for UI compatibility.
5. Add tests.

## Acceptance

- Build output can be traced back to task contracts.

## Rollback

Stop writing the wrapper and keep existing output fields.

## Implementation Status

Status: done on 2026-04-30.

Implemented:

- `AiSiteBuildTaskService::finalizeBuildTaskStatesAfterRunLoop` now attaches a Build-stage `render_data` contract once all blueprint tasks are done.
- The contract wraps page layouts, shared components, materialized pages, asset manifest, and build summary while preserving existing output fields.
- Session scope read/write allowlists now include `build_contracts`, `build_workbench`, and `render_data_contract`.
- Regression coverage verifies render-data contract metadata, source refs, payload, and workbench persistence shape.
