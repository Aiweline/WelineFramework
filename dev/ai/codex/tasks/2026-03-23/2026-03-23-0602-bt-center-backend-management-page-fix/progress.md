# Progress - bt center backend management page fix

- 2026-03-23 06:02 Created the task workspace.
- 2026-03-23 06:03 Loaded workspace startup context (`SOUL.md`, `USER.md`, memory, `dev/ai/codex/README.md`) and resumed prior BT task context from `2026-03-23-0320-bt-center-server-management`.
- 2026-03-23 06:04 Selected repo guidance chain: `codex-task-workspace`, `weline-routing`, `module-development`, `testing`.
- 2026-03-23 06:05 Defined this follow-up scope as backend route/UI restoration for `Weline_Bt_Center`, separate from the already-completed monitoring/Telegram feature task.
- 2026-03-23 06:13 Implemented the first backend fix set: added module env router config, switched BT backend menu/template paths from underscore URLs to backend helper paths, and polished the list/form layout structure for better backend alignment.
- 2026-03-23 06:15 Tried setup:upgrade --route, but this repo's CLI parser rejected --route despite advertising it; switched to setup:upgrade -m Weline_Bt_Center --yes for route refresh.
- 2026-03-23 06:23 Confirmed the deeper route-registration root cause: module metadata exposed `namespace_path = Weline\Bt\Center`, but the real backend controller still declared `namespace Weline\Bt_Center\Controller\Backend`, so route collection skipped the controller entirely.
- 2026-03-23 06:28 Moved the BT backend controller onto the `Weline\Bt\Center\Controller\Backend` namespace expected by the module scanner and added a compatibility `class_alias()` back to `Weline\Bt_Center\Controller\Backend\BtServer`.
- 2026-03-23 06:57 Re-ran `php bin/w setup:upgrade -m Weline_Bt_Center --yes` and verified `generated/routers/backend_pc.php` now contains `bt_center/backend/bt-server` plus list/form/save routes pointing at `Weline\Bt\Center\Controller\Backend\BtServer`.
- 2026-03-23 07:01 Verified the real local backend entry uses the random `app/etc/env.php` backend prefix (`f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c`), not a literal `/backend` path; direct unauthenticated `curl -I` probes to the BT list/form URLs now return login redirects instead of `404`.
- 2026-03-23 07:04 Found one more functional mismatch: the BT list page delete button submitted `POST`, but the generated route for `delete()` was `DELETE`, so the delete flow still 404ed even after the list/form routes were fixed.
- 2026-03-23 07:09 Renamed the BT delete action to `postDelete()`, refreshed routes, and verified the generated delete route is now `bt_center/backend/bt-server/delete::POST`; unauthenticated `POST` probes now redirect to login instead of returning `404`.
