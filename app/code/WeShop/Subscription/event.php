<?php

declare(strict_types=1);

/**
 * WeShop_Subscription 事件定义
 *
 * 事件命名规范：WeShop_Subscription::{type}::{event_name}
 *
 * 可用事件：
 *
 * 1. WeShop_Subscription::subscription_created
 *    - 触发时机：订阅创建后
 *    - 数据：['subscription' => Subscription, 'plan' => SubscriptionPlan]
 *
 * 2. WeShop_Subscription::subscription_renewed
 *    - 触发时机：订阅续费后
 *    - 数据：['subscription' => Subscription]
 *
 * 3. WeShop_Subscription::subscription_cancelled
 *    - 触发时机：订阅取消后
 *    - 数据：['subscription' => Subscription, 'reason' => string]
 *
 * 4. WeShop_Subscription::subscription_paused
 *    - 触发时机：订阅暂停后
 *    - 数据：['subscription' => Subscription]
 *
 * 5. WeShop_Subscription::subscription_resumed
 *    - 触发时机：订阅恢复后
 *    - 数据：['subscription' => Subscription]
 *
 * 6. WeShop_Subscription::subscription_expired
 *    - 触发时机：订阅过期后
 *    - 数据：['subscription' => Subscription]
 */
