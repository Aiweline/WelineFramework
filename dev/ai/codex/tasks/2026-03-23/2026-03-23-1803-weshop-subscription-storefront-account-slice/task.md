# Task: weshop subscription storefront account slice

- Task ID: 2026-03-23-1803-weshop-subscription-storefront-account-slice
- Started: 2026-03-23 18:03
- Status: in_progress
- Owner: Codex
- Source: subagent request: subscription clean route/page-data/default theme hook + tests

## Goal

- Complete a production-usable `WeShop_Subscription` storefront/account slice with:
- clean route support (`/subscription*`)
- thin frontend controllers backed by page-data services
- default-theme pages under `app/design/WeShop/default/frontend/pages/subscription/`
- account-center entry injection through `WeShop_Customer::frontend::account::orders::cards`
- at least one PHPUnit suite for the new logic

## Scope

- In scope:
- `app/code/WeShop/Subscription/**`
- `app/design/WeShop/default/frontend/pages/subscription/**`
- this task workspace files
- Out of scope:
- `WeShop_Theme` and `Weline_Theme` module source code
- shared host customer page templates

## Constraints

- Do not revert unrelated dirty worktree changes.
- Keep backward compatibility for legacy subscription routes where possible.

## Related Plans

- None yet.

## Related Files

- `app/code/WeShop/Subscription/**`
- `app/design/WeShop/default/frontend/pages/subscription/**`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
