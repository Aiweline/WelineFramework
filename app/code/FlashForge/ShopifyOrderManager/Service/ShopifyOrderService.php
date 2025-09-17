<?php

namespace FlashForge\ShopifyOrderManager\Service;

use FlashForge\ShopifyOrderManager\Api\OrderInterface;
use FlashForge\ShopifyOrderManager\Helper\ShopifyApi;
use Weline\Framework\Manager\ObjectManager;

/**
 * Shopify订单服务实现
 * 实现统一的订单抽象接口，专门处理Shopify订单数据
 */
class ShopifyOrderService implements OrderInterface
{
    private ShopifyApi $shopifyApi;
    private array $shopData;

    public function __construct(array $shopData)
    {
        $this->shopData = $shopData;
        $this->shopifyApi = ObjectManager::getInstance(ShopifyApi::class);
        $this->shopifyApi->init($shopData['shop_url'], $shopData['api_key']);
    }

    /**
     * 获取单个订单详情
     */
    public function getOrder(string $orderId): ?array
    {
        try {
            $response = $this->shopifyApi->getOrder($orderId);
            
            if (isset($response['order'])) {
                return $this->normalizeOrder($response['order']);
            }
            
            return null;
        } catch (\Exception $e) {
            throw new \Exception("获取订单失败: " . $e->getMessage());
        }
    }

    /**
     * 获取订单列表
     */
    public function getOrders(array $filters = [], int $limit = 50, ?string $cursor = null): array
    {
        try {
            $params = array_merge([
                'limit' => $limit,
                'status' => 'any'
            ], $filters);

            if ($cursor) {
                $params['page_info'] = $cursor;
            }

            $response = $this->shopifyApi->getOrders($params);
            
            $orders = [];
            if (isset($response['orders'])) {
                foreach ($response['orders'] as $order) {
                    $orders[] = $this->normalizeOrder($order);
                }
            }

            return [
                'orders' => $orders,
                'has_next_page' => isset($response['link']) && strpos($response['link'], 'rel="next"') !== false,
                'next_cursor' => $this->extractNextCursor($response['link'] ?? ''),
                'total_count' => count($orders)
            ];
        } catch (\Exception $e) {
            throw new \Exception("获取订单列表失败: " . $e->getMessage());
        }
    }

    /**
     * 获取订单项详情
     */
    public function getOrderItems(string $orderId): array
    {
        try {
            $response = $this->shopifyApi->getOrder($orderId);
            
            $items = [];
            if (isset($response['order']['line_items'])) {
                foreach ($response['order']['line_items'] as $item) {
                    $items[] = $this->normalizeOrderItem($item, $response['order']);
                }
            }
            
            return $items;
        } catch (\Exception $e) {
            throw new \Exception("获取订单项失败: " . $e->getMessage());
        }
    }

    /**
     * 标准化订单数据格式
     */
    public function normalizeOrder(array $rawOrder): array
    {
        return [
            'platform' => 'shopify',
            'shop_id' => $this->shopData['shop_id'],
            'order_id' => $rawOrder['id'] ?? '',
            'order_number' => $rawOrder['order_number'] ?? '',
            'name' => $rawOrder['name'] ?? '',
            'email' => $rawOrder['email'] ?? '',
            'phone' => $rawOrder['phone'] ?? '',
            'created_at' => $rawOrder['created_at'] ?? '',
            'updated_at' => $rawOrder['updated_at'] ?? '',
            'processed_at' => $rawOrder['processed_at'] ?? '',
            'cancelled_at' => $rawOrder['cancelled_at'] ?? '',
            'closed_at' => $rawOrder['closed_at'] ?? '',
            'financial_status' => $rawOrder['financial_status'] ?? '',
            'fulfillment_status' => $rawOrder['fulfillment_status'] ?? '',
            'total_price' => floatval($rawOrder['total_price'] ?? 0),
            'subtotal_price' => floatval($rawOrder['subtotal_price'] ?? 0),
            'total_tax' => floatval($rawOrder['total_tax'] ?? 0),
            'total_discounts' => floatval($rawOrder['total_discounts'] ?? 0),
            'total_shipping_price' => floatval($rawOrder['shipping_lines'][0]['price'] ?? 0),
            'currency' => $rawOrder['currency'] ?? '',
            'gateway' => $rawOrder['gateway'] ?? '',
            'test' => $rawOrder['test'] ?? false,
            'tags' => $rawOrder['tags'] ?? '',
            'note' => $rawOrder['note'] ?? '',
            'billing_address' => $this->normalizeAddress($rawOrder['billing_address'] ?? []),
            'shipping_address' => $this->normalizeAddress($rawOrder['shipping_address'] ?? []),
            'customer' => $this->normalizeCustomer($rawOrder['customer'] ?? []),
            'raw_data' => json_encode($rawOrder) // 保存原始数据用于调试
        ];
    }

