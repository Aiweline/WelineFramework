# Task: Frontend theme preview/layout mismatch

- Started: 2026-03-22 11:35
- Status: completed
- Request: Make http://127.0.0.1:9982/.../index/index?preview_theme=11 render the same preview result as the backend layout preview page for theme 11 draft homepage, and investigate why the default homepage layout inside weshop-motor is not rendered even on the correct preview page.

## Working Notes

- Session startup completed: read SOUL.md, USER.md, memory/2026-03-21.md, memory/2026-03-22.md, and dev/ai/codex/ACTIVE.md.
- MEMORY.md does not exist in this workspace.
- Routing skills selected: `theme-development`, `extension-points`, `weline-routing`, and `testing` for this pass.
- 12:45 Attempted runtime parity checks through `curl`/`http:req` on `127.0.0.1:9981`; all requests timed out (port is open but no response payload returned), so visual parity validation was initially blocked by local runtime availability.
- 13:38 Reconfirmed runtime is on `https://127.0.0.1:9982` and reproduced the frontend loop with live response headers.
- 13:39 Confirmed malformed redirect source: `Weline\Frontend\Observer\ResponseRedirectBefore::handleSeoRedirect()` appended `/` to the full redirect URL string, producing preview locations like `...&_t=1774157915/`.
- 13:41 Confirmed canonical preview loop source: canonical preview requests with `frontend_theme_id/backend_theme_id/.../weline_preview_token` still returned another `301` because `ProcessPreviewThemeUriBefore -> PreviewContextService::persistCurrentRequestContext()` synced legacy preview params back into `$_GET`, reintroducing `preview_theme` before `Theme\Controller\Router::rewritePreviewThemeQuery()` ran.
- Patched frontend SEO redirect handling to skip preview URLs and rebuild URLs safely.
- Patched preview request context sync so canonical preview URLs no longer get legacy `preview_theme` re-injected.
- Patched frontend preview entry generation to point to a unified Theme preview content route instead of falling back to the business homepage controller.
- Added `Weline\Theme\Controller\Frontend\ThemePreview\Content` so direct preview and editor preview share one frontend content rendering path.
- Patched `Weline\Theme\Controller\Backend\ThemeEditor::buildFrontendPreviewUrl()` to emit the same verified frontend preview content route.
- Replaced `weshop-motor` base layout header/footer `<w:theme:template>` usage with `Weline\Theme\Block\Partials` blocks to stop invalid `theme/theme/frontend/partials.*` template resolution.
- Patched `hero-slider` fallback logic so homepage JSON top-level `title/subtitle/...` is rendered when no `slides` array is present.

## Final Outcome

- Completed at: 2026-03-22 16:11
- Frontend `preview_theme=11` now redirects into the unified Theme preview content endpoint instead of falling through to the business homepage controller.
- Verified the final frontend preview HTML now renders the `weshop-motor` homepage draft/default layout content, including:
  - `motor-welcome-modal`
  - `data-wslot="homepage-hero"`
  - `Ride The Extraordinary`
  - `Premium motorcycles, parts, and gear.`
  - `Shop By Category`
  - `Hot Deals`
  - `Free Shipping On Orders Over $99`
- Fixed the remaining theme-default-layout regression in `hero-slider`: when homepage JSON provides top-level `title/subtitle/...` but no `slides`, the widget now renders that theme layout content instead of unrelated demo slides.
- Rechecked `var/log/wls/error.log` after the successful preview requests; no new `theme/theme/frontend/partials.header/default.phtml` or `theme/theme/frontend/partials.footer/default.phtml` entries were produced during the latest validation pass.
- Direct backend `layout-preview` HTML capture is still blocked without an authenticated admin session: requesting `https://127.0.0.1:9982/.../theme/backend/theme-editor/layout-preview?...` now redirects to `admin/login`. However, backend preview URL construction has been patched to point at the same verified frontend preview content flow, so the editor iframe path and direct frontend preview path are now aligned.
- Follow-up regression fixed at 18:04: explicit `preview_theme` requests no longer inherit stale WLS request-state theme ids. `preview_theme=10` now stays on `default`, and `preview_theme=11` now stays on `weshop-motor`.
- Root cause of that follow-up regression: `Weline\Theme\Controller\Router::rewritePreviewThemeQuery()` rewrote `$_GET` only, but the live `Request` singleton still served cached/stale params to `ThemePreview\Gateway`, causing `frontend_theme_id=10` to override the explicit `preview_theme=11`.
- Added router-side request-sync logic plus a gateway safeguard so legacy `preview_theme` always wins over stale cached `frontend_theme_id/backend_theme_id`.

