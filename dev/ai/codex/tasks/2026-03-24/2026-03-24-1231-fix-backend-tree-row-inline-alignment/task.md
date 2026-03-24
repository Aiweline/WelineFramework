# Task: Fix backend tree row inline alignment

- Task ID: 2026-03-24-1231-fix-backend-tree-row-inline-alignment
- Started: 2026-03-24 12:31
- Status: completed
- Owner: Codex
- Source: 用户反馈：箭头和选择的文字等应该一行

## Goal

- Fix the ACL role-assignment tree row so the expand arrow, checkbox, icon, title, type badge, and count badge stay on one visual line.

## Scope

- In scope:
- `Weline_Acl` backend role-assignment tree row layout
- CSS-only alignment changes local to the affected template
- Out of scope:
- ACL data generation, counts, badges, and tree business logic

## Constraints

- Keep the change minimal and local to the affected backend template.
- Do not modify shared vendor `jstree` assets.

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Acl/view/templates/Backend/Acl/Role/assign.phtml`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
