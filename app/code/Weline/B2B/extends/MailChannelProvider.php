<?php

declare(strict_types=1);

namespace Weline\B2B\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $variables = [
            ['code' => 'order_number', 'label' => __('订单号'), 'sample' => 'ORD202601010001'],
            ['code' => 'order_uuid', 'label' => __('订单 UUID'), 'sample' => 'ord-uuid-example'],
            ['code' => 'customer_name', 'label' => __('客户姓名'), 'sample' => '张三'],
            ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'buyer@example.com'],
            ['code' => 'hang_status', 'label' => __('挂单阶段'), 'sample' => 'awaiting_merchant_approval'],
            ['code' => 'hang_status_label', 'label' => __('挂单阶段文案'), 'sample' => '待商家确认'],
            ['code' => 'deposit_amount', 'label' => __('定金金额'), 'sample' => 'CNY 300.00'],
            ['code' => 'balance_amount', 'label' => __('尾款金额'), 'sample' => 'CNY 700.00'],
            ['code' => 'grand_total', 'label' => __('订单金额'), 'sample' => 'CNY 1000.00'],
            ['code' => 'currency', 'label' => __('货币'), 'sample' => 'CNY'],
            ['code' => 'message', 'label' => __('附加说明'), 'sample' => ''],
        ];

        $channels = [
            [
                'code' => 'Weline_B2B::hang_deposit_received',
                'name' => __('批发定金已收'),
                'description' => __('定金支付成功：提示客户等待客服联系'),
                'dir' => 'hang_deposit_received',
            ],
            [
                'code' => 'Weline_B2B::hang_balance_paid',
                'name' => __('批发尾款已付清'),
                'description' => __('尾款支付成功：提示客户进入待发货'),
                'dir' => 'hang_balance_paid',
            ],
        ];

        $out = [];
        foreach ($channels as $ch) {
            $dir = $ch['dir'];
            unset($ch['dir']);
            $out[] = array_merge($ch, [
                'module' => 'Weline_B2B',
                'variables' => $variables,
                'default_templates' => MailTemplateDefaultLocales::fileEntries($dir),
            ]);
        }

        return $out;
    }
}
