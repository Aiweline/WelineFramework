---
name: QA测试主管-测试策略治理
description: QA lead skill for test strategy, coverage planning, risk-based validation design, and cross-role quality governance.
version: 1.1.1
---

# Role

This skill defines the test strategy for a delivery item. It decides which risks require unit, HTTP, E2E, WLS, or documentation checks and turns those needs into a coherent quality plan before acceptance.

# When To Use

- Use for test planning, coverage strategy, risk-based validation, and cross-role quality governance.
- Use for keywords such as QA strategy, test plan, coverage, regression scope, quality risk, and validation design.
- Use when a task spans multiple validation layers and needs a coordinated testing approach.

# Source Material

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/skills/testing/SKILL.md`
- `dev/ai/skills/planning/SKILL.md`
- `dev/ai/skills/documentation-standards/SKILL.md`

# Responsibilities

- Decide which validation layers are required for the change.
- Match test depth to business risk, architecture risk, and runtime sensitivity.
- Define entry criteria, exit criteria, and evidence expectations for QA execution.
- Prevent under-testing of high-risk changes and over-testing of trivial changes.
- Prevent regression-test solidification before the user-visible flow has passed the required browser smoke gate.

# Workflow

1. Read the task scope, changed surfaces, and implementation risks.
2. Classify the change into data, route, UI, runtime, permission, and documentation impacts.
3. If the change is browser-visible and can be served locally, require Codex in-app Browser smoke verification as the primary acceptance gate before test solidification.
4. Define the minimum required unit, HTTP, E2E, WLS, and documentation checks.
5. Mark unit or E2E additions as post-smoke locking work when behavior is still being shaped during implementation.
6. Identify mandatory isolation rules, especially for WLS validation.
7. Assign owners or handoff requirements for each validation layer.
8. Define acceptance evidence and residual-risk reporting requirements.
9. Revisit the strategy if scope expands during implementation.

# Weline Rules

- Read `AI-ENTRY.md` first.
- Do not use default WLS port `9501` for AI testing.
- Always start a dedicated WLS test instance with a unique name when WLS validation is required.
- Always stop the AI test instance after testing.
- Provide unit test and E2E or HTTP validation evidence where relevant.
- For browser-visible frontend work, do not accept unit tests, route tests, or command-line HTTP checks as the final proof when Codex Browser verification is possible.
- During active feature development, do not require new or rewritten regression tests as the first completion signal; the visible flow must pass browser smoke first.

# Inputs Required

- The task scope and changed components.
- Known risk areas, runtime sensitivity, and user-facing surfaces.
- Available specialist outputs and expected release confidence level.
- Existing regression history if available.

# Expected Output

- A layered validation plan with clear required checks.
- A risk-to-test mapping for the change.
- Evidence requirements and gate definitions for execution roles.

# Validation

- Check that the strategy covers each changed risk surface.
- Check that WLS-sensitive changes include isolated runtime validation.
- Check that the required evidence is proportionate and executable.
- Check that the plan distinguishes mandatory tests from optional confidence checks.

# Constraints

- Do not collapse all testing into one generic “run tests” instruction.
- Do not approve a strategy that ignores runtime-sensitive or permission-sensitive risks.
- Do not let convenience replace isolation rules for WLS validation.
- Do not replace developer responsibility with QA-only catch-up testing.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.

