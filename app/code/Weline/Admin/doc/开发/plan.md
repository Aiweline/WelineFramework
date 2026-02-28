# Weline_Admin 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_Admin 负责提供旧事件 `Weline_Admin::msg` 的兼容层，将旧事件转发到新的通知系统。

## 变更内容

### 新增文件：Observer/LegacyNotificationObserver.php

监听旧事件 `Weline_Admin::msg`，转发到新系统。

### 实现逻辑

```php
class LegacyNotificationObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        w_msg(
            $data['topic'] ?? 'system_info',
            $data['type'] ?? 'info',
            $data['title'],
            $data['content'],
            $data
        );
    }
}
```

### 修改文件：etc/event.xml

注册 LegacyNotificationObserver 监听 `Weline_Admin::msg` 事件。

## 进度跟踪

详见 [task.md](./task.md)
