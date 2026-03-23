# Task: weshop-2fa-self-service-slice

- Task ID: 2026-03-23-1020-weshop-2fa-self-service-slice
- Started: 2026-03-23 10:20
- Status: in_progress
- Owner: Codex
- Source: user request
- Follows: `dev/ai/codex/tasks/2026-03-23/2026-03-23-0951-weshop-google-account-center-slice/`

## Goal

- Add practical 2FA self-service enablement for WeShop storefront and backend users on top of the existing challenge flow.
- Keep the flow compatible with the framework's `Weline_TwoFactorAuth` module and the existing WeShop auth orchestration.
- Group backend Google binding and 2FA under a shared account-security IA without touching `WeShop_Theme` or `Weline_Theme`.

## Scope

- In scope:
  - inspect the current `Weline_TwoFactorAuth` capabilities
  - identify the right self-service entry points for storefront and backend
  - implement the next smallest usable slice
  - add focused tests and verification
- Out of scope:
  - full unified `/api/rest/v1/weshop/auth/*` implementation
  - broad ecommerce domain work outside auth/security

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme` internals.
- Prefer framework-native 2FA services and avoid creating a second 2FA protocol.
- Keep controller logic thin and service-driven.
- Task state must live only in this task workspace; `dev/ai/codex/ACTIVE.md` is deprecated for mutable progress.
- Full `php bin/w setup:upgrade --yes` is currently blocked by an unrelated parse error in `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`, so verification must stay module-scoped.

## Related Files

- `app/code/WeShop/Auth/`
- `app/code/WeShop/GoogleAuth/`
- `app/code/WeShop/Customer/`
- `app/code/Weline/TwoFactorAuth/`
- `generated/routers/frontend_pc.php`
- `generated/routers/backend_pc.php`

## Resume

- Start with [`plan.md`](./plan.md) and [`progress.md`](./progress.md).
