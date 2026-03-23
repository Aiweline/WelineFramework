# Progress - bt-center-server-management

- 2026-03-23 03:20 Created the task workspace.
- 2026-03-23 17:30 Recovered prior analysis, confirmed `Bt_Center` CRUD exists, and identified notification/contact routing gaps that affect Telegram support.
- 2026-03-23 18:20 Confirmed the namespace/path mismatch fix was still needed for `Weline_Bt_Center`: schema diff now creates `m_weline_bt_server`, but the seed data did not backfill because the module had already advanced to `1.1.0`.
- 2026-03-23 18:45 Added `DefaultBtServerSeeder`, new `Setup/Install.php`, and `Weline\Bt\Center\Setup\Install` wrapper; simplified `Setup/Upgrade.php` to use the shared seeder and bumped the module to `1.1.1` so existing installs run the seeding upgrade.
- 2026-03-23 18:58 Re-ran `php bin/w setup:upgrade -m Weline_Bt_Center`; confirmed the 6 provided BT servers were inserted into `m_weline_bt_server`.
- 2026-03-23 19:00 Re-ran `cron:task:collect` and forced `bt_server_health_check` twice; first run marked all 6 panels `up` with HTTP `200`, second run reported `0` state changes and `0` notifications as expected.
- 2026-03-23 19:02 Re-ran focused PHPUnit for `BtServerMonitorServiceTest` and `TelegramAdapterTest`; assertions passed and PHPUnit still returned warning status because the environment has no code coverage driver.
- 2026-03-23 19:03 Verification note: framework `database:query` returned stale cached SELECT results for immediate post-cron checks, so direct `psql` queries were used to confirm persisted BT health fields.