    /**
     * 标准化订单项数据格式
     */
    public function normalizeOrderItem(array $rawItem, array $orderData): array
    {
        // 计算单件税费
        $quantity = intval($rawItem['quantity'] ?? 1);
        $totalTax = floatval($rawItem['total_discount'] ?? 0);
        $unitTax = $quantity > 0 ? $totalTax / $quantity : 0;

        return [
            'platform' => 'shopify',
            'shop_id' => $this->shopData['shop_id'],
            'order_id' => $orderData['id'] ?? '',
            'order_number' => $orderData['order_number'] ?? '',
            'item_id' => $rawItem['id'] ?? '',
            'product_id' => $rawItem['product_id'] ?? '',
            'variant_id' => $rawItem['variant_id'] ?? '',
            'sku' => $rawItem['sku'] ?? '',
            'title' => $rawItem['title'] ?? '',
            'variant_title' => $rawItem['variant_title'] ?? '',
            'vendor' => $rawItem['vendor'] ?? '',
            'quantity' => $quantity,
            'price' => floatval($rawItem['price'] ?? 0), // 真实价格
            'total_discount' => floatval($rawItem['total_discount'] ?? 0),
            'total_tax' => $totalTax,
            'unit_tax' => $unitTax, // 单件税费
            'fulfillable_quantity' => intval($rawItem['fulfillable_quantity'] ?? 0),
            'fulfillment_status' => $rawItem['fulfillment_status'] ?? '',
            'requires_shipping' => $rawItem['requires_shipping'] ?? false,
            'taxable' => $rawItem['taxable'] ?? false,
            'gift_card' => $rawItem['gift_card'] ?? false,
            'name' => $rawItem['name'] ?? '',
            'variant_inventory_management' => $rawItem['variant_inventory_management'] ?? '',
            'properties' => json_encode($rawItem['properties'] ?? []),
            'tax_lines' => json_encode($rawItem['tax_lines'] ?? []),
            'discount_allocations' => json_encode($rawItem['discount_allocations'] ?? []),
            'raw_data' => json_encode($rawItem) // 保存原始数据用于调试
        ];
    }

    /**
     * 标准化地址数据
     */
    private function normalizeAddress(array $address): array
    {
        return [
            'first_name' => $address['first_name'] ?? '',
            'last_name' => $address['last_name'] ?? '',
            'company' => $address['company'] ?? '',
            'address1' => $address['address1'] ?? '',
            'address2' => $address['address2'] ?? '',
            'city' => $address['city'] ?? '',
            'province' => $address['province'] ?? '',
            'country' => $address['country'] ?? '',
            'zip' => $address['zip'] ?? '',
            'phone' => $address['phone'] ?? '',
            'name' => $address['name'] ?? '',
            'province_code' => $address['province_code'] ?? '',
            'country_code' => $address['country_code'] ?? '',
            'country_code_v2' => $address['country_code_v2'] ?? '',
            'latitude' => $address['latitude'] ?? null,
            'longitude' => $address['longitude'] ?? null
        ];
    }

    /**
     * 标准化客户数据
     */
    private function normalizeCustomer(array $customer): array
    {
        return [
            'id' => $customer['id'] ?? null,
            'email' => $customer['email'] ?? '',
            'first_name' => $customer['first_name'] ?? '',
            'last_name' => $customer['last_name'] ?? '',
            'phone' => $customer['phone'] ?? '',
            'verified_email' => $customer['verified_email'] ?? false,
            'accepts_marketing' => $customer['accepts_marketing'] ?? false,
            'created_at' => $customer['created_at'] ?? '',
            'updated_at' => $customer['updated_at'] ?? '',
            'orders_count' => $customer['orders_count'] ?? 0,
            'state' => $customer['state'] ?? '',
            'total_spent' => floatval($customer['total_spent'] ?? 0),
            'last_order_id' => $customer['last_order_id'] ?? null,
            'note' => $customer['note'] ?? '',
            'tags' => $customer['tags'] ?? ''
        ];
    }

    /**
     * 提取下一页游标
     */
    private function extractNextCursor(string $linkHeader): ?string
    {
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
            $url = $matches[1];
            if (preg_match('/page_info=([^&]+)/', $url, $pageMatches)) {
                return $pageMatches[1];
            }
        }
        return null;
    }

    /**
     * 测试API连接
     */
    public function testConnection(): bool
    {
        try {
            return $this->shopifyApi->testConnection();
        } catch (\Exception $e) {
            return false;
        }
    }
}
