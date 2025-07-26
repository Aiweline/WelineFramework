# Weline Framework 核心框架模块

## 模块概述

Weline Framework 是 Weline 系统的核心框架模块，提供了整个系统的基础架构和核心功能。

## 主要功能

### 1. 模块注册系统
- 提供模块注册机制
- 管理模块依赖关系
- 版本控制支持

### 2. 路由系统
- MVC 架构支持
- 路由解析和分发
- 控制器自动加载

### 3. 数据库操作
- ORM 支持
- 数据库连接管理
- 查询构建器

### 4. 缓存系统
- 多级缓存支持
- 缓存策略管理
- 性能优化

### 5. 事件系统
- 事件监听器
- 事件分发机制
- 插件扩展支持

## 使用方法

### 模块注册
```php
use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Your_Module',
    __DIR__,
    '1.0.0',
    '模块描述',
    ['依赖模块1', '依赖模块2']
);
```

### 控制器创建
```php
namespace Your\Module\Controller;

use Weline\Framework\Controller\AbstractController;

class YourController extends AbstractController
{
    public function index()
    {
        // 控制器逻辑
    }
}
```

### 模型创建
```php
namespace Your\Module\Model;

use Weline\Framework\Database\Model;

class YourModel extends Model
{
    protected $table = 'your_table';
    
    // 模型逻辑
}
```

## 配置说明

### 数据库配置
在 `app/etc/config.php` 中配置数据库连接信息：

```php
'database' => [
    'host' => 'localhost',
    'dbname' => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
    'charset' => 'utf8mb4'
]
```

### 缓存配置
```php
'cache' => [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => 'var/cache'
        ]
    ]
]
```

## 依赖关系

- 无依赖模块（核心模块）

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 注意事项

1. 此模块为系统核心，不建议修改
2. 所有其他模块都依赖此模块
3. 升级时需谨慎，可能影响整个系统 