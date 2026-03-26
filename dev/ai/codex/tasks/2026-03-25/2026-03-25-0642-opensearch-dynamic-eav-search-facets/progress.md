# Progress - opensearch dynamic eav search facets

- 2026-03-25 06:42 Created the task workspace.
- 2026-03-25 15:xx Reconnected workspace context, reloaded repo skills, and linted all newly touched search/category/filter/EAV PHP files.
- 2026-03-25 15:xx Rebuilt broken `SearchQueryProvider`, `Catalog\Controller\Frontend\Category\View`, and `SearchIndexer` files to remove malformed strings/quotes and restore syntax.
- 2026-03-25 15:xx Completed browse-path stabilization: legacy Elasticsearch `search()` compatibility for `category_id` + `price_min`/`price_max`, search page data normalization, search/filter controller testability wrappers, and frontend search template facet UI.
- 2026-03-25 15:xx Added or updated focused tests for Search indexer extenders, browse DSL, query provider `browseProducts`, and product EAV document extension; fixed Filters unit regressions caused by dynamic EAV auto-discovery.
- 2026-03-25 15:xx Fixed real runtime blockers: `ProductEavService` group ordering no longer queries a nonexistent `sort_order` column, and `app/etc/env.php` now includes OpenSearch `data_dir` / `log_dir`.
- 2026-03-25 15:xx Verified `install_opensearch.php check`, `search:index --yes`, focused phpunit batch (`47` tests / `212` assertions), frontend preflight, and focused storefront e2e (`weshop-search` + `weshop-filters`) green.
- 2026-03-26 09:xx Reconnected the dirty worktree, selectively staged only the Search/EAV/facet slice, and excluded unrelated mixed-file changes such as product-tab UI tweaks, extra i18n edits, and category-page pixel marker additions.
- 2026-03-26 09:xx Re-ran the focused phpunit batch (`47` tests / `212` assertions) and OpenSearch installer check; the check exposed local `app/etc/env.php` drift because `data_dir` / `log_dir` had fallen back to the low-space `E:` project disk.
- 2026-03-26 09:xx Re-ran `install_opensearch.php install` with external `D:/WelineRuntime/opensearch-data`, `D:/WelineRuntime/opensearch-logs`, and `D:/WelineRuntime/opensearch-tmp` env overrides so the live runtime stayed on `extend/server/opensearch` while data/log/tmp moved back off the cramped repo disk.
- 2026-03-26 09:xx Re-verified `install_opensearch.php check`, `php bin/w search:index --yes`, `php tests/e2e/framework/preflight-refresh.php`, and `node tests/e2e/start.js specs/frontend/weshop-search.spec.js specs/frontend/weshop-filters.spec.js` green before preparing the checkpoint commit.
