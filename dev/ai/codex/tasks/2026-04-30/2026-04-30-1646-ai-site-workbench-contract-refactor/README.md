# AI Site Workbench Contract Refactor

This directory is the execution package for the AI site workbench contract refactor.

Use this package in small slices. Do not ask an implementation agent to read every file. Pick one atom from `05-atomic-task-index.md`, then read only that atom document and the source files named in it.

## Reading Rules

- Human/global review: read `00-global-architecture.md`.
- Contract work: read `01-contract-flow.md` plus the specific atom.
- Frontend work: read `02-frontend-workbench-ux.md` plus the specific atom.
- Skill work: read `03-skill-management.md` plus the specific atom.
- Queue work: read `04-queue-sse-boundary.md` plus the specific atom.
- Implementation agents should not read all atom documents in one pass.

## Hard Boundaries

- AI execution stays in queue workers.
- SSE only displays queue status and logs.
- Frontend edits requirements, skills, and starts queue jobs only.
- Downstream stages may not mutate upstream frozen contract fields.
- Old sessions without new contract fields must remain usable.
