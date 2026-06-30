---
name: 单元测试工程师-单元测试覆盖
description: Unit test engineer skill for PHPUnit or Pest coverage, service-level assertions, and focused regression protection. Disabled by default; use only when the user explicitly asks to write, update, or run unit tests.
version: 1.1.1
---

# Role

This skill is disabled by default. Use it only when the current user request explicitly asks to write, update, or run unit tests for WelineFramework code.

# When To Use

- Use only for explicit user requests mentioning unit test, PHPUnit, Pest, service test, helper test, coverage, or "补单测".
- Do not infer this skill from ordinary bug fixes, business-logic changes, or validation needs.

# Source Material

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/skills/testing/SKILL.md`
- `dev/ai/skills/service-development/SKILL.md`
- `dev/ai/skills/code-generation-standards/SKILL.md`
- `dev/ai/skills/community-module/SKILLS-CONSOLIDATED.md`

# Responsibilities

- Create targeted unit tests around changed logic only after confirming explicit user intent.
- Extract logic into testable seams when direct testing is otherwise impossible.
- Keep assertions precise enough to protect against regression.
- Provide fast-running evidence that complements, rather than replaces, route or UI checks.

# Workflow

1. Confirm the current user request explicitly asks for unit-test authoring or execution.
2. Read the task scope and identify the narrowest reliable unit boundary.
3. Confirm whether the behavior belongs in a service, helper, model, or collaborator test.
4. Add failing or missing test coverage that reproduces the expected behavior.
5. Update implementation only as needed to make the behavior testable and correct.
6. Run focused unit-test commands for the affected module or class when execution was requested or needed for the requested unit-test work.
7. Review for assertion quality, readability, and regression value.
8. Report the executed command and what behavior the test now protects.

# Weline Rules

- Prefer small, isolated, testable changes.
- Do not author, update, or run unit tests unless the current user request explicitly asks for unit-test work.
- Keep business logic in services instead of controllers or templates when testability matters.
- Do not hardcode user-facing text.

# Inputs Required

- The changed logic and its owning module.
- Expected behavior, edge cases, and regression risks.
- The explicit user request that authorizes unit-test work.
- Existing tests or target test directory.
- The preferred focused test command.

# Expected Output

- New or updated unit tests that directly cover the changed logic.
- A focused test command and pass result.
- A note describing the protected regression case.

# Validation

- Run `php bin/w phpunit:run --module=...` or an equivalently focused test command.
- Confirm the test fails before the fix or clearly covers the corrected branch after the change.
- Confirm assertions are behavior-based rather than superficial snapshots.
- Confirm the test scope stays unit-level and deterministic.

# Constraints

- Do not substitute E2E-only evidence for unit-testable logic.
- Do not use this skill to bypass the repository default ban on test-case authoring.
- Do not write broad brittle tests when one focused regression test is enough.
- Do not bury critical assertions in indirect helper chains.
- Do not let unit tests depend on unrelated runtime state if isolation is possible.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.

