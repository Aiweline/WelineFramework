---
name: 单元测试工程师-测试数据与回归
description: Unit test engineer skill for stable fixtures, edge-case data design, and regression-oriented test inputs in WelineFramework. Disabled by default; use only when the user explicitly asks for test data, fixtures, or regression test inputs.
version: 1.1.1
---

# Role

This skill is disabled by default. Use it only when the current user request explicitly asks for test data, fixtures, or regression test inputs.

# When To Use

- Use only for explicit user requests mentioning fixture, test data, regression case, dataset, boundary-case input, or reproducible test input.
- Do not infer this skill from ordinary bug fixes, validation needs, or edge-case reasoning.

# Source Material

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/skills/testing/SKILL.md`
- `dev/ai/skills/community-module/SKILLS-CONSOLIDATED.md`
- `dev/ai/skills/php84-performance/SKILL.md`

# Responsibilities

- Build stable and realistic test inputs for changed logic only after confirming explicit user intent.
- Cover null safety, boundary values, invalid shapes, and historical regression patterns.
- Keep test data readable and close to the business rule being protected.
- Reduce flakiness by removing unnecessary dependence on ambient state.

# Workflow

1. Confirm the current user request explicitly asks for test data, fixtures, or regression test inputs.
2. Read the defect or feature behavior and identify the minimum input combinations that matter.
3. Convert known bugs and edge conditions into explicit datasets or fixtures.
4. Add null, empty, duplicate, and invalid-shape cases where the requested test path warrants them.
5. Keep test data local, named, and understandable.
6. Run the focused unit suite only when execution was requested or needed for the requested test-data work.
7. Remove redundant datasets that do not increase defect detection value.
8. Document the key regression scenario in the test naming or comments if needed.

# Weline Rules

- Prefer small, isolated, testable changes.
- Do not author, update, or run unit tests, fixtures, regression cases, or test data unless the current user request explicitly asks for that work.
- Follow PHP null-safety expectations when building regression cases.
- Keep module boundaries intact when preparing fixtures or collaborators.

# Inputs Required

- The changed logic and known failure modes.
- The explicit user request that authorizes test-data or fixture work.
- Historical bug symptoms, edge cases, or stack-trace triggers.
- Existing fixture style in the target module.
- Focused unit-test command for verification.

# Expected Output

- Focused datasets or fixtures that reproduce meaningful edge cases.
- Updated regression coverage with clear input intent.
- Evidence that the new datasets pass after the fix and protect the failure mode.

# Validation

- Run focused unit tests that consume the new datasets or fixtures only when the user explicitly requested unit-test execution.
- Confirm the added cases cover meaningful branches or prior bugs.
- Confirm no dataset depends on unrelated mutable global state.
- Confirm fixture complexity stays justified by risk.

# Constraints

- Do not add bulky generic fixtures that hide the real regression case.
- Do not use this skill to bypass the repository default ban on test-case authoring.
- Do not depend on random values or time-sensitive data without control.
- Do not create test data that crosses module boundaries without a strong reason.
- Do not duplicate many near-identical datasets when one explicit case is enough.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.

