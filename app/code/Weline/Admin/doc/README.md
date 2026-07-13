# Weline Admin 后台管理模块

## 模块概述

Weline Admin 是系统的后台管理模块，提供了完整的后台管理界面和功能，包括用户管理、权限控制、系统配置等。

## 主要功能

### 1. 后台管理界面
- 响应式管理面板
- 多主题支持
- 用户友好的操作界面

### 2. 用户管理
- 管理员账户管理
- 用户角色分配
- 权限控制

### 3. 系统配置
- 系统参数配置
- 模块管理
- 缓存管理

### 4. 权限控制
- 基于角色的访问控制
- 菜单权限管理
- 操作权限验证

### 5. 菜单管理接口权限

后台菜单管理页面的菜单入口使用 `Weline_Admin::system_menu_manager` 作为菜单资源。菜单详情、保存、排序和删除等接口必须额外声明方法级 ACL，并以该菜单资源作为 `parent_source`：详情接口使用 `read` access mode，保存、排序和删除接口使用 `edit` access mode。菜单可见性只控制侧栏入口，不能替代控制器方法级权限。

后台左侧栏菜单搜索会展示匹配分组及其叶子菜单。没有有效路由的分组项仅用于表达层级，搜索结果中必须使用不可点击语义；只有配置了有效路由的菜单项可以作为链接跳转。

Admin 跨模块读取主题配置键时使用
`Weline\Backend\Api\View\BackendThemeConfigInterface::SESSION_CONFIG_KEY`，不得引用
Backend Block。菜单、后台用户上下文、角色与权限读取优先使用 Backend/Acl 发布的
Api DTO、catalog、reader 和 authorization 契约，不得把目标模块 Model 或 Service
带入 Admin 控制器。

管理员列表、编辑、状态和角色关联统一使用
`Weline\Backend\Api\User\BackendUserAdministrationInterface`；当前用户临时数据使用
`BackendCurrentUserDataInterface`，角色目录使用 `Weline\Acl\Api\Role\RoleCatalogInterface`。
后台密码登录、尝试次数、Session 身份和 remember token 只调用
`Weline\Backend\Api\Auth\BackendInteractiveAuthInterface`；Admin 获得的用户对象是不含密码哈希、
token 密文和 ORM 状态的 `BackendLoginAccount`。`Weline_Admin_Login::password_verified`
事件中的 `user` 同样是该公开快照；集成模块不得依赖 `BackendUser` Model 或在事件内直接持久化它。
登录回跳的路由权限判定使用 `AuthorizationServiceInterface`，默认菜单入口使用
`MenuReaderInterface`，不得回退到 Acl/Backend 内部 Service。

### 6. 日志管理
- 操作日志记录
- 系统日志查看
- 错误日志分析

## 使用方法

### 访问后台
```
http://your-domain/{backend_key}/admin/login
```

`backend_key` 是实例配置的后台区域 Key。裸 `/admin/login` 与 `/admin/login/post`
必须返回 404，不能进入登录页或继续按前台路由派发。

### 登录后台
1. 访问后台登录页面
2. 输入管理员账户和密码
3. 验证成功后进入管理面板

### 创建管理员账户
```php
use Weline\Admin\Model\Admin;

$admin = new Admin();
$admin->setUsername('admin');
$admin->setPassword('password');
$admin->setEmail('admin@example.com');
$admin->save();
```

### 权限配置
```php
use Weline\Acl\Model\Role;
use Weline\Acl\Model\Permission;

// 创建角色
$role = new Role();
$role->setName('管理员');
$role->save();

// 分配权限
$permission = new Permission();
$permission->setRoleId($role->getId());
$permission->setResource('admin::system::config');
$permission->setAction('read');
$permission->save();
```

## 配置说明

### 后台配置
在 `app/etc/admin.php` 中配置后台相关设置：

