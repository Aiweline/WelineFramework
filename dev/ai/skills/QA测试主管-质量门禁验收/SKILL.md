---
name: QA测试主管-质量门禁验收
description: Review completed Weline validation evidence and issue a scope-level acceptance decision. Use only after implementation evidence exists and QA signoff is requested; route release readiness to the CI gate and remediation/test authoring to the owning skill.
---

# QA acceptance gate

## Workflow

1. Read the changed scope, required risk/evidence matrix, implementation summary, and raw results.
2. Match each claim to concrete command, Browser, runtime, data, permission, or documentation evidence.
3. Mark every required gate `pass`, `fail`, `missing`, or `not applicable` with a reason.
4. Return failed/missing items to their owner with the exact proof still required.
5. Issue `accepted`, `conditionally accepted`, or `rejected` for the changed scope and list residual risks.

## Gate rules

- Summary claims without underlying evidence do not pass.
- Evidence must exercise the actual changed surface and include required isolation/cleanup.
- Focused tests are blockers only where the risk strategy requires them.
- This skill does not issue a deployment/release decision; route that to `CI发布工程师-CI与发布门禁`.

