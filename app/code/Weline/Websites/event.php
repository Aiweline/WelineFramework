<?php

declare(strict_types=1);

/*
 * 网站模块事件规约
 */

return [
    'Weline_Websites::website_save_after' => [
        'name' => __('网站保存后'),
        'description' => __('网站保存后触发，可用于更新缓存、通知等相关操作。'),
        'doc' => 'website_save_after.md',
    ],
    'Weline_Websites::domain::purchase_success' => [
        'name' => __('域名购买成功'),
        'description' => __('域名购买成功后触发，可用于通知、自动 DNS 解析、证书申请等后续操作。数据包含 domain、order_id、website_id、auto_create_site。'),
        'doc' => 'domain_purchase_success.md',
    ],
];

