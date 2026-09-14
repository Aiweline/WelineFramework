<?php

declare(strict_types=1);

namespace Weline\Marketing\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $variables = [
            ['code' => 'order_uuid', 'label' => __('订单 UUID'), 'sample' => 'ord-uuid-example'],
            ['code' => 'order_number', 'label' => __('订单号'), 'sample' => 'ORD202601010001'],
            ['code' => 'customer_name', 'label' => __('客户姓名'), 'sample' => '张三'],
            ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'user@example.com'],
            ['code' => 'grand_total', 'label' => __('订单金额'), 'sample' => '99.00'],
            ['code' => 'currency', 'label' => __('货币'), 'sample' => 'CNY'],
            ['code' => 'continue_pay_url', 'label' => __('继续支付链接'), 'sample' => 'https://example.com/checkout/success?order_uuid=x&checkout_token=y'],
            ['code' => 'created_at', 'label' => __('下单时间'), 'sample' => '2026-09-14 10:00:00'],
            ['code' => 'payment_status', 'label' => __('支付状态'), 'sample' => 'pending'],
        ];

        return [[
            'code' => 'Weline_Marketing::unpaid_order_reminder',
            'name' => __('未付订单催付'),
            'description' => __('营销挽回：未支付订单定时催付邮件'),
            'module' => 'Weline_Marketing',
            'variables' => $variables,
            'default_templates' => MailTemplateDefaultLocales::fileEntries('unpaid_order_reminder'),
        ]];
    }
}
