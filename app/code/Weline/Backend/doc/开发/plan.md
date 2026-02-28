# Weline_Backend 消息订阅通知系统 - 模块计划

> 总计划：[消息订阅通知系统](../../../../.cursor/plans/消息订阅通知系统.plan.md)

## 模块职责

Weline_Backend 是消息订阅通知系统的核心模块，负责：
- 数据模型（7 个表，含用户联系人）
- 事件定义和观察者
- 消息路由服务
- 渠道适配器
- 用户联系人管理
- 后台管理界面

---

## 用户联系人体系

### 设计目标

将通知发送与用户的多渠道联系方式解耦：
- **用户联系人 (UserContact)**：用户的联系方式（邮箱、手机、飞书 ID、钉钉 ID 等）
- **渠道联系人 (ChannelContact)**：某渠道下的联系人配置
- 发送通知时，根据渠道类型查找对应的联系人信息

### 联系人模型

#### UserContact 用户联系人表

| 字段 | 类型 | 说明 |
|------|------|------|
| contact_id | INT PK | 主键 |
| user_id | INT | 后台用户 ID |
| channel_code | VARCHAR(50) | 渠道标识（email/feishu/dingtalk/sms/webhook） |
| contact_value | VARCHAR(255) | 联系方式值（邮箱、手机号、用户 ID 等） |
| contact_name | VARCHAR(100) | 联系人名称（显示用） |
| is_verified | SMALLINT | 是否已验证 |
| is_default | SMALLINT | 是否为该渠道的默认联系人 |
| is_enabled | SMALLINT | 是否启用 |
| extra_config | TEXT | 扩展配置 JSON |

联合唯一索引：(user_id, channel_code, contact_value)

### 联系人自动创建机制

1. **用户注册事件**：监听 `Weline_Backend::user::registered` 事件
2. **自动创建默认联系人**：
   - 用户邮箱 → email 渠道默认联系人
   - 用户手机（如有） → sms 渠道默认联系人
3. **联系人可手动管理**：用户可在后台添加/编辑/删除联系人

### 通知发送流程

```
w_msg() 
  → SystemNotificationObserver 
    → NotificationRouter.route()
      → 遍历渠道
        → UserContactService.getContactByChannel(userId, channelCode)
          → ChannelAdapter.send(notification, contact)
```

### 新增接口

```php
interface UserContactServiceInterface
{
    // 获取用户指定渠道的联系人
    public function getContactByChannel(int $userId, string $channelCode): ?array;
    
    // 获取用户所有联系人
    public function getUserContacts(int $userId): array;
    
    // 创建联系人
    public function createContact(int $userId, string $channelCode, string $value, array $options = []): bool;
    
    // 设置默认联系人
    public function setDefaultContact(int $userId, string $channelCode, int $contactId): bool;
}
```

---

## 数据模型

### 1. NotificationTopic 消息主题表

| 字段 | 类型 | 说明 |
|------|------|------|
| topic_id | INT PK | 主键 |
| topic_code | VARCHAR(50) UNIQUE | 主题标识 |
| topic_name | VARCHAR(100) | 显示名称 |
| topic_group | VARCHAR(50) | 分组 |
| topic_group_name | VARCHAR(100) | 分组名称 |
| description | VARCHAR(500) | 描述 |
| module | VARCHAR(100) | 来源模块 |
| icon | VARCHAR(100) | 图标 |
| color | VARCHAR(20) | 主题色 |
| default_channels | TEXT | 默认渠道 JSON |
| is_enabled | SMALLINT | 是否启用 |
| sort_order | INT | 排序 |

### 2. SystemNotification 系统通知表

| 字段 | 类型 | 说明 |
|------|------|------|
| notification_id | INT PK | 主键 |
| topic_code | VARCHAR(50) | 消息主题 |
| type | VARCHAR(20) | 类型：info/success/warning/error/urgent |
| title | VARCHAR(200) | 标题 |
| content | TEXT | 内容 |
| priority | SMALLINT | 优先级 1-10 |
| source_module | VARCHAR(100) | 来源模块 |
| metadata | TEXT | 扩展数据 JSON |
| is_icon | SMALLINT | 是否图标 |
| is_img | SMALLINT | 是否图片 |
| avatar | VARCHAR(255) | 头像 |
| external_notified | SMALLINT | 是否已通知外部 |
| external_channels | TEXT | 已通知渠道 JSON |

