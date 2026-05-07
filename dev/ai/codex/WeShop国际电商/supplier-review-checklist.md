# Supplier Feature Review Checklist

This document is the WEL-58 review anchor for supplier, seller, merchant, store, and vendor-adjacent WeShop capability. It defines the classification structure, QA acceptance template, current documentation gaps, and documentation update boundary. It does not decide product scope.

## Classification Rules

Use these status values in issue comments and follow-up docs:

- Completed: implemented, documented where needed, and backed by role-specific validation evidence.
- Incomplete: expected by the agreed product scope but missing or partially implemented.
- Defective: implemented but failing functional, security, data isolation, i18n, or validation expectations.
- Needs product confirmation: code or wording suggests capability, but product semantics are not defined.
- Not applicable: reviewed and intentionally out of scope for the current WeShop supplier flow.

Every classified item must carry evidence: reviewed files, command output or test result, changed files if any, documentation update status, and remaining risk.

## Current Documentation Scan

| Area | Existing signal | Current documentation state | Gap or next owner |
| --- | --- | --- | --- |
| WeShop international docs | `README.md`, `acceptance-matrix.md`, `module-status.md`, `api-contracts.md`, and test plans cover auth, cart, checkout, filters, backend IA, API, and test closure. | No supplier, vendor, seller, or merchant review section existed before this checklist. | Keep cross-module review status here and mirror acceptance gates in `acceptance-matrix.md`. |
| Cart storefront | `CartPageDataService` maps `seller`; cart layout renders `item.seller`; `i18n/*` contains `Sold by`. | `app/code/WeShop/Cart/doc/README.md` documents cart pages, hooks, slots, events, and APIs, but not seller source, ownership, or display rules. | Frontend/business review must confirm whether `seller` is real supplier identity or display-only data. If confirmed or changed, update Cart README or hook docs. |
| Product and Catalog | Product and catalog docs focus on layout hooks, events, and product listing surfaces. | `Product/doc/README.md` does not describe product owner, supplier ID, seller relation, or vendor-visible product management. Catalog has hook/event docs only. | Business review must confirm whether product ownership exists. If added or confirmed, document Product/Catalog ownership fields, services, and APIs. |
| Store | Store model, query provider, backend template, i18n, and event docs exist; terms use store/shop wording. | Store has event docs but no module `doc/README.md`, and the docs do not define store as supplier, seller, or sales channel. | Needs product confirmation. If Store is supplier-facing, add module README coverage for identity, permissions, query provider, and lifecycle events. |
| B2B | Company and B2B customer models, backend pages, frontend hooks, and events exist. | B2B docs cover company/customer events and hooks, not supplier marketplace semantics. | Do not treat B2B company as supplier without product confirmation. If linked, document company-to-supplier boundaries and data isolation. |
| Inventory | Inventory source model/comment and backend dashboard mention warehouse or supplier sources. | Inventory has event docs but no module `doc/README.md`; supplier source semantics and ownership filtering are undocumented. | Security/business review must confirm source-to-supplier relation. If real, add Inventory README covering source ownership, ACL, and stock isolation. |
| Order, Checkout, Invoice, Logistics, RMA | Event and hook docs exist for checkout, order, invoice, logistics, and returns. | No docs describe supplier order split, seller-scoped fulfillment, seller invoice ownership, or per-supplier after-sales rules. | If multi-supplier orders exist or are added, update owning module docs and API/query-provider docs. |
| Payment and Affiliate | Payment provider config uses gateway merchant terms; Affiliate has commission fields and summaries. | Payment/Affiliate docs do not define supplier payout, settlement, or vendor ledger capability. | Do not conflate gateway merchant configuration or affiliate commission with supplier settlement. Product confirmation required before documenting as supplier payout. |
| Security and ACL | Backend menus/controllers exist across relevant modules. | Current module docs do not include a supplier permission matrix or cross-supplier data isolation rules. | Security review must provide PASS/defect/needs-confirmation rows; confirmed permission changes require module doc updates. |
| i18n | `Sold by` has Cart translations; Store `en_US.csv` still contains multiple Chinese source/target values. | No supplier-specific i18n key inventory exists. | Frontend/i18n review must list new or corrected keys. Any visible text changes require module-local i18n updates and docs or change notes. |

## Review Checklist Template

