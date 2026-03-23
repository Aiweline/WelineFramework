# Task: weshop invoice subscription account slices

- Task ID: 2026-03-23-1835-weshop-invoice-subscription-account-slices
- Started: 2026-03-23 18:35
- Status: completed
- Owner: Codex
- Source: user: 提交后继续开发，整模块推进 default 主题账户中心与 clean route 切片

## Goal

- Land the next default-theme storefront/account batch by integrating the worker-completed `WeShop_Invoice` and `WeShop_Subscription` slices with clean routes, account-center card injection, and local verification.

## Scope

- In scope:
  - `WeShop_Invoice` clean route, page-data service, default-theme invoice page, account-center order-card hook, i18n, and tests
  - `WeShop_Subscription` clean route, list/detail page-data services, default-theme pages, account-center hook entry, i18n, and tests
  - Task logging and verification notes for this batch
- Out of scope:
  - Shared host page changes outside already existing customer order-card slots
  - RMA business logic changes
  - Google login, 2FA, unified auth, or checkout/payment orchestration

## Constraints

- Keep changes inside `Invoice` / `Subscription` modules, their default-theme pages, and task logs.
- Do not modify `WeShop_Theme` or `Weline_Theme`.
- Record environment-level `setup:upgrade` failures when they are unrelated to the scoped modules.
- Preserve clean storefront routes and default-theme compatibility.

## Related Plans

- None yet.

## Related Files

- `app/code/WeShop/Invoice/**`
- `app/design/WeShop/default/frontend/pages/invoice/**`
- `app/code/WeShop/Subscription/**`
- `app/design/WeShop/default/frontend/pages/subscription/**`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
