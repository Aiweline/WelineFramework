# Task: WeShop International Ecommerce Implementation

- Started: 2026-03-22 22:50
- Updated: 2026-03-23 02:45
- Status: in_progress
- Owner: Codex
- Source: user request

## Goal

Implement the production auth foundation for WeShop without modifying `WeShop_Theme` or `Weline_Theme` module internals:

- storefront Google login
- backend Google login
- frontend/backend 2FA-aware login orchestration
- default-theme-compatible hook injection
- unified auth slice that can be expanded into the larger ecommerce plan

## Constraints

- Do not modify `WeShop_Theme` or `Weline_Theme` module implementations.
- Work in a dirty tree without reverting unrelated user changes.
- Use framework-native modules, hooks, routes, setup upgrade, and tests.
- Keep changes vertical-slice oriented instead of attempting the entire mega-plan in one turn.

## Completed In This Slice

### 1. Google auth module

Created `WeShop_GoogleAuth` with:

- register/env/menu wiring
- `GoogleBinding` model
- `GoogleBindingService`
- `GoogleOAuthService`
- `GoogleLoginService`
- `BackendWebAuthService`
- frontend controllers for `start`, `callback`, and backend challenge
- backend controller for backend-user Google binding
- hook templates for storefront/backend login buttons
- backend binding page and backend challenge page templates

### 2. Backend password login -> unified challenge flow

Patched [`app/code/Weline/Admin/Controller/Login.php`](e:/WelineFramework/DEV-workspace/app/code/Weline/Admin/Controller/Login.php):

- successful password verification now calls `BackendWebAuthService::beginLoginForBackendUser(...)`
- if 2FA is enabled for the backend actor shadow ID, the controller redirects to `weshop_googleauth/frontend/auth/backend-challenge`
- only after challenge completion does the formal backend session get issued

This aligns backend password login with the same state machine already used by Google login:

- `primary_auth`
- optional `challenge_required`
- final authenticated session

### 3. Hook compatibility and framework compliance

The initially discovered historical hook names were not valid under current hook-spec rules, so they were normalized:

- backend login providers:
  - from `Weline_Admin::backend::login::providers`
  - to `Weline_Admin::backend::partials::login::providers`
- storefront social login buttons:
  - from `WeShop_Social::login::buttons`
  - to `WeShop_Social::frontend::partials::login::buttons`

Updated:

- backend login template
- default storefront login template
- GoogleAuth hook implementation paths
- hook specs in `Weline_Admin` and `WeShop_Social`
- required hook documentation files

### 4. Tests

Added unit tests for the new module:

- [`app/code/WeShop/GoogleAuth/Test/Unit/Service/GoogleLoginServiceTest.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Test/Unit/Service/GoogleLoginServiceTest.php)
  - binding requires a local user id
  - backend Google auth requires an existing binding
- [`app/code/WeShop/GoogleAuth/Test/Unit/Service/BackendWebAuthServiceTest.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Test/Unit/Service/BackendWebAuthServiceTest.php)
  - non-backend challenges are rejected
  - backend challenges are accepted

## Files Touched

