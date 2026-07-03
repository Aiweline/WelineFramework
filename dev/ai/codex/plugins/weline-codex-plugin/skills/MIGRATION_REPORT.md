# Migration Report

## Scope

This migration rewrites the legacy Weline AI skill set into a Multica-compatible role-based directory under `dev/ai/skills/`.

## Mandatory Sources Checked

- `AI-ENTRY.md`
- `AI-README.md`
- `CLAUDE.md`
- `dev/ai/skills/_index.md`

## Original Skill Sources Checked

- `dev/ai/skills/planning/SKILL.md`
- `dev/ai/skills/codex-task-workspace/SKILL.md`
- `dev/ai/skills/testing/SKILL.md`
- `dev/ai/skills/weline-framework-core/SKILL.md`
- `dev/ai/skills/weline-framework-runtime/SKILL.md`
- `dev/ai/skills/weline-framework-skill-router/SKILL.md`
- `dev/ai/skills/extension-points/SKILL.md`
- `dev/ai/skills/weline-sticker/SKILL.md`
- `dev/ai/skills/runtime-and-process/SKILL.md`
- `dev/ai/skills/session-development/SKILL.md`
- `dev/ai/skills/theme-development/SKILL.md`
- `dev/ai/skills/template-source-editing/SKILL.md`
- `dev/ai/skills/frontend-components/SKILL.md`
- `dev/ai/skills/i18n-internationalization/SKILL.md`
- `dev/ai/skills/friendly-notifications/SKILL.md`
- `dev/ai/skills/database-model-standards/SKILL.md`
- `dev/ai/skills/unified-query-provider/SKILL.md`
- `dev/ai/skills/acl-permission-system/SKILL.md`
- `dev/ai/skills/config-and-env/SKILL.md`
- `dev/ai/skills/module-development/SKILL.md`
- `dev/ai/skills/service-development/SKILL.md`
- `dev/ai/skills/cache-usage/SKILL.md`
- `dev/ai/skills/debug-logging/SKILL.md`
- `dev/ai/skills/documentation-standards/SKILL.md`
- `dev/ai/skills/create-framework-command/SKILL.md`
- `dev/ai/skills/php84-performance/SKILL.md`
- `dev/ai/skills/code-generation-standards/SKILL.md`
- `dev/ai/skills/windows-command-quoting/SKILL.md`
- `dev/ai/skills/weline-routing/SKILL.md`
- `dev/ai/skills/sse-streaming/SKILL.md`

- `dev/ai/skills/visitor-pixel/SKILL.md`
- `dev/ai/skills/website-to-template/SKILL.md`
- `dev/ai/skills/community-module/SKILLS-CONSOLIDATED.md`

## Missing Sources

No listed mandatory or original source files were missing at migration time.

## Migration Notes

- The new directory is role-based instead of topic-based.
- The Technical Director role is intentionally excluded, per repository requirements.
- Each skill is self-contained and does not require loading the legacy source files at runtime.
- Shared Weline constraints were redistributed only to the roles they materially affect.
- The legacy topic-based skill directories were removed after the role-based consolidation was validated.
