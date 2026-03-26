# Result - opensearch dynamic eav search facets

## Outcome

- Implemented the approved OpenSearch dynamic EAV search/facet plan on top of the earlier OpenSearch-default install work.
- Search indexing now supports provider + extender composition, and product documents are enriched with `eav_search_text` plus nested `eav_facets`.
- Search/category/filter browsing now routes through `w_query('search', 'browseProducts', ...)`, with OpenSearch/Elasticsearch facet DSL support and DB fallback preserved.
- Search storefront UI now renders applied filters, dynamic facet groups, and pagination fallback from the browse result structure.
- Follow-up closure fixed the real post-commit runtime gaps: the `frontend_is_searchable` column is now backed by a committed EAV migration, and product EAV indexing now reads the real product value tables so dynamic EAV text/facets are populated in live OpenSearch documents.
- Focused storefront e2e is now pinned to the stable WLS/direct runtime for this feature, and the filters spec validates dynamic facet JSON on the proven `/filters/filter?category_id=14` path instead of the flaky category HTML route.

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
- `app/code/WeShop/Product/Test/Unit/Service/ProductEavServiceTest.php`
- `app/code/WeShop/Product/Test/Unit/Extends/Module/WeShop_Search/DocumentExtender/ProductEavSearchDocumentExtenderTest.php`
- `app/code/WeShop/Search/Test/Unit/Engine/ElasticsearchEngineTest.php`
- `app/code/WeShop/Search/Test/Unit/Query/SearchQueryProviderTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchIndexerTest.php`
- `app/code/WeShop/Search/Test/Unit/Service/SearchPageDataServiceTest.php`
- `app/code/WeShop/Filters/Test/Unit/Controller/Frontend/AjaxTest.php`
- `app/code/Weline/Eav/register.php`
- `app/code/Weline/Eav/Setup/Db/Migration/add_eav_attribute_frontend_is_searchable_20260326-v1.1.1.php`
- `app/code/Weline/Eav/Model/EavAttribute/Option.php`
- `app/code/Weline/Eav/Model/EavAttribute/Type.php`
- `app/code/Weline/Eav/test/Unit/Model/EavAttributeSchemaFieldAliasTest.php`
- `app/design/WeShop/default/frontend/pages/catalog/category.phtml`
- `app/etc/env.php`
- `tests/e2e/specs/frontend/weshop-search.spec.js`
- `tests/e2e/specs/frontend/weshop-filters.spec.js`

## Verification

- `php bin/w setup:upgrade -m Weline_Eav --sync`
  - Passed, and verified `m_eav_attribute.frontend_is_searchable` now exists
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Search/Test/Unit app/code/WeShop/Filters/Test/Unit app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php app/code/WeShop/Product/Test/Unit/Extends/Module/WeShop_Search/DocumentExtender/ProductEavSearchDocumentExtenderTest.php --colors=never`
  - Passed: `47` tests / `212` assertions
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Eav/test/Unit/Model/EavAttributeSchemaFieldAliasTest.php app/code/WeShop/Search/Test/Unit app/code/WeShop/Filters/Test/Unit app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php app/code/WeShop/Product/Test/Unit/Extends/Module/WeShop_Search/DocumentExtender/ProductEavSearchDocumentExtenderTest.php app/code/WeShop/Product/Test/Unit/Service/ProductEavServiceTest.php --colors=never`
  - Passed: `55` tests / `241` assertions
- `php app/code/WeShop/Search/env/script/install_opensearch.php check`
  - Passed
- `php app/code/WeShop/Search/env/script/install_opensearch.php install`
  - Passed after restoring external `D:/WelineRuntime/opensearch-data` and `D:/WelineRuntime/opensearch-logs` so the repo `E:` drive no longer blocks OpenSearch disk checks
- `php bin/w search:index --yes`
  - Passed
- `php bin/w search:index --provider=product --force`
  - Passed
- `php tests/e2e/framework/preflight-refresh.php`
  - Passed
- `node tests/e2e/start.js specs/frontend/weshop-search.spec.js specs/frontend/weshop-filters.spec.js`
  - Passed: `2` specs
- `PLAYWRIGHT_RUNTIME_STRATEGY=wls PLAYWRIGHT_E2E_TRANSPORT=direct node tests/e2e/start.js specs/frontend/weshop-search.spec.js specs/frontend/weshop-filters.spec.js`
  - Passed: `4` tests
- Direct OpenSearch verification:
  - Confirmed `products` mapping contains `eav_search_text` and nested `eav_facets`
  - Confirmed `/products/_doc/product_2` contains `eav_search_text: "品牌 Apple"` and populated `eav_facets`
- Direct filter/search verification:
  - `curl -k "https://127.0.0.1:9982/filters/filter?category_id=14"` returned dynamic `brand/color/material` facets
  - `w_query('search', 'browseProducts', ['keyword' => '品牌 Apple', ...])` returned `engine: opensearch` with dynamic EAV facets in the result

## Remaining Risks

- Search/category pagination currently relies on template-level fallback rendering when the browse result does not provide prebuilt pagination HTML.
- The live OpenSearch installer remains sensitive to disk placement: keeping binaries under `extend/server/opensearch` is fine, but on this machine the data/log/tmp paths need to stay on `D:` because `E:` currently has only about `1.25 GB` free.
- The fallback Playwright runtime remains less reliable than the WLS runtime for this feature slice, so the focused search/filter specs are now explicitly pinned to WLS/direct.

## Next Resume Step

- If the next task extends dynamic EAV browse further, the next likely slice is richer facet behavior for numeric/date attributes (`range_buckets`) and broader storefront adoption beyond the current search/category/filter entry points.
