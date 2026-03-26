# Result - opensearch dynamic eav search facets

## Outcome

- Implemented the approved OpenSearch dynamic EAV search/facet plan on top of the earlier OpenSearch-default install work.
- Search indexing now supports provider + extender composition, and product documents are enriched with `eav_search_text` plus nested `eav_facets`.
- Search/category/filter browsing now routes through `w_query('search', 'browseProducts', ...)`, with OpenSearch/Elasticsearch facet DSL support and DB fallback preserved.
- Search storefront UI now renders applied filters, dynamic facet groups, and pagination fallback from the browse result structure.

## Changed Files

- `app/code/WeShop/Search/Api/SearchBrowseEngineInterface.php`
- `app/code/WeShop/Search/Service/SearchIndexer.php`
- `app/code/WeShop/Search/Service/SearchService.php`
- `app/code/WeShop/Search/Service/SearchPageDataService.php`
- `app/code/WeShop/Search/extends/module/Weline_Framework/Query/SearchQueryProvider.php`
- `app/code/WeShop/Search/Engine/ElasticsearchEngine.php`
- `app/code/WeShop/Search/view/templates/Frontend/Search/index.phtml`
- `app/code/WeShop/Filters/Controller/Frontend/Ajax.php`
- `app/code/WeShop/Filters/Service/FilterService.php`
- `app/code/WeShop/Filters/Provider/PriceFilterProvider.php`
- `app/code/WeShop/Catalog/Controller/Frontend/Category/View.php`
- `app/code/WeShop/Product/Service/ProductEavService.php`
- `app/code/WeShop/Product/Test/Unit/Extends/Module/WeShop_Search/DocumentExtender/ProductEavSearchDocumentExtenderTest.php`
- `app/code/WeShop/Search/Test/Unit/Engine/ElasticsearchEngineTest.php`
- `app/code/WeShop/Search/Test/Unit/Query/SearchQueryProviderTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchIndexerTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchPageDataServiceTest.php`
- `app/code/WeShop/Filters/Test/Unit/Controller/Frontend/AjaxTest.php`
- `app/design/WeShop/default/frontend/pages/catalog/category.phtml`
- `app/etc/env.php`

## Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Search/Test/Unit app/code/WeShop/Filters/Test/Unit app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php app/code/WeShop/Product/Test/Unit/Extends/Module/WeShop_Search/DocumentExtender/ProductEavSearchDocumentExtenderTest.php --colors=never`
  - Passed: `47` tests / `212` assertions
- `php app/code/WeShop/Search/env/script/install_opensearch.php check`
  - Passed
- `php app/code/WeShop/Search/env/script/install_opensearch.php install`
  - Passed after restoring external `D:/WelineRuntime/opensearch-data` and `D:/WelineRuntime/opensearch-logs` so the repo `E:` drive no longer blocks OpenSearch disk checks
- `php bin/w search:index --yes`
  - Passed
- `php tests/e2e/framework/preflight-refresh.php`
  - Passed
- `node tests/e2e/start.js specs/frontend/weshop-search.spec.js specs/frontend/weshop-filters.spec.js`
  - Passed: `2` specs
- Direct OpenSearch verification:
  - Confirmed `products` mapping contains `eav_search_text` and nested `eav_facets`

## Remaining Risks

- The current sample database indexed successfully, but sampled product documents still showed empty `eav_search_text` / `eav_facets`. This is still consistent with the current fixture data not yet having product EAV attributes flagged as searchable/filterable, not with a broken mapping or failed index rebuild.
- Search/category pagination currently relies on template-level fallback rendering when the browse result does not provide prebuilt pagination HTML.
- The live OpenSearch installer remains sensitive to disk placement: keeping binaries under `extend/server/opensearch` is fine, but on this machine the data/log/tmp paths need to stay on `D:` because `E:` currently has only about `1.25 GB` free.

## Next Resume Step

- If a follow-up task needs a live demo of dynamic EAV search hits/facets, create or update a product EAV attribute in admin, enable `frontend_is_filterable` and/or `frontend_is_searchable`, rebuild the product index, and re-run the focused storefront search/category checks.
