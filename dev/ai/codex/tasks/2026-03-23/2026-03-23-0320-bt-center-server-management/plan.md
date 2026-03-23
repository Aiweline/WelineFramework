# Plan - bt-center-server-management

## Outcome

- `Bt_Center` stores and displays the requested BT servers, monitors their availability, and emits alert/recovery notifications that can route to Telegram.

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Fix existing `Bt_Center` CRUD/menu/template issues and extend the server schema for monitoring
- [x] Add setup seeding, monitor services, notification topic provider, and 10-minute cron
- [x] Add Telegram adapter and align backend notification routing/subscription contact usage
- [x] Run validation commands
- [x] Update result.md and task notes

## Verification Targets

- [x] PHP syntax checks on touched files
- [x] `php bin/w setup:upgrade`
- [x] Targeted PHPUnit or equivalent focused checks
