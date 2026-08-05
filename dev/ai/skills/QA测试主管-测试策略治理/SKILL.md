---
name: QA测试主管-测试策略治理
description: Design a risk-based Weline validation/evidence plan across command, API, runtime, Browser, permission, data, documentation, and test surfaces. Use when QA strategy, coverage governance, or cross-layer acceptance planning is requested; routine implementation validation stays with the owning skill.
---

# Risk-based validation strategy

## Workflow

1. Map each changed surface to its failure mode, user impact, reversibility, and runtime/data/security sensitivity.
2. Select the smallest real-entry evidence that distinguishes each risk; add durable tests only where regression risk justifies them.
3. Mark mandatory gates, optional confidence checks, prerequisites, cleanup, owners, and blocked evidence.
4. Require Browser evidence for rendered behavior and isolated WLS evidence only for WLS-sensitive behavior.
5. Revisit the matrix when scope changes.

## Output

Return a compact `risk → proof → pass condition → owner` matrix plus residual risks. Do not replace it with “run tests,” demand broad unrelated suites, or use a heavyweight layer where a narrower proof is decisive.

Route exact test-layer choices to `testing`; this skill designs the strategy rather than authoring tests or issuing release approval.

