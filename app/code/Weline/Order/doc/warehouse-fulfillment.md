# Warehouse Fulfillment（P3A-002）

## Fact ownership

- Order owns `FulfillmentUnit` and immutable `FulfillmentProgressLedger`.
- Inventory owns Reservation warehouse mapping, WarehouseQuota and
  InventoryLedger.
- The cross-module boundary is limited to
  `DefaultWarehouseResolverInterface` and
  `WarehouseInventoryCapabilityInterface`.

The retired Inventory memory coordinator is not a production state source.

## Durable writer cutover

`OrderFacade` can receive the public default-Warehouse resolver. A new physical
FulfillmentUnit then records:

- `warehouse_id`: trusted default logical Warehouse;
- `warehouse_source=legacy_default` while the binding's durable
  `writer_enabled=0`;
- `allocations_json`: immutable Offer/qty provenance.

Before MIG-P3A creates a default logical Warehouse, the resolver's stable
`ERROR_MISSING` keeps P2 checkout compatible: the new FulfillmentUnit has no
Warehouse assignment and continues on the legacy Store inventory path.
Ambiguous, invalid, cross-environment, or unauthorized Warehouse resolution is
not eligible for this fallback and remains fail closed.

After MIG-P3A fresh verify enables that binding, new units record
`warehouse_source=warehouse`; Checkout binds the same order line's existing P2
Reservation to that Warehouse before Order creation. Mode-off remains on the
P2 Store logical inventory. Existing units with a non-null Warehouse are never
overwritten and are interpreted as `warehouse` when an older row has no source.

## Partial ship

`WarehouseFulfillmentService::partialShip()` requires
`expectedVersion + idempotencyKey + requestHash`.

1. lock/load the FulfillmentUnit;
2. require its original Warehouse;
3. reject stale version and `fulfilled + requested > qty`;
4. CAS update qty/version/status;
5. append one `partial_ship` progress event in the same transaction.

Same-payload replay is zero-write. A different payload on the same key is an
idempotency conflict.

## Original-Warehouse refund

`OriginalWarehouseLocator` reads the immutable allocation snapshot for an
Order Offer. The existing cash-success `RefundOutbox` includes the located
Warehouse/source:

- `legacy_default` or no Warehouse provenance: call the legacy P2 Store return;
- `warehouse`: call `returnCommittedToWarehouse()` and mutate only the original
  WarehouseQuota;
- missing/ambiguous multi-warehouse provenance: `BLOCKED_AUTHORIZATION`.

Cash success is not rolled back when stock return fails. The inventory outbox
remains pending and retries independently; its stable effect/item key and the
Inventory ledger make the eventual return exactly once.

## Verification

```bash
vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php \
  app/code/Weline/Order/Test/Unit/Service/WarehouseFulfillmentServiceDatabaseIntegrationTest.php \
  app/code/Weline/Order/Test/Unit/Service/WarehouseRefundOutboxDatabaseIntegrationTest.php
php bin/w setup:schema:check -m Weline_Order --json
```

Module version: `Weline_Order 2.12.2`.
