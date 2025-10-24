<?php

namespace FlashForge\ShopifyOrderManager\Api;

/**
 * 统一订单抽象接口
 * 定义订单导出的标准接口，支持多平台扩展
 */
interface OrderInterface
{
    /**
     * 获取单个订单详情
     * 
     * @param string $orderId 订单ID
     * @return array|null 订单数据，失败返回null
     */
    public function getOrder(string $orderId): ?array;

    /**
     * 获取订单列表
     * 
     * @param array $filters 过滤条件
     * @param int $limit 限制数量
     * @param string|null $cursor 游标（用于分页）
     * @return array 包含订单列表和分页信息
     */
    public function getOrders(array $filters = [], int $limit = 50, ?string $cursor = null): array;

    /**
     * 获取订单项详情
     * 
     * @param string $orderId 订单ID
     * @return array 订单项列表
     */
    public function getOrderItems(string $orderId): array;

    /**
     * 标准化订单数据格式
     * 
     * @param array $rawOrder 原始订单数据
     * @return array 标准化后的订单数据
     */
    public function normalizeOrder(array $rawOrder): array;

    /**
     * 标准化订单项数据格式
     * 
     * @param array $rawItem 原始订单项数据
     * @param array $orderData 订单数据（用于关联信息）
     * @return array 标准化后的订单项数据
     */
    public function normalizeOrderItem(array $rawItem, array $orderData): array;

    /**
     * 测试API连接
     * 
     * @return bool 连接是否成功
     */
    public function testConnection(): bool;
}
