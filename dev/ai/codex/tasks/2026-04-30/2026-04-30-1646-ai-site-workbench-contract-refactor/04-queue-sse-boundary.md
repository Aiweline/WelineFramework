# Queue and SSE Boundary

## Current Boundary

Plan, task plan, build, and asset generation are queue operations. Queue workers own AI execution. SSE endpoints and frontend scripts show status and logs only.

## Required Boundary

Keep this separation:

- Controllers create queue rows and return queue metadata.
- Queue workers load session/request state and execute AI services.
- SSE reports queue state and log events.
- Browser code never executes AI generation.

## Contract Refactor Impact

Selected skills and contract metadata must be written before queue execution starts. Workers must be able to run without browser memory or active page state.

## Failure Handling

If selected skills are missing, disabled, or invalid, the queue operation should fail with a readable error. It should not fall back to silent prompt changes except for the documented default `claude-design` when no skills were selected.
