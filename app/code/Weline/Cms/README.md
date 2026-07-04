# Weline_Cms

`Weline_Cms` owns CMS page entities, routing, publish state, and soft-delete lifecycle.

Theme remains the owner of layout selection, visual editing, virtual layouts, widget placement, visual content, and page-level render Meta. CMS stores only the relationship to a Theme layout through Theme query APIs; it does not store Theme layout body, Theme Meta, widget config, visual content, or virtual layout payload.

## Theme Target

CMS registers a Theme target provider at:

```text
extends/module/Weline_Theme/TargetType/CmsPageTargetTypeProvider.php
```

The target identity is fixed:

- `target_type`: `cms_page`
- `target_id`: CMS `page_id`
- `layout_type`: `cms_page`

Theme/Meta page-level identify data is therefore resolved under:

```text
theme.{area}.targets.cms_page.{page_id}.layouts.cms_page.{layout_option}
```

## Query Interface

Public reads are exposed through:

- `w_query('cms', 'getPage', ['page_id'|'identifier'])`
- `w_query('cms', 'listPages', ['status', 'scope', 'page', 'page_size'])`
- `w_query('cms', 'listPathGroups', ['website_id', 'path_group', 'search'])`
- `w_query('cms', 'resolveThemeTarget', ['target_id'])`
- `w_query('cms', 'renderPagePayload', ['identifier'|'page_id', 'scope', 'preview'])`

Cross-module Theme data access must continue through `w_query('theme', ...)`.

## Delete Policy

CMS pages are soft-deleted by setting `deleted_at` and disabling the page. Theme target data is retained and is not hard-deleted with the page.

## Copy Policy

Backend copy actions support both a single CMS page and a top-level path group. The operator chooses a target website before submitting. CMS copies the page/path records inside `Weline_Cms`, while Theme-owned layout selection, visual layout rows, and virtual layout versions are copied through `w_query('theme', 'copyTargetLayoutData', ...)` so CMS never writes Theme internals directly.

## Preview URL

CMS preview links use the real public slug and query parameters instead of inserting a preview-only path segment. The URL shape is:

```text
/{cms-slug}?cms_preview=1&preview=1&preview_version=draft&weline_preview_token={token}
```

`weline_preview_token` is generated and validated by `w_query('theme', 'generatePreviewToken'|'validatePreviewToken', ...)`. A valid token can open a draft preview without exposing the internal backend preview route; without a valid token, unpublished CMS pages still resolve to 404 on the public slug.
