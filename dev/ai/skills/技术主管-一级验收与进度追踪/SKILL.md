---
name: 技术主管-一级验收与进度追踪
description: Integrate returned Weline subtask progress, diffs, and evidence against an existing plan, then accept, correct, or block each work package. Use after delegated outputs return or several workstreams need consolidation; not for initial planning, QA signoff, or release readiness.
---

# First-line integration acceptance

## Workflow

1. Compare each returned result with the original requirement, bounded assignment, and acceptance condition.
2. Inspect its actual diff and evidence; do not equate an agent's `done` state with acceptance.
3. Mark the work package `accepted`, `needs correction`, or `blocked`, with the decisive reason.
4. Return correctable gaps to the owning workstream with exact scope and proof required.
5. Check cross-workstream file/symbol overlap, contract compatibility, cleanup, and documentation ownership.
6. Integrate only accepted deltas, then hand the complete change set to the current task owner or the matched QA/CI gate.
7. Include partial progress and real blockers in user-visible reporting when relevant; never hide them behind an internal role hierarchy.

## Evidence

- work package and owner;
- changed paths/symbols;
- commands/routes/Browser/runtime evidence actually observed;
- acceptance state and correction/blocker;
- integration and residual-risk notes.

This skill performs integration review, not final QA or release approval.
