# Acceptance Matrix

- Storefront password login, Google login, and 2FA login pass end-to-end.
- Backend password login, Google login, and 2FA login pass end-to-end.
- Unified auth API can issue tokens, refresh, verify challenges, logout, and read `/me` under `/api/weshop/rest/v1/auth/*`.
- `default` theme storefront pages for login, cart, checkout, order success, review, QA, RMA, and customer account are accessible and render correctly.
- Missing hook or slot situations surface warnings in the editor and through `w_msg()`.
- Checkout page gets payment methods from `w_query('payment', 'getCheckoutPaymentMethods', ...)`.
- Checkout `default` layout variants render controller/page `content` first so dynamic payment UI works across all checkout variants.
- Place-order flow no longer calls missing methods; it validates checkout data, creates an order, and returns structured payment result data.
- `default` account center renders recent orders, wishlist preview, recently viewed preview, and guess-you-may-like recommendations from service-driven data.
- `recently-viewed` storefront route exists, redirects guests to login, and its AJAX remove endpoint returns a structured redirect payload when unauthenticated.
- Logged-in product detail views feed the recently-viewed history service so account-center previews and the dedicated history page stay in sync.
- `compare` storefront route exists, redirects guests to login, and its AJAX add/remove endpoints return structured redirect payloads when unauthenticated.
- `default` theme renders compare entry points on product detail, category cards, and customer account center without modifying `WeShop_Theme` or `Weline_Theme`.
- Customer account center quick links and discovery cards can surface compare data through WeShop-owned hooks and aggregated dashboard services.
- `WeShop_Customer::frontend::account::security::cards` and `WeShop_Customer::frontend::account::discovery::cards` remain available for cross-module injection.
