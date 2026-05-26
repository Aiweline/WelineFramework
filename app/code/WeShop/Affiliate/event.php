<?php

return [
    'WeShop_Affiliate::share_created' => [
        'name' => __('分销分享链接已创建'),
        'description' => __('分销模块生成新的商品分享码后触发，负载包含 share、affiliate_id、customer_id、product_id、share_code。'),
        'doc' => 'share_created.md',
    ],
    'WeShop_Affiliate::share_outbound' => [
        'name' => __('分销分享已发出'),
        'description' => __('用户复制链接、二维码或社交平台分享后触发，负载包含 share、touch、share_code、platform。'),
        'doc' => 'share_outbound.md',
    ],
    'WeShop_Affiliate::share_clicked' => [
        'name' => __('分销分享被点击'),
        'description' => __('分享跳转链接被点击后触发，负载包含 share、touch、attribution、share_code、product_id、target_url。'),
        'doc' => 'share_clicked.md',
    ],
    'WeShop_Affiliate::attribution_started' => [
        'name' => __('分销归因已开始'),
        'description' => __('有效点击写入 30 天末触归因后触发，负载包含 share、attribution、customer_id、visitor_key、expires_at。'),
        'doc' => 'attribution_started.md',
    ],
    'WeShop_Affiliate::engagement_recorded' => [
        'name' => __('分销互动已记录'),
        'description' => __('有效归因下发生浏览、收藏、加购、评价等互动后触发，负载包含 event_type、touch、attribution。'),
        'doc' => 'engagement_recorded.md',
    ],
    'WeShop_Affiliate::conversion_pending' => [
        'name' => __('分销转化待确认'),
        'description' => __('订单创建后生成待结算佣金流水时触发，负载包含 order_id、customer_id、attribution、commissions。'),
        'doc' => 'conversion_pending.md',
    ],
    'WeShop_Affiliate::commission_created' => [
        'name' => __('分销佣金流水已创建'),
        'description' => __('订单项级佣金流水创建后触发，负载包含 commission、order_id、affiliate_id、status。'),
        'doc' => 'commission_created.md',
    ],
    'WeShop_Affiliate::commission_status_changed' => [
        'name' => __('分销佣金状态已变更'),
        'description' => __('支付、取消、退款导致佣金状态变化后触发，负载包含 commission、order_id、old_status、new_status、reason。'),
        'doc' => 'commission_status_changed.md',
    ],
    'WeShop_Affiliate::reward_requested' => [
        'name' => __('分销奖励请求'),
        'description' => __('预留给优惠券、积分、通知等模块监听的奖励扩展入口。Affiliate 第一版不内置默认奖励实现。'),
        'doc' => 'reward_requested.md',
    ],
];
