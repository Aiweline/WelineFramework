# WeShop Seller P0 Business Slices

Date: 2026-05-07
Issue: WEL-58
Canonical code/data term: `seller`
Chinese business term: 供应商/商家
Frontend copy: Seller/Merchant

## Current Boundary

The director architecture decision removes supplier/seller semantics as a product blocker. P0 is marketplace delivery with seller projections around the current catalog, inventory, order, invoice, and payment surfaces. P0 must not rewrite checkout placement, payment processing, or the main order aggregate.

The integration baseline is not ready yet. WEL-128 is still in progress and owns the single parent integration branch/SHA plus clean setup coordination. Because schema changes must land on that exact baseline, this pass returns the executable business slice plan and does not start model/schema edits.

## Slice 1: Seller Entity And Backend Account Binding

First PR target: create the `seller` business identity and bind backend users/roles to seller ownership without changing catalog, checkout, or order write paths.

Likely files and modules:

- `app/code/WeShop/B2B/Model/Seller.php` or a new `app/code/WeShop/Seller/Model/Seller.php` if the module boundary is approved.
- `app/code/WeShop/B2B/Model/SellerUser.php` or `app/code/WeShop/Seller/Model/SellerUser.php`.
- `app/code/WeShop/B2B/Service/*Seller*` or `app/code/WeShop/Seller/Service/*`.
- Backend controller/menu/doc/i18n files only if the first PR includes management UI.
- Unit tests under the owning module `Test/Unit`.

Migration/model approach:

- Use model attributes such as `#[Table]`, `#[Col]`, and `#[Index]`; do not edit generated files.
- `weshop_seller` fields: `seller_id`, `code`, `name`, `status`, `country`, `currency`, `locale`, `timezone`, contact fields, timestamps if the framework model convention supports them on the target baseline.
- `weshop_seller_user` fields: `seller_user_id`, `seller_id`, `backend_user_id`, `role_id`, `is_owner`, `status`.
- Unique indexes: seller `code`; seller-user `(seller_id, backend_user_id)`.
- Ownership rule: a backend user can manage seller-scoped data only through an active `seller_user` binding or explicit privileged no-seller role.

Acceptance evidence:

- Unit coverage for seller normalization, binding lookup, active/inactive binding, and privileged no-seller handling at service level.
- `git diff --check`, `php -l` for changed PHP, and setup evidence on the WEL-128 integration baseline.

## Slice 2: Product Seller Ownership And Inventory Source Binding

Second PR target: attach seller ownership to products and optionally to inventory sources while preserving the existing product aggregate.

Likely files and modules:

- `app/code/WeShop/Product/Model/Product.php` for `seller_id`.
- Product backend save/list/query services and query provider paths that expose product data.
- `app/code/WeShop/Inventory/Model/Source.php` for optional `seller_id` when a source belongs to a seller.
- Product and Inventory backend controllers/services for seller ownership filters.
- Unit tests under Product and Inventory.

Migration/model approach:

- Add nullable `seller_id` to `weshop_product` for transitional data.
- Add nullable `seller_id` to `weshop_inventory_source` only if inventory source ownership is in the same PR; otherwise defer to a follow-up.
- Add indexes for seller-scoped filters: product `seller_id`, inventory source `seller_id`.
- Existing products with no seller remain visible only to super-admin or an explicit privileged role until migration/backfill is decided.

Ownership boundary:

- Seller-bound users must only list, edit, delete, or assign products where `product.seller_id` matches one of their active seller bindings.
- Super-admin and explicit privileged no-seller roles may manage unassigned products for backfill.

Acceptance evidence:

- Unit coverage for seller product filter, unassigned product privileged handling, product save rejecting cross-seller writes, and inventory source binding if included.
- No `vendor` or `supplier` field names in code or schema.

## Slice 3: Order Item Seller Snapshot, Seller Order, And Settlement Projection

Third PR target: create seller-visible order/settlement projections without changing checkout, payment, or the main order aggregate.

Likely files and modules:

- `app/code/WeShop/Order/Model/OrderItem.php` for seller snapshot fields.
- New order projection model such as `WeShop\Order\Model\SellerOrder`.
- New settlement projection model such as `WeShop\Order\Model\SellerSettlement` or a dedicated settlement model under the approved seller module boundary.
- Order detail/list services that build seller-scoped views.
- Invoice and Payment services only for read/projection linkage; no payment rewrite.
- Unit tests under Order, Invoice, and Payment as needed.

Migration/model approach:

- Add nullable snapshot fields to `weshop_order_item`: `seller_id`, `seller_code`, `seller_name`.
- Create `weshop_seller_order` projection keyed by `seller_order_id`, `order_id`, `seller_id`, totals, status, currency, and timestamps.
- Create `weshop_seller_settlement` projection keyed by `settlement_id`, `seller_id`, `seller_order_id`, amount, currency, commission amount/rate, payout status, and timestamps.
- Populate projections from existing order item/product data after checkout order creation through a service or observer; do not alter checkout order placement contract in this PR.

Ownership boundary:

- Seller users can read seller orders and settlements only for active seller bindings.
- Main order remains customer/global admin aggregate.
- Seller settlement is distinct from Affiliate referral commission and B2B receivable.

Acceptance evidence:

- Unit coverage for seller snapshot creation, seller order projection grouping, settlement amount calculation inputs, cross-seller denial at service/filter level, and no mutation of the main order/payment flow.

## First Implementation PR Recommendation

Start with Slice 1 after WEL-128 publishes the integration SHA. It is the smallest safe schema surface and unlocks all later ownership filters. Do not start Slice 2 or Slice 3 until Slice 1 provides a stable seller identity and account binding service.

Suggested first branch name:

`agent/weline/wel-58-seller-entity-account-binding`

Suggested first PR acceptance gate:

- Models and services for seller and seller-user binding.
- Backend ACL metadata and service-level ownership checks.
- Unit tests for binding lookup and active/inactive access decisions.
- Setup validation on WEL-128 integration branch.
- No checkout, payment, order aggregate, or frontend seller display changes.

## Dependencies And Risks

- WEL-128 must provide the integration baseline before schema/model changes are safe to start.
- WEL-18 still blocks HTTP/E2E and runtime ACL proof.
- Security must define the ACL source matrix for seller entity, product ownership, seller order, settlement, and inventory-source binding.
- Documentation architecture doc should remain the canonical high-level source; this report is the business-module implementation split.
