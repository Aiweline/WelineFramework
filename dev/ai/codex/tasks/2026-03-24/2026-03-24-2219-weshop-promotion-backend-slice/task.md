# Task: weshop promotion backend slice

- Task ID: 2026-03-23-2219-weshop-promotion-backend-slice
- Started: 2026-03-23 22:19
- Status: in_progress
- Owner: Codex
- Source: Complete Promotion backend IA/menu, harden coupon admin controllers, and add targeted tests after storefront slice

## Goal

- finish the Promotion backend slice so coupons can be managed via a marketing menu entry backed by a repository/service layer and protected by targeted unit tests

## Scope

- In scope:
  - marketing menu entry and dashboard template for the coupon backend
  - PromotionCouponRepository/PromotionCouponManagementService to encapsulate summary and persistence logic
  - controller hardening for `Index` and `Save`
  - targeted PHPUnit coverage for the management service
  - documentation updates inside this task workspace
- Out of scope:
  - storefront cart/checkout coupon application flows

## Constraints

- Keep backend controllers thin and let the service/repository pair manage validation and persistence; keep template work minimal.

## Related Plans

- None yet.

## Related Files

- See plan/progress/result for tracked files.

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
