# Task: opensearch dynamic eav search facets

- Task ID: 2026-03-25-0642-opensearch-dynamic-eav-search-facets
- Started: 2026-03-25 06:42
- Status: in_progress
- Owner: Codex
- Source: codex chat

## Goal

- Implement the approved OpenSearch dynamic EAV search and facet plan end to end.
- Keep OpenSearch as the default engine installed under `extend/server/opensearch`.
- Make search/category/filter browsing use the unified `w_query('search', 'browseProducts', ...)` path.
- Add dynamic EAV searchable/filterable indexing support with provider/extender-based document enrichment.
- Leave the workspace in a verified state with unit coverage, OpenSearch install/index checks, and focused frontend e2e passing.

## Scope

- In scope:
- Search index provider/extender chain, browse query provider, OpenSearch/Elasticsearch facet DSL, dynamic EAV facet/search indexing, search/category/filter controller integration, frontend search template, focused unit/e2e verification, env/install consistency.
- Out of scope:
- Creating new product EAV fixture data in the database just to demonstrate non-empty EAV facet values.
- Redesigning unrelated storefront modules beyond the search/category/filter integration points touched by this plan.

## Constraints

- Do not revert unrelated dirty-worktree changes.
- Keep OpenSearch install directory fixed at `extend/server/opensearch`.
- Preserve backward-compatible `searchProducts()`, `suggest`, `rebuildIndex`, `indexEntity`, and `deleteEntity` query operations.
- `frontend_is_searchable` remains independent from `frontend_is_filterable`.

## Related Plans

- User-approved plan: OpenSearch 动态 EAV 搜索与筛选方案

## Related Files

- `app/code/WeShop/Search/`
- `app/code/WeShop/Filters/`
- `app/code/WeShop/Product/`
- `app/code/Weline/Eav/`
- `app/design/WeShop/default/frontend/pages/catalog/category.phtml`
- `app/etc/env.php`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
