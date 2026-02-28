# Weline_Websites 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_Websites 负责：
- 注册域名相关消息主题
- 迁移现有通知调用到 w_msg()

## 变更内容

### 新增文件：extends.php

注册主题提供者。

```php
return [
    \Weline\Backend\Api\NotificationTopicProviderInterface::class => [
        \Weline\Websites\Extends\NotificationTopicProvider::class,
    ],
];
```

### 新增文件：Extends/NotificationTopicProvider.php

提供域名相关消息主题。

```php
class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'domain_expiring',
                'name' => __('域名到期提醒'),
                'group' => 'domain_management',
                'group_name' => __('域名管理'),
                'icon' => 'ri-time-line',
                'color' => '#f1b44c',
            ],
            [
                'code' => 'domain_sync',
                'name' => __('域名同步通知'),
                'group' => 'domain_management',
                'group_name' => __('域名管理'),
                'icon' => 'ri-refresh-line',
                'color' => '#50a5f1',
            ],
            [
                'code' => 'domain_transfer',
                'name' => __('域名转移通知'),
                'group' => 'domain_management',
                'group_name' => __('域名管理'),
                'icon' => 'ri-arrow-left-right-line',
                'color' => '#34c38f',
            ],
        ];
    }
}
```

### 修改文件

迁移以下文件中的通知调用：
- Service/DomainSyncService.php - 域名同步通知
- Cron/DomainSync.php - 域名到期提醒

## 进度跟踪

详见 [notification-task.md](./notification-task.md)
