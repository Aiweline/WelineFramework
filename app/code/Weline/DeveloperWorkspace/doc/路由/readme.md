# Weline框架路由系统

## 📖 概述
Weline框架采用模块化路由系统，支持前端、后端、API等多种路由类型。路由规则清晰，便于开发和维护。

## 🏗️ URL结构
```
[网站前缀]/[区域前缀]/[货币前缀]/[语言前缀]/[路由]
```

## 🎯 路由结构
```
[模块路由前缀]/[控制器路径]/[方法名]/[子路径...]
```

## ⚙️ 模块路由配置
每个模块在 `etc/env.php` 文件中配置路由前缀：
```php
<?php
return [
    'router' => 'datatable'  // 模块路由前缀
];
```

## 📋 路由规则详解

### 1. 基础规则
- 路由由 **模块路由前缀** + **控制器路径** + **方法名** 组成
- 支持多级嵌套路径
- 控制器位置决定路由类型（前端/后端/API）

### 2. 控制器路径映射
| 控制器位置 | 路由类型 | 示例路径 |
|------------|----------|----------|
| `Controller\` | 前端路由 | `Controller\Test\Index.php` → `test/index` |
| `Controller\Backend\` | 后端路由 | `Controller\Backend\Store.php` → `backend/store` |
| `Api\Rest\V1\` | API路由 | `Api\Rest\V1\User.php` → `rest/v1/user` |

### 3. 方法名映射
- 默认方法：`index()`
- 自定义方法：`form()` → `/form`
- 嵌套调用：`form/index` → 调用 `form()` 方法的子路径

## 🔐 后端路由 (Backend Routes)

### 配置文件
`app/etc/env.php` 中配置后端区域前缀：
```php
<?php
return [
    'admin' => 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8',
];
```

### 控制器位置
```
app/code/[Vendor]/[Module]/Controller/Backend/[ControllerName].php
```

### 路由规则
- **区域前缀**：`U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`
- **URL格式**：`http://域名/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/[模块路由前缀]/[控制器路径]/[方法名]`

### 示例
**控制器**：`app/code/Weline/Demo/Controller/Backend/Index.php`
```php
<?php
namespace Weline\Demo\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Index extends BackendController
{
    public function index()
    {
        return $this->fetch();
    }

    public function edit()
    {
        return $this->fetch();
    }
}
```

**模块配置**：`app/code/Weline/Demo/etc/env.php`
```php
<?php
return [
    'router' => 'weline-demo'
];
```

**生成的路由**：
- `http://127.0.0.1:9981/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline-demo/backend/index/index`
- `http://127.0.0.1:9981/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline-demo/backend/index/edit`

**路由解析**：
- 网站前缀：`http://127.0.0.1:9981`
- 后端区域前缀：`U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`
- 模块路由前缀：`weline-demo`
- 控制器路径：`backend/index` (对应 `Controller\Backend\Index`)
- 方法名：`index` 或 `edit`


## 🌐 前端路由 (Frontend Routes)

### 配置文件
`app/etc/env.php` 中配置前端区域前缀：
```php
<?php
return [
    'frontend' => '',  // 前端无区域前缀
];
```

### 控制器位置
```
app/code/[Vendor]/[Module]/Controller/[ControllerName].php
```
**注意**：前端控制器直接放在 `Controller` 目录下，不需要 `Frontend` 子目录。

### 路由规则
- **区域前缀**：无（空字符串）
- **URL格式**：`http://域名/[模块路由前缀]/[控制器路径]/[方法名]`

### 示例
**控制器**：`app/code/Weline/DataTable/Controller/Test/Index.php`
```php
<?php
namespace Weline\DataTable\Controller\Test;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function index()
    {
        return $this->fetch();
    }

    public function form()
    {
        return $this->fetch();
    }
}
```

**模块配置**：`app/code/Weline/DataTable/etc/env.php`
```php
<?php
return [
    'router' => 'datatable'
];
```

**生成的路由**：
- `http://127.0.0.1:9981/datatable/test/index/index`
- `http://127.0.0.1:9981/datatable/test/index/form/index`

**路由解析**：
- 网站前缀：`http://127.0.0.1:9981`
- 前端区域前缀：无
- 模块路由前缀：`datatable`
- 控制器路径：`test/index` (对应 `Controller\Test\Index`)
- 方法名：`index` 或 `form`
- 嵌套路径：`form/index` (调用 `form()` 方法的子路径)

## 🔌 API路由 (API Routes)

### 配置文件
`app/etc/env.php` 中配置API区域前缀：
```php
<?php
return [
    'api' => 'api',
];
```

### 控制器位置
```
app/code/[Vendor]/[Module]/Api/Rest/V1/[ControllerName].php
```

### 路由规则
- **区域前缀**：`api`
- **URL格式**：`http://域名/api/[模块路由前缀]/rest/v1/[控制器路径]/[方法名]`

