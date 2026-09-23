<?php

return [
    'Weline_Payment::webhook_inbox_received' => [
        'name' => \__('Payment Webhook Inbox 已接收'),
        'description' => \__('PaymentCallbackReceiver 成功写入 immutable inbox 后触发，供 Dev Relay 等扩展消费。'),
    ],
    'Weline_Payment::checkout::available_methods::enrich' => [
        'name' => \__('结账可用支付方式列表旁路注入'),
        'description' => \__('PaymentQueryProvider 组装可用方式后派发；Observer 写入 incentive_savings_minor 等扁字段。'),
        'doc' => 'checkout-available-methods-enrich.md',
    ],
];
