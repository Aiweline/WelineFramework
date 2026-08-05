---
name: weline-framework
description: Locate the authoritative WelineFramework repository guidance and route a task to the smallest relevant canonical skill set. Use for Weline task routing or rule-source questions; this entry does not require loading every rule or skill.
---

# WelineFramework Entry

1. Read repository `AGENTS.md`, then `AI-ENTRY.md`.
2. Follow `dev/ai/global-constraints.md`.
3. Use `dev/ai/skills/_index.md` and load only the skills matched to the task.
4. For module work, follow the owning module's `doc/AI-INDEX.md` before editing.

Plugin role skills are generated adapters; their canonical bodies live under
`dev/ai/skills/`. If those repository files are unavailable, report the
incomplete checkout instead of substituting stale plugin copies.

GitNexus is not bundled by this plugin. Use the repository's optional
compatibility workflow only when `AGENTS.md` permits it.
