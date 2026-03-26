# Acceptance Matrix

- Storefront password login, Google login, and 2FA login pass end-to-end.
- Backend password login, Google login, and 2FA login pass end-to-end.
- Unified auth API can issue tokens, refresh, verify challenges, logout, and read `/me` under `/api/rest/v1/weshop/auth/*`.
- `default` theme storefront pages for login, cart, checkout, order success, review, QA, RMA, and customer account are accessible and render correctly.
- `default` theme category pages render filters through `WeShop_Filters::frontend::partials::filters::container` with assigned `filters`, `applied_filters`, and `clear_all_url`.
- Storefront filter AJAX uses clean routes `filters/filter`, `filters/options`, and `filters/counts`, and returns structured JSON envelopes.
- Missing hook or slot situations surface warnings in the editor and through `w_msg()`.
- Checkout page gets payment methods from `w_query('payment', 'getCheckoutPaymentMethods', ...)`.
- Checkout order creation derives shipping/tax from:
  - `w_query('shipping', 'calculateShipping', ...)`
  - `w_query('tax', 'calculateTax', ...)`
  and not from all-zero placeholder totals.
- Checkout `default` layout variants render controller/page `content` first so dynamic payment UI works across all checkout variants.
- Place-order flow validates checkout data, creates an order, and returns structured payment result data.
- `default` theme cart and checkout summary areas expose row-level hook hosts for subtotal/shipping/tax/discount/grand-total extensions.
- `default` account center renders recent orders, wishlist preview, recently viewed preview, and guess-you-may-like recommendations from service-driven data.
- `recently-viewed` storefront route exists, redirects guests to login, and its AJAX remove endpoint returns a structured redirect payload when unauthenticated.
- Logged-in product detail views feed the recently-viewed history service so account-center previews and the dedicated history page stay in sync.
- `compare` storefront route exists, redirects guests to login, and its AJAX add/remove endpoints return structured redirect payloads when unauthenticated.
- `default` theme renders compare entry points on product detail, category cards, and customer account center without modifying `WeShop_Theme` or `Weline_Theme`.
- `default` product detail exposes stable review and Q&A tab slots so module slices can render user content without editing theme modules.
- `default` customer account center exposes stable security, discovery, and order/after-sales card hooks so post-purchase modules can inject entry points without editing theme modules.
- `WeShop_Customer::frontend::account::security::cards` and `WeShop_Customer::frontend::account::discovery::cards` remain available for cross-module injection.
