# Commerce Admin Menu and ACL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every management capability in the commerce-kernel plan a real Weline backend menu, route, page, and matching framework ACL resource.

**Architecture:** Reuse `Weline_Backend::business_operations` as the root and let every owning business module contribute its own menu tree and backend surface. Source IDs use `Vendor_Module::tag1:tag2:code`; the same resource semantics protect menu visibility, Controller routes, QueryProvider operations, and task/operation actions. Existing services remain the business owners and pages call only module-local services or published cross-module contracts.

**Tech Stack:** PHP 8.4, Weline backend Controllers and templates, `etc/backend/menu.xml`, `#[Weline\Framework\Acl\Acl]`, Weline.Api/bin-query, PHPUnit, PostgreSQL, WLS and built-in Browser.

## Global Constraints

- Preserve the user-owned dirty worktree; do not overwrite unrelated changes.
- Do not edit `generated/**`, `view/tpl/**`, or `routes.xml`.
- PostgreSQL is the formal persistence proof; SQLite is development-only.
- User-visible strings must be translatable.
- Menu hiding never replaces Controller ACL enforcement.
- Dangerous actions use POST, CSRF, Scope validation, idempotency and audit, but no second ACL system or reauthentication layer.
- Do not commit, push, deploy, execute production migrations, or perform real payments.
- Keep the retained `ai-test-commerce-acc003-fixed-20260729-9877` instance live for final user acceptance.

---

### Task 1: Freeze the commerce admin feature contract

**Files:**
- Create: `app/code/Weline/Backend/test/Unit/Commerce/CommerceAdminMenuContractTest.php`
- Create: `dev/ai/codex/tasks/2026-07-30/2026-07-30-0154-commerce-admin-menu-and-acl/artifacts/commerce-admin-feature-matrix.json`
- Modify: `dev/ai/codex/tasks/2026-07-30/2026-07-30-0154-commerce-admin-menu-and-acl/plan.md`
- Modify: `dev/ai/codex/tasks/2026-07-30/2026-07-30-0154-commerce-admin-menu-and-acl/progress.md`

**Interfaces:**
- Consumes: module `etc/backend/menu.xml`, Controller `#[Acl]` attributes and route naming convention.
- Produces: a fixed list of required feature IDs and a PHPUnit contract that fails when a required menu, route or ACL resource is absent.

- [ ] **Step 1: Write the failing contract test**

The test defines literal module/feature expectations for:

```php
[
    'Product' => ['products', 'offers', 'sku-registry', 'categories', 'media', 'store-copy', 'shards'],
    'Inventory' => ['stocks', 'adjustments', 'warehouses', 'authorizations', 'reservations', 'leases', 'ledger', 'migration'],
    'Cart' => ['carts', 'exceptions'],
    'Checkout' => ['sessions', 'diagnostics'],
    'Order' => ['orders', 'statuses', 'shipments', 'refunds', 'invoices', 'exceptions'],
    'Shipping' => ['addresses', 'zones', 'carriers', 'rates', 'free-shipping', 'services', 'tracking', 'fulfillment'],
    'Payment' => ['methods', 'transactions', 'webhooks', 'effects', 'payment-reconcile', 'refund-reconcile', 'urgent'],
    'Tax' => ['classes', 'rules', 'engine', 'shadow', 'lkg', 'migration'],
    'Search' => ['config', 'generations', 'incremental', 'degraded', 'migration'],
    'Vendor' => ['vendors', 'authorizations', 'product-bindings', 'split-rules', 'payouts', 'reversals', 'migration'],
    'Subscription' => ['subscriptions', 'periods', 'renewals', 'attempts', 'missed-watermarks', 'migration'],
    'B2B' => ['groups', 'price-lists', 'quotes', 'approvals', 'snapshots', 'migration'],
    'CustomerAsset' => ['assets', 'ledger', 'settlements', 'returns', 'exceptions', 'migration'],
    'Queue' => ['queues', 'types', 'consumers', 'retries', 'inbox-outbox'],
]
```

For each literal feature it asserts:

- one menu source contains `::commerce:`;
- the action is non-empty and contains no `*`;
- the action resolves to an owning-module backend Controller method;
- the Controller class/method has an ACL source with the same module and feature code;
- no create/view/edit route requiring an object ID is used as a menu action.

- [ ] **Step 2: Run RED**

Run:

