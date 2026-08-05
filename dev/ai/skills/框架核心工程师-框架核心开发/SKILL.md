---
name: 框架核心工程师-框架核心开发
description: Implement or review WelineFramework-owned internals, shared abstractions, base classes, dependency/runtime behavior, and platform contracts. Use when the changed code or contract belongs to framework core even if the first symptom appears in one module; use business-module skills when that module owns the behavior.
---

# Framework core development

## Boundary

- Confirm the owner from shared core paths and contracts, not merely from the number of affected modules.
- If one module owns the behavior and no shared contract is defective, keep the change module-local.
- Preserve existing public contracts unless the task explicitly changes them; identify downstream compatibility before editing.
- Do not patch a downstream symptom when a shared command, importer, controller, or runtime owner is defective.

## Workflow

1. Locate the minimal shared entry points with targeted project-intelligence/source evidence and current tests.
2. Trace callers and affected runtime paths; distinguish contract, implementation, and migration impact.
3. Implement the smallest root-cause change.
4. Add focused regression coverage when the shared risk warrants durable proof.
5. Validate through the actual setup, command, HTTP, Browser, WLS, or focused test surface.
6. Update architecture/API documentation only when the shared design or interface changed.

## Core-specific guardrails

- Do not introduce process-global mutable request state.
- Outside runtime context assembly, use `WelineEnv`, `w_env*`, request objects, or explicit Context instead of `$_SERVER`.
- A temporary `$_SERVER` bridge is limited to Fiber/WLS request-context assembly and must materialize explicit context objects.
- Report affected module contracts, migration needs, decisive evidence, and residual risk.

