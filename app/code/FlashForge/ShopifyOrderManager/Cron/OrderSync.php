<?php

namespace FlashForge\ShopifyOrderManager\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Helper\OrderSync as OrderSyncHelper;

/**
 * 订单同步定时任务
 * 每10分钟执行一次，从各个Shopify店铺拉取订单
 */
class OrderSync implements CronTaskInterface
{
    private OrderSyncHelper $orderSyncHelper;

    public function __construct()
    {
        $this->orderSyncHelper = ObjectManager::getInstance(OrderSyncHelper::class);
    }

    /**
     * 任务名称
     */
    public function name(): string
    {
        return 'Shopify订单同步任务';
    }

    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'shopify_order_sync';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return '每10分钟从Shopify店铺拉取最新订单信息，包含订单状态更新和新订单抓取';
    }

    /**
     * Cron时间表达式 - 每10分钟执行一次
     */
    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        try {
            // 同步所有店铺订单
            $results = $this->orderSyncHelper->syncAllShops();

            // 统计结果
            $totalShops = count($results);
            $successShops = 0;
            $totalNewOrders = 0;
            $totalUpdatedOrders = 0;
            $errors = [];

            foreach ($results as $result) {
                if ($result['success']) {
                    $successShops++;
                    $totalNewOrders += $result['new_orders'];
                    $totalUpdatedOrders += $result['updated_orders'];
                } else {
                    $errors[] = "{$result['shop_name']}: {$result['error']}";
                }
            }

            // 构建执行结果
            $message = "订单同步完成 - ";
            $message .= "店铺: {$successShops}/{$totalShops} 成功, ";
            $message .= "新增订单: {$totalNewOrders}, ";
            $message .= "更新订单: {$totalUpdatedOrders}";

            if (!empty($errors)) {
                $message .= " | 错误: " . implode('; ', $errors);
            }

            return $message;

        } catch (\Exception $e) {
            $errorMessage = "订单同步任务执行失败: " . $e->getMessage();
            
            // 记录错误日志
            error_log($errorMessage);
            
            return $errorMessage;
        }
    }

    /**
     * 任务超时时间（分钟）
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 15; // 15分钟超时
    }
}
