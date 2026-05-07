# WeShop Supplier Remediation Classification

Date: 2026-05-07
Issue: WEL-58
Branch: `agent/weline/wel-58-consolidated-066fd4c4`

## Consolidated Inputs

This branch consolidates only the WEL-58 scoped specialist outputs:

- Business supplier capability audit: `app/code/WeShop/doc/reports/supplier-capability-audit-2026-05-07.md`.
- Documentation checklist updates: `dev/ai/codex/WeShop国际电商/README.md`, `acceptance-matrix.md`, and `supplier-review-checklist.md`.
- ACL static hardening for supplier-adjacent backend controllers in B2B, Product, Order, Inventory, Invoice, and Affiliate.
- Frontend/theme/i18n safe fixes for cart, checkout, checkout success, and Affiliate Chinese translations.

Out-of-scope worktrees were not included. Broad i18n sweeps, unrelated framework/runtime changes, generated artifacts, and E2E failure screenshots remain outside this branch.

## Product And Supplier Semantics

The current codebase does not define marketplace supplier support. `seller`, `vendor`, `merchant`, and `supplier` are not canonical persisted business identities in WeShop today.

- Supplier/seller meaning: needs product or architecture confirmation. The safe current interpretation is that WeShop has B2B buyer companies, inventory sources, affiliate customers, and storefront display hints, but not a first-class marketplace seller.
- Product ownership: incomplete and product-blocked. `WeShop_Product` has no `seller_id`, `vendor_id`, `supplier_id`, merchant account, or offer ownership table.
- Order split: incomplete and product-blocked. Checkout creates one order and order items without seller ownership or seller suborders.
- Settlement/payout: incomplete and product-blocked. Affiliate commission is referral accounting, and B2B receivable is buyer credit AR; neither is seller payout.
- Seller route: defective or product-blocked depending on intended UX. Theme files reference a `seller` URL, but no WeShop seller controller or route was found.
- Seller display surfaces: partially present but not semantically complete. Cart can display a seller label when data is supplied and the frontend pass hardened escaping/i18n, but product detail, checkout, order, invoice, account center, and seller storefront rules still need product confirmation.
- Supplier data isolation: ACL static coverage is improved, but true supplier-level data isolation cannot be proven until the ownership model exists. Runtime role/direct-URL proof also remains blocked by WEL-18 runtime availability.

## Unit Gate Classification

Targeted PHPUnit on the consolidated branch:

- PASS: B2B 20 tests / 58 assertions.
- PASS: Inventory 22 tests / 59 assertions.
- PASS: Cart 26 tests / 157 assertions.
- FAIL: Product 38 tests / 126 assertions, 1 error, 3 failures, 1 skipped, 3 risky. Classification: stale test harness/request fixture debt and controller harness debt. Failures show request params defaulting instead of test values and uninitialized `PcController` object manager access. No supplier production regression was identified.
- FAIL: Order 40 tests / 134 assertions, 1 error, 6 failures, 7 risky. Classification: stale test harness/request fixture debt with one business-contract review needed for retry-payment redirect expectations. Failures show customer/order/page params defaulting to zero or one, direct redirect expectation drift, and uninitialized object manager access. No supplier model regression was identified.
- FAIL: Invoice 22 tests / 56 assertions, 2 failures, 2 risky. Classification: stale REST pagination expectation or fixture drift. Tests expect page 2 and page size 50 while production code resolves default page 1 and default size 20 unless request params are read correctly.
- FAIL: Payment 36 tests / 109 assertions, 1 failure. Classification: unresolved Payment unit contract; owner should inspect `ProcessTest::testIndexProcessesPaymentWithResolvedOrder` because the happy path returns `success=false`. This is not supplier semantics, but it blocks a clean supplier-adjacent gate.
- ERROR: Affiliate 25 tests / 91 assertions, 2 errors, 2 risky. Classification: unit fixture/schema debt. The unit harness lacks the `weshop_affiliate` table for `AffiliateAdminPageDataServiceTest`.
- FAIL: Checkout 41 tests / 420 assertions, 3 failures, 3 risky. Classification: stale request payload/fixture debt around retry order id and place-order payload. No supplier production regression was identified.

## Runtime And E2E Classification

HTTP/E2E acceptance remains blocked by WEL-18. WEL-58 must not report HTTP/E2E PASS until the runtime path is available. Current blockers are `http:request` WLS config TypeError, missing `server:start` and `server:stop` command registration, `m_api_user.updated_at` install/schema mismatch, and no reachable 9502+ WLS target.

## Recommended Owners

- Unit-test owner: update stale request mocks and controller fixtures, then add metadata-level ACL regression after this consolidated branch is used as the base.
- Payment business/unit owners: classify or fix the `ProcessTest` happy-path failure.
- Product/architecture owner: decide whether supplier means marketplace seller, inventory source, B2B company, affiliate partner, or another entity.
- Security/E2E owners: rerun role/direct-URL and HTTP/E2E proof only after WEL-18 runtime blockers are resolved.