### 3. UserNotificationSubscription 用户订阅表

| 字段 | 类型 | 说明 |
|------|------|------|
| subscription_id | INT PK | 主键 |
| user_id | INT | 后台用户 ID |
| topic_code | VARCHAR(50) | 订阅主题 |
| channel | VARCHAR(50) | 渠道 |
| min_type | VARCHAR(20) | 最低级别 |
| is_enabled | SMALLINT | 是否启用 |
| channel_config | TEXT | 渠道配置 JSON |

联合唯一索引：(user_id, topic_code, channel)

### 4. UserNotificationStatus 用户通知状态表

| 字段 | 类型 | 说明 |
|------|------|------|
| status_id | INT PK | 主键 |
| user_id | INT | 后台用户 ID |
| notification_id | INT | 通知 ID |
| is_read | SMALLINT | 是否已读 |
| read_at | DATETIME | 阅读时间 |

联合唯一索引：(user_id, notification_id)

### 5. NotificationChannel 渠道配置表

| 字段 | 类型 | 说明 |
|------|------|------|
| channel_id | INT PK | 主键 |
| channel_code | VARCHAR(50) UNIQUE | 渠道标识 |
| channel_name | VARCHAR(100) | 渠道名称 |
| channel_config | TEXT | 配置 JSON |
| subscribed_topics | TEXT | 订阅主题 JSON |
| min_type | VARCHAR(20) | 最低级别 |
| is_enabled | SMALLINT | 是否启用 |

## 接口和服务

### NotificationType 枚举

```php
enum NotificationType: string
{
    case INFO = 'info';       // 蓝色
    case SUCCESS = 'success'; // 绿色
    case WARNING = 'warning'; // 橙色
    case ERROR = 'error';     // 红色
    case URGENT = 'urgent';   // 深红
}
```

### NotificationTopicProviderInterface

```php
interface NotificationTopicProviderInterface
{
    public function getTopics(): array;
}
```

### ChannelAdapterInterface

```php
interface ChannelAdapterInterface
{
    public function getChannelCode(): string;
    public function send(array $notification, array $config): bool;
    public function formatMessage(array $notification): array;
    public function test(array $config): bool;
}
```

## 新增文件清单

| 路径 | 说明 |
|------|------|
| Model/NotificationTopic.php | 消息主题模型 |
| Model/SystemNotification.php | 系统通知模型 |
| Model/UserNotificationSubscription.php | 用户订阅模型 |
| Model/UserNotificationStatus.php | 用户通知状态模型 |
| Model/NotificationChannel.php | 渠道配置模型 |
| Enum/NotificationType.php | 消息类型枚举 |
| Api/NotificationTopicProviderInterface.php | 主题注册接口 |
| Api/Notification/ChannelAdapterInterface.php | 渠道适配器接口 |
| Service/NotificationService.php | 通知服务 |
| Service/NotificationRouter.php | 消息路由服务 |
| Service/TopicCollector.php | 主题收集服务 |
| Adapter/Notification/FeishuAdapter.php | 飞书适配器 |
| Adapter/Notification/DingtalkAdapter.php | 钉钉适配器 |
| Adapter/Notification/EmailAdapter.php | 邮件适配器 |
| Adapter/Notification/WebhookAdapter.php | Webhook 适配器 |
| Observer/SystemNotificationObserver.php | 通知观察者 |
| Controller/Backend/NotificationSubscription.php | 用户订阅控制器 |
| Controller/Backend/NotificationChannel.php | 渠道配置控制器 |
| Controller/Api/Notification.php | 通知 API |
| Block/System/Notification.php | 通知 Block |
| view/blocks/system/notification.phtml | 通知模板 |
| view/templates/Backend/NotificationSubscription/*.phtml | 订阅页面 |
| view/templates/Backend/NotificationChannel/*.phtml | 渠道配置页面 |
| event.php | 事件定义 |
| etc/event.xml | Observer 注册 |
| etc/backend/menu.xml | 后台菜单 |
| extends.php | 内置主题注册 |

## 进度跟踪

详见 [task.md](./task.md)
