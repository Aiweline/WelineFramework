# Task: Theme preview empty middle content

- Started: 2026-03-22 18:20
- Completed: 2026-03-22 22:35
- Status: completed
- Request: Fix theme preview so the middle content area renders backend visual-editor layout data or theme default layout/meta/widget content instead of showing empty content.

## Working Notes

- Session startup context re-read for this turn.
- `dev/ai/codex/ACTIVE.md` points to an unrelated WLS startup task, so it was left untouched.
- Confirmed two root causes:
  - preview content stub stayed empty, so `contentTemplate` and `meta.*` driven layouts rendered blank middle content;
  - `LayoutPathResolver` still searched `themePath/view/theme/...`, so modern design themes like `app/design/WeShop/motor/frontend/...` fell back to `Weline_Theme` default layout templates.
- Added `ThemePreviewContentRenderer` to:
  - load draft/published layout data with preview-friendly fallback;
  - seed theme default draft layout when preview has no saved layout data;
  - render slot/widget HTML into `content` plus known `meta.*` fragments for homepage/product/product_list/cart/checkout_success layouts.
- Wired preview payload generation into both frontend preview content controller and backend theme editor unified preview entry.
- Updated `ControllerFetchFileBefore` so controller-assigned runtime `meta` survives layout param injection/cached layout reuse.
- Reworked `LayoutPathResolver` so layout existence/file resolution now goes through `ThemePathResolver`, while still returning canonical `Weline_Theme::theme/...` module paths to the fetch pipeline.
- Added focused regression test coverage for layout resolution and preview payload generation.

## Changed Files

- `app/code/Weline/Theme/Service/ThemePreviewContentRenderer.php`
- `app/code/Weline/Theme/Controller/Frontend/ThemePreview/Content.php`
- `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`
- `app/code/Weline/Theme/Observer/ControllerFetchFileBefore.php`
- `app/code/Weline/Theme/Helper/LayoutPathResolver.php`
- `app/code/Weline/Theme/test/Unit/LayoutPathResolverTest.php`

## Verification

- `php -l app/code/Weline/Theme/Service/ThemePreviewContentRenderer.php`
- `php -l app/code/Weline/Theme/Controller/Frontend/ThemePreview/Content.php`
- `php -l app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`
- `php -l app/code/Weline/Theme/Helper/LayoutPathResolver.php`
- `php -l app/code/Weline/Theme/Observer/ControllerFetchFileBefore.php`
- `php -l app/code/Weline/Theme/test/Unit/LayoutPathResolverTest.php`
- `php vendor/phpunit/phpunit/phpunit --no-coverage app/code/Weline/Theme/test/Unit/LayoutPathResolverTest.php`
- Framework bootstrap verification scripts confirmed:
  - theme 11 homepage layout now resolves to `app/design/WeShop/motor/frontend/layouts/homepage/default.phtml`;
  - preview renderer now returns non-empty homepage `meta/content` payloads for theme 11 and theme 10.
- Follow-up verification on this turn:
  - `php tmp-layout-path-check.php`
    - theme 10 resolves homepage preview file to `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml`
    - theme 11 resolves homepage preview file to `app/design/WeShop/motor/frontend/layouts/homepage/default.phtml`
  - `php tmp-preview-payload-check.php`
    - theme 10 homepage preview payload: `meta_keys=banner,deals`, `content_len=38836`
    - theme 11 homepage preview payload: `meta_keys=banner,deals,categories`, `content_len=10633`
  - `php -l app/code/Weline/Theme/test/Unit/LayoutPathResolverTest.php`
  - `php vendor/phpunit/phpunit/phpunit --no-coverage app/code/Weline/Theme/test/Unit/LayoutPathResolverTest.php`
    - result: `Tests: 4, Assertions: 13`
- Direct HTTP preview verification could not be completed in this turn because local ports `9981/9982` were not responding.

## Resume Notes

- When the local WLS/frontend runtime is back up, recheck:
  - `http://127.0.0.1:9982/CNY/zh_Hans_CN/index/index?preview_theme=11`
  - `http://127.0.0.1:9982/CNY/zh_Hans_CN/index/index?preview_theme=10`
  - backend `theme-editor/layout-preview` for theme 11 homepage draft
- Expected outcome after this patch:
  - theme 11 uses motor layout template instead of `Weline_Theme` homepage default;
  - default theme preview no longer shows empty middle content because `meta/banner/deals/categories` and fallback content are injected;
  - default theme and motor theme now stay separated in preview, with regression coverage added for theme 10 fallback vs theme 11 motor layout resolution.
