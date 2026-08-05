# Weline Codex Plugin

This directory is a discovery and packaging surface. It does not own Weline
engineering rules or role-skill bodies.

## Canonical sources

- Entry and authority: `AI-ENTRY.md`
- Global constraints: `dev/ai/global-constraints.md`
- Skill router: `dev/ai/skills/_index.md`
- Skill bodies: `dev/ai/skills/*/SKILL.md`

The files under `skills/<name>/SKILL.md` are generated thin adapters. Refresh
or verify them from the repository root:

```bash
php dev/ai/scripts/sync-codex-plugin-skills.php
php dev/ai/scripts/sync-codex-plugin-skills.php --check
```

The explicit plugin source is registered by `.agents/plugins/marketplace.json`.
After reinstalling an updated plugin, start a new Codex task so discovery uses
the new manifest and adapters. Installed cache copies are not edited in place.
