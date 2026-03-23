# Task Record

- Started: 2026-03-22 11:55
- Status: completed
- Mode: consultation

## Goal

Evaluate whether the AI site-building workbench should live under `Weline_Websites` while loading a provider-specific toolset via a `code`, so `GuoLaiRen_PageBuilder` or future modules can supply their own workflows and tools.

## Context

- User is asking for architecture judgment only, not implementation.
- Existing context indicates PageBuilder already has AI site-agent/session/workbench related work and Websites owns website/domain concepts.

## Progress

- Session startup files loaded (`SOUL.md`, `USER.md`, `memory`, `MEMORY.md`, `dev/ai/codex/ACTIVE.md`).
- Loaded repo skills: `planning`, `extension-points`.
- Inspecting current plans and module boundaries for `Weline_Websites` and `GuoLaiRen_PageBuilder`.

## Findings

- `Weline_Websites` already owns the website-level entry and default agent concept:
  - backend menu exposes `site_builder_agent`
  - backend controller `SiteBuilderAgent`
  - AI agent code `website_builder`
  - default toolset is bundled in `WebsiteBuilderAgent`
- `GuoLaiRen_PageBuilder` already implements a workflow-heavy workbench:
  - dedicated backend menu `ai_site_agent`
  - session/event persistence service `AiSiteAgentSessionService`
  - SSE workspace, stage control, scope editing, domain status query, AI brief generation, publish checklist
- `QuickBuildAggregator` already demonstrates a useful modular pattern: PageBuilder owns an entry and orchestration surface, but discovers concrete capabilities from other modules via events/query providers.
- `Weline_Websites` also already uses extension contracts (`extends.php` for registrar adapters), so adding a workbench/provider extension contract would fit the repository style.

## Conclusion

- The idea is feasible and structurally better than keeping every AI site-building workbench hardcoded inside `PageBuilder`.
- Recommended split:
  - `Weline_Websites` owns the generic AI site-building workbench entry, website-domain lifecycle concepts, and provider contract selection.
  - Provider modules such as `GuoLaiRen_PageBuilder` own specialized workflow steps, tools, preview/render integrations, and provider-specific session payload details.
- Prefer a provider `code` plus contract-driven registration, but avoid letting `Weline_Websites` know `PageBuilder` internals directly.

## Suggested Boundary

- `Weline_Websites`
  - workbench registry
  - provider selection by `code`
  - generic session shell and provider metadata
  - website/domain/publish orchestration contracts
- `GuoLaiRen_PageBuilder`
  - provider implementation for `code=pagebuilder`
  - virtual-theme and page-preview tools
  - stage flow specialized for PageBuilder
  - optional migration of current `AiSiteAgentSessionService` behind a shared contract

## Risks

- If `Websites` owns too much workflow detail, it will quickly become a second PageBuilder and create reverse dependencies.
- If provider `code` only swaps tool lists but not workflow/state contracts, PageBuilder’s special stages will still leak into the core module.
- Existing PageBuilder session schema may need a platform/provider split instead of being moved wholesale.

## Outcome

- Delivered architecture recommendation only; no product code changed.
- Only task tracking files were updated for workspace continuity.

## Open Questions

- Should the workbench UI entry belong to the domain owner (`Websites`) while tools are supplied by provider modules?
- Should workflow orchestration and session persistence stay in the provider module or be split into platform/core + provider layers?

## Next

- Inspect existing AI site-builder plans and relevant module/menu/service structure.
- Produce a recommended module boundary, extension contract, and risk list.
