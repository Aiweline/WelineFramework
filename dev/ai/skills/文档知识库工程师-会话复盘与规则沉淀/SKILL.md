---
name: 文档知识库工程师-会话复盘与规则沉淀
description: Extract confirmed reusable lessons from user corrections, repeated failures, or accepted Weline practices and merge them into the smallest owning rule, skill, or memory. Use for retrospectives, learning, rule or skill cleanup, and “以后别再这样”; never promote one-off incidents or raw chat transcripts.
---

# 会话复盘与规则沉淀

## Promotion standard

Promote a lesson only when all are true:

1. A wrong default, repeated omission, user correction, or failed validation is identifiable.
2. The correct behavior is supported by user direction, code, verified runtime evidence, or a stable framework contract.
3. The behavior is reusable beyond the current file or transient environment.

Do not persist guesses, credentials, temporary paths, raw chat, or a duplicate of an existing rule.

## Minimal record

Capture only:

- Trigger: when the lesson applies.
- Root cause: the reusable mechanism, not “forgot” or “careless”.
- Do / Avoid: the shortest executable correction.
- Verify: evidence that distinguishes the correct result.
- Owner: the single file or skill that should carry it.

Evidence that is useful only for this task belongs in its task record, not in default-loaded guidance.

## Placement

| Scope | Owner |
|---|---|
| Cross-role Weline invariant | `dev/ai/global-constraints.md` |
| Specialized workflow or technical contract | The matching `dev/ai/skills/*/SKILL.md` |
| Skill discovery or routing | `dev/ai/skills/_index.md` |
| Repository-specific instruction | The repository's `AGENTS.md` |
| Current-task evidence | `dev/ai/codex/tasks/**` |
| Superseded history | `dev/ai/archive/**` |

Do not copy project-specific lessons into sibling repositories or global behavior.

## Workflow

1. Identify the correction and its evidence.
2. Search the owning active rule or skill for the same root cause.
3. Merge or tighten existing text; add a new rule only when no owner exists.
4. Remove the stale or duplicate wording that the new text replaces.
5. Check routing, links, Markdown/frontmatter, and any behavior affected by executable guidance.
6. Report the changed owner, evidence, and any unresolved uncertainty.

Use `文档知识库工程师-技能索引与知识库` only when the discovery structure or index itself changes.

## Quality bar

- Entry files route; they do not repeat full policies.
- Global rules contain cross-cutting constraints, not domain tutorials.
- Skills describe exact triggers, boundaries, workflow, and validation; long catalogs or examples live in references.
- A one-off incident stays in the task record unless later evidence makes it reusable.
- Validation is proportional: link/frontmatter/diff checks for guidance, runtime checks only when behavior or tooling changes.
