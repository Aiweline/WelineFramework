# Weline_Server 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_Server 负责：
- 注册服务器相关消息主题
- 迁移现有通知调用到 w_msg()

## 变更内容

### 新增文件：extends.php

注册主题提供者。

### 新增文件：Extends/NotificationTopicProvider.php

提供服务器相关消息主题。

```php
class NotificationTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'server_health',
                'name' => __('服务器健康'),
                'group' => 'server_management',
                'group_name' => __('服务器管理'),
                'icon' => 'ri-heart-pulse-line',
                'color' => '#34c38f',
            ],
            [
                'code' => 'process_failure',
                'name' => __('进程异常'),
                'group' => 'server_management',
                'group_name' => __('服务器管理'),
                'icon' => 'ri-error-warning-line',
                'color' => '#f46a6a',
            ],
            [
                'code' => 'master_resurrection',
                'name' => __('主进程复活'),
                'group' => 'server_management',
                'group_name' => __('服务器管理'),
                'icon' => 'ri-restart-line',
                'color' => '#50a5f1',
            ],
        ];
    }
}
```

### 修改文件

迁移以下文件中的通知调用：
- Observer/MasterResurrectionFailedObserver.php - 主进程复活失败通知

## 进度跟踪

详见 [task.md](./task.md)