```bash
php bin/w phpunit:run --name=CommerceAdminMenuContractTest --colors=never
```

Expected: FAIL listing missing modules/features and existing wildcard/parameterized actions.

- [ ] **Step 3: Persist the literal matrix**

Write the same feature IDs to `commerce-admin-feature-matrix.json` with fields:
`feature_id`, `module`, `menu_source`, `route`, `acl_source`, `page`, `test_id`,
`status`, `evidence`.

- [ ] **Step 4: Validate the artifact**

Run:

```bash
php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' \
  dev/ai/codex/tasks/2026-07-30/2026-07-30-0154-commerce-admin-menu-and-acl/artifacts/commerce-admin-feature-matrix.json
```

Expected: exit `0`.

### Task 2: Establish stable commerce parent menus and ACL tag contract

**Files:**
- Modify: `app/code/Weline/Backend/etc/backend/menu.xml`
- Test: `app/code/Weline/Backend/test/Unit/Commerce/CommerceAdminMenuContractTest.php`
- Modify: `dev/ai/skills/安全权限工程师-ACL与后台安全/SKILL.md`
- Modify: `app/code/Weline/Acl/doc/README.md`

**Interfaces:**
- Produces parent sources under `Weline_Backend::business_operations`:
  `commerce_catalog_group`, `commerce_inventory_group`,
  `commerce_trade_group`, `commerce_fulfillment_group`,
  `commerce_finance_group`, `commerce_tax_search_group`,
  `commerce_partner_group`, `commerce_operations_group`.

- [ ] **Step 1: Add failing assertions for all parent sources**
- [ ] **Step 2: Run RED and confirm the parent-source failures**
- [ ] **Step 3: Add the eight empty parent nodes to Backend menu XML**
- [ ] **Step 4: Run the contract test and confirm only leaf-feature failures remain**
- [ ] **Step 5: Run a skill pressure baseline without the new ACL-tag section**

The scenario asks an agent to add a tagged menu while pressured to reuse the
parent ACL as authorization. Record whether it omits the Controller resource or
misstates tag metadata as authorization.

- [ ] **Step 6: Add the minimal ACL tag guidance**

Document:

```text
Vendor_Module::tag1:tag2:code
```

and require menu source, Controller ACL, exact leaf permission, parent topology,
tag-path grant behavior, URL-denial verification and Query/task/operation source
checks. State that `AclTag` metadata changes presentation only.

- [ ] **Step 7: Re-run the pressure scenario with the updated skill**
- [ ] **Step 8: Validate Markdown paths and task-scoped diff**

### Task 3: Implement Product administration

**Files:**
- Create: `app/code/Weline/Product/etc/backend/menu.xml`
- Create: `app/code/Weline/Product/Controller/Backend/Catalog.php`
- Create: `app/code/Weline/Product/view/templates/backend/catalog/index.phtml`
- Create: `app/code/Weline/Product/Test/Unit/Controller/Backend/ProductAdminSurfaceContractTest.php`
- Modify: `app/code/Weline/Product/i18n/zh_Hans_CN.csv`
- Modify: `app/code/Weline/Product/i18n/en_US.csv`

**Interfaces:**
- Routes: `weline_product/backend/catalog/{products|offers|skuRegistry|categories|media|storeCopy|shards}`.
- Controller ACL leaves:
  `Weline_Product::commerce:catalog:<feature>`.
- Reads use Product repositories/services already listed in Product AI-INDEX;
  Store Copy and shard status use their published QueryProviders.

- [ ] Write a failing test for seven menu/route/ACL/page sections.
- [ ] Run RED.
- [ ] Implement the Controller actions with one shared renderer and section-specific data providers.
- [ ] Implement the source template with real empty/error/data states.
- [ ] Add the seven menu leaves under `commerce_catalog_group`.
- [ ] Run Product test GREEN and the global contract test.
- [ ] Run `setup:upgrade --route` and verify all seven routes.
- [ ] Validate the seven pages through WLS and Browser.

### Task 4: Implement Inventory administration

**Files:**
- Create: `app/code/Weline/Inventory/etc/backend/menu.xml`
- Create: `app/code/Weline/Inventory/Controller/Backend/Inventory.php`
- Create: `app/code/Weline/Inventory/view/templates/backend/inventory/index.phtml`
- Create: `app/code/Weline/Inventory/Test/Unit/Controller/Backend/InventoryAdminSurfaceContractTest.php`
- Modify: Inventory i18n files.

