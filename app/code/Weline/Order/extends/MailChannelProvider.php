<?php

declare(strict_types=1);

namespace Weline\Order\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        return [
            [
                'code' => 'Weline_Order::order_created',
                'name' => __('订单创建通知'),
                'description' => __('新订单创建后的客户/运营邮件通知'),
                'module' => 'Weline_Order',
            ],
            [
                'code' => 'Weline_Order::order_paid',
                'name' => __('订单支付通知'),
                'description' => __('订单支付成功邮件通知'),
                'module' => 'Weline_Order',
            ],
            [
                'code' => 'Weline_Order::order_status_changed',
                'name' => __('订单状态变更通知'),
                'description' => __('订单状态变更邮件通知'),
                'module' => 'Weline_Order',
            ],
            [
                'code' => 'Weline_Order::order_shipped',
                'name' => __('订单发货通知'),
                'description' => __('订单发货/物流邮件通知'),
                'module' => 'Weline_Order',
            ],
            [
                'code' => 'Weline_Order::order_refund',
                'name' => __('订单退款通知'),
                'description' => __('订单退款相关邮件通知'),
                'module' => 'Weline_Order',
            ],
        ];
    }
}
