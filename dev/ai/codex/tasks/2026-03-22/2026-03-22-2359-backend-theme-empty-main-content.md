# Task: Backend theme empty main content

- Started: 2026-03-22 23:59
- Completed: 2026-03-23 00:25
- Status: completed
- Request: Fix the backend page regression where the main content area renders blank and the page body contains an empty `<main id="main-content">`, likely related to Theme layout rendering.

## Context

- User reported a backend page at `https://127.0.0.1:9982/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/CNY/zh_Hans_CN/theme/backend/index` rendering with an empty main content region.
- Today already included multiple Theme runtime changes:
  - preview payload/content fallback fixes;
  - router/query sync fixes;
  - `ControllerFetchFileBefore` / `ControllerFetchFileAfter` performance optimization and backend layout template updates.
- The current regression may be caused by backend layout templates preferring the wrong content variable after the fetch pipeline change, or by controller/runtime metadata getting lost before layout wrapping.

## Progress

- Completed workspace startup context per `AGENTS.md`.
- Loaded `theme-development` and `testing` skills for this task.
- Reviewed related task logs:
  - `2026-03-22-1820-theme-preview-empty-middle-content.md`
  - `2026-03-22-2246-theme-router-performance-fetch-file-after.md`
- Switched `dev/ai/codex/ACTIVE.md` to this task.
- Confirmed the exact reported backend URL redirects to admin login without an authenticated session, so route-for-route browser verification was blocked in this turn.
- Inspected the backend layout templates plus compiled template output and found the actual regression root cause:
  - the recent fallback rewrite introduced unsupported inline template syntax like `{{content ?: meta.content}}`;
  - Weline's Taglib compiler does not support PHP-style `?:` expressions inside `{{ ... }}`, so compiled layouts generated broken PHP and the backend main content rendered blank.
- Expanded the fix beyond backend-only files because the same unsupported inline fallback syntax had also been introduced into several frontend Theme layouts in the same change family.
- Replaced every affected inline fallback with explicit `<if>/<elseif>` branches that the compiler supports.
- Added a focused regression test that scans `app/code/Weline/Theme/view/theme/**/*.phtml` and fails if unsupported `{{ a ?: b }}` inline fallback syntax appears again.
- Did not overwrite `dev/ai/codex/ACTIVE.md` at task end because another in-progress task updated it during this turn.

## Verification

- `rg -n "\{\{[^\n}]*\?:[^\n}]*\}\}" app\code\Weline\Theme\view\theme -g "*.phtml"`
  - no matches
- `php -l app/code/Weline/Theme/test/Unit/ThemeTemplateInlineFallbackSyntaxTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage app/code/Weline/Theme/test/Unit/ThemeTemplateInlineFallbackSyntaxTest.php`
  - passed: `Tests: 1, Assertions: 1`
  - runner reported an existing PHPUnit deprecation notice
- `php -l` passed for all touched backend/frontend layout `.phtml` files
- Manual compiled-output inspection of `app/code/Weline/Theme/view/tpl/zh_Hans_CN/theme/backend/layouts/default/com_default.phtml` captured the broken pre-fix compilation pattern, which matched the reported empty-main symptom.

## Root Cause

- The blank backend main area was caused by unsupported inline fallback template expressions such as `{{content ?: meta.content}}`.
- The Theme Taglib compiler treats `{{ ... }}` as variable-path syntax, not a general PHP expression parser, so `?:` inside the braces compiled into invalid/broken PHP access patterns.
- As a result, the backend default layout's main-content branch did not render prepared controller content correctly after the recent `fetch_file_after` optimization.

## Changed Files

- `app/code/Weline/Theme/view/theme/backend/layouts/dashboard/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/1280.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/1440.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/blank.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/fullscreen/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/login/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/minimal/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/print/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/account/auth.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/account_auth/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/account_logout/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/category/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/default/default.phtml`
- `app/code/Weline/Theme/test/Unit/ThemeTemplateInlineFallbackSyntaxTest.php`

## Notes

- Exact authenticated browser verification of `https://127.0.0.1:9982/.../theme/backend/index` still needs a logged-in admin session.
- The runtime should recompile the touched layout templates on next request; the stale compiled file still on disk reflects the pre-fix source until that recompilation happens.
