# Task: bt center backend management page fix

- Task ID: 2026-03-23-0602-bt-center-backend-management-page-fix
- Started: 2026-03-23 06:02
- Status: completed
- Owner: Codex
- Source: follow-up to 2026-03-23-0320-bt-center-server-management: fix backend management page alignment and 404

## Goal

- Restore the `Weline_Bt_Center` backend management UI so the menu entry opens a working page, CRUD links resolve without 404, and the page layout matches backend expectations.

## Scope

- In scope:
- Diagnose the real backend route and ACL/menu wiring used by `Weline_Bt_Center`
- Fix broken backend URLs and any missing module router config
- Align the BT server list/form templates with existing backend layout conventions
- Validate the management page route and basic backend flow
- Out of scope:
- Extending the monitoring / Telegram feature set added in task `2026-03-23-0320-bt-center-server-management`
- Reworking unrelated backend theme/layout systems outside the BT module

## Constraints

- Worktree already contains many unrelated edits; do not revert or disturb them.
- Follow Weline routing/module conventions: backend routes belong in `etc/env.php`, URLs should use framework helpers, and route changes require `php bin/w setup:upgrade --route`.
- Prefer focused route/UI validation over broad refactors.

## Related Plans

- Follows `dev/ai/codex/tasks/2026-03-23/2026-03-23-0320-bt-center-server-management/`.

## Related Files

- `app/code/Weline/Bt_Center/etc/backend/menu.xml`
- `app/code/Weline/Bt_Center/etc/env.php`
- `app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`
- `app/code/Weline/Bt_Center/view/templates/Backend/BtServer/index.phtml`
- `app/code/Weline/Bt_Center/view/templates/Backend/BtServer/form.phtml`
- `app/code/Weline/Bt/Center/...` compatibility wrappers if route aliases depend on them

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
