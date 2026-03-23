# Progress - weshop default theme catalog filters checkout hosts

- 2026-03-23 21:51 Created the task workspace.
- 2026-03-24 09:xx Normalized the category page to expose canonical `WeShop_Filters` and `WeShop_Catalog` hook hosts while preserving legacy `Weline_Theme` filter sidebar fallback and built-in fallback markup.
- 2026-03-24 09:xx Updated product listing layout variants `1..4` to expose the same canonical filter container and category products-content hosts with safe fallback rendering.
- 2026-03-24 09:xx Added `WeShop_Shipping::checkout::methods` to checkout layout variants `1..4` so shipping providers can inject dynamic method UI consistently across layout options.
- 2026-03-24 09:xx Added unit tests for category/listing hook hosts and checkout layout hook hosts.
- 2026-03-24 09:xx Re-ran focused syntax checks for the touched `.phtml` files and targeted PHPUnit for the new view-guard tests.
