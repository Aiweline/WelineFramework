---
name: Codex Recovered Plan - PageBuilder Site Builder
overview: Recover the unfinished PageBuilder-native site-builder feature plan from Codex session logs.
source_session: C:\Users\17142\.codex\sessions\2026\03\21\rollout-2026-03-21T10-32-29-019d0e3c-a905-76c2-849c-8e2117376d1d.jsonl
source_timestamp: 2026-03-21T04:40:49.188Z
status: pending
isProject: true
todos:
  - id: codex-pagebuilder-site-builder-1
    content: Inspect PageBuilder and Websites models, render pipeline, quick-build and domain services, and existing site-builder references
    status: in_progress
  - id: codex-pagebuilder-site-builder-2
    content: Implement persistence-layer changes for session and event storage, virtual-theme models, and Page fields
    status: pending
  - id: codex-pagebuilder-site-builder-3
    content: Add hybrid theme source infrastructure and refactor render and style selection to support file and virtual themes
    status: pending
  - id: codex-pagebuilder-site-builder-4
    content: Implement draft preview and layout or component services plus backend controllers for the PageBuilder site builder
    status: pending
  - id: codex-pagebuilder-site-builder-5
    content: Build the PageBuilder-native site-builder UI, SSE endpoints, menu, ACL, i18n, and final site materialization flow
    status: pending
  - id: codex-pagebuilder-site-builder-6
    content: Run setup upgrades and targeted verification
    status: pending
---

# Codex Recovered Plan - PageBuilder Site Builder

## Recovery Note

Recovered from the last `update_plan` found in the source session. This reflects the plan state in the session log and has not been revalidated against the current repository.

## Original Explanation

This work spans schema, services, render pipeline, controllers, and backend UI. I’m sequencing it so data contracts and hybrid theme support land before SSE/UI wiring.
