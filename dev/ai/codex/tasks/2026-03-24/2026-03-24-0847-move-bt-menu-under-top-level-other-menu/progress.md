# Progress - Move Bt menu under top-level other menu

- 2026-03-24 08:47 Created the task workspace.
- 2026-03-24 09:50 Confirmed the current BT backend menu was nested as `其他工具 -> BT 管理中心 -> 服务器管理` and chose the smallest UI cleanup: collapse it into a single direct `BT 管理中心` entry under `Weline_Backend::other_tools_group`.
- 2026-03-24 09:53 Updated `app/code/Weline/Bt_Center/etc/backend/menu.xml` so `Weline_Bt_Center::bt_server` is the direct menu node under `other_tools_group`, and removed the extra `bt_center` wrapper node.
- 2026-03-24 09:54 Updated `app/code/Weline/Bt_Center/Controller/Backend/BtServer.php` to drop the removed `Weline_Bt_Center::bt_center` ACL parent from the class-level ACL attribute.
- 2026-03-24 09:57 Validation note: `php bin/w menu:collect -m Weline_Bt_Center` fails framework parent-chain validation for cross-module parents because the filtered collect cannot see `Weline_Backend::other_tools_group`; switched to full `php bin/w menu:collect` instead.
- 2026-03-24 10:00 Verified PHP syntax and runtime menu ACL state. `m_acl` now shows `Weline_Bt_Center::bt_server -> Weline_Backend::other_tools_group` and no remaining `Weline_Bt_Center::bt_center` menu ACL row.
