# WeShop Supplier Capability Audit

Date: 2026-05-07
Scope: WEL-58 supplier/vendor capability inventory across WeShop B2B, Product, Order, Inventory, Invoice, Payment, Affiliate, Cart, Checkout, Frontend, theme, and planning docs.

## Reading Evidence

- Repository entry documents reviewed first: `AI-ENTRY.md`, `AI-README.md`, `CLAUDE.md`, `dev/ai/diagrams/00-INDEX.txt`, `dev/ai/diagrams/01-framework-overview.txt`, `dev/ai/diagrams/08-module-docs-index.txt`.
- WeShop planning docs reviewed: `dev/ai/codex/WeShop国际电商/README.md`, `module-status.md`, `complete-module-status.md`, `admin-ia.md`.
- Target module docs checked. `Product` and `Cart` have `doc/README.md`; most target modules only have event/hook docs and no module README.
- Source review covered `app/code/WeShop/{B2B,Product,Order,Inventory,Invoice,Payment,Affiliate,Cart,Checkout,Frontend}` and `app/design/WeShop`.

## Completed

- B2B buyer account chain exists. `WeShop_B2B` has company/customer profile, credit, payment terms, approval, receivable, statement, and B2B credit payment flows. This is enterprise-buyer AR/credit, not a marketplace seller model.
- Inventory source chain exists. `WeShop_Inventory` has `weshop_inventory_source` and `weshop_inventory_source_item`, including source-product quantity, source admin, and source-item admin screens. The source comment mentions warehouse/supplier, but there is no supplier account ownership.
- Core order chain exists. `WeShop_Order` persists one order header and order items from checkout. Items carry product id, name, SKU, quantity, price, and total.
- Payment and invoice chains exist for the current single-order flow. `WeShop_Payment` includes normal payment methods and B2B credit account; `WeShop_Invoice` attaches invoices to orders.
- Affiliate chain exists. `WeShop_Affiliate` manages customer referral code, commission rate, total commission, paid commission, admin list/edit, and storefront affiliate summary.
- Backend entry points exist for Product, Order, Inventory, Invoice, Payment, B2B, and Affiliate through `etc/backend/menu.xml` files and the admin IA plan.

## Incomplete For Supplier/Marketplace

- No independent supplier/seller/merchant model exists. There is no canonical seller table, seller status, seller profile, seller account binding, onboarding, approval, or seller ACL boundary.
- No product ownership exists. `WeShop_Product\Model\Product` has stock, cost, price, SKU, category, status, layout, and website linkage, but no `supplier_id`, `seller_id`, `vendor_id`, `merchant_id`, or offer table.
- No seller order model exists. `Order` and `OrderItem` do not store seller/source ownership, and checkout creates one order instead of splitting by seller or source.
- No seller settlement/payout exists. Affiliate commission is a referral account model and B2B receivable is buyer credit AR; neither is marketplace seller settlement.
- No seller storefront exists. Theme files contain optional seller labels and some `seller` links, but source search found no seller controller/router/API.
- No supplier API exists. Current REST/API surfaces cover cart, checkout, order, invoice, auth, and B2B invoice/credit, but not seller onboarding, seller products, seller orders, payouts, or supplier inventory ownership.

## Defects Or Misleading Surfaces

- Cart and some theme layouts can render a `seller` label if data is present, but current cart/product data does not populate a real seller from a persisted model.
- Checkout success theme variants link to `seller`, but no matching WeShop seller route/controller was found.
- Inventory source is named broadly enough to represent warehouse/supplier, but it is only a stock source. It lacks account ownership, permissions, public profile, settlement identity, and product listing responsibility.
- Product/cart stock checks still rely on `Product::stock` in cart add flow, while inventory source items maintain separate source quantities. Supplier/source stock is not the checkout source of truth.
- Affiliate commission calculation exists, but order/payment flow search did not find an order event observer that books referral commission into affiliate balances.

## Product Confirmation Needed

- Confirm the intended meaning of supplier: marketplace seller, dropship supplier, warehouse/source, brand/manufacturer, B2B buyer company, or affiliate partner.
- Confirm catalog ownership model: one product per seller, shared product with multiple seller offers, or product source-only inventory assignment.
- Confirm order model: one parent order with seller suborders, independent seller orders, or source-based fulfillment splits.
- Confirm settlement model: seller payout ledger, marketplace commission rate, affiliate referral commission, B2B receivable, or a combination.
- Confirm storefront and admin expectations: public seller pages, seller dashboard, backend-only supplier management, API-only integration, or phased rollout.

## Recommended Split

- This issue small-scope closure: keep as audit/documentation only unless product confirms seller marketplace semantics.
- Follow-up issue A: product confirmation and data contract for supplier/seller entity, product ownership, order split, and settlement.
- Follow-up issue B: implement supplier/seller base module after A, with model, ACL, backend CRUD, status workflow, and tests.
- Follow-up issue C: implement product ownership/offer assignment after B, including backend field/filters and product service/query changes.
- Follow-up issue D: implement seller order split and seller order views after B/C, including checkout, order item metadata, invoices/refunds, and tests.
- Follow-up issue E: implement settlement/payout ledger after D, separating marketplace seller settlement from affiliate referral commission.
- Follow-up issue F: implement storefront seller display/API only after product confirms public UX and route contract.

## Validation

- No runtime code was changed.
- Static/source audit commands were run with `rg`, `Get-Content`, and `git status`.
- No WLS instance was started because this was a documentation and capability inventory task.
