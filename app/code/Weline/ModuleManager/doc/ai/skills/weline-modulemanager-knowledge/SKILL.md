---
name: weline-modulemanager-knowledge
description: "Deterministic documentation and source locator for Weline_ModuleManager; use for tasks owned by this module."
---

<!-- weline:mcp-skill:auto-generated -->
# Weline_ModuleManager knowledge locator

## Role

Route work to the exact module documentation and indexed source facts. This skill is a derived locator, not an independent policy source.

## When To Use

Use for tasks whose owning path or symbol is inside `app/code/Weline/ModuleManager`.

## Load First

- `AI-ENTRY.md`
- `dev/ai/global-constraints.md`
- `app/code/Weline/ModuleManager/doc/AI-INDEX.md`
- `app/code/Weline/ModuleManager/doc/README.md` (`sha256:682bf7139ce5717cc2fc2e9b71d4816a6814d801a50c6dc93eb2ba4fc185b7f1`)
- `app/code/Weline/ModuleManager/doc/后台模块列表检索与过滤.md` (`sha256:2dc555ae20259c2635f51f22956a81048818ce7e1f32d6199286e47325116379`)
- `app/code/Weline/ModuleManager/doc/模块卸载数据库备份与恢复系统架构.md` (`sha256:756937d0483776f6840678c009dbeb159f5126016d222f0688f6d6c2193465e5`)
- `app/code/Weline/ModuleManager/doc/模块备份恢复API文档.md` (`sha256:f49e2d2775010e697e8fd2c36cdc8b5da8bff7b0a5b43fd3afe08fecdd4e479f`)
- `app/code/Weline/ModuleManager/doc/模块备份恢复使用指南.md` (`sha256:953d76a292caf6392511313ab9c7b74b9a9ee7293828f67f1adba0bf5bb6fa47`)
- `app/code/Weline/ModuleManager/doc/模块备份恢复数据流程图.md` (`sha256:ac6d21f11a2eb98b41c67a8a1edabe97eac908f854fa20504a5f90525369a83b`)

## Workflow

1. Confirm the owning module is `Weline_ModuleManager`.
2. Read the returned exact document paths and hashes; do not scan unrelated files.
3. Ask the MCP for indexed symbols and deterministic drift evidence before proposing changes.
4. Treat source code and module docs as authoritative when this derived skill disagrees.

## Guardrails

- Never treat vector similarity alone as proof that documentation is stale.
- Never edit outside `app/code/Weline/ModuleManager/doc/**` through the documentation pipeline.
- Draft, stale, contested, or deprecated skills are not actionable guidance.
- Do not overwrite a hand-written skill that lacks the generated marker.

## Validation

- Source digest: `sha256:e58a61228f58e423811b0ff056995a08d34a1d0c9933faefe6ae7b80e0965ae7`.
- Code digest: `sha256:58ee54c62e08cc4baffb9ba0aecd9568caeb7248aa92624735cc5379c5dfd0f0`.
- Docs digest: `sha256:29ab87578d92a3359ca26fd08d43174bff3409c944f33d30d74c2ce4a901077f`.
- Re-resolve this skill after any indexed source hash changes.

## Output

Return exact repository-relative paths, symbol or heading locators, content hashes, freshness status, and the required validation entrypoints.
