---
name: 框架核心工程师-命令与代码生成
description: Create or review Weline CLI commands, console registration, scaffolding, templates, and framework-safe generated PHP across supported environments. Use for command entry points or reusable code generators; use environment compatibility for shell transport.
---

# Commands and code generation

## Boundary

- Commands orchestrate input/output and call services; they do not own complex business rules.
- Generated code follows current Weline patterns and is refreshed through its registry/collector, never by editing generated output.
- Do not assume a new command/provider/Taglib file is discoverable until its owning metadata refresh proves it.

## Workflow

1. Inspect existing command signatures, services, templates, registry/collector, and target platform.
2. Implement the entry point or generator with explicit flags, errors, and dependencies.
3. Refresh only the relevant discovery layer; command registration uses `php bin/w command:upgrade`.
4. Validate a focused success/failure scenario and inspect generated source/output.
5. For shell composition, use `CI发布工程师-环境兼容与命令安全` to verify quoting on the target platform.
6. Report usage, refreshed registry, generated paths, platform assumptions, and result.

If a broad upgrade times out, use the narrowest listing/dry-run/bootstrap check that proves discovery; do not infer registration from file existence.

