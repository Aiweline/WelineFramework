# Weline AI Rules Pack

Compatibility map for AI hosts and plugin packaging. Do not load it as a second rule body after `AI-ENTRY.md`.

## Canonical surfaces

| Concern | Owner |
|---|---|
| Repository identity and canonical-core boundary | `AGENTS.md` |
| Cross-task safety, authorization, validation, Git and delivery | `dev/ai/global-constraints.md` |
| Skill selection | `dev/ai/skills/_index.md` |
| Specialist workflow and domain rules | Selected `dev/ai/skills/*/SKILL.md` |
| Module architecture and contracts | Owning module `doc/AI-INDEX.md` and linked docs |
| Current behavior | Targeted source, configuration, tests and runtime evidence |
| Task evidence | `dev/ai/codex/tasks/**` |
| Historical material | `dev/ai/archive/**` |

## Loading contract

`AI-ENTRY.md` → `dev/ai/global-constraints.md` → `dev/ai/skills/_index.md` → selected skill(s) → owning module docs → targeted implementation/evidence.

Do not:

- copy global rules into entry files, indexes, adapters, or every specialist skill;
- treat historical plans/reports as current contracts;
- let a lower-priority module or skill weaken safety/authorization rules;
- keep deprecated compatibility skills on an active discovery surface.

Codex/plugin adapters should contain precise trigger metadata and a short pointer to the canonical skill. Detailed instructions stay in `dev/ai/skills`.
