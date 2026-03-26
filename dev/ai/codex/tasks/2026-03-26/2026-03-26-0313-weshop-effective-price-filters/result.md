# Result - WeShop effective price filters

## Outcome

- Completed the bounded effective-price filter alignment slice.
- `ProductQueryProvider` fallback semantics now use effective discounted price for search fallback,
  price stats, price-range filtering, and range counts, which keeps DB fallback behavior aligned
  with the already-normalized indexed search documents.

## Changed Files

- `app/code/WeShop/Product/Extends/module/Weline_Framework/Query/ProductQueryProvider.php`
- `app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php`

## Verification

- `php vendor/bin/phpunit --no-coverage app/code/WeShop/Product/Test/Unit/Query/ProductQueryProviderTest.php app/code/WeShop/Product/Test/Unit/Controller/Frontend/Product/ProductListTest.php app/code/WeShop/Filters/Test/Unit/Controller/Frontend/AjaxTest.php --colors=never`
  Result: `17 tests / 76 assertions`, existing PHPUnit deprecation unchanged.
- `PLAYWRIGHT_RUNTIME_STRATEGY=wls PLAYWRIGHT_E2E_TRANSPORT=direct node tests/e2e/start.js specs/frontend/weshop-filters.spec.js specs/frontend/weshop-search.spec.js`
  Result: `2 passed` against `https://127.0.0.1:9982`.

## Remaining Risks

- The fallback provider now resolves effective price in PHP for correctness; if category/product
  sets become very large, this secondary path may need a later optimization pass.
- External search engines remain aligned only as long as indexed documents are kept fresh through
  normal indexing flows.

## Next Resume Step

- Continue the next base-layer commerce rules slice, with `Tax` the most direct follow-up after
  price/filter semantics are now closed.
