# Task Log - Backend fetch_file_after content-key optimization

- Date: 2026-03-23
- Started: 2026-03-23 00:12:00
- Status: completed
- Request: Continue optimizing the backend render path because the page is still slow, with the profiler still showing `controller_chain::action_execute` and `Weline_Framework_Controller::fetch_file_after` hotspots.

## Context

- Earlier work already removed one duplicate layout/content render path, but the backend route can still spend significant time inside `Weline\Theme\Observer\ControllerFetchFileAfter`.
- Local evidence now points to a second hotspot:
  - the observer stores full rendered content HTML in `meta.content`, `child_html.content`, and `content`
  - `Weline\Framework\View\Template::ob_file()` merges fetch dictionaries into the shared template data
  - backend layout partials therefore inherit a large HTML payload even though only the main content slot needs it
- The current worktree is dirty; this fix must stay scoped to Theme observer/layout behavior and task logs only.

## Plan

1. Add a request-scoped content store that keeps rendered HTML behind a lightweight key and clears itself via `StateManager`.
2. Change backend layout wrapping in `ControllerFetchFileAfter` to pass `contentRenderKey` instead of the raw content HTML.
3. Update backend layout templates to resolve content via the key and keep old fallbacks for compatibility.
4. Extend the focused unit test and run lint/PHPUnit verification.

## Progress

- Completed workspace startup context per `AGENTS.md`.
- Re-read the relevant Theme observer/layout/template code and the prior performance task log.
- Confirmed the main remaining backend waste is template-data pollution from large rendered `content` strings.
- Added `app/code/Weline/Theme/Service/PreparedContentStore.php` to keep rendered backend content in a request-scoped store keyed by a lightweight `contentRenderKey`.
- Updated `app/code/Weline/Theme/Observer/ControllerFetchFileAfter.php` so backend layouts receive `contentRenderKey` instead of the raw HTML blob, while frontend layouts keep the old direct-content path.
- Added `app/code/Weline/Theme/view/theme/backend/partials/layout/content.phtml` and switched the core backend layout templates to this shared resolver partial.
- Extended `app/code/Weline/Theme/test/Unit/ControllerFetchFileAfterTest.php` with a backend case that asserts the observer now passes `contentRenderKey` and no longer injects the full prefetched HTML into backend template data.

## Verification

- `php -l` passed for:
  - `app/code/Weline/Theme/Observer/ControllerFetchFileAfter.php`
  - `app/code/Weline/Theme/Service/PreparedContentStore.php`
  - `app/code/Weline/Theme/test/Unit/ControllerFetchFileAfterTest.php`
  - `app/code/Weline/Theme/view/theme/backend/partials/layout/content.phtml`
  - the touched backend layout `.phtml` files
- `php vendor/phpunit/phpunit/phpunit app/code/Weline/Theme/test/Unit/ControllerFetchFileAfterTest.php`
  - completed with `2 tests`, `18 assertions`
  - runner still emitted the existing warning `No code coverage driver available`

## Risks / Notes

- Unknown custom layouts may still read `content` or `meta.content` directly; compatibility fallbacks should remain in templates during this change.
- Because the request store is WLS-sensitive, it must register a reset callback so content does not leak across requests.

## Changed Files

- `dev/ai/codex/ACTIVE.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0012-backend-fetch-file-after-content-key.md`
- `app/code/Weline/Theme/Observer/ControllerFetchFileAfter.php`
- `app/code/Weline/Theme/Service/PreparedContentStore.php`
- `app/code/Weline/Theme/view/theme/backend/partials/layout/content.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/blank.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/1280.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/1440.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/dashboard/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/fullscreen/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/login/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/minimal/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/print/default.phtml`
- `app/code/Weline/Theme/test/Unit/ControllerFetchFileAfterTest.php`

## Outcome

- Backend layout wrapping no longer pushes the full prefetched page HTML into shared template data for the core backend layouts.
- The optimized path now keeps the heavy HTML in a request-scoped store and lets the layout main-content slot resolve it only where needed, which should materially reduce the `fetch_file_after` observer cost on large backend pages.

## Changed Files

- `dev/ai/codex/ACTIVE.md`
- `dev/ai/codex/tasks/2026-03-23/2026-03-23-0012-backend-fetch-file-after-content-key.md`
