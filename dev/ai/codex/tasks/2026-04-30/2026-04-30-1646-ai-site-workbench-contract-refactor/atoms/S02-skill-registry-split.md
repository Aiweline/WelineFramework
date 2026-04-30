# S02 Skill Registry Split

## Background Snapshot

`AiSiteSkillRegistry` currently scans builtin skill folders and builds prompt guide text. It should become an orchestration facade over smaller services.

## Goal

Split skill lookup into builtin provider, custom provider, and selection resolver.

## Non-goals

Do not add UI. Do not change Stage1/Stage2 prompts yet.

## Touch Points

- `AiSiteSkillRegistry.php`
- New skill provider services
- Existing `skills/*/SKILL.md`

## Implementation Steps

1. Keep public registry methods compatible.
2. Move file scanning into a builtin provider.
3. Move DB lookup into a custom provider.
4. Add a resolver that merges sources without duplicate codes.
5. Preserve existing default behavior for `claude-design`.

## Acceptance

- Existing callers still receive the same builtin prompt guide.
- Custom provider can be added without changing callers.

## Rollback

Restore registry to file-only behavior.
