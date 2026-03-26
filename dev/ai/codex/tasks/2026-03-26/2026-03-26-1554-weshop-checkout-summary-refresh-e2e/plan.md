# Plan

1. Reuse the public customer register flow to establish a logged-in storefront session.
2. Seed a cart item for that session using the storefront add-to-cart path.
3. Load checkout, intercept `/checkout/methods` with a controlled refreshed summary payload, trigger a change, and assert the summary DOM updates.
4. Run the focused e2e spec on runtime `9982` and record the outcome.
