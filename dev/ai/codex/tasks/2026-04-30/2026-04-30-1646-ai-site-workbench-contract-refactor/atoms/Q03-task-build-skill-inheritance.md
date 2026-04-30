# Q03 Task Build Skill Inheritance

## Background Snapshot

Stage2 should inherit Stage1 skills by default. Build should read confirmed contract context, not reselect skills.

## Goal

Propagate skill context from Stage1 to Stage2 and Build.

## Non-goals

Do not build frontend override UI.

## Touch Points

- `AiSiteTaskPlanQueue`
- `AiSiteVirtualThemePlanService`
- `AiSiteBuildTaskService`

## Implementation Steps

1. Read Stage1 contract context during task plan start.
2. Default Stage2 selected skills to Stage1 snapshots.
3. Allow explicit Stage2 override only through normalized request data.
4. Make Build read confirmed task/contract skill context.
5. Add tests for inherit and override cases.

## Acceptance

- Stage2 generation has deterministic skill context.
- Build does not select new skills by itself.

## Rollback

Remove inheritance and use Stage2 existing prompt behavior.
