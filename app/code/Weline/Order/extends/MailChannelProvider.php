<?php

declare(strict_types=1);

namespace Weline\Order\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $variables = [
            ['code' => 'order_id', 'label' => __('订单 ID'), 'sample' => '1001'],
            ['code' => 'order_uuid', 'label' => __('订单 UUID'), 'sample' => 'ord-uuid-example'],
            ['code' => 'order_number', 'label' => __('订单号'), 'sample' => 'ORD202601010001'],
            ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'user@example.com'],
            ['code' => 'status', 'label' => __('状态'), 'sample' => 'paid'],
            ['code' => 'old_status', 'label' => __('原状态'), 'sample' => 'pending'],
            ['code' => 'comment', 'label' => __('备注'), 'sample' => ''],
            ['code' => 'message', 'label' => __('附加说明'), 'sample' => ''],
        ];

        $channels = [
            ['code' => 'Weline_Order::order_created', 'name' => __('订单创建通知'), 'description' => __('新订单创建后的客户/运营邮件通知'), 'dir' => 'order_created'],
            ['code' => 'Weline_Order::order_paid', 'name' => __('订单支付通知'), 'description' => __('订单支付成功邮件通知'), 'dir' => 'order_paid'],
            ['code' => 'Weline_Order::order_status_changed', 'name' => __('订单状态变更通知'), 'description' => __('订单状态变更邮件通知'), 'dir' => 'order_status_changed'],
            ['code' => 'Weline_Order::order_shipped', 'name' => __('订单发货通知'), 'description' => __('订单发货/物流邮件通知'), 'dir' => 'order_shipped'],
            ['code' => 'Weline_Order::order_refund', 'name' => __('订单退款通知'), 'description' => __('订单退款相关邮件通知'), 'dir' => 'order_refund'],
        ];

        $out = [];
        foreach ($channels as $ch) {
            $dir = $ch['dir'];
            unset($ch['dir']);
            $out[] = array_merge($ch, [
                'module' => 'Weline_Order',
                'variables' => $variables,
                'default_templates' => [
                    [
                        'locale' => 'zh_Hans_CN',
                        'subject_file' => "view/email/{$dir}/zh_Hans_CN.subject.txt",
                        'body_file' => "view/email/{$dir}/zh_Hans_CN.html",
                    ],
                    [
                        'locale' => 'en_US',
                        'subject_file' => "view/email/{$dir}/en_US.subject.txt",
                        'body_file' => "view/email/{$dir}/en_US.html",
                    ],
                ],
            ]);
        }

        return $out;
    }
}
