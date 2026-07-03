# S01 Custom Skill Storage

## Background Snapshot

Builtin skills are file-backed. The workbench needs user-created skills without writing to code directories.

## Goal

Add DB-backed custom skill storage.

## Non-goals

Do not build frontend UI. Do not change prompt injection.

## Touch Points



- setup or schema files used by this module

## Implementation Steps


2. Add a custom skill model/table with code, name, description, body, status, source, timestamps.
3. Enforce unique custom skill code.
4. Add repository methods for find/list/save.
5. Add tests if the module has model tests.

## Acceptance

- Custom skills can be persisted and listed from backend PHP code.
- Builtin skill files are untouched.

## Rollback

Remove the model/schema and repository.
