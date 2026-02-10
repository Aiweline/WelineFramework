<?php

declare(strict_types=1);

/**
 * Weline_Saas 模块事件定义
 *
 * 本模块发出的事件：
 * - Weline_Saas::provisioning::bind_dns：DNS 绑定步骤，观察者可执行实际 DNS 配置（如 Terraform/Cloudflare）
 */

return [
    'Weline_Saas::provisioning::bind_dns' => [
        'name' => __('SaaS 配置 - 绑定 DNS'),
        'description' => __('一站式配置流程执行 DNS 绑定步骤时触发，观察者可在此创建 Zone/记录（如通过 Terraform）。'),
        'version' => '1.0.0',
        'type' => 'provisioning',
        'data_contract' => [
            'data' => [
                'provisioning_order_id' => ['type' => 'integer', 'description' => '配置订单 ID'],
                'domain' => ['type' => 'string', 'description' => '域名'],
                'dns_vendor' => ['type' => 'string', 'description' => 'DNS 供应商代码'],
                'dns_account_id' => ['type' => 'integer', 'description' => 'DNS 账户 ID'],
                'website_id' => ['type' => 'integer', 'description' => '网站 ID'],
                'handled' => ['type' => 'bool', 'description' => '由观察者设为 true 表示已处理'],
            ],
        ],
    ],
];
