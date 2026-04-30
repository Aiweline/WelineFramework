# API01 Skill List URL and Endpoint

## Background Snapshot

The workspace template receives backend URLs from `AiSiteAgent::workspace()`. Skill UI needs a list endpoint before it can render choices.

## Goal

Expose a skill list URL and endpoint.

## Non-goals

Do not build save/delete endpoints. Do not build frontend UI.

## Touch Points

- `AiSiteAgent.php`
- Workspace template assigned URLs
- Skill registry facade

## Implementation Steps

1. Add a workspace URL for skill list.
2. Add a controller handler returning builtin and custom skills.
3. Include source, status, code, name, description, and whether it is selectable.
4. Return a consistent JSON envelope.
5. Add controller-level test if available.

## Acceptance

- Frontend can request all available skills from the workspace page.

## Rollback

Remove the URL assignment and endpoint handler.
