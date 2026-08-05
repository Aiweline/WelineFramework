# Rules relocated

This directory is intentionally not an active rule layer.

- Repository-wide rules: `dev/ai/global-constraints.md`
- Skill routing: `dev/ai/skills/_index.md`
- Cursor adapters: `.cursor/rules/*.mdc`
- Historical rules and protocols: `dev/ai/archive/rules/`

Do not add `alwaysApply` mirrors here. Put a cross-task rule in the global owner, a domain workflow in its canonical skill/module documentation, or a host-specific conditional pointer in that host's adapter directory.
