---
name: weline-framework-core
description: Use for WelineFramework repository work: module development, controllers, models, env/config, i18n, setup:upgrade, route registration, http:request verification, and framework-specific guardrails. Trigger when the task mentions WelineFramework, Weline modules, `php bin/w`, backend/frontend controllers, `#[Col]`, menu.xml, env.php, or framework coding conventions.
---

# Weline Framework Core

Use this skill when working in `E:\WelineFramework\DEV-workspace`.

## Quick workflow

1. Read [`references/guardrails.md`](references/guardrails.md) first.
2. For general framework development, read [`references/dev-workflow.md`](references/dev-workflow.md).
3. If the task is domain-specific, use [`references/repo-skill-map.md`](references/repo-skill-map.md) to choose the matching repo skill under `dev/ai/skills`.

## Non-negotiables

- Do not invent framework APIs. Verify methods in code/docs before using them.
- Do not edit `generated/`.
- Do not add `routes.xml`; use `php bin/w setup:upgrade --route`.
- Schema changes go through Model attributes like `#[Col]` and `#[Index]`, then `php bin/w setup:upgrade`.
- User-facing text must use `__()` / `<lang>` / i18n CSVs.
- For backend deletion/confirmation flows, use framework notification components instead of browser native dialogs.

## Validation defaults

- After code changes, prefer `php bin/w http:request ...` for route/function verification.
- For frontend or backend UI changes, also validate visually when practical.
