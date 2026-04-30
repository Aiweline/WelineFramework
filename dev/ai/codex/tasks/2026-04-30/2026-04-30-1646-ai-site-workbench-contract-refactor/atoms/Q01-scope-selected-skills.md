# Q01 Scope Selected Skills

## Background Snapshot

Requirements are saved into session scope before generation. Skill selection must live with that same requirement state.

## Goal

Persist `selected_skill_codes` in session scope.

## Non-goals

Do not run AI. Do not change Stage1 prompt.

## Touch Points

- `AiSiteAgent.php`
- Scope patch handling
- Frontend payload later

## Implementation Steps

1. Allow `selected_skill_codes` in scope patch input.
2. Normalize to a unique string array.
3. Preserve empty array as "use default" at resolver time.
4. Return saved scope with selected skills.
5. Add tests or controller smoke validation.

## Acceptance

- Refreshing the workspace can recover selected skill codes from scope.

## Rollback

Remove the scope key handling.
