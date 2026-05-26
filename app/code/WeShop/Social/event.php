<?php

return [
    'WeShop_Social::share_before' => [
        'name' => __('社交分享记录前'),
        'description' => __('社交分享持久化前触发，负载包含可修改的 share_data；可携带 affiliate_share_code 串联分销分享链路。'),
        'doc' => 'share_before.md',
    ],
    'WeShop_Social::share_after' => [
        'name' => __('社交分享记录后'),
        'description' => __('社交分享持久化后触发，负载包含 share、share_data；Affiliate 会监听 share_data.affiliate_share_code。'),
        'doc' => 'share_after.md',
    ],
];
