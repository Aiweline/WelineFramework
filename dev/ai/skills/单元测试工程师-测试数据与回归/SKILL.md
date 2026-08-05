---
name: 单元测试工程师-测试数据与回归
description: Design the smallest stable Weline fixture, dataset, boundary input, or regression case required by a focused test. Use when fixture/test-data work is explicitly requested or selected by the testing router; do not use for production imports, ambient shared data, or broad unrelated suites.
---

# Focused regression data

## Workflow

1. Identify the focused test, failure mode, and minimum meaningful input combinations.
2. Add only boundary, null, empty, duplicate, invalid-shape, or historical cases that distinguish the behavior.
3. Keep data local to the test, named by intent, deterministic, and easy to clean.
4. Run the consuming focused test and remove cases that add no detection value.
5. Report the protected input and cleanup state.

## Quality bar

- Follow the target module's existing fixture style.
- Control time, randomness, IDs, files, database rows, cache, and queue state.
- Do not create cross-module or ambient fixtures without a demonstrated need.
- Prefer one explicit regression case to many near-identical datasets.
- Fixture complexity must be justified by the changed risk.

