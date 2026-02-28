# Weline_Framework 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_Framework 负责提供 `w_msg()` PHP 全局函数，方便开发者快速发送系统通知。

## 变更内容

### 修改文件：Common/functions.php

添加 `w_msg()` 全局函数。

### 函数签名

```php
function w_msg(
    string $topic,           // 消息主题
    string $type,            // 类型：info/success/warning/error/urgent
    string $title,           // 标题
    string $content,         // 内容
    array $options = []      // priority, metadata, icon, notify_users
): void;
```

### 实现逻辑

1. 获取 EventsManager 实例
2. 组装事件数据
3. 触发 `Weline_Backend::application::system_notification` 事件

### 使用示例

```php
w_msg('domain_expiring', 'warning', 
    __('域名即将到期'),
    __('域名 %{domain} 将于 %{days} 天后到期', ['domain' => 'example.com', 'days' => 7]),
    ['metadata' => ['domain' => 'example.com']]
);
```

## 进度跟踪

详见 [task.md](./task.md)
