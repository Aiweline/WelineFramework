<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Model\CheckoutGroup;
use Weline\Order\Model\DisplayNumberRegistry;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

/**
 * OrderFacade DB writer/reader（TEST-P2E-05 成功 submit 落库）。
 *
 * 表：weline_checkout_group / weline_order / weline_order_item /
 * weline_fulfillment_unit / weline_display_number_registry。
 * persist 在单事务内写 Group + Orders + Items + FulfillmentUnits；
 * 展示号 registry 由 Allocator 先占（失败补偿释放）。
 */
final class OrmOrderFacadeStore implements OrderFacadeStoreInterface
{
    public function __construct(
        private ?CheckoutGroup $groupModel = null,
        private ?Order $orderModel = null,
        private ?OrderItem $itemModel = null,
        private ?FulfillmentUnit $fulfillmentUnitModel = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null memory 形状的 group 数组
     */
    public function findGroupByIdempotencyKey(string $idempotencyKey): ?array
    {
        $row = $this->group()
            ->where(CheckoutGroup::schema_fields_IDEMPOTENCY_KEY, trim($idempotencyKey))
            ->find()
            ->fetch();
        if (!$row instanceof CheckoutGroup || !$row->getId()) {
            return null;
        }

        return $this->hydrateGroup($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findGroup(string $checkoutGroupUuid): ?array
    {
        $row = $this->group()
            ->where(CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID, trim($checkoutGroupUuid))
            ->find()
            ->fetch();
        if (!$row instanceof CheckoutGroup || !$row->getId()) {
            return null;
        }

        return $this->hydrateGroup($row);
    }

    /**
     * @return array<string, mixed>|null memory 形状的 order 行
     */
    public function findOrder(string $orderUuid): ?array
    {
        $row = $this->order()
            ->where(Order::schema_fields_ORDER_UUID, trim($orderUuid))
            ->find()
            ->fetch();
        if (!$row instanceof Order || !$row->getId()) {
            return null;
        }

        return $this->hydrateOrder($row);
    }

    /**
     * 单事务持久化整组（Group + Orders + Items + FulfillmentUnits）。
     *
     * @param array<string, mixed> $group memory 形状 group（含 orders 行）
     */
    public function persist(array $group): void
    {
        $tx = $this->group();
        $tx->beginTransaction();
        try {
            foreach ($group['orders'] as $orderRow) {
                $this->insertOrder($orderRow);
            }
            $this->insertGroup($group);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $group */
    private function insertGroup(array $group): void
    {
        $totals = \is_array($group['totals'] ?? null) ? $group['totals'] : [];
        $snapshots = \is_array($group['snapshots'] ?? null) ? $group['snapshots'] : [];
        $this->group()->setData([
            CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID => (string)$group['checkout_group_uuid'],
            CheckoutGroup::schema_fields_WEBSITE_ID => (int)($group['website_id'] ?? 0),
            CheckoutGroup::schema_fields_STORE_ID => (int)($group['store_id'] ?? 0),
            CheckoutGroup::schema_fields_CURRENCY => (string)$group['currency'],
            CheckoutGroup::schema_fields_STATUS => (string)$group['status'],
            CheckoutGroup::schema_fields_IDEMPOTENCY_KEY => (string)$group['idempotency_key'],
            CheckoutGroup::schema_fields_REQUEST_HASH => (string)$group['request_hash'],
            CheckoutGroup::schema_fields_SHIPPING_OWNER_ORDER_UUID => $group['shipping_charge_owner_order_uuid'] ?? null,
            CheckoutGroup::schema_fields_GRAND_TOTAL_MINOR => (int)($totals['grand_total_minor'] ?? 0),
            CheckoutGroup::schema_fields_MONEY_SNAPSHOT_JSON => $this->encode($snapshots['money'] ?? []),
            CheckoutGroup::schema_fields_SCOPE_SNAPSHOT_JSON => $this->encode($snapshots['scope'] ?? []),
            CheckoutGroup::schema_fields_SHIPPING_SNAPSHOT_JSON => $this->encode($snapshots['shipping'] ?? []),
            CheckoutGroup::schema_fields_TAX_SNAPSHOT_JSON => $this->encode($snapshots['tax'] ?? []),
        ])->save();
    }

    /** @param array<string, mixed> $row memory 形状 order 行 */
    private function insertOrder(array $row): void
    {
        $money = \is_array($row['money'] ?? null) ? $row['money'] : [];
        $snapshots = \is_array($row['snapshots'] ?? null) ? $row['snapshots'] : [];
        $shipping = \is_array($snapshots['shipping'] ?? null) ? $snapshots['shipping'] : [];
        $orderModel = $this->order();
        $orderModel->setData([
            Order::schema_fields_ORDER_NUMBER => (string)($row['display_number'] ?? $row['order_uuid']),
            Order::schema_fields_ORDER_UUID => (string)$row['order_uuid'],
            Order::schema_fields_CHECKOUT_GROUP_UUID => (string)$row['checkout_group_uuid'],
            Order::schema_fields_WEBSITE_ID => (int)($row['website_id'] ?? 0),
            Order::schema_fields_STORE_ID => (int)($row['store_id'] ?? 0),
            Order::schema_fields_CUSTOMER_ID => $row['customer_id'] ?? null,
            Order::schema_fields_CUSTOMER_EMAIL => ($email = trim((string)($row['customer_email'] ?? ''))) !== ''
                ? $email
                : null,
            Order::schema_fields_CUSTOMER_NAME => ($name = trim((string)($row['customer_name'] ?? ''))) !== ''
                ? $name
                : null,
            Order::schema_fields_CUSTOMER_PHONE => ($phone = trim((string)($row['customer_phone'] ?? ''))) !== ''
                ? $phone
                : null,
            Order::schema_fields_STATUS => (string)$row['status'],
            Order::schema_fields_STATE => (string)$row['status'],
            Order::schema_fields_CURRENCY => (string)$row['currency'],
            Order::schema_fields_SUBTOTAL => $this->minorToMajor((int)($money['subtotal_minor'] ?? 0)),
            Order::schema_fields_SHIPPING_AMOUNT => $this->minorToMajor((int)($money['shipping_amount_minor'] ?? 0)),
            Order::schema_fields_TAX_AMOUNT => $this->minorToMajor((int)($money['tax_amount_minor'] ?? 0)),
            // 折扣必须与其它金额列一起落盘：money 快照里已有 discount_amount_minor（券 + 支付方式激励），
            // 若只写小计/运费/税费/总额而不写折扣列，该列会停在默认 0，
            // 于是订单自身金额不守恒（小计 + 运费 + 税费 - 折扣 ≠ 订单总额），
            // 后台订单详情直接渲染该列 ⇒ 展示出对不上的金额分解。
            Order::schema_fields_DISCOUNT_AMOUNT => $this->minorToMajor((int)($money['discount_amount_minor'] ?? 0)),
            Order::schema_fields_GRAND_TOTAL => $this->minorToMajor((int)($money['grand_total_minor'] ?? 0)),
            Order::schema_fields_SOURCE_MODULE => 'Weline_Order',
            Order::schema_fields_PAYMENT_METHOD => strtolower(trim((string)($row['payment_method'] ?? ''))),
            Order::schema_fields_SHIPPING_METHOD => (string)($shipping['method'] ?? ''),
            Order::schema_fields_SHIPPING_ADDRESS => $this->encode($shipping['address'] ?? []),
            Order::schema_fields_BILLING_ADDRESS => $this->encode(
                \is_array($row['billing_address'] ?? null) ? $row['billing_address'] : [],
            ),
            Order::schema_fields_MONEY_SNAPSHOT_JSON => $this->encode($money),
            Order::schema_fields_CATALOG_SNAPSHOT_JSON => $this->encode($snapshots['catalog'] ?? ['lines' => $row['items'] ?? []]),
            Order::schema_fields_SCOPE_SNAPSHOT_JSON => $this->encode($row['scope'] ?? []),
            Order::schema_fields_TAX_SNAPSHOT_JSON => $this->encode($snapshots['tax'] ?? []),
            Order::schema_fields_SHIPPING_SNAPSHOT_JSON => $this->encode($snapshots['shipping'] ?? []),
            Order::schema_fields_IS_SHIPPING_CHARGE_OWNER => !empty($row['is_shipping_charge_owner']) ? 1 : 0,
            Order::schema_fields_SPLIT_KEY => (string)($row['split_key'] ?? 'default'),
            Order::schema_fields_STATE_VERSION => (int)($row['state_version'] ?? 0),
            Order::schema_fields_ORDER_TYPE => strtolower(trim((string)($row['order_type'] ?? 'toc'))) ?: 'toc',
            Order::schema_fields_TYPE_PAYLOAD_JSON => $this->encode(
                \is_array($row['type_payload'] ?? null) ? $row['type_payload'] : [],
            ),
            Order::schema_fields_CHECKOUT_ENTRY => $this->normalizeCheckoutEntry(
                (string)($row['checkout_entry'] ?? ''),
            ),
        ])->save();

        $orderId = (int)$orderModel->getId();
        if ($orderId <= 0) {
            // PgSQL Model save 可能不回填自增主键；按 UUID 再读一次。
            $reloaded = $this->order()
                ->where(Order::schema_fields_ORDER_UUID, (string)$row['order_uuid'])
                ->find()
                ->fetch();
            $orderId = $reloaded instanceof Order ? (int)$reloaded->getId() : 0;
        }
        if ($orderId <= 0) {
            throw new \RuntimeException('order_persist_missing_id:' . (string)$row['order_uuid']);
        }
        foreach (\is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
            $qtyMinor = (int)($item['qty_minor'] ?? 0);
            $unitMinor = (int)($item['unit_price_minor'] ?? 0);
            $this->item()->setData([
                OrderItem::schema_fields_ORDER_ID => $orderId,
                OrderItem::schema_fields_PRODUCT_ID => $item['product_id'] ?? null,
                OrderItem::schema_fields_PRODUCT_SKU => (string)($item['sku'] ?? ''),
                OrderItem::schema_fields_PRODUCT_NAME => (string)($item['name'] ?? ''),
                OrderItem::schema_fields_QTY_ORDERED => $qtyMinor,
                OrderItem::schema_fields_PRICE => $this->minorToMajor($unitMinor),
                OrderItem::schema_fields_ROW_TOTAL => $this->minorToMajor((int)($item['row_total_minor'] ?? $qtyMinor * $unitMinor)),
                OrderItem::schema_fields_TAX_AMOUNT => $this->minorToMajor((int)($item['tax_amount_minor'] ?? 0)),
                OrderItem::schema_fields_ITEM_UUID => $this->newItemUuid(),
                OrderItem::schema_fields_ORDER_UUID => (string)$row['order_uuid'],
                OrderItem::schema_fields_OFFER_ID => $item['offer_id'] ?? null,
                OrderItem::schema_fields_QTY_MINOR => $qtyMinor,
                OrderItem::schema_fields_UNIT_PRICE_MINOR => $unitMinor,
                OrderItem::schema_fields_CATALOG_LINE_SNAPSHOT_JSON => $this->encode($item),
                OrderItem::schema_fields_TAX_SNAPSHOT_JSON => $this->encode($item['tax_snapshot'] ?? []),
            ])->save();
        }
        foreach (\is_array($row['fulfillment_units'] ?? null) ? $row['fulfillment_units'] : [] as $unit) {
            $this->fulfillmentUnit()->setData([
                FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID => (string)$unit['fulfillment_unit_uuid'],
                FulfillmentUnit::schema_fields_ORDER_UUID => (string)$row['order_uuid'],
                FulfillmentUnit::schema_fields_CHECKOUT_GROUP_UUID => (string)$row['checkout_group_uuid'],
                FulfillmentUnit::schema_fields_STATUS => (string)($unit['status'] ?? FulfillmentUnit::STATUS_PENDING),
                FulfillmentUnit::schema_fields_WAREHOUSE_ID => $unit['warehouse_id'] ?? null,
                FulfillmentUnit::schema_fields_WAREHOUSE_SOURCE
                    => $unit['warehouse_source'] ?? null,
                FulfillmentUnit::schema_fields_ALLOCATIONS_JSON
                    => $this->encode($unit['allocations'] ?? []),
                FulfillmentUnit::schema_fields_QTY_MINOR => (int)($unit['qty_minor'] ?? 0),
                FulfillmentUnit::schema_fields_FULFILLED_QTY_MINOR => (int)($unit['fulfilled_qty_minor'] ?? 0),
                FulfillmentUnit::schema_fields_FULFILLMENT_VERSION => (int)($unit['fulfillment_version'] ?? 0),
            ])->save();
        }
    }

    /**
     * @return array<string, mixed> memory 形状 group
     */
    private function hydrateGroup(CheckoutGroup $row): array
    {
        $groupUuid = (string)$row->getData(CheckoutGroup::schema_fields_CHECKOUT_GROUP_UUID);
        $orderRows = [];
        $orderUuids = [];
        $collection = $this->order()
            ->where(Order::schema_fields_CHECKOUT_GROUP_UUID, $groupUuid)
            ->order(Order::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        foreach ($collection->getItems() as $orderModel) {
            if (!$orderModel instanceof Order) {
                continue;
            }
            $hydrated = $this->hydrateOrder($orderModel);
            $orderRows[] = $hydrated;
            $orderUuids[] = (string)$hydrated['order_uuid'];
        }

        $money = $this->decode((string)$row->getData(CheckoutGroup::schema_fields_MONEY_SNAPSHOT_JSON));

        return [
            'checkout_group_uuid' => $groupUuid,
            'order_uuids' => $orderUuids,
            'currency' => (string)$row->getData(CheckoutGroup::schema_fields_CURRENCY),
            'status' => (string)$row->getData(CheckoutGroup::schema_fields_STATUS),
            'totals' => [
                'subtotal_minor' => (int)($money['subtotal_minor'] ?? 0),
                'shipping_amount_minor' => (int)($money['shipping_amount_minor'] ?? 0),
                'tax_amount_minor' => (int)($money['tax_amount_minor'] ?? 0),
                'grand_total_minor' => (int)$row->getData(CheckoutGroup::schema_fields_GRAND_TOTAL_MINOR),
                'order_count' => \count($orderRows),
            ],
            'orders' => $orderRows,
            'shipping_charge_owner_order_uuid' => $row->getData(CheckoutGroup::schema_fields_SHIPPING_OWNER_ORDER_UUID) ?: null,
            'idempotency_key' => (string)$row->getData(CheckoutGroup::schema_fields_IDEMPOTENCY_KEY),
            'request_hash' => (string)$row->getData(CheckoutGroup::schema_fields_REQUEST_HASH),
            'website_id' => (int)$row->getData(CheckoutGroup::schema_fields_WEBSITE_ID),
            'store_id' => (int)$row->getData(CheckoutGroup::schema_fields_STORE_ID),
            'snapshots' => [
                'money' => $money,
                'scope' => $this->decode((string)$row->getData(CheckoutGroup::schema_fields_SCOPE_SNAPSHOT_JSON)),
                'shipping' => $this->decode((string)$row->getData(CheckoutGroup::schema_fields_SHIPPING_SNAPSHOT_JSON)),
                'tax' => $this->decode((string)$row->getData(CheckoutGroup::schema_fields_TAX_SNAPSHOT_JSON)),
            ],
        ];
    }

    /**
     * @return array<string, mixed> memory 形状 order 行
     */
    private function hydrateOrder(Order $row): array
    {
        $catalog = $this->decode((string)$row->getData(Order::schema_fields_CATALOG_SNAPSHOT_JSON));
        $items = \is_array($catalog['lines'] ?? null) ? $catalog['lines'] : [];

        return [
            'order_uuid' => (string)$row->getData(Order::schema_fields_ORDER_UUID),
            'checkout_group_uuid' => (string)$row->getData(Order::schema_fields_CHECKOUT_GROUP_UUID),
            'status' => (string)$row->getData(Order::schema_fields_STATUS),
            'payment_status' => (string)$row->getData(Order::schema_fields_PAYMENT_STATUS),
            'payment_method' => (string)$row->getData(Order::schema_fields_PAYMENT_METHOD),
            'currency' => (string)$row->getData(Order::schema_fields_CURRENCY),
            'website_id' => (int)$row->getData(Order::schema_fields_WEBSITE_ID),
            'store_id' => (int)$row->getData(Order::schema_fields_STORE_ID),
            'customer_id' => $row->getData(Order::schema_fields_CUSTOMER_ID),
            'customer_email' => (string)$row->getData(Order::schema_fields_CUSTOMER_EMAIL),
            'items' => $items,
            'money' => $this->decode((string)$row->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON)),
            'scope' => $this->decode((string)$row->getData(Order::schema_fields_SCOPE_SNAPSHOT_JSON)),
            'billing_address' => $this->decode((string)$row->getData(Order::schema_fields_BILLING_ADDRESS)),
            'snapshots' => [
                'money' => $this->decode((string)$row->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON)),
                'catalog' => $catalog,
                'scope' => $this->decode((string)$row->getData(Order::schema_fields_SCOPE_SNAPSHOT_JSON)),
                'tax' => $this->decode((string)$row->getData(Order::schema_fields_TAX_SNAPSHOT_JSON)),
                'shipping' => $this->decode((string)$row->getData(Order::schema_fields_SHIPPING_SNAPSHOT_JSON)),
            ],
            'fulfillment_units' => $this->hydrateFulfillmentUnits(
                (string)$row->getData(Order::schema_fields_ORDER_UUID),
            ),
            'is_shipping_charge_owner' => (bool)(int)$row->getData(Order::schema_fields_IS_SHIPPING_CHARGE_OWNER),
            'split_key' => (string)($row->getData(Order::schema_fields_SPLIT_KEY) ?: 'default'),
            'state_version' => (int)$row->getData(Order::schema_fields_STATE_VERSION),
            'number_kind' => DisplayNumberRegistry::KIND_ORDER,
            'display_number' => (string)$row->getData(Order::schema_fields_ORDER_NUMBER),
            'order_type' => strtolower(trim((string)($row->getData(Order::schema_fields_ORDER_TYPE) ?: 'toc'))) ?: 'toc',
            'type_payload' => $this->decode((string)$row->getData(Order::schema_fields_TYPE_PAYLOAD_JSON)),
            'checkout_entry' => $this->normalizeCheckoutEntry(
                (string)$row->getData(Order::schema_fields_CHECKOUT_ENTRY),
            ),
        ];
    }

    /**
     * @param array<string,mixed> $typePayload
     */
    public function updateTypePayload(string $orderUuid, array $typePayload): void
    {
        $row = $this->order()
            ->where(Order::schema_fields_ORDER_UUID, trim($orderUuid))
            ->find()
            ->fetch();
        if (!$row instanceof Order || !$row->getId()) {
            throw new \RuntimeException('order_type_payload_update_missing:' . $orderUuid);
        }
        $row->setData(Order::schema_fields_TYPE_PAYLOAD_JSON, $this->encode($typePayload))->save();
    }

    /**
     * Tob hang payable revision: update money snapshot grand_total + major grand_total.
     *
     * @param array<string,mixed> $audit
     */
    public function updateHangPayableGrandTotal(string $orderUuid, int $payableGrandTotalMinor, array $audit = []): void
    {
        $row = $this->order()
            ->where(Order::schema_fields_ORDER_UUID, trim($orderUuid))
            ->find()
            ->fetch();
        if (!$row instanceof Order || !$row->getId()) {
            throw new \RuntimeException('order_hang_payable_update_missing:' . $orderUuid);
        }
        $money = $this->decode((string)$row->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON));
        $prev = (int)($money['grand_total_minor'] ?? 0);
        $money['grand_total_minor'] = max(0, $payableGrandTotalMinor);
        $money['hang_revision_previous_grand_total_minor'] = $prev;
        if ($audit !== []) {
            $money['hang_revision_audit'] = $audit;
        }
        $row->setData(Order::schema_fields_MONEY_SNAPSHOT_JSON, $this->encode($money))
            ->setData(Order::schema_fields_GRAND_TOTAL, $this->minorToMajor(max(0, $payableGrandTotalMinor)))
            ->save();
    }

    /** @return list<array<string, mixed>> */
    private function hydrateFulfillmentUnits(string $orderUuid): array
    {
        $rows = $this->fulfillmentUnit()
            ->where(FulfillmentUnit::schema_fields_ORDER_UUID, $orderUuid)
            ->order(FulfillmentUnit::schema_fields_ID, 'ASC')
            ->select()
            ->fetch();
        $units = [];
        foreach ($rows->getItems() as $row) {
            if (!$row instanceof FulfillmentUnit) {
                continue;
            }
            $units[] = [
                'fulfillment_unit_uuid' => (string)$row->getData(FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID),
                'order_uuid' => (string)$row->getData(FulfillmentUnit::schema_fields_ORDER_UUID),
                'checkout_group_uuid' => (string)$row->getData(FulfillmentUnit::schema_fields_CHECKOUT_GROUP_UUID),
                'status' => (string)$row->getData(FulfillmentUnit::schema_fields_STATUS),
                'warehouse_id' => $row->getData(FulfillmentUnit::schema_fields_WAREHOUSE_ID),
                'warehouse_source' => $row->getData(
                    FulfillmentUnit::schema_fields_WAREHOUSE_SOURCE,
                ),
                'allocations' => $this->decode((string)$row->getData(
                    FulfillmentUnit::schema_fields_ALLOCATIONS_JSON,
                )),
                'qty_minor' => (int)$row->getData(FulfillmentUnit::schema_fields_QTY_MINOR),
                'fulfilled_qty_minor' => (int)$row->getData(FulfillmentUnit::schema_fields_FULFILLED_QTY_MINOR),
                'fulfillment_version' => (int)$row->getData(FulfillmentUnit::schema_fields_FULFILLMENT_VERSION),
            ];
        }

        return $units;
    }

    private function minorToMajor(int $minor): string
    {
        return \number_format($minor / 100, 2, '.', '');
    }

    /** @param mixed $value */
    private function encode(mixed $value): string
    {
        $json = \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('order_facade_snapshot_encode_failed');
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function normalizeCheckoutEntry(string $code): string
    {
        $code = strtolower(trim($code));
        $allowed = ['checkout', 'express', 'quick_buy', 'helppay', 'unknown'];
        if ($code === '' || !\in_array($code, $allowed, true)) {
            return 'unknown';
        }

        return $code;
    }

    private function decode(string $json): array
    {
        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function newItemUuid(): string
    {
        $data = \random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0f) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3f) | 0x80);

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
    }

    private function group(): CheckoutGroup
    {
        $this->groupModel ??= new CheckoutGroup();
        $fresh = clone $this->groupModel;

        return $fresh->clear();
    }

    private function order(): Order
    {
        $this->orderModel ??= new Order();
        $fresh = clone $this->orderModel;

        return $fresh->clear();
    }

    private function item(): OrderItem
    {
        $this->itemModel ??= new OrderItem();
        $fresh = clone $this->itemModel;

        return $fresh->clear();
    }

    private function fulfillmentUnit(): FulfillmentUnit
    {
        $this->fulfillmentUnitModel ??= new FulfillmentUnit();
        $fresh = clone $this->fulfillmentUnitModel;

        return $fresh->clear();
    }
}
