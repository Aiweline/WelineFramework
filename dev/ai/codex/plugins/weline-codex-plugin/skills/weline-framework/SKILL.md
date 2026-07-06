---
name: weline-framework
description: Entry skill for WelineFramework development in Codex. Use when working in the WelineFramework repository, routing Weline tasks to role skills, applying repository guardrails, or validating Weline changes.
---

# WelineFramework Entry Skill

Use this skill as the first plugin entry point for WelineFramework work in Codex. When installed from GitHub, prefer a sparse `dev/ai` checkout so `dev/ai/AI-RULES-PACK.md` is available next to the plugin source.

## Required Reading Order

1. Read repository `AGENTS.md`.
2. Read `AI-ENTRY.md`.
3. Read `dev/ai/AI-RULES-PACK.md`.
4. Read `dev/ai/global-constraints.md`.
5. Read `dev/ai/skills/_index.md`.
6. Load only the 1 to 3 role skills matched to the current task.
7. Read diagrams, module docs, source files, tests, and configuration only when relevant.

## Routing

- General engineering, code quality, safe edits, validation evidence: `通用工程师-开发规范与代码质量`.
- Skill indexes, knowledge routing, or AI documentation structure: `文档知识库工程师-技能索引与知识库`.
- CI, release gates, publish readiness, or automation safety: `CI发布工程师-CI与发布门禁`.
- Runtime, WLS, Session Server, SSE, reload/restart behavior: matching `WLS运行时工程师-*`.
- UI, browser-visible pages, components, layouts, and visual quality: matching `前端主题工程师-*` plus `ui-ux-pro-max`.
- QueryProvider, worker, or frontend API chains: `前端主题工程师-前端API交互`.
- Code graph exploration, impact analysis, debugging, refactoring, or GitNexus CLI work: matching `gitnexus-*`.

## Non-Negotiables

- Do not modify generated Weline artifacts directly.
- Do not touch default WLS port `9501` during AI testing.
- Do not edit functions, classes, or methods before GitNexus upstream impact analysis.
- Do not commit without GitNexus change detection.
- Preserve unrelated user changes in the worktree.
- Validate through the closest repeatable command and report any unverified surface honestly.

## Plugin Notes

This plugin bundles WelineFramework skills for Codex discovery. The repository source of truth remains the WelineFramework repository documents, especially `AI-ENTRY.md`, `dev/ai/AI-RULES-PACK.md`, `dev/ai/global-constraints.md`, and `dev/ai/skills/_index.md`.
