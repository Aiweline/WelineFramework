---
name: CI发布工程师-CI与发布门禁
description: Assess Weline CI, pre-merge, deployment, and release readiness from repeatable evidence. Use for pipeline gates, preflight checks, release signoff, automation readiness, flaky prerequisites, or deciding whether a change may advance; do not use it to implement features or mutate production.
---

# CI and release gates

## Load

- The target pipeline/release documentation and returned validation evidence
- `testing` or a QA skill only when that domain is part of the gate

## Workflow

1. Resolve the exact merge/release target, changed surfaces, and required confidence.
2. Map each material risk to a repeatable gate; reuse focused existing tests and add task-scoped checks when the requested change needs them.
3. Run cheap deterministic gates before runtime, Browser, or deployment checks.
4. Verify commands are bounded, non-interactive, portable to the actual runner, and free of hidden local state.
5. Separate feature failure, gate failure, and environment/precondition failure.
6. Return `ready`, `not ready`, or `blocked`, with decisive evidence and the minimum next action.

## Rules

- Do not approve from intuition, green-looking UI, or incomplete logs.
- A deployment request authorizes the documented delivery flow, not unrelated application-code fixes or gate bypasses.
- Use a unique WLS test instance; never port `9501`; clean it up or explicitly hand it off.
- Update release-facing API/architecture documentation when the contract changed.
- Remote execution requires an explicitly confirmed target and usable documented transport/credentials. If the transport is unavailable, report a blocker.
- Do not substitute Chrome/JumpServer/Luna/BaoTa terminals, OS focus automation, or arbitrary SSH for a missing release transport.

## Output

- target and decision;
- gates passed/failed/not run;
- exact commands or acceptance surfaces and results;
- environment blockers, residual risk, and rollback trigger;
- live URL/instance handoff only when it actually exists.
