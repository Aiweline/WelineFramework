# API02 Skill Save Endpoint

## Background Snapshot

Custom skills are DB-backed and need create/edit support from the workbench.

## Goal

Add a backend endpoint to create or update custom skills.

## Non-goals

Do not allow editing builtin skill files.

## Touch Points


- Skill repository
- Skill normalizer

## Implementation Steps

1. Accept code, name, description, body, and status.
2. Normalize and validate body.
3. Reject builtin code conflicts.
4. Save custom skill.
5. Return saved skill metadata and validation errors in a stable envelope.

## Acceptance

- A custom skill can be created and edited through backend PHP endpoint.
- Builtin skills cannot be overwritten.

## Rollback

Remove the save handler; existing builtin skills remain usable.
