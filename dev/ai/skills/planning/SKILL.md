---
name: planning
description: Create implementation-ready Weline plans with source evidence, a chosen design, bounded task cards, dependencies, acceptance evidence, risks, and rollback. Use in Plan mode or when the user requests an implementation/acceptance plan or execution handoff; not for test authoring, live multi-agent dispatch, simple answers, or an already bounded implementation.
---

# Planning

## Load

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/skills/_index.md`
4. `references/plan-specification.md`
5. The owning module `doc/AI-INDEX.md`, targeted source/evidence, and only the domain skills needed by the plan

## Boundaries

- Planning is read-only unless the user also asks to implement or to persist the plan.
- Do not invent files, symbols, routes, commands, tests, runtime state, or impact. Separate confirmed facts, inferences, decisions, and open blockers.
- Do not schedule commit, push, deployment, production writes, or irreversible operations without the authorization required by the global constraints.
- Use a task workspace only when the plan is multi-step, high-risk, resumable, or intended for handoff.
- `planning` owns the plan artifact. `技术主管-任务拆分与调度` owns live dispatch; QA skills own validation strategy/evidence decisions; testing skills own test implementation.

## Workflow

1. Preserve the original objective and turn it into numbered, testable requirements, scope, exclusions, assumptions, and blockers.
2. Inspect the targeted source, configuration, existing tests, module documentation, runtime entrypoints, and impact evidence required to explain current behavior.
3. Choose one implementation design. Define public contracts, data/state transitions, permissions, failures, compatibility, and prohibited shortcuts.
4. Map each modification point to its upstream/downstream effects, risk, documentation owner, and proof.
5. Split the design into dependency-ordered task cards. Each card must state its result, allowed paths/symbols, steps, validation, completion condition, and stop condition.
6. Select proportionate evidence for each changed surface. Use focused tests where durable coverage is warranted; use Browser for browser-visible behavior and isolated WLS only when runtime behavior requires it.
7. Run a readiness review against the reference. Mark the plan `READY` only when execution no longer depends on hidden design choices; otherwise mark it `BLOCKED` and state the exact decision needed.

## Required Result

- requirement and acceptance baseline;
- current-state evidence and chosen design;
- modification/impact matrix;
- dependency graph and executable task cards;
- validation matrix with environment prerequisites and cleanup;
- documentation changes, risks, rollback, and stop conditions;
- `DRAFT`, `READY`, or `BLOCKED` status with any unresolved decision.

Use `references/plan-specification.md` for the detailed schema instead of copying its templates into this file.

## Validation

- Every referenced path, symbol, route, and command must be verified or explicitly marked as a planned discovery step.
- Every requirement must map to a modification/task and decisive evidence.
- Validation must distinguish the intended result from a fallback or symptom disappearance.
- Low-cost deterministic checks precede runtime/Browser checks.
- A browser-visible task card includes the real route, interaction, visible assertion, and console expectation.
- A WLS task card uses a unique `ai-test-*` instance and available port `>=9502`, with cleanup or explicit manual-acceptance handoff.
- Do not claim that an unexecuted planning check or implementation test ran.