**Interfaces:**
- Routes: `inventory/backend/inventory/{stocks|adjustments|warehouses|authorizations|reservations|leases|ledger|migration}`.
- ACL leaves: `Weline_Inventory::commerce:inventory:<feature>`.
- Writes call `InventoryService`, warehouse authorization/capability services and
  `WarehouseMigrationService`; no direct Model mutation from Controller.

- [ ] Write and run failing eight-feature test.
- [ ] Implement read pages first and make read cases GREEN.
- [ ] Add POST/CSRF actions for adjustments, authorization changes and approved migration operations.
- [ ] Add menu leaves under `commerce_inventory_group`.
- [ ] Run Inventory unit/integration tests against PostgreSQL.
- [ ] Run Browser permitted/denied and Scope-isolation cases.

### Task 5: Implement Cart and Checkout administration

**Files:**
- Create: `app/code/Weline/Cart/etc/backend/menu.xml`
- Create: `app/code/Weline/Cart/Controller/Backend/Cart.php`
- Create: `app/code/Weline/Cart/view/templates/backend/cart/index.phtml`
- Create: `app/code/Weline/Cart/Test/Unit/Controller/Backend/CartAdminSurfaceContractTest.php`
- Modify: `app/code/Weline/Checkout/etc/backend/menu.xml`
- Modify: `app/code/Weline/Checkout/Controller/Backend/Order.php`
- Create: `app/code/Weline/Checkout/Test/Unit/Controller/Backend/CheckoutAdminSurfaceContractTest.php`

**Interfaces:**
- Cart routes: `cart/backend/cart/{carts|exceptions}`.
- Checkout routes: `checkout/backend/order/{sessions|diagnostics}`.
- ACL leaves use `commerce:trade`.

- [ ] Add RED tests proving no wildcard action and no object-detail menu.
- [ ] Implement Cart list/exception pages through Cart services.
- [ ] Add Checkout session/diagnostic list actions without duplicating Order ownership.
- [ ] Replace current Checkout wildcard/detail menu nodes.
- [ ] Run Cart/Checkout tests and global contract GREEN.
- [ ] Verify both permitted and denied Browser behavior.

### Task 6: Normalize Order, Shipping and Payment administration

**Files:**
- Modify: `app/code/Weline/Order/etc/backend/menu.xml`
- Modify: `app/code/Weline/Order/Controller/Backend/Order.php`
- Modify: `app/code/Weline/Order/Controller/Backend/Status.php`
- Modify: `app/code/Weline/Order/Controller/Backend/Shipment.php`
- Modify: `app/code/Weline/Order/Controller/Backend/Refund.php`
- Modify: `app/code/Weline/Order/Controller/Backend/Invoice.php`
- Create: `app/code/Weline/Order/view/templates/backend/order/exceptions.phtml`
- Modify: `app/code/Weline/Shipping/etc/backend/menu.xml`
- Modify: `app/code/Weline/Shipping/Controller/Backend/Manager.php`
- Modify: `app/code/Weline/Shipping/Controller/Backend/Carrier.php`
- Modify: `app/code/Weline/Shipping/Controller/Backend/RateTemplate.php`
- Modify: `app/code/Weline/Shipping/Controller/Backend/FreeShippingRule.php`
- Modify: `app/code/Weline/Shipping/Controller/Backend/ShippingService.php`
- Modify: `app/code/Weline/Shipping/Controller/Backend/Tracking.php`
- Create: `app/code/Weline/Shipping/view/templates/backend/manager/fulfillment.phtml`
- Modify: `app/code/Weline/Payment/etc/backend/menu.xml`
- Modify: Payment backend Controllers and templates for Webhook/effect/reconcile/urgent sections.
- Create: focused admin-surface contract tests in each module.

**Interfaces:**
- Replace wildcard and create/generate menu actions with stable list/dashboard actions.
- ACL leaves use `commerce:trade`, `commerce:fulfillment`, or `commerce:finance`.

- [ ] Write RED tests for existing invalid actions and missing features.
- [ ] Normalize Order leaves and implement exception list.
- [ ] Expand Shipping leaves while reusing its Manager data/services.
- [ ] Expand Payment leaves using existing Dashboard/Transaction services.
- [ ] Run module tests and global contract.
- [ ] Browser-check every leaf and denied direct URL.

### Task 7: Implement Tax and Search administration

