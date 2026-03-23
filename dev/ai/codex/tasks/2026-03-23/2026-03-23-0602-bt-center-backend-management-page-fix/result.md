# Result - bt center backend management page fix

## Outcome

- Fixed the `Weline_Bt_Center` backend management route chain so the menu entry, list page, form page, save action, and delete action are registered and resolve through the expected backend URLs.
- Restored backend template alignment for the BT server list/form pages by switching them to consistent backend helper URLs and tightening the row/card/button structure.
- Preserved compatibility with the historical controller namespace by aliasing `Weline\Bt\Center\Controller\Backend\BtServer` back to `Weline\Bt_Center\Controller\Backend\BtServer`.
- Fixed the last functional blocker in authenticated use: the delete controller now executes the framework delete query with `fetch()`, and the list page uses a working confirm binding so the delete flow completes end to end.

## Changed Files

- `app/code/Weline/Bt_Center/etc/env.php`
- `app/code/Weline/Bt_Center/etc/backend/menu.xml`
- `app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`
- `app/code/Weline/Bt_Center/view/templates/Backend/BtServer/index.phtml`
- `app/code/Weline/Bt_Center/view/templates/Backend/BtServer/form.phtml`

## Verification

- `php -l app/code/Weline/Bt_Center/etc/env.php`
- `php -l app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`
- `php -l app/code/Weline/Bt_Center/view/templates/Backend/BtServer/index.phtml`
- `php -l app/code/Weline/Bt_Center/view/templates/Backend/BtServer/form.phtml`
- `php bin/w setup:upgrade -m Weline_Bt_Center --yes`
- `rg -n "bt_center/backend|bt-server" generated/routers/backend_pc.php`
- `curl.exe -k -I https://127.0.0.1:9982/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/bt_center/backend/bt-server`
- `curl.exe -k -I https://127.0.0.1:9982/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/bt_center/backend/bt-server/form`
- `curl.exe -k -I -X POST https://127.0.0.1:9982/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/bt_center/backend/bt-server/delete`
- Playwright authenticated verification on `https://127.0.0.1:9982/.../bt_center/backend/bt-server`
- `psql -h 127.0.0.1 -p 5432 -U weline_dev -d weline_dev -c "SELECT server_id,name FROM m_weline_bt_server WHERE server_id = 7 OR name = 'BT-Test-Temp-20260323';"`

Verification result:
- Generated backend routes now include BT list/form/save/delete endpoints.
- Unauthenticated requests to the real BT backend URLs now redirect to the admin login page instead of returning `404`, which confirms the routes are being resolved.
- Authenticated browser verification now passes for the BT server list page, add form, add/save redirect, delete confirm modal, and delete refresh behavior.
- Direct PostgreSQL verification confirms the temporary test row (`server_id = 7`, `BT-Test-Temp-20260323`) was physically deleted after the UI delete flow.

## Remaining Risks

- Other framework modules still appear to contain mixed `delete()` vs `postDelete()` / `delete()->fetch()` conventions; this task fixed only `Weline_Bt_Center`.

## Next Resume Step

- If the duplicate legacy delete-binding block in `index.phtml` becomes worth cleaning further, it can be removed in a follow-up now that the stable rebinding layer has been proven live.
