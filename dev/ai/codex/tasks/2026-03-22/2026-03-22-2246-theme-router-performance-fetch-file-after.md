# Task Log - Theme router performance hotspot in fetch_file_after

- Date: 2026-03-22
- Started: 2026-03-22 22:46:00
- Status: completed
- Request: The request cost is too high; optimize performance based on the profiler hotspot screenshot showing `router_start`, `controller_chain::action_execute`, and `Weline_Framework_Controller::fetch_file_after`.

## Context

- The provided profiler screenshot shows:
  - `router_start` around 6.15s
  - `controller_chain::action_execute` around 6.01s
  - `Weline_Framework_Controller::fetch_file_after` around 2.36s
  - `Weline\Theme\Observer\ControllerFetchFileAfter` around 2.34s
- The hot path strongly suggests the main optimization target is the Theme observer that wraps controller output into the active layout/theme shell after the controller returns.
- This workspace already has many unrelated modified and untracked files, including framework/router/theme files, so the fix must stay tightly scoped.

## Plan

1. Trace the exact code path for `fetch_file_after` and identify the expensive nested calls.
2. Look for repeated layout/theme resolution, duplicate file parsing, or unnecessary rendering work that happens on every request.
3. Implement the smallest safe optimization that removes the measured waste without changing page behavior.
4. Run targeted verification: lint, focused timing checks, and any existing tests that cover the touched path.
5. Record residual risks if broader structural work is still needed.

## Progress

- Completed workspace startup context per `AGENTS.md`.
- Used `weline-framework-skill-router`, then loaded `weline-routing` and `extension-points`.
- Confirmed there is no `BOOTSTRAP.md` or `MEMORY.md` in this workspace.
- Found the relevant tracing points in:
  - `app/code/Weline/Framework/Runtime/WlsRuntime.php`
  - `app/code/Weline/Framework/Router/Core.php`
  - `app/code/Weline/Framework/Controller/PcController.php`
  - `app/code/Weline/Theme/etc/event.xml`
  - `app/code/Weline/Theme/Observer/ControllerFetchFileAfter.php`
- Root cause identified:
  - `ControllerFetchFileBefore` rewrote the controller fetch target from the content template to the layout template.
  - The main controller fetch therefore rendered the layout once before `fetch_file_after`.
  - `ControllerFetchFileAfter` then rendered the content template again and rendered the layout again, creating the measured duplicate work.
  - Several layout templates still preferred `fetch(contentTemplate)` over already-prepared `meta.content` / `content`, which would have preserved duplicate content rendering even after fixing the event flow.
- Implemented:
  - `ControllerFetchFileBefore` now keeps `fileName` pointed at the original content template and passes the resolved layout separately as `layoutTemplate`.
  - `ControllerFetchFileAfter` now reads `layoutTemplate` and reuses prefetched controller content via `eventData.content` before falling back to a fresh content fetch.
  - Updated affected frontend/backend layout templates to prefer `meta.content` / `content` and only fall back to `contentTemplate` when necessary.
  - Added `ControllerFetchFileAfterTest` to pin the new single-layout-render behavior.
- Follow-up on the exact backend URL:
  - `https://127.0.0.1:9982/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/CNY/zh_Hans_CN/pagebuilder/backend/page/index`
  - CLI probes only reached the backend login redirect, so they did not exercise the actual page-list controller path.
  - Tracing the controller/template for that route found a second hotspot in `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/Page/index.phtml`: repeated `Website` model loads inside parent badges, sitemap link generation, and child row rendering.
  - That page therefore had an N+1 query pattern that scales with the number of rows in the page tree.
- Implemented follow-up optimization for the exact PageBuilder backend page:
  - `app/code/GuoLaiRen/PageBuilder/Controller/Backend/Page.php` now caches accessible website IDs and website lists within the request.
  - `app/code/GuoLaiRen/PageBuilder/Controller/Backend/Page.php` now batch-loads all websites referenced by the visible parent/child pages into `website_map`.
  - `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/Page/index.phtml` now uses `website_map` for website name/code/display URL instead of per-row dynamic loads.

## Decisions

- Optimize the measured hot path first instead of broad speculative tuning elsewhere.
- Preserve unrelated dirty worktree changes and patch only the files necessary for this hotspot.

## Verification

- `php -l` passed for touched observer/test PHP files.
- `php -l` passed for all modified layout `.phtml` files.
- `php vendor/phpunit/phpunit/phpunit app/code/Weline/Theme/test/Unit/ControllerFetchFileAfterTest.php`
  - assertions passed
  - runner emitted existing warning/deprecation metadata about code coverage availability
- Live request probe on `https://127.0.0.1:9983`:
  - successful responses reported `X-Wls-Performance-Routerstart: 284.61ms` and `525.85ms`
  - follow-up probes later failed because the local WLS listener became unstable / refused connections

## Changed Files

- `dev/ai/codex/tasks/2026-03-22/2026-03-22-2246-theme-router-performance-fetch-file-after.md`
- `app/code/Weline/Theme/Observer/ControllerFetchFileBefore.php`
- `app/code/Weline/Theme/Observer/ControllerFetchFileAfter.php`
- `app/code/Weline/Theme/view/theme/frontend/layouts/default/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/account_auth/default.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/account/auth.phtml`
- `app/code/Weline/Theme/view/theme/frontend/layouts/account_logout/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/print/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/minimal/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/login/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/fullscreen/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/dashboard/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/default.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/blank.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/1440.phtml`
- `app/code/Weline/Theme/view/theme/backend/layouts/default/1280.phtml`
- `app/code/Weline/Theme/test/Unit/ControllerFetchFileAfterTest.php`
- `app/code/GuoLaiRen/PageBuilder/Controller/Backend/Page.php`
- `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/Page/index.phtml`

## Risks / Notes

- The successful runtime probes used the currently live local port `9983`, not the exact route shown in the screenshot, so the measured improvement is strong evidence but not a route-for-route benchmark.
- Local WLS listener stability remains imperfect; repeated probes started failing after initial success, which limits exhaustive runtime validation.
- If a custom third-party layout template still hard-depends on always fetching `contentTemplate` directly, it now still works through the fallback branch, but the optimal fast path depends on preferring `content` / `meta.content`.
- The exact `pagebuilder/backend/page/index` page still needs an authenticated backend session for route-for-route timing verification after the controller/template optimization.

## Outcome

- Collapsed the duplicate controller/layout rendering path responsible for the profiler hotspot and removed the PageBuilder backend page-list N+1 website loads that were still keeping this exact page slow.
