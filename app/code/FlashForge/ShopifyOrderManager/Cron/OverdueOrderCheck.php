<?php

namespace FlashForge\ShopifyOrderManager\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Helper\OrderSync as OrderSyncHelper;

/**
 * 超时订单检查定时任务
 * 每天执行一次，检查超过15天未发货的订单并发送飞书通知
 */
class OverdueOrderCheck implements CronTaskInterface
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
        return 'Shopify超时订单检查任务';
    }

    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'shopify_overdue_order_check';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return '每天检查超过15天未发货的订单，并通过飞书发送提醒通知';
    }

    /**
     * Cron时间表达式 - 每天早上9点执行
     */
    public function cron_time(): string
    {
        return '0 9 * * *';
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        try {
            $success = $this->orderSyncHelper->checkOverdueOrders();

            if ($success) {
                return "超时订单检查完成，已发送相关通知";
            } else {
                return "超时订单检查完成，但通知发送失败";
            }

        } catch (\Exception $e) {
            $errorMessage = "超时订单检查任务执行失败: " . $e->getMessage();
            
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
        return 10; // 10分钟超时
    }
}