## Files Changed

- `app/code/Weline/Frontend/Observer/ResponseRedirectBefore.php`
- `app/code/Weline/Theme/Service/PreviewContextService.php`
- `app/code/Weline/Theme/Service/ThemePreviewEntryApplication.php`
- `app/code/Weline/Theme/Controller/Frontend/ThemePreview/Content.php`
- `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`
- `app/design/WeShop/motor/frontend/layouts/base.phtml`
- `app/code/Weline/Theme/view/theme/frontend/widgets/banner/hero-slider/default.phtml`
- `app/code/Weline/Theme/Controller/Router.php`
- `app/code/Weline/Theme/Controller/Frontend/ThemePreview/Gateway.php`
- `app/code/Weline/Theme/test/Unit/PreviewContextServiceTest.php`
- `app/code/Weline/Theme/test/Unit/RouterPreviewRewriteTest.php`

## Verification

- `php -l app/code/Weline/Theme/Controller/Frontend/ThemePreview/Content.php`
- `php -l app/code/Weline/Theme/Service/ThemePreviewEntryApplication.php`
- `php -l app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`
- `php -l app/design/WeShop/motor/frontend/layouts/base.phtml`
- `php -l app/code/Weline/Theme/view/theme/frontend/widgets/banner/hero-slider/default.phtml`
- `php bin/w setup:upgrade --stage=route_update --yes`
- `php bin/w server:reload`
  - Reported orchestrator timeout on batch 1, but `var/server/instances/default.json` recovered to all core services/workers `ready` and runtime verification passed afterward.
- `curl -k -L "https://127.0.0.1:9982/CNY/zh_Hans_CN/index/index?preview_theme=11"`
  - Result: `302` to `theme/frontend/theme-preview/content?...layout_type=homepage...`, then `200 OK`.
  - Result HTML contains the expected homepage layout markers/content listed above.
- `curl -k -L "https://127.0.0.1:9982/f7LYPUzS4UD9UL1kqkf0hzzPxyxmvT8c/CNY/zh_Hans_CN/theme/backend/theme-editor/layout-preview?..."`
  - Result: redirected to `admin/login` without backend session, so direct authenticated backend page parity could not be captured in this shell-only validation.
- `php -l app/code/Weline/Theme/Controller/Router.php`
- `php -l app/code/Weline/Theme/Controller/Frontend/ThemePreview/Gateway.php`
- `php -l app/code/Weline/Theme/test/Unit/RouterPreviewRewriteTest.php`
- `vendor/bin/phpunit app/code/Weline/Theme/test/Unit/RouterPreviewRewriteTest.php app/code/Weline/Theme/test/Unit/PreviewContextServiceTest.php`
  - Result: 3 tests passed / 12 assertions; only the environment's existing coverage warning/deprecation notice remained.
- `curl -k -I "https://127.0.0.1:9982/CNY/zh_Hans_CN/index/index?preview_theme=10"`
  - Result: `302` with `frontend_theme_id=10`
- `curl -k -I "https://127.0.0.1:9982/CNY/zh_Hans_CN/index/index?preview_theme=11"`
  - Result: `302` with `frontend_theme_id=11`
- Marker checks on the final HTML:
  - `tmp-live-preview-10.html`: `default_markers=1`, `motor_markers=0`
  - `tmp-live-preview-11.html`: `default_markers=0`, `motor_markers=3`

## Resume Notes

- If someone needs a full backend-vs-frontend iframe parity screenshot, do it in a browser session that already has admin auth cookies.
- There is still a separate WLS/process stability issue in the environment (session server/orchestrator timeout noise in historical logs), but it did not block the final preview fix verification.
- `dev/ai/codex/ACTIVE.md` was already repointed to another unrelated WLS task during this pass, so it was intentionally left untouched to avoid overwriting that active record.
