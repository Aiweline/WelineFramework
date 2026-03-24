# Result - Move Bt menu under top-level other menu

## Outcome

- Completed. The BT backend menu is now flattened to a single direct entry under `Weline_Backend::other_tools_group`, instead of the old nested `BT 管理中心 -> 服务器管理` structure.

## Changed Files

- `app/code/Weline/Bt_Center/etc/backend/menu.xml`
- `app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0847-move-bt-menu-under-top-level-other-menu/task.md`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0847-move-bt-menu-under-top-level-other-menu/plan.md`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0847-move-bt-menu-under-top-level-other-menu/progress.md`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0847-move-bt-menu-under-top-level-other-menu/result.md`

## Verification

- `php -l app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`
  Result: passed, no syntax errors.
- `php bin/w menu:collect -m Weline_Bt_Center`
  Result: expected framework limitation for cross-module parent validation; filtered collect cannot resolve `Weline_Backend::other_tools_group`.
- `php bin/w menu:collect`
  Result: passed and refreshed menu/ACL runtime data.
- `psql ... select acl_id, source_id, parent_source, source_name, acl_origin, update_time from m_acl where source_id like 'Weline_Bt_Center::bt%' order by acl_id;`
  Result: verified `Weline_Bt_Center::bt_server` now has parent `Weline_Backend::other_tools_group` and the old `Weline_Bt_Center::bt_center` menu ACL row is gone.

## Remaining Risks

- Legacy `m_menu` table rows still contain the historical BT hierarchy (`bt_center -> bt_server`), but current backend menu tree reads from ACL/menu-collection state in `m_acl`; this task did not migrate that legacy table.

## Next Resume Step

- If UI verification is needed, open the backend sidebar and confirm BT appears directly under `应用工具 -> 其他工具`.
