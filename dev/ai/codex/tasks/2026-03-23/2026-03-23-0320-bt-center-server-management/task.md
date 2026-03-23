# Task: bt-center-server-management

- Task ID: 2026-03-23-0320-bt-center-server-management
- Started: 2026-03-23 03:20
- Status: completed
- Owner: Codex
- Source: user request to update `Weline_Bt_Center` with server management, 10-minute health checks, and Telegram notifications

## Goal

- Extend `Weline_Bt_Center` with seeded BT servers, health monitoring, a 10-minute cron check, and Telegram-capable notifications.

## Scope

- In scope:
- Seed the 6 provided BT panel servers into `Weline_Bt_Center`
- Add monitor fields and backend UI for BT server health
- Add a cron task that probes server accessibility every 10 minutes
- Send state-change notifications through existing backend notification routing
- Add a Telegram notification adapter and make subscriptions/contacts use the real contact system
- Out of scope:
- Full authenticated BT login automation
- Telegram bot/chat secret provisioning from the user side

## Constraints

- Worktree already has many unrelated changes; avoid touching unrelated files.
- Use the existing Weline backend notification architecture rather than building custom Telegram logic in `Bt_Center`.

## Related Plans

- See `plan.md`.

## Related Files

- `app/code/Weline/Bt_Center/`
- `app/code/Weline/Backend/`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
