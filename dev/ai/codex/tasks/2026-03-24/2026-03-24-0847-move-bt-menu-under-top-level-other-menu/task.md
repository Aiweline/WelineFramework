# Task: Move Bt menu under top-level other menu

- Task ID: 2026-03-24-0847-move-bt-menu-under-top-level-other-menu
- Started: 2026-03-24 08:47
- Status: completed
- Owner: Codex
- Source: user: Bt的菜单挪到顶级其他菜单里面去

## Goal

- Move the `Weline_Bt_Center` backend entry into the top-level "Other Tools" branch without the extra nested `BT 管理中心 -> 服务器管理` layer.

## Scope

- In scope:
- `app/code/Weline/Bt_Center/etc/backend/menu.xml`
- `app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`
- Task workspace notes and verification output for this menu move
- Out of scope:
- Unrelated backend menu restructuring
- BT server feature logic, data model, or templates

## Constraints

- Preserve the existing backend route `*/backend/bt-server`
- Keep changes isolated to the BT menu and ACL wiring

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Bt_Center/etc/backend/menu.xml`
- `app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
