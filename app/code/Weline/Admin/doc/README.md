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

### 5. 日志管理
- 操作日志记录
- 系统日志查看
- 错误日志分析

## 使用方法

### 访问后台
```
http://your-domain/admin
```

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

## 常见问题

### Q: 忘记管理员密码怎么办？
A: 可以通过数据库直接重置密码，或使用命令行工具重置。

### Q: 如何添加新的管理菜单？
A: 在模块的配置文件中添加菜单配置，并确保有相应的权限设置。

### Q: 后台访问速度慢怎么办？
A: 检查缓存配置，清理系统缓存，优化数据库查询。 