### 示例
**控制器**：`app/code/Weline/DataTable/Api/Rest/V1/User.php`
```php
<?php
namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Api\RestController;

class User extends RestController
{
    public function index()
    {
        return $this->jsonResponse(['users' => []]);
    }

    public function create()
    {
        return $this->jsonResponse(['message' => 'User created']);
    }
}
```

**模块配置**：`app/code/Weline/DataTable/etc/env.php`
```php
<?php
return [
    'router' => 'datatable'
];
```

**生成的路由**：
- `http://127.0.0.1:9981/api/datatable/rest/v1/user/index`
- `http://127.0.0.1:9981/api/datatable/rest/v1/user/create`

**路由解析**：
- 网站前缀：`http://127.0.0.1:9981`
- API区域前缀：`api`
- 模块路由前缀：`datatable`
- API版本路径：`rest/v1`
- 控制器路径：`user` (对应 `Api\Rest\V1\User`)
- 方法名：`index` 或 `create`

## 🔐 后端API路由 (Backend API Routes)

### 配置文件
`app/etc/env.php` 中配置后端API区域前缀：
```php
<?php
return [
    'api_admin' => 'J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE',
];
```

### 控制器位置
```
app/code/[Vendor]/[Module]/Api/Rest/V1/[ControllerName].php
```

### 路由规则
- **区域前缀**：`J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE`
- **URL格式**：`http://域名/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/[模块路由前缀]/rest/v1/[控制器路径]/[方法名]`

### 示例
**控制器**：`app/code/Weline/DataTable/Api/Rest/V1/Admin.php`
```php
<?php
namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Api\BackendRestController;

class Admin extends BackendRestController
{
    public function index()
    {
        return $this->jsonResponse(['admin_data' => []]);
    }

    public function delete()
    {
        return $this->jsonResponse(['message' => 'Deleted successfully']);
    }
}
```

**模块配置**：`app/code/Weline/DataTable/etc/env.php`
```php
<?php
return [
    'router' => 'datatable'
];
```

**生成的路由**：
- `http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/datatable/rest/v1/admin/index`
- `http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/datatable/rest/v1/admin/delete`

**路由解析**：
- 网站前缀：`http://127.0.0.1:9981`
- 后端API区域前缀：`J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE`
- 模块路由前缀：`datatable`
- API版本路径：`rest/v1`
- 控制器路径：`admin` (对应 `Api\Rest\V1\Admin`)
- 方法名：`index` 或 `delete`

## 📚 路由类型总结

| 路由类型 | 区域前缀 | 控制器位置 | URL示例 |
|----------|----------|------------|---------|
| **前端路由** | 无 | `Controller\` | `http://127.0.0.1:9981/datatable/test/index` |
| **后端路由** | `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8` | `Controller\Backend\` | `http://127.0.0.1:9981/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/datatable/backend/store` |
| **API路由** | `api` | `Api\Rest\V1\` | `http://127.0.0.1:9981/api/datatable/rest/v1/user` |
| **后端API** | `J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE` | `Api\Rest\V1\` | `http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/datatable/rest/v1/admin` |

## 🎯 路由最佳实践

### 1. 模块路由前缀命名
- 使用小写字母和连字符：`datatable`、`shop-store`
- 避免使用下划线或特殊字符
- 保持简洁且具有描述性

### 2. 控制器命名
- 使用 PascalCase：`Index`、`UserManager`
- 控制器名应该反映其功能
- 避免过于复杂的嵌套结构

### 3. 方法命名
- 使用 camelCase：`index()`、`createUser()`
- 方法名应该清晰表达其功能
- RESTful API 建议使用标准方法名：`index`、`create`、`update`、`delete`

### 4. 嵌套路径
- 支持多级嵌套：`datatable/test/index/form/index`
- 适用于复杂的功能模块
- 保持路径层级的逻辑性

## 🔧 常见问题

### Q: 为什么我的路由访问不了？
A: 检查以下几点：
1. 模块的 `etc/env.php` 是否正确配置了 `router`
2. 控制器是否继承了正确的基类
3. 是否运行了 `php bin/w s:up` 更新路由
4. 控制器文件路径是否正确

### Q: 前端路由和后端路由有什么区别？
A:
- **前端路由**：无区域前缀，控制器在 `Controller\` 下
- **后端路由**：有区域前缀 `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`，控制器在 `Controller\Backend\` 下

### Q: 如何实现嵌套路径？
A: 在控制器中定义对应的方法，路径会自动映射：
```php
// URL: datatable/test/index/form/index
// 调用: Controller\Test\Index::form() 方法
public function form()
{
    // 处理 form/index 子路径
    return $this->fetch();
}
```

## 📖 参考资料

- [Weline框架官方文档](https://aiweline.com)
- [控制器开发指南](../控制器/readme.md)
- [模块开发指南](../模块/readme.md)
