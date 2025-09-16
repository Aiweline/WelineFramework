<?php

namespace FlashForge\ShopifyOrderManager\Console;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use FlashForge\ShopifyOrderManager\Helper\OrderSync;

/**
 * 超时订单检查命令行工具
 * 用法：php bin/w shopify:check-overdue
 */
class OverdueCheckCommand implements CommandInterface
{
    private OrderSync $orderSync;

    public function __construct()
    {
        $this->orderSync = ObjectManager::getInstance(OrderSync::class);
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = []): void
    {
        echo "⏰ 开始检查超时订单...\n";
        echo "==================\n";

        $startTime = microtime(true);

        try {
            $success = $this->orderSync->checkOverdueOrders();

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            if ($success) {
                echo "✅ 超时订单检查完成\n";
                echo "⏱️  耗时: {$duration}秒\n";
                echo "📧 相关通知已发送\n";
            } else {
                echo "⚠️  超时订单检查完成，但通知发送失败\n";
                echo "⏱️  耗时: {$duration}秒\n";
                echo "📝 请检查飞书通知配置\n";
            }

        } catch (\Exception $e) {
            echo "❌ 检查过程中发生错误: " . $e->getMessage() . "\n";
            echo "📝 错误详情: " . $e->getFile() . ":" . $e->getLine() . "\n";
        }
    }

    /**
     * 命令提示信息
     */
    public function tip(): string
    {
        return '
检查超过15天未发货的订单并发送飞书通知

用法:
  php bin/w shopify:check-overdue

说明:
  此命令会检查所有超过15天未发货的订单，并通过飞书发送提醒通知。
  通常由定时任务自动执行，也可以手动运行进行测试。
        ';
    }
}