| ID | Chain | Classification | Must verify | Required evidence | Documentation trigger |
| --- | --- | --- | --- | --- | --- |
| SUP-01 | Supplier identity | TBD | Whether supplier/seller/vendor/store/company is a first-class model, alias, or not applicable. | Model/service/query-provider files, business decision, changed files. | Identity semantics go in the owning module README and this checklist. |
| SUP-02 | Product ownership | TBD | Product fields, services, backend pages, APIs, and filters for supplier-owned products. | Product/Catalog code review, unit/HTTP validation if changed. | Product/Catalog README or API docs. |
| SUP-03 | Frontend seller display | TBD | Product list/detail, cart, checkout, order, account center, and email/notification surfaces show seller safely and through i18n. | Template files, i18n keys, screenshot or HTTP/E2E evidence if changed. | Frontend, Cart, Product, Checkout, or Order hook/template docs. |
| SUP-04 | Cart, checkout, and order split | TBD | Totals, shipping, tax, discounts, payment, and order placement handle multi-supplier line grouping if in scope. | Cart/Checkout/Order service tests or HTTP/E2E evidence. | Cart/Checkout/Order README or API docs. |
| SUP-05 | Inventory source ownership | TBD | Supplier-linked sources cannot leak or mutate other supplier stock. | Inventory model/service/controller review and security matrix. | Inventory README and event/query-provider docs. |
| SUP-06 | Fulfillment, invoice, and after-sales | TBD | Shipment, invoice, RMA, refund, and logistics views respect supplier boundaries. | Order/Invoice/Logistics/RMA review and role-based validation. | Owning module docs and acceptance matrix update. |
| SUP-07 | Settlement, payout, and commission | TBD | Supplier settlement is not confused with payment gateway merchant config or affiliate commission. | Payment/Affiliate/Invoice review and product decision. | Payment/Affiliate/Invoice README or change note if in scope. |
| SUP-08 | ACL and data isolation | TBD | Backend menus, controllers, services, APIs, and query providers enforce supplier-scoped access. | Role matrix, cross-supplier negative tests, changed files if any. | ACL notes in owning module README/API docs. |
| SUP-09 | Admin IA | TBD | Backend menu labels, forms, list pages, edit/delete flows, and bulk actions reflect supplier scope. | Menu/template/controller review and HTTP evidence if changed. | Admin IA doc and module README updates. |
| SUP-10 | APIs and query providers | TBD | Supplier-scoped APIs, REST routes, and `w_query()` providers define inputs, outputs, and authorization. | API route/query-provider review, HTTP tests if changed. | `api-contracts.md` and module API docs. |
| SUP-11 | Unit and E2E tests | TBD | Existing supplier-adjacent tests are run; defects get targeted regression coverage. | PHPUnit/E2E commands, pass/fail logs, WLS port and cleanup if used. | Test matrix update when coverage changes. |
| SUP-12 | Documentation closure | TBD | All confirmed code/config/permission/i18n/API changes have module-local docs or a justified no-doc reason. | Changed docs, skipped docs rationale, remaining risk. | This checklist plus owning module docs. |

## Issue Comment Evidence Template

Use this format when a specialist reports back in WEL-58:

```text
Supplier review area:
Classification:
Reviewed files:
Changed files:
Commands executed:
Validation evidence:
Documentation updates:
No-doc-update reason:
Remaining risks:
QA checklist rows affected:
```

## Documentation Update Boundary

Documentation changes belong in the narrowest owning location:

- Cross-module supplier review structure belongs in this file, `supplier-review-checklist.md`, under the WeShop international docs directory.
- International WeShop acceptance gates belong in the peer file `acceptance-matrix.md`.
- Module capability, configuration, permissions, API contracts, events, hooks, query providers, or i18n changes belong under the affected `app/code/WeShop/<Module>/doc/` directory.
- If a relevant module has no `doc/README.md`, create one only when the specialist confirms a stable module capability or behavior change that needs an owner-facing summary.
- Do not write detailed process reports or fix logs to the repository root.

## Open Documentation Risks

- Specialist reports are not yet available, so all checklist rows remain TBD and must be completed from business, security, frontend, unit-test, E2E, and QA evidence.
- `Cart` has seller display signals, but the seller data source and ownership semantics are not documented.
- `Store`, `B2B`, `Inventory`, and `Affiliate` expose vendor-adjacent concepts, but none should be treated as supplier product scope without confirmation.
- Most supplier-adjacent modules lack module-level `doc/README.md`, so future confirmed supplier changes may require adding or expanding module docs.
- Security and ACL documentation is missing for cross-supplier data isolation.
- i18n review is still required for seller/store/supplier labels before QA acceptance.
