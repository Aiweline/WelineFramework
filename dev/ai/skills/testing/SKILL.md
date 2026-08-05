---
name: testing
description: Choose or design Weline test coverage, locate supported runners, diagnose a failing test, or review test strategy across PHPUnit/Pest, Vitest, HTTP/Browser smoke, PostgreSQL integration, and Playwright. Use when test work itself needs a decision or artifact; routine validation of a straightforward change stays with the owning skill.
---

# Testing router

## Choose the proof

| Behavior or risk | Primary proof | Specialist when needed |
|---|---|---|
| Pure PHP branch, service, helper, normalizer | Focused PHPUnit/Pest unit test | `单元测试工程师-单元测试覆盖` |
| Framework bootstrap, ORM, persistence, filesystem integration | Focused integration test | Unit-test skill; add ORM skill for persistence contracts |
| Deterministic browser utility | Focused Vitest test | Owning frontend skill |
| Route registration or HTTP status | `http:request` or bounded HTTP probe | Route/UI smoke skill only for diagnosis or a dedicated smoke task |
| Rendered layout or one interaction | In-app Browser on the real route | Owning frontend skill |
| Auth, cookies, redirects, or multi-step journey | Focused Playwright case | `E2E自动化工程师-端到端流程测试` |
| Reproducible boundary dataset | Small test-owned fixture | `单元测试工程师-测试数据与回归` |

Do not use a heavier layer to hide an easier assertion, and do not claim browser behavior from unit-only evidence.

## Test-specific constraints

- Follow the target module's existing layout. If none exists, use its supported PHP or frontend runner convention.
- Keep assertions behavioral and deterministic. Track and clean every row, file, cache key, queue item, or setting created by a test.
- PostgreSQL evidence is required when schema, transaction, locking, JSON, or persistence semantics matter.
- Test harness helpers do not authorize native production fetch/XHR/axios.
- Never store or guess credentials in tests.

## Workflow

1. Identify the changed behavior, owning module, nearby tests, and supported runner.
2. Select the narrowest proof and load only its specialist skill when authoring or diagnosing that layer.
3. Reproduce the risk with one focused assertion or real-entry check.
4. Run the smallest stable command; expand only when risk or a failure requires it.
5. Report command/route, observed result, cleanup, and unverified gaps.

Read [references/commands-and-layouts.md](references/commands-and-layouts.md) only for exact runner paths and flags.
