# Result - bt-center-server-management

## Outcome

- `Weline_Bt_Center` now includes the requested 6 BT panel servers, backend server-management CRUD/health fields, a 10-minute health-check cron, and notification topic integration for BT server health changes.
- `Weline_Backend` now supports a Telegram notification adapter and routes merged channel/contact configuration so Telegram can be configured through the existing notification/contact flow.
- Fresh installs and existing installs are both covered: `Setup/Install.php` seeds the default BT servers for new environments, and `Setup/Upgrade.php` with module version `1.1.1` backfills existing environments.

## Changed Files

- `app/code/Weline/Bt_Center/Controller/Backend/BtServer.php`
- `app/code/Weline/Bt_Center/Cron/BtServerHealthCheck.php`
- `app/code/Weline/Bt_Center/Extends/NotificationTopicProvider.php`
- `app/code/Weline/Bt_Center/Model/BtServer.php`
- `app/code/Weline/Bt_Center/Service/BtPanelProbeService.php`
- `app/code/Weline/Bt_Center/Service/BtServerMonitorService.php`
- `app/code/Weline/Bt_Center/Service/DefaultBtServerSeeder.php`
- `app/code/Weline/Bt_Center/Setup/Install.php`
- `app/code/Weline/Bt_Center/Setup/Upgrade.php`
- `app/code/Weline/Bt_Center/etc/backend/menu.xml`
- `app/code/Weline/Bt_Center/etc/module.xml`
- `app/code/Weline/Bt_Center/extends.php`
- `app/code/Weline/Bt_Center/register.php`
- `app/code/Weline/Bt_Center/view/templates/Backend/BtServer/form.phtml`
- `app/code/Weline/Bt_Center/view/templates/Backend/BtServer/index.phtml`
- `app/code/Weline/Bt/Center/Controller/Backend/BtServer.php`
- `app/code/Weline/Bt/Center/Cron/BtServerHealthCheck.php`
- `app/code/Weline/Bt/Center/Model/BtServer.php`
- `app/code/Weline/Bt/Center/Setup/Install.php`
- `app/code/Weline/Bt/Center/Setup/Upgrade.php`
- `app/code/Weline/Backend/Adapter/Notification/TelegramAdapter.php`
- `app/code/Weline/Backend/Controller/Backend/NotificationSubscription.php`
- `app/code/Weline/Backend/Service/ContactService.php`
- `app/code/Weline/Backend/Service/NotificationRouter.php`
- `app/code/Weline/Backend/extends.php`
- `app/code/Weline/Backend/view/templates/Backend/NotificationSubscription/index.phtml`
- `app/code/Weline/Bt_Center/test/Unit/Service/BtServerMonitorServiceTest.php`
- `app/code/Weline/Backend/test/Unit/Adapter/TelegramAdapterTest.php`

## Verification

- `php -l app/code/Weline/Bt/Center/Model/BtServer.php`
- `php -l app/code/Weline/Bt_Center/Model/BtServer.php`
- `php -l app/code/Weline/Bt_Center/Setup/Upgrade.php`
- `php -l app/code/Weline/Bt_Center/Setup/Install.php`
- `php -l app/code/Weline/Bt_Center/Cron/BtServerHealthCheck.php`
- `php -l app/code/Weline/Bt_Center/Service/DefaultBtServerSeeder.php`
- `php -l app/code/Weline/Backend/Adapter/Notification/TelegramAdapter.php`
- `php bin/w setup:upgrade -m Weline_Bt_Center`
- `php bin/w cron:task:collect`
- `php bin/w cron:task:run -force bt_server_health_check`
- `php bin/w cron:task:run -force bt_server_health_check`
- `vendor\bin\phpunit --bootstrap app/bootstrap_phpunit.php app/code/Weline/Bt_Center/test/Unit/Service/BtServerMonitorServiceTest.php`
- `vendor\bin\phpunit --bootstrap app/bootstrap_phpunit.php app/code/Weline/Backend/test/Unit/Adapter/TelegramAdapterTest.php`
- Direct PostgreSQL verification via `psql`:
- `SELECT server_id,name,external_url,is_enabled,last_check_status FROM m_weline_bt_server ORDER BY server_id;`
- `SELECT server_id,name,last_check_status,last_http_code,last_response_time_ms,last_check_at,last_state_changed_at FROM m_weline_bt_server ORDER BY server_id;`

## Remaining Risks

- The provided BT panel usernames/passwords are now embedded in module seed data for these default server records. If those credentials should not live in code, rotate them and update the records in backend after deployment.
- Telegram delivery still requires backend configuration from the user side: a valid Telegram bot token on the channel config and a chat ID on the contact config/subscription side.
- Framework `database:query` SELECT output may be stale immediately after writes because it goes through the framework query layer; use `psql` or another uncached DB client when verifying fresh persistence.

## Next Resume Step

- In backend notification settings, create or update a Telegram channel/contact with real `bot_token` and `chat_id`, subscribe it to `bt_server_health`, then force the cron again to validate end-to-end delivery.
