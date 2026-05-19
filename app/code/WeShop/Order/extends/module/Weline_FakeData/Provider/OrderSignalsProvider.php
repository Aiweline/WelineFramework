<?php

declare(strict_types=1);

namespace WeShop\Order\Extends\Module\Weline_FakeData\Provider;

use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;
use WeShop\Product\Model\Product;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;
use Weline\Framework\Manager\ObjectManager;

class OrderSignalsProvider implements FakeDataProviderInterface
{
    private const CODE = 'weshop_order_signals';
    private const ENTITY_ORDER = 'order';
    private const ENTITY_ORDER_ITEM = 'order_item';
    private const CUSTOMER_LOGIN = 'weline';

    private readonly Order $order;
    private readonly OrderItem $orderItem;
    private readonly Product $product;
    private readonly AuthCustomer $authCustomer;

    public function __construct(
        Order|OrderItem|null $order = null,
        mixed $orderItem = null,
        mixed $product = null,
        mixed $authCustomer = null,
    ) {
        if ($order instanceof OrderItem) {
            $this->orderItem = $order;
            $this->order = ObjectManager::getInstance(Order::class);
            $this->product = $orderItem instanceof Product ? $orderItem : ($product instanceof Product ? $product : ObjectManager::getInstance(Product::class));
            $this->authCustomer = $product instanceof AuthCustomer ? $product : ($authCustomer instanceof AuthCustomer ? $authCustomer : ObjectManager::getInstance(AuthCustomer::class));
            return;
        }

        $this->order = $order instanceof Order ? $order : ObjectManager::getInstance(Order::class);
        $this->orderItem = $orderItem instanceof OrderItem ? $orderItem : ObjectManager::getInstance(OrderItem::class);
        $this->product = $product instanceof Product ? $product : ObjectManager::getInstance(Product::class);
        $this->authCustomer = $authCustomer instanceof AuthCustomer ? $authCustomer : ObjectManager::getInstance(AuthCustomer::class);
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getModuleName(): string
    {
        return 'WeShop_Order';
    }

    public function getLabel(): string
    {
        return 'WeShop demo bestseller and recommendation signals';
    }

    public function getSortOrder(): int
    {
        return 400;
    }

    public function getDependencies(): array
    {
        return ['weshop_product', 'weshop_customer'];
    }

    public function describe(): array
    {
        return [
            'entities' => [self::ENTITY_ORDER, self::ENTITY_ORDER_ITEM],
            'orders' => count($this->getSignalOrders()),
            'customer_login' => self::CUSTOMER_LOGIN,
        ];
    }

    public function seed(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $customerId = $this->getTargetCustomerId();
        if ($customerId <= 0) {
            return $result->addError((string) __('假订单信号需要前台客户 %{1} 已存在。', [self::CUSTOMER_LOGIN]));
        }

        foreach ($this->getSignalOrders() as $signalOrder) {
            $items = (array) ($signalOrder['items'] ?? []);
            if ($items === []) {
                continue;
            }

            $computedSubtotal = 0.0;
            foreach ($items as $item) {
                $computedSubtotal += ((float) ($item['price'] ?? 0)) * ((int) ($item['quantity'] ?? 1));
            }
            $shippingAmount = (float) ($signalOrder['shipping_amount'] ?? 0);
            $discountAmount = (float) ($signalOrder['discount_amount'] ?? 0);
            $taxAmount = (float) ($signalOrder['tax_amount'] ?? 0);
            $subtotal = (float) ($signalOrder['subtotal'] ?? $computedSubtotal);
            $total = (float) ($signalOrder['total'] ?? round($subtotal + $shippingAmount + $taxAmount - $discountAmount, 2));

            $existingOrder = $this->order->reset()
                ->where(Order::schema_fields_increment_id, (string) $signalOrder['increment_id'])
                ->find()
                ->fetch();

            $orderModel = $this->order->reset()->clearData();
            if ($existingOrder->getId()) {
                $orderModel->load((int) $existingOrder->getId());
            }

            $orderModel
                ->setData(Order::schema_fields_increment_id, (string) $signalOrder['increment_id'])
                ->setData(Order::schema_fields_customer_id, $customerId)
                ->setData(Order::schema_fields_status, (string) ($signalOrder['status'] ?? 'completed'))
                ->setData(Order::schema_fields_subtotal, $subtotal)
                ->setData(Order::schema_fields_shipping_amount, $shippingAmount)
                ->setData(Order::schema_fields_discount_amount, $discountAmount)
                ->setData(Order::schema_fields_tax_amount, $taxAmount)
                ->setData(Order::schema_fields_total, $total)
                ->setData(Order::schema_fields_payment_status, (string) ($signalOrder['payment_status'] ?? 'paid'))
                ->setData(Order::schema_fields_fulfillment_status, (string) ($signalOrder['fulfillment_status'] ?? 'delivered'))
                ->setData(Order::schema_fields_return_status, (string) ($signalOrder['return_status'] ?? 'none'))
                ->setData(Order::schema_fields_shipping_method, (string) ($signalOrder['shipping_method'] ?? 'standard'))
                ->setData(Order::schema_fields_payment_method, (string) ($signalOrder['payment_method'] ?? 'credit_card'))
                ->setData(Order::schema_fields_shipping_address, json_encode($signalOrder['shipping_address'] ?? $this->getDefaultShippingAddress(), JSON_UNESCAPED_UNICODE))
                ->setData(Order::schema_fields_fulfillment_carrier, (string) ($signalOrder['fulfillment_carrier'] ?? 'SF Express'))
                ->setData(Order::schema_fields_fulfillment_tracking_number, (string) ($signalOrder['fulfillment_tracking_number'] ?? ('WS' . ($signalOrder['order_id'] ?? '000000'))))
                ->setData(Order::schema_fields_shipped_at, (string) ($signalOrder['shipped_at'] ?? $signalOrder['created_at']))
                ->setData(Order::schema_fields_delivered_at, (string) ($signalOrder['delivered_at'] ?? $signalOrder['created_at']))
                ->setData(Order::schema_fields_created_at, (string) ($signalOrder['created_at'] ?? date('Y-m-d H:i:s')))
                ->setData(Order::schema_fields_updated_at, (string) ($signalOrder['created_at'] ?? date('Y-m-d H:i:s')))
                ->save();

            $savedOrder = $this->order->reset()
                ->where(Order::schema_fields_increment_id, (string) $signalOrder['increment_id'])
                ->find()
                ->fetch();
            $savedOrderId = (int) ($savedOrder->getId() ?? 0);
            if ($savedOrderId <= 0) {
                $result->addError((string) __('保存假订单 %{1} 失败。', [$signalOrder['increment_id'] ?? '']));
                continue;
            }

            $context->record(
                self::CODE,
                self::ENTITY_ORDER,
                $savedOrderId,
                'order:' . (string) $signalOrder['increment_id'],
                ['increment_id' => (string) $signalOrder['increment_id'], 'customer_id' => $customerId]
            );
            $existingOrder->getId() ? $result->addUpdated() : $result->addCreated();

            foreach ($items as $item) {
                $sku = (string) ($item['sku'] ?? '');
                $product = $this->loadProductBySku($sku);
                if (!$product || !$product->getId()) {
                    $result->addWarning((string) __('已跳过假订单信号商品行：缺少商品 %{1}', [$sku]));
                    continue;
                }

                $qty = (int) ($item['quantity'] ?? 1);
                $price = (float) ($item['price'] ?? $product->getPrice());
                $createdAt = (string) ($signalOrder['created_at'] ?? date('Y-m-d H:i:s'));

                $existingItem = $this->orderItem->reset()
                    ->where(OrderItem::schema_fields_ORDER_ID, $savedOrderId)
                    ->where(OrderItem::schema_fields_PRODUCT_ID, (int) $product->getId())
                    ->find()
                    ->fetch();

                $this->orderItem->reset()
                    ->clearData()
                    ->setData(OrderItem::schema_fields_ORDER_ID, $savedOrderId)
                    ->setData(OrderItem::schema_fields_PRODUCT_ID, (int) $product->getId())
                    ->setData(OrderItem::schema_fields_PRODUCT_NAME, (string) $product->getName())
                    ->setData(OrderItem::schema_fields_PRODUCT_SKU, (string) $product->getSku())
                    ->setData(OrderItem::schema_fields_PRODUCT_IMAGE, (string) $product->getImage())
                    ->setData(OrderItem::schema_fields_QUANTITY, $qty)
                    ->setData(OrderItem::schema_fields_PRICE, $price)
                    ->setData(OrderItem::schema_fields_TOTAL, round($qty * $price, 2))
                    ->setData(OrderItem::schema_fields_CREATED_AT, $createdAt)
                    ->save();

                $savedItem = $this->orderItem->reset()
                    ->where(OrderItem::schema_fields_ORDER_ID, $savedOrderId)
                    ->where(OrderItem::schema_fields_PRODUCT_ID, (int) $product->getId())
                    ->find()
                    ->fetch();
                $itemId = (int) ($savedItem->getId() ?? 0);
                if ($itemId <= 0) {
                    $result->addError((string) __('保存假订单信号商品行 %{1} 失败。', [$sku]));
                    continue;
                }

                $context->record(
                    self::CODE,
                    self::ENTITY_ORDER_ITEM,
                    $itemId,
                    'order-item:' . $savedOrderId . ':' . $product->getId(),
                    ['order_id' => $savedOrderId, 'sku' => $product->getSku()]
                );
                $existingItem->getId() ? $result->addUpdated() : $result->addCreated();
            }
        }

        return $result;
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();

        foreach ($context->getRecordService()->getRecords(self::CODE, self::ENTITY_ORDER_ITEM) as $record) {
            $itemId = (int) ($record['entity_id'] ?? 0);
            $stableKey = (string) ($record['stable_key'] ?? '');
            if ($itemId > 0) {
                $this->orderItem->clear()
                    ->getQuery()
                    ->where(OrderItem::schema_fields_ID, $itemId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            }
            if ($stableKey !== '') {
                $context->getRecordService()->removeRecord(self::CODE, $stableKey);
            }
        }

        foreach ($context->getRecordService()->getRecords(self::CODE, self::ENTITY_ORDER) as $record) {
            $orderId = (int) ($record['entity_id'] ?? 0);
            $stableKey = (string) ($record['stable_key'] ?? '');
            if ($orderId > 0) {
                $this->order->clear()
                    ->getQuery()
                    ->where(Order::schema_fields_ID, $orderId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            }
            if ($stableKey !== '') {
                $context->getRecordService()->removeRecord(self::CODE, $stableKey);
            }
        }

        return $result;
    }

    private function getTargetCustomerId(): int
    {
        $customer = $this->authCustomer->reset()
            ->where(AuthCustomer::schema_fields_username, self::CUSTOMER_LOGIN, '=', 'or')
            ->where(AuthCustomer::schema_fields_email, self::CUSTOMER_LOGIN)
            ->find()
            ->fetch();

        return (int) ($customer->getId() ?? 0);
    }

    private function loadProductBySku(string $sku): ?Product
    {
        if ($sku === '') {
            return null;
        }

        $product = $this->product->reset()
            ->where(Product::schema_fields_sku, $sku)
            ->find()
            ->fetch();

        return $product->getId() ? $product : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSignalOrders(): array
    {
        return [
            [
                'order_id' => 910001,
                'increment_id' => 'WSFAKE910001',
                'created_at' => '2026-05-12 10:00:00',
                'status' => 'completed',
                'payment_status' => 'paid',
                'fulfillment_status' => 'delivered',
                'shipping_amount' => 18,
                'items' => [
                    ['sku' => 'ELEC-IPHONE-15-PRO-BLK-256', 'quantity' => 2, 'price' => 7999],
                    ['sku' => 'ELEC-AIRPODS-PRO-USBC', 'quantity' => 2, 'price' => 1699],
                    ['sku' => 'ELEC-POWERBANK-20K', 'quantity' => 2, 'price' => 299],
                ],
            ],
            [
                'order_id' => 910002,
                'increment_id' => 'WSFAKE910002',
                'created_at' => '2026-05-12 13:20:00',
                'status' => 'completed',
                'payment_status' => 'paid',
                'fulfillment_status' => 'delivered',
                'shipping_amount' => 18,
                'items' => [
                    ['sku' => 'ELEC-GALAXY-S24-TITANIUM-256', 'quantity' => 2, 'price' => 7299],
                    ['sku' => 'ELEC-BOSE-ULTRA', 'quantity' => 1, 'price' => 2599],
                    ['sku' => 'ELEC-ROUTER-MESH-2PK', 'quantity' => 1, 'price' => 1399],
                ],
            ],
            [
                'order_id' => 910003,
                'increment_id' => 'WSFAKE910003',
                'created_at' => '2026-05-13 09:15:00',
                'status' => 'fulfilled',
                'payment_status' => 'paid',
                'fulfillment_status' => 'shipped',
                'shipping_amount' => 28,
                'items' => [
                    ['sku' => 'ELEC-MACBOOK-AIR-15', 'quantity' => 3, 'price' => 10999],
                    ['sku' => 'ELEC-USB-C-DOCK', 'quantity' => 3, 'price' => 799],
                    ['sku' => 'ELEC-4K-MONITOR-27', 'quantity' => 2, 'price' => 2899],
                    ['sku' => 'ELEC-LOGI-MX-MASTER', 'quantity' => 3, 'price' => 699],
                ],
            ],
            [
                'order_id' => 910004,
                'increment_id' => 'WSFAKE910004',
                'created_at' => '2026-05-13 15:45:00',
                'status' => 'completed',
                'payment_status' => 'paid',
                'fulfillment_status' => 'delivered',
                'shipping_amount' => 18,
                'items' => [
                    ['sku' => 'ELEC-IPAD-AIR-13', 'quantity' => 2, 'price' => 5299],
                    ['sku' => 'ELEC-KEYBOARD-MECH-84', 'quantity' => 2, 'price' => 899],
                    ['sku' => 'ELEC-WATCHFACE-BUNDLE', 'quantity' => 5, 'price' => 49],
                ],
            ],
            [
                'order_id' => 910005,
                'increment_id' => 'WSFAKE910005',
                'created_at' => '2026-05-14 11:30:00',
                'status' => 'completed',
                'payment_status' => 'paid',
                'fulfillment_status' => 'delivered',
                'shipping_amount' => 18,
                'items' => [
                    ['sku' => 'ELEC-SONY-XM5', 'quantity' => 4, 'price' => 2399],
                    ['sku' => 'ELEC-SAMPLE-PRESET-PACK', 'quantity' => 6, 'price' => 79],
                    ['sku' => 'ELEC-GAMING-HANDHELD', 'quantity' => 2, 'price' => 3299],
                ],
            ],
            [
                'order_id' => 910006,
                'increment_id' => 'WSFAKE910006',
                'created_at' => '2026-05-14 17:10:00',
                'status' => 'processing',
                'payment_status' => 'paid',
                'fulfillment_status' => 'shipped',
                'shipping_amount' => 18,
                'items' => [
                    ['sku' => 'ELEC-CANON-VLOG', 'quantity' => 2, 'price' => 4599],
                    ['sku' => 'ELEC-POWERBANK-20K', 'quantity' => 4, 'price' => 299],
                    ['sku' => 'ELEC-SMARTHOME-HUB', 'quantity' => 3, 'price' => 599],
                ],
            ],
            [
                'order_id' => 910007,
                'increment_id' => 'WSFAKE910007',
                'created_at' => '2026-05-15 09:40:00',
                'status' => 'completed',
                'payment_status' => 'paid',
                'fulfillment_status' => 'delivered',
                'shipping_amount' => 12,
                'items' => [
                    ['sku' => 'ELEC-AIRPODS-PRO-MAGSAFE', 'quantity' => 4, 'price' => 1799],
                    ['sku' => 'ELEC-IPHONE-15-PRO-WHT-256', 'quantity' => 1, 'price' => 7999],
                    ['sku' => 'ELEC-WATCHFACE-BUNDLE', 'quantity' => 3, 'price' => 49],
                ],
            ],
            [
                'order_id' => 910008,
                'increment_id' => 'WSFAKE910008',
                'created_at' => '2026-05-15 18:25:00',
                'status' => 'processing',
                'payment_status' => 'paid',
                'fulfillment_status' => 'pending',
                'shipping_amount' => 18,
                'items' => [
                    ['sku' => 'ELEC-USB-C-DOCK', 'quantity' => 4, 'price' => 799],
                    ['sku' => 'ELEC-LOGI-MX-MASTER', 'quantity' => 4, 'price' => 699],
                    ['sku' => 'ELEC-4K-MONITOR-27', 'quantity' => 2, 'price' => 2899],
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultShippingAddress(): array
    {
        return [
            'name' => 'WeShop Demo',
            'phone' => '18800000000',
            'country' => 'CN',
            'province' => 'Shanghai',
            'city' => 'Shanghai',
            'district' => 'Pudong',
            'address' => 'No. 1000 Century Avenue',
            'postcode' => '200120',
        ];
    }
}
