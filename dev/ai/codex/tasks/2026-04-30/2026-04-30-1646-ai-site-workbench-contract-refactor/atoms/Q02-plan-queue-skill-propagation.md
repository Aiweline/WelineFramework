# Q02 Plan Queue Skill Propagation

## Background Snapshot

Plan generation is started by controller code but executed by `AiSitePlanQueue`. The worker must receive skill selection without browser state.

## Goal

Propagate selected skills into the plan queue request and Stage1 runtime.

## Non-goals

Do not implement Stage1 contracts in this atom.

## Touch Points

- `AiSiteAgent::handleStartPlan()`
- Queue content creation
- `AiSitePlanQueue`
- `AiSiteExecutionBlueprintService`

## Implementation Steps

1. Read selected skills from request/scope.
2. Store them in plan queue content or operation request.
3. In the queue worker, resolve skill snapshots before invoking Stage1.
4. Pass snapshots into Stage1 params.
5. Fail clearly if a selected skill is invalid.

## Acceptance

- Plan queue worker can log or expose the skill codes it will use.
- No direct browser execution path is introduced.

## Rollback

Stop passing selected skills and resolver will use default skill behavior.