- [`app/code/Weline/Admin/Controller/Login.php`](e:/WelineFramework/DEV-workspace/app/code/Weline/Admin/Controller/Login.php)
- [`app/code/Weline/Admin/view/templates/Login/index.phtml`](e:/WelineFramework/DEV-workspace/app/code/Weline/Admin/view/templates/Login/index.phtml)
- [`app/code/Weline/Admin/hook.php`](e:/WelineFramework/DEV-workspace/app/code/Weline/Admin/hook.php)
- [`app/code/Weline/Admin/doc/hook/backend/login/providers.md`](e:/WelineFramework/DEV-workspace/app/code/Weline/Admin/doc/hook/backend/login/providers.md)
- [`app/code/WeShop/Social/hook.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/Social/hook.php)
- [`app/code/WeShop/Social/doc/hook/frontend/login/buttons.md`](e:/WelineFramework/DEV-workspace/app/code/WeShop/Social/doc/hook/frontend/login/buttons.md)
- [`app/design/WeShop/default/frontend/pages/customer/login.phtml`](e:/WelineFramework/DEV-workspace/app/design/WeShop/default/frontend/pages/customer/login.phtml)
- [`app/code/WeShop/GoogleAuth/register.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/register.php)
- [`app/code/WeShop/GoogleAuth/etc/env.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/etc/env.php)
- [`app/code/WeShop/GoogleAuth/etc/backend/menu.xml`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/etc/backend/menu.xml)
- [`app/code/WeShop/GoogleAuth/Model/GoogleBinding.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Model/GoogleBinding.php)
- [`app/code/WeShop/GoogleAuth/Service/GoogleBindingService.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Service/GoogleBindingService.php)
- [`app/code/WeShop/GoogleAuth/Service/GoogleOAuthService.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Service/GoogleOAuthService.php)
- [`app/code/WeShop/GoogleAuth/Service/GoogleLoginService.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Service/GoogleLoginService.php)
- [`app/code/WeShop/GoogleAuth/Service/BackendWebAuthService.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Service/BackendWebAuthService.php)
- [`app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Start.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Start.php)
- [`app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Callback.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/Callback.php)
- [`app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/BackendChallenge.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Controller/Frontend/Auth/BackendChallenge.php)
- [`app/code/WeShop/GoogleAuth/Controller/Backend/Auth/Binding.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Controller/Backend/Auth/Binding.php)
- [`app/code/WeShop/GoogleAuth/view/hooks/Weline_Admin/backend/partials/login/providers.phtml`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/view/hooks/Weline_Admin/backend/partials/login/providers.phtml)
- [`app/code/WeShop/GoogleAuth/view/hooks/WeShop_Social/frontend/partials/login/buttons.phtml`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/view/hooks/WeShop_Social/frontend/partials/login/buttons.phtml)
- [`app/code/WeShop/GoogleAuth/view/templates/Backend/Auth/binding.phtml`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/view/templates/Backend/Auth/binding.phtml)
- [`app/code/WeShop/GoogleAuth/view/templates/Frontend/Auth/backend-challenge.phtml`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/view/templates/Frontend/Auth/backend-challenge.phtml)
- [`app/code/WeShop/GoogleAuth/Test/Unit/Service/GoogleLoginServiceTest.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Test/Unit/Service/GoogleLoginServiceTest.php)
- [`app/code/WeShop/GoogleAuth/Test/Unit/Service/BackendWebAuthServiceTest.php`](e:/WelineFramework/DEV-workspace/app/code/WeShop/GoogleAuth/Test/Unit/Service/BackendWebAuthServiceTest.php)

## Verification

### Syntax

- `php -l app/code/Weline/Admin/Controller/Login.php`
- `php -l` for all PHP files under `app/code/WeShop/GoogleAuth/`
- `php -l app/code/Weline/Admin/hook.php`
- `php -l app/code/WeShop/Social/hook.php`
- `php -l` for both new GoogleAuth unit test files

All passed.

### Setup / route generation

- `php bin/w setup:upgrade --yes`

Passed.

Generated routes confirmed by grep in `generated/routers`:

- `weshop_googleauth/frontend/auth/start`
- `weshop_googleauth/frontend/auth/callback`
- `weshop_googleauth/frontend/auth/backend-challenge`
- `weshop_googleauth/backend/auth/binding`

### Tests

Direct PHPUnit runs:

- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/GoogleAuth/Test/Unit/Service/GoogleLoginServiceTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage --configuration phpunit.xml app/code/WeShop/GoogleAuth/Test/Unit/Service/BackendWebAuthServiceTest.php`

Both test files passed with exit code `0`. The environment still reports a project-level PHPUnit deprecation notice, but no test failures or warnings remain with `--no-coverage`.

## Open Risks / Follow-up

- Real Google OAuth still depends on valid Google client id/secret configuration.
- Backend Google login intentionally does not auto-create admin users; binding must exist first.
- The 2FA setup UX for WeShop shadow-user IDs is still not solved; login orchestration exists, self-service enablement still needs another slice.
- Broader API auth, frontend account binding UX, and end-to-end browser coverage remain to be implemented.

## Next Recommended Slice

1. Add HTTP tests for:
   - Google callback success/failure
   - backend challenge GET/POST
   - binding page ACL behavior
2. Add frontend account-center Google bind/unbind UX if absent.
3. Extend unified auth API coverage so Google and 2FA challenge flows are also testable through `/weshop/auth/*`.
