# Progress - WeShop effective price filters

- 2026-03-26 03:13 Created the task workspace.
- 2026-03-26 03:xx Re-read workspace/task rules, resumed after the completed `PriceService`
  storefront closure, and froze the next bounded gap: DB/search fallback filtering still used raw
  persisted base `price`.
- 2026-03-26 03:xx Confirmed the live indexed search path was already aligned because search
  documents now store `price=current`; the remaining drift lived in
  `ProductQueryProvider::{searchProducts,getPriceStats,filterByPriceRange,countByPriceRange}`.
- 2026-03-26 03:xx Reworked `ProductQueryProvider` so search fallback filtering, range stats,
  range counts, and range ID selection all resolve through `PriceService` payloads in memory,
  then sort/paginate from the resolved items instead of applying stale base-price SQL clauses.
- 2026-03-26 03:xx Expanded `ProductQueryProviderTest` with effective-price coverage for search
  fallback, stats, range filtering, and range counting, then re-verified `filters` + `search`
  browser smoke green on runtime `9982`.
