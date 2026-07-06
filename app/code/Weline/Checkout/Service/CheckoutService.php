<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Service;

use Weline\Checkout\Model\Order;
use Weline\Checkout\Model\OrderItem;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\EventsManager;

/**
 * 结账服务
 */
class CheckoutService
{
    private const BUSINESS_SOURCE_FIELDS = [
        'source_app',
        'source_module',
        'business_code',
        'business_name',
    ];

    private ConnectionFactory $connectionFactory;
    private EventsManager $eventsManager;

    public function __construct(
        ConnectionFactory $connectionFactory,
        EventsManager $eventsManager
    ) {
        $this->connectionFactory = $connectionFactory;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 验证结账数据
     * 
     * @param array $data
     * @return array 返回验证结果 ['valid' => bool, 'errors' => []]
     */
    public function validateCheckout(array $data): array
    {
        // 派遣验证前事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::validate::before', [
            'data' => &$data,
        ]);
        
        $errors = [];
        
        // 验证客户ID
        if (empty($data['customer_id'])) {
            $errors[] = __('客户ID不能为空');
        }
        
        // 验证订单项
        if (empty($data['items']) || !is_array($data['items'])) {
            $errors[] = __('订单项不能为空');
        } else {
            foreach ($data['items'] as $index => $item) {
                if (empty($item['product_id'])) {
                    $errors[] = __('订单项 %{1} 的产品ID不能为空', [$index + 1]);
                }
                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    $errors[] = __('订单项 %{1} 的数量必须大于0', [$index + 1]);
                }
                if (empty($item['price']) || $item['price'] < 0) {
                    $errors[] = __('订单项 %{1} 的价格无效', [$index + 1]);
                }
            }
        }
        
        // 验证收货地址
        if (empty($data['shipping_address'])) {
            $errors[] = __('收货地址不能为空');
        }
        
        $result = [
            'valid' => empty($errors),
            'errors' => $errors
        ];
        
        // 派遣验证后事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::validate::after', [
            'data' => $data,
            'result' => &$result,
        ]);
        
        return $result;
    }

    /**
     * 计算订单总额
     * 
     * @param array $items 订单项
     * @param float $shippingAmount 运费
     * @param float $taxAmount 税费
     * @param float $discountAmount 折扣金额
     * @return array
     */
    public function calculateTotals(array $items, float $shippingAmount = 0.0, float $taxAmount = 0.0, float $discountAmount = 0.0): array
    {
        // 派遣计算前事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::calculate_totals::before', [
            'items' => &$items,
            'shipping_amount' => &$shippingAmount,
            'tax_amount' => &$taxAmount,
            'discount_amount' => &$discountAmount,
        ]);
        
        $subtotal = 0.0;
        
        foreach ($items as $item) {
            $quantity = (float)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $subtotal += $quantity * $price;
        }
        
        $total = $subtotal + $shippingAmount + $taxAmount - $discountAmount;
        
        $result = [
            'subtotal' => round($subtotal, 2),
            'shipping_amount' => round($shippingAmount, 2),
            'tax_amount' => round($taxAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'total_amount' => round($total, 2)
        ];
        
        // 派遣计算后事件
        $this->eventsManager->dispatch('Weline_Checkout::checkout::calculate_totals::after', [
            'items' => $items,
            'result' => &$result,
        ]);
        
        return $result;
    }

    /**
     * 创建订单
     * 
     * @param array $data
     * @return Order
     * @throws \Exception
     */
    public function createOrder(array $data): Order
    {
        // 验证数据
        $validation = $this->validateCheckout($data);
        if (!$validation['valid']) {
            throw new \Exception(implode(', ', $validation['errors']));
        }
        
        $conn = $this->connectionFactory->getConnection();
        $conn->beginTransaction();
        
        try {
            // 派遣创建订单前事件
            $this->eventsManager->dispatch('Weline_Checkout::checkout::create_order::before', [
                'data' => &$data,
            ]);
            
            // 计算订单总额
            $totals = $this->calculateTotals(
                $data['items'],
                (float)($data['shipping_amount'] ?? 0.0),
                (float)($data['tax_amount'] ?? 0.0),
                (float)($data['discount_amount'] ?? 0.0)
            );
            $businessContext = $this->resolveBusinessContext($data, $data['items']);
            
            // 创建订单
            /** @var Order $order */
            $order = ObjectManager::getInstance(Order::class);
            $orderData = [
                Order::schema_fields_ORDER_NUMBER => $order->generateOrderNumber(),
                Order::schema_fields_CUSTOMER_ID => $data['customer_id'],
                Order::schema_fields_STATUS => Order::STATUS_PENDING,
                Order::schema_fields_SUBTOTAL => $totals['subtotal'],
                Order::schema_fields_SHIPPING_AMOUNT => $totals['shipping_amount'],
                Order::schema_fields_TAX_AMOUNT => $totals['tax_amount'],
                Order::schema_fields_DISCOUNT_AMOUNT => $totals['discount_amount'],
                Order::schema_fields_TOTAL_AMOUNT => $totals['total_amount'],
                Order::schema_fields_CURRENCY => $data['currency'] ?? 'CNY',
                Order::schema_fields_SOURCE_APP => $businessContext['source_app'],
                Order::schema_fields_SOURCE_MODULE => $businessContext['source_module'],
                Order::schema_fields_BUSINESS_CODE => $businessContext['business_code'],
                Order::schema_fields_BUSINESS_NAME => $businessContext['business_name'],
                Order::schema_fields_SHIPPING_ADDRESS => is_array($data['shipping_address']) 
                    ? json_encode($data['shipping_address'], JSON_UNESCAPED_UNICODE) 
                    : $data['shipping_address'],
                Order::schema_fields_BILLING_ADDRESS => !empty($data['billing_address'])
                    ? (is_array($data['billing_address']) 
                        ? json_encode($data['billing_address'], JSON_UNESCAPED_UNICODE) 
                        : $data['billing_address'])
                    : (is_array($data['shipping_address']) 
                        ? json_encode($data['shipping_address'], JSON_UNESCAPED_UNICODE) 
                        : $data['shipping_address']),
                Order::schema_fields_PAYMENT_METHOD => $data['payment_method'] ?? '',
                Order::schema_fields_PAYMENT_STATUS => Order::PAYMENT_STATUS_PENDING,
                Order::schema_fields_SHIPPING_METHOD => $data['shipping_method'] ?? '',
                Order::schema_fields_SHIPPING_STATUS => Order::SHIPPING_STATUS_PENDING,
                Order::schema_fields_REMARK => $data['remark'] ?? '',
                Order::schema_fields_CREATED_TIME => date('Y-m-d H:i:s'),
                Order::schema_fields_UPDATED_TIME => date('Y-m-d H:i:s'),
            ];
            $this->stripUnavailableBusinessSourceFields($order, $orderData);
            $order->setData($orderData);
            $order->save();
            
            // 创建订单项
            /** @var OrderItem $orderItem */
            $orderItem = ObjectManager::getInstance(OrderItem::class);
            foreach ($data['items'] as $item) {
                $itemBusinessContext = $this->resolveItemBusinessContext($item, $businessContext);
                $orderItemData = [
                    OrderItem::schema_fields_ORDER_ID => $order->getId(),
                    OrderItem::schema_fields_PRODUCT_ID => $item['product_id'],
                    OrderItem::schema_fields_PRODUCT_NAME => $item['product_name'] ?? '',
                    OrderItem::schema_fields_PRODUCT_SKU => $item['product_sku'] ?? '',
                    OrderItem::schema_fields_SOURCE_APP => $itemBusinessContext['source_app'],
                    OrderItem::schema_fields_SOURCE_MODULE => $itemBusinessContext['source_module'],
                    OrderItem::schema_fields_BUSINESS_CODE => $itemBusinessContext['business_code'],
                    OrderItem::schema_fields_BUSINESS_NAME => $itemBusinessContext['business_name'],
                    OrderItem::schema_fields_QUANTITY => $item['quantity'],
                    OrderItem::schema_fields_PRICE => $item['price'],
                    OrderItem::schema_fields_TOTAL_PRICE => (float)$item['quantity'] * (float)$item['price'],
                    OrderItem::schema_fields_ATTRIBUTES => !empty($item['attributes'])
                        ? (is_array($item['attributes'])
                            ? json_encode($item['attributes'], JSON_UNESCAPED_UNICODE)
                            : $item['attributes'])
                        : '',
                    OrderItem::schema_fields_CREATED_TIME => date('Y-m-d H:i:s'),
                ];
                $this->stripUnavailableBusinessSourceFields($orderItem, $orderItemData);
                $orderItem->clear()
                    ->setData($orderItemData)
                    ->save();
            }
            
            // 派遣创建订单后事件
            $this->eventsManager->dispatch('Weline_Checkout::checkout::create_order::after', [
                'order' => $order,
                'order_id' => $order->getId(),
            ]);
            
            $conn->commit();
            return $order;
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stripUnavailableBusinessSourceFields(AbstractModel $model, array &$data): void
    {
        $availableColumns = $this->availableColumnMap($model);
        if ($availableColumns === null) {
            return;
        }

        foreach (self::BUSINESS_SOURCE_FIELDS as $field) {
            if (!isset($availableColumns[$field])) {
                unset($data[$field]);
            }
        }
    }

    /**
     * @return array<string, true>|null
     */
    private function availableColumnMap(AbstractModel $model): ?array
    {
        try {
            $columns = $model->columns();
        } catch (\Throwable) {
            return null;
        }

        $map = [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $name = $column['Field']
                ?? $column['field']
                ?? $column['column_name']
                ?? $column['Column']
                ?? $column['name']
                ?? '';
            $name = trim((string)$name);
            if ($name !== '') {
                $map[$name] = true;
            }
        }

        return $map === [] ? null : $map;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $items
     * @param array<string, string> $fallback
     * @return array{source_app: string, source_module: string, business_code: string, business_name: string}
     */
    private function resolveBusinessContext(array $data, array $items = [], array $fallback = []): array
    {
        $business = is_array($data['business'] ?? null) ? $data['business'] : [];

        $sourceModule = $this->firstSourceString($data, ['source_module', 'business_module', 'module'], 100)
            ?: $this->firstSourceString($business, ['source_module', 'business_module', 'module'], 100)
            ?: $this->uniqueItemSource($items, ['source_module', 'business_module', 'module'], 100)
            ?: ($fallback['source_module'] ?? '');
        $sourceApp = $this->firstSourceString($data, ['source_app', 'app_code', 'app'], 80)
            ?: $this->firstSourceString($business, ['source_app', 'app_code', 'app'], 80)
            ?: $this->uniqueItemSource($items, ['source_app', 'app_code', 'app'], 80)
            ?: $this->sourceAppFromModule($sourceModule)
            ?: ($fallback['source_app'] ?? '');
        $businessCode = $this->firstSourceString($data, ['business_code', 'business_type'], 100)
            ?: $this->firstSourceString($business, ['code', 'business_code', 'business_type'], 100)
            ?: $this->uniqueItemSource($items, ['business_code', 'business_type'], 100)
            ?: ($fallback['business_code'] ?? '');
        $businessName = $this->firstSourceString($data, ['business_name', 'business_label'], 160)
            ?: $this->firstSourceString($business, ['name', 'business_name', 'business_label'], 160)
            ?: $this->uniqueItemSource($items, ['business_name', 'business_label'], 160, (string)__('混合业务订单'))
            ?: ($fallback['business_name'] ?? '');

        $sourceModule = $sourceModule !== '' ? $sourceModule : 'Weline_Checkout';
        $sourceApp = $sourceApp !== '' ? $sourceApp : ($this->sourceAppFromModule($sourceModule) ?: 'Weline');
        $businessCode = $businessCode !== '' ? $businessCode : 'checkout_order';
        $businessName = $businessName !== '' ? $businessName : 'Weline Checkout';

        return [
            'source_app' => $sourceApp,
            'source_module' => $sourceModule,
            'business_code' => $businessCode,
            'business_name' => $businessName,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, string> $fallback
     * @return array{source_app: string, source_module: string, business_code: string, business_name: string}
     */
    private function resolveItemBusinessContext(array $item, array $fallback): array
    {
        return $this->resolveBusinessContext($item, [$item], $fallback);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    private function firstSourceString(array $data, array $keys, int $limit): string
    {
        foreach ($keys as $key) {
            $value = $this->shortSourceString($data[$key] ?? '', $limit);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $keys
     */
    private function uniqueItemSource(array $items, array $keys, int $limit, string $mixedValue = 'mixed'): string
    {
        $values = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = $this->firstSourceString($item, $keys, $limit);
            if ($value === '') {
                continue;
            }
            $values[$value] = true;
            if (count($values) > 1) {
                return $this->shortSourceString($mixedValue, $limit);
            }
        }

        return $values === [] ? '' : (string)array_key_first($values);
    }

    private function shortSourceString(mixed $value, int $limit): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        return strlen($value) <= $limit ? $value : substr($value, 0, $limit);
    }

    private function sourceAppFromModule(string $moduleName): string
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return '';
        }

        return str_contains($moduleName, '_') ? strstr($moduleName, '_', true) : $moduleName;
    }
}