**Files:**
- Create Tax/Search menu XML, backend Controllers, templates, i18n and focused tests.

**Interfaces:**
- Tax routes: `tax/backend/tax/{classes|rules|engine|shadow|lkg|migration}`.
- Search routes: `search/backend/search/{config|generations|incremental|degraded|migration}`.
- ACL leaves use `commerce:tax` and `commerce:search`.

- [ ] Write and run RED tests.
- [ ] Implement Tax reads/actions through Tax services and rollout gate.
- [ ] Implement Search reads/actions through Search services and rollout gate.
- [ ] Add menu leaves under `commerce_tax_search_group`.
- [ ] Run PostgreSQL Tax/Search integration tests.
- [ ] Run Browser permitted/denied and no-404 cases.

### Task 8: Implement Vendor and Subscription administration

**Files:**
- Create each module's menu XML, backend Controller, source template, i18n and focused test.

**Interfaces:**
- Vendor routes cover vendors, authorizations, product bindings, split rules,
  payouts, reversals and migration.
- Subscription routes cover subscriptions, periods, renewals, attempts,
  missed watermarks and migration.
- ACL leaves use `commerce:partner`.

- [ ] Write and run RED tests.
- [ ] Implement Vendor surfaces through public/module-local services.
- [ ] Implement Subscription surfaces through public/module-local services.
- [ ] Add menu leaves.
- [ ] Run PostgreSQL module integration tests.
- [ ] Run Browser permitted/denied cases.

### Task 9: Implement B2B and CustomerAsset administration

**Files:**
- Create each module's menu XML, backend Controller, source template, i18n and focused test.

**Interfaces:**
- B2B routes cover groups, price lists, quotes, approvals, snapshots and migration.
- CustomerAsset routes cover assets, ledger, settlements, returns, exceptions and migration.
- ACL leaves use `commerce:partner`.

- [ ] Write and run RED tests.
- [ ] Implement B2B surfaces through B2B services.
- [ ] Implement CustomerAsset surfaces through CustomerAsset services.
- [ ] Add menu leaves.
- [ ] Run registered-clone PostgreSQL tests and clean the clone.
- [ ] Run Browser permitted/denied cases.

### Task 10: Complete Queue and commerce operations administration

**Files:**
- Modify: `app/code/Weline/Queue/etc/backend/menu.xml`
- Modify: Queue backend Controllers/templates.
- Create: `app/code/Weline/Queue/Test/Unit/Controller/Backend/CommerceOperationsAdminSurfaceContractTest.php`

**Interfaces:**
- Routes: queue list/type/consumers/retries/inboxOutbox.
- ACL leaves use `commerce:operations`.

- [ ] Write RED test for five Queue/operations features.
- [ ] Implement pages through `QueueAdminService` and existing QueryProviders.
- [ ] Add menu leaves under `commerce_operations_group`.
- [ ] Run Queue tests and global contract.
- [ ] Verify permitted/denied pages in Browser.

### Task 11: Full route, ACL, PostgreSQL and Browser acceptance

**Files:**
- Modify: feature matrix statuses/evidence.
- Modify: task `progress.md`, `result.md`.
- Create: task `artifacts/final-evidence.md`.

- [ ] Run PHP lint for every changed PHP file.
- [ ] Parse every changed menu XML.
- [ ] Run `setup:upgrade --route`.
- [ ] Run all focused module and global contract tests.
- [ ] Run PostgreSQL integration suites and confirm migration clone registry is zero.
- [ ] Reload only `ai-test-commerce-acc003-fixed-20260729-9877`.
- [ ] Verify WLS 2/2 healthy, homepage 200 and backend unauthenticated redirect.
- [ ] With an allowed role, traverse every feature menu and assert a real page.
- [ ] With a denied role, assert every feature menu is absent and direct URLs fail.
- [ ] Assert Browser console has no new error/warn.
- [ ] Reconcile every matrix row to PASS with evidence.
- [ ] Run task-scoped `git diff --check`; preserve unrelated dirty changes.

## Execution Order

```text
Task 1 → Task 2
              ├→ Task 3
              ├→ Task 4
              ├→ Task 5
              ├→ Task 6
              ├→ Task 7
              ├→ Task 8
              ├→ Task 9
              └→ Task 10
Tasks 3–10 → Task 11
```

No task is complete until its own RED→GREEN cycle and Browser/permission evidence pass.
