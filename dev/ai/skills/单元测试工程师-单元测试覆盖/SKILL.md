---
name: 单元测试工程师-单元测试覆盖
description: Author, update, review, or diagnose focused Weline PHPUnit/Pest unit or integration tests for services, helpers, models, and regressions. Use when durable PHP coverage is explicitly requested or selected by the testing router; do not load for routine non-test validation or browser journeys.
---

# Focused PHP test coverage

## Workflow

1. Confirm that a unit/integration test is the narrowest durable proof and inspect nearby module tests.
2. Identify one service, helper, model, or collaborator boundary and the regression it must distinguish.
3. Add the smallest behavioral assertion; extract a testable seam only when the production design benefits.
4. Run the narrowest supported PHPUnit/Pest command.
5. Report the command, result, cleanup, and protected regression.

## Quality bar

- Prefer behavior assertions over snapshots or implementation trivia.
- Keep inputs deterministic and independent of unrelated runtime state.
- A focused test complements, rather than replaces, route/UI evidence.
- Do not expand one regression into unrelated fixtures or broad coverage work.
- When practical, show the test would fail for the original defect; otherwise explain the branch it protects.

Exact commands and layouts are routed through `testing` and the target module's current test configuration.

