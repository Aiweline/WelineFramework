# Weline_Backend 模块扩展文档

## 概述

Weline_Backend 模块提供后台通知与渠道扩展点，允许其他模块注册**通知渠道适配器**（如飞书、钉钉、邮件、Webhook）和**通知主题提供者**，用于系统消息推送与主题分类。

## 快速开始

### 1. 实现通知渠道适配器

在您的模块中实现 `Weline\Backend\Api\Notification\ChannelAdapterInterface`，并在 `extends.php` 中注册：

```php
// extends.php
use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use YourModule\Adapter\Notification\MyChannelAdapter;

return [
    ChannelAdapterInterface::class => [
        MyChannelAdapter::class,
    ],
];
```

### 2. 实现通知主题提供者

实现 `Weline\Backend\Api\NotificationTopicProviderInterface`，并在 `extends.php` 中注册：

```php
use Weline\Backend\Api\NotificationTopicProviderInterface;
use YourModule\Extends\MyTopicProvider;

return [
    NotificationTopicProviderInterface::class => [
        MyTopicProvider::class,
    ],
];
```

## 详细说明

### ChannelAdapterInterface（通知渠道适配器）

**接口**: `Weline\Backend\Api\Notification\ChannelAdapterInterface`

**用途**: 扩展后台通知发送渠道（如飞书、钉钉、邮件、Webhook 等）。

**必须实现方法**:
- `getChannelCode(): string` 渠道标识
- `getChannelName(): string` 渠道显示名称
- `send(array $notification, array $config): bool` 发送通知
- `formatMessage(array $notification): array` 格式化消息
- `test(array $config): bool` 测试连通性
- `getConfigFields(): array` 后台配置项定义

**内置实现**: 飞书(FeishuAdapter)、钉钉(DingtalkAdapter)、邮件(EmailAdapter)、Webhook(WebhookAdapter)，见 `app/code/Weline/Backend/Adapter/Notification/`。

### NotificationTopicProviderInterface（通知主题提供者）

**接口**: `Weline\Backend\Api\NotificationTopicProviderInterface`

**用途**: 提供可选的系统通知主题列表（主题码、名称、分组、图标、默认渠道等），供后台配置与路由使用。

**必须实现方法**:
- `getTopics(): array` 返回主题数组，每项含 `code`、`name`、`group`、`group_name`、`description`、`icon`、`color`、`default_channels` 等键。

**内置实现**: `Weline\Backend\Extends\NotificationTopicProvider`，提供系统信息/警告/告警等主题。

## 示例

### 自定义渠道适配器示例

```php
namespace YourModule\Adapter\Notification;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;

class MyChannelAdapter implements ChannelAdapterInterface
{
    public function getChannelCode(): string
    {
        return 'my_channel';
    }

    public function getChannelName(): string
    {
        return __('我的渠道');
    }

    public function send(array $notification, array $config): bool
    {
        // 使用 $config 中的配置发送到第三方
        return true;
    }

    public function formatMessage(array $notification): array
    {
        return [
            'title' => $notification['title'] ?? '',
            'content' => $notification['content'] ?? '',
        ];
    }

    public function test(array $config): bool
    {
        return true;
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'api_key', 'label' => __('API Key'), 'type' => 'text', 'required' => true],
        ];
    }
}
```

### 主题提供者示例

```php
namespace YourModule\Extends;

use Weline\Backend\Api\NotificationTopicProviderInterface;

class MyTopicProvider implements NotificationTopicProviderInterface
{
    public function getTopics(): array
    {
        return [
            [
                'code' => 'my_topic',
                'name' => __('我的主题'),
                'group' => 'custom',
                'group_name' => __('自定义'),
                'description' => __('自定义通知主题'),
                'icon' => 'ri-notification-line',
                'color' => '#50a5f1',
                'default_channels' => ['backend'],
            ],
        ];
    }
}
```