```php
'admin' => [
    'title' => 'Weline 后台管理',
    'logo' => 'static/admin/images/logo.png',
    'theme' => 'default',
    'session_timeout' => 3600
]
```

### 菜单配置
```php
'menu' => [
    'system' => [
        'label' => '系统管理',
        'icon' => 'fa-cog',
        'children' => [
            'config' => [
                'label' => '系统配置',
                'url' => 'admin/system/config'
            ]
        ]
    ]
]
```

## 依赖关系

- Weline_SystemConfig
- Weline_Backend
- Weline_Acl

## 公共后台控制器契约

其他模块的后台控制器统一继承
`Weline\Admin\Api\Controller\BaseController`。该公共基类保持 Admin 的后台请求初始化、
布局包装、`fetchBase()`、登录会话与运行时页面缓存行为，并继续由 Framework 的
`BackendController` 承担后台 Key、ACL 和登录重定向权威校验。使用方必须在
`etc/module.php` 的 `requires` 与 Composer `require` 中声明 `Weline_Admin`。

`Weline\Admin\Controller\BaseController` 只作为旧代码兼容入口保留，并单向继承上述
公共实现；新代码不得再跨模块引用这个内部命名空间。

## WLS 视图预热

Admin 通过 `Api/View/ViewWarmupContributionProvider.php` 声明登录页、公共后台模板、
登录 Provider Hook 和高频静态资源。WLS 由 Theme 的通用预热执行器读取 Framework 编译
Provider 索引；Theme 不直接引用 Admin 的资源路径。契约说明见
`app/code/Weline/Theme/doc/worker-view-warmup-contributions.md`。

## 版本信息

- 当前版本：1.0.1
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 安全注意事项

1. 定期更改管理员密码
2. 启用双因素认证
3. 限制后台访问IP
4. 定期检查操作日志
5. 及时更新安全补丁

## 系统消息通知机制

### 概述

Weline Admin 模块提供了系统消息通知机制，允许其他模块通过事件系统发送系统消息到后台管理界面。该机制基于事件观察者模式实现，实现了模块间的解耦通信。

### 工作原理

1. **事件触发**：其他模块通过触发 `Weline_Admin::msg` 事件来发送系统消息
2. **事件监听**：Admin 模块的 `SystemNotificationObserver` 观察者监听该事件
3. **消息保存**：观察者接收到事件后，将消息保存到系统消息表中
4. **界面显示**：后台管理界面自动显示未读的系统消息

### 使用方法

#### 基本用法

在任何模块中，通过事件管理器触发 `Weline_Admin::msg` 事件即可发送系统消息：

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 获取事件管理器
/** @var EventsManager $eventsManager */
$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 发送系统消息
$eventsManager->dispatch('Weline_Admin::msg', [
    'data' => [
        'title' => '系统通知',
        'content' => '这是一条系统消息内容',
        'is_read' => false,  // 是否已读，默认为 false（未读）
        'is_icon' => 1,      // 使用图标头像，1=图标，0=不使用
        'is_img' => 0,       // 使用图片头像，1=图片，0=不使用
        'avatar' => 'ri-notification-line'  // 头像内容（图标名称或图片路径）
    ]
]);
```

#### 消息数据格式

发送消息时，需要提供以下数据格式：

| 字段 | 类型 | 必填 | 说明 | 默认值 |
|------|------|------|------|--------|
| `title` | string | 是 | 消息标题，最大长度120字符 | - |
| `content` | string | 是 | 消息内容，支持多行文本 | - |
| `is_read` | bool | 否 | 是否已读，false=未读，true=已读 | false |
| `is_icon` | int | 否 | 是否使用图标头像，1=使用，0=不使用 | 1 |
| `is_img` | int | 否 | 是否使用图片头像，1=使用，0=不使用 | 0 |
| `avatar` | string | 否 | 头像内容，图标名称（如 'ri-notification-line'）或图片路径 | 'ri-notification-line' |

#### 使用图标头像

```php
$eventsManager->dispatch('Weline_Admin::msg', [
    'data' => [
        'title' => '订单通知',
        'content' => '您有新的订单需要处理',
        'is_icon' => 1,
        'is_img' => 0,
        'avatar' => 'ri-shopping-cart-line'  // RemixIcon 图标名称
    ]
]);
```

#### 使用图片头像

```php
$eventsManager->dispatch('Weline_Admin::msg', [
    'data' => [
        'title' => '用户消息',
        'content' => '用户提交了新的反馈',
        'is_icon' => 0,
        'is_img' => 1,
        'avatar' => 'assets/images/users/avatar-1.jpg'  // 图片路径
    ]
]);
```

#### 完整示例

```php
<?php

