# Skill migration status

The active skill system is role/domain based. `dev/ai/skills/_index.md` is the current catalog and `ROLE_SKILL_BINDING.md` preserves legacy-name mappings.

## Current policy

- Legacy topic names are compatibility history, not active `SKILL.md` discovery entries.
- Current skills must not load deprecated aliases as source material.
- Missing historical sources are not runtime dependencies.
- Codex/plugin copies are adapters; canonical instructions remain under `dev/ai/skills`.

## Historical gaps

The original migration report claimed every listed source was present. The current repository does not contain several historical sources, including:

- `extension-points/SKILL.md`
- `debug-logging/SKILL.md`

Those missing names remain only as migration labels in `ROLE_SKILL_BINDING.md`; active skills must link directly to current owners or current supporting material.

## Verification

After skill reorganization, check:

1. every active frontmatter name/description;
2. every referenced current path;
3. `_index.md` routing;
4. absence of deprecated skill directories on discovery surfaces;
5. adapter bodies remain pointers rather than copied canonical instructions.
