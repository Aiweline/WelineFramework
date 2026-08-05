---
name: E2E自动化工程师-端到端流程测试
description: Author, execute, or diagnose a focused Playwright-driven Weline journey when auth, cookies, redirects, browser state, or multiple steps require real-browser proof, or when the user explicitly requests E2E work. Ordinary UI changes and route smoke do not trigger it.
---

# Focused end-to-end flow

## Boundary

- Use E2E only when a route probe, Browser smoke, or lower test layer cannot prove the stateful journey.
- Prefer an existing spec/case/filter; add one focused case only when durable coverage is proportionate.
- Run through `php bin/w e2e:run`, not an ad hoc runner or unrelated suite.

## Workflow

1. Define the exact user journey, state/auth prerequisites, changed risk, and success condition.
2. Select the smallest existing spec, case ID, or grep scope.
3. Prepare isolated runtime/data state and only supplied/local credentials.
4. Execute, inspect the first divergent user step, and rerun the same narrow scope after correction.
5. Report command, scenario, step evidence, cleanup, flakiness/prerequisite gaps, and result.

Do not hide flaky setup, broaden one case into a suite, or substitute unit/HTTP evidence for state that only the real browser carries.