namespace Your\Module\Controller;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class YourController
{
    /**
     * 发送系统消息示例
     */
    public function sendNotification(): void
    {
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        // 发送订单通知
        $eventsManager->dispatch('Weline_Admin::msg', [
            'data' => [
                'title' => '新订单提醒',
                'content' => '订单编号：ORD-20241219-001 已创建，请及时处理。',
                'is_read' => false,
                'is_icon' => 1,
                'avatar' => 'ri-shopping-bag-line'
            ]
        ]);
        
        // 发送系统警告
        $eventsManager->dispatch('Weline_Admin::msg', [
            'data' => [
                'title' => '系统警告',
                'content' => '服务器磁盘使用率已达到 85%，请及时清理。',
                'is_read' => false,
                'is_icon' => 1,
                'avatar' => 'ri-alert-line'
            ]
        ]);
    }
}
```

### 注意事项

1. **必需字段**：`title` 和 `content` 是必需字段，如果缺少这些字段，消息将不会被保存
2. **头像类型**：`is_icon` 和 `is_img` 不能同时为 1，如果同时设置，优先使用 `is_icon`
3. **默认行为**：如果不指定头像类型，默认使用图标头像（`is_icon=1`）
4. **错误处理**：消息保存失败不会影响主流程，错误会被静默处理（开发模式下会记录到错误日志）
5. **事件名称**：必须使用 `Weline_Admin::msg` 作为事件名称

### 技术实现

- **观察者类**：`Weline\Admin\Observer\SystemNotificationObserver`
- **事件名称**：`Weline_Admin::msg`
- **数据模型**：`Weline\Admin\Model\System\SystemNotification`
- **配置文件**：`app/code/Weline/Admin/etc/event.xml`

### 扩展开发

如果需要扩展系统消息功能，可以：

1. **自定义观察者**：创建新的观察者类来处理特定类型的消息
2. **消息分类**：在消息数据中添加 `type` 字段来区分不同类型的消息
3. **消息优先级**：添加 `priority` 字段来设置消息优先级
4. **消息过期**：添加 `expire_time` 字段来设置消息过期时间

## 常见问题

### Q: 忘记管理员密码怎么办？
A: 可以通过数据库直接重置密码，或使用命令行工具重置。

### Q: 如何添加新的管理菜单？
A: 在模块的配置文件中添加菜单配置，并确保有相应的权限设置。

### Q: 后台访问速度慢怎么办？
A: 检查缓存配置，清理系统缓存，优化数据库查询。

### Q: 如何发送系统消息？
A: 使用事件管理器触发 `Weline_Admin::msg` 事件，传入包含 `title` 和 `content` 的数据数组即可。详细用法请参考"系统消息通知机制"章节。

### Q: 系统消息发送失败怎么办？
A: 检查消息数据格式是否正确，确保 `title` 和 `content` 字段存在且不为空。开发模式下可以查看错误日志获取详细错误信息。

## 系统通知公共读取契约

跨模块展示未读系统通知时使用
`Weline\Admin\Api\Notification\SystemNotificationDirectoryInterface`。返回值是不可变
`SystemNotificationRecord`，不会暴露 Admin ORM、查询条件或可变 Model 状态。
