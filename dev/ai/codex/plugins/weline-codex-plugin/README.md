# Weline Codex Plugin

This plugin packages WelineFramework's Codex-facing development skills into a repo-local plugin source.

## Contents

- `skills/weline-framework/SKILL.md`: plugin entry skill for WelineFramework work.
- `skills/*/SKILL.md`: role-based WelineFramework engineering skills copied from `dev/ai/skills`.
- `skills/gitnexus-*`: GitNexus workflow skills referenced by the repository AI entry point.
- `skills/_index.md`, `skills/README.md`, `skills/TEAM_WORKFLOW.md`, `skills/ROLE_SKILL_BINDING.md`, and `skills/MIGRATION_REPORT.md`: routing and migration support docs.

## Marketplace

The marketplace lives at:

- `dev/ai/codex/.agents/plugins/marketplace.json`

The plugin source is:

- `dev/ai/codex/plugins/weline-codex-plugin`

Install from this checkout:

```powershell
codex plugin marketplace add dev/ai/codex
codex plugin add weline-codex-plugin@weline-framework
codex plugin list
```

Install from GitHub after the `dev` branch is pushed:

```powershell
codex plugin marketplace add Aiweline/WelineFramework --ref dev --sparse dev/ai/codex
codex plugin add weline-codex-plugin@weline-framework
codex plugin list
```

After reinstalling or updating the plugin, start a new Codex thread to load the refreshed skills.
