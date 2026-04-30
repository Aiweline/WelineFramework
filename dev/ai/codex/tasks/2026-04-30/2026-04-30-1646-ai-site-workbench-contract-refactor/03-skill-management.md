# Skill Management

## Current Snapshot

`AiSiteSkillRegistry` can read builtin skills from `skills/*/SKILL.md`. It can build prompt guide content, but the workbench cannot create skills, cannot select skills per request, and does not freeze selected skill context into generated artifacts.

## Target Model

Skill sources:

- `builtin_file`: read-only skills from the module skill directory.
- `custom_db`: user-created skills from a DB model.

Skill fields:

- `code`
- `name`
- `description`
- `body`
- `status`
- `source`
- `created_at`
- `updated_at`

## Runtime Rule

Generation does not only store selected skill codes. It also stores a snapshot with normalized body and body hash in `contract_context.skill_snapshots`. This makes later review reproducible even if a custom skill changes.

## Safety Rule

Custom skills cannot override builtin codes. Disabled skills cannot be selected for new generation, but historical contracts can still display the old snapshot.
