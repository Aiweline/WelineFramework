# Weline Framework API 接口开发规范

## 一、概述

Weline Framework 提供了统一的API接口开发规范，确保所有API接口遵循相同的注释标准，便于统一管理和自动生成文档。

**重要提示**：
- **只有继承 `Weline\Framework\Controller\AbstractRestController` 的控制器类的方法才需要遵循本规范**
- Observer 类、Helper 类、Service 类等非 API 控制器不需要遵循 API 规范
- 不符合规范的接口在路由注册时会被拦截，无法正常访问

**规范说明**：
- API文档信息使用PHPDoc的 `@Document` 标签
- **不需要** `ApiDoc` Attribute（Acl注解已经可以用于权限控制和API识别）
- 后台API接口必须使用 `Acl` Attribute标记权限（Acl注解可以直接运行代码）
- 公开接口和前端接口不需要Acl注释

### 1.1 API URL 结构规范

**重要变更**：API URL 结构已更新，支持国际化（i18n）和多货币支持。

**URL 结构格式**：
```
[网站前缀]/{区域前缀}/{货币前缀}/{语言前缀}/[模组前缀]/[路由]
```

**结构说明**：
- `[]` 表示**必然存在**的部分
- `{}` 表示**可存在可不存在**的部分（可选）

**各部分说明**：

| 部分 | 说明 | 是否必填 | 示例 |
|------|------|---------|------|
| `[网站前缀]` | 网站基础URL | ✅ 必填 | `http://127.0.0.1:9981` |
| `{区域前缀}` | API区域标识（前端API或后端API） | ⚠️ 可选 | `api`、`api123`、`api_admin` 等 |
| `{货币前缀}` | 货币代码（3位大写字母） | ⚠️ 可选 | `CNY`、`USD`、`EUR` 等 |
| `{语言前缀}` | 语言代码 | ⚠️ 可选 | `zh_Hans_CN`、`en_US` 等 |
| `[模组前缀]` | 模块路由标识 | ✅ 必填 | `weline_api`、`weline_frontend` 等 |
| `[路由]` | API路由路径 | ✅ 必填 | `rest/v1/auth/login` 等 |

**完整URL示例**：

1. **前端API（带i18n）**：
   ```
   http://127.0.0.1:9981/api123/USD/en_US/weline_api/rest/v1/auth/login
   ```
   - 网站前缀：`http://127.0.0.1:9981`
   - 区域前缀：`api123`（从 `env.php` 的 `api` 配置获取）
   - 货币前缀：`USD`
   - 语言前缀：`en_US`
   - 模组前缀：`weline_api`
   - 路由：`rest/v1/auth/login`

2. **前端API（不带i18n）**：
   ```
   http://127.0.0.1:9981/api123/weline_api/rest/v1/auth/login
   ```
   - 网站前缀：`http://127.0.0.1:9981`
   - 区域前缀：`api123`
   - 货币前缀：无（可选）
   - 语言前缀：无（可选）
   - 模组前缀：`weline_api`
   - 路由：`rest/v1/auth/login`

3. **后端API**：
   ```
   http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/weline_api/rest/v1/backend/auth/login
   ```
   - 网站前缀：`http://127.0.0.1:9981`
   - 区域前缀：`J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE`（从 `env.php` 的 `api_admin` 配置获取）
   - 货币前缀：无（后端API通常不需要）
   - 语言前缀：无（后端API通常不需要）
   - 模组前缀：`weline_api`
   - 路由：`rest/v1/backend/auth/login`

**配置说明**：
- 区域前缀配置在 `app/etc/env.php` 中：
  - `api`: 前端API区域前缀（如 `'api' => 'api123'`）
  - `api_admin`: 后端API区域前缀（如 `'api_admin' => 'J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE'`）
- 货币和语言前缀从当前用户会话或网站配置中获取
- 路由注册时只注册 `[模组前缀]/[路由]` 部分，区域前缀和i18n前缀在URL生成时动态添加
- 区域/API 路径段（例如 `api` 或 `API`）永远不作为货币代码解析；货币识别会先排除已配置的区域路由前缀

**在 `@example` 注释中的使用**：
- `Path` 字段应使用完整路径，包含区域前缀、货币、语言（如果适用）
- 示例：
  ```
  Path: /api123/USD/en_US/weline_api/rest/v1/auth/login
  ```
  或
  ```
  Path: /J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/weline_api/rest/v1/backend/auth/login
  ```

## 二、规范要求

### 2.1 必填要求

**重要**：所有API接口必须包含完整的信息，参考 `app\code\Weline\Api\Api\Rest\V1\Test.php` 的注释格式。不符合规范的接口会在路由注册时被拦截，无法正常访问。

API接口必须同时满足以下要求：

1. ✅ **PHPDoc注释**：方法必须有完整的PHPDoc注释
2. ✅ **@Document注释**：必须使用 `@Document` 标签提供API文档信息
3. ✅ **参数注释**：所有方法参数必须有 `@param` 注释，**必须明确说明参数是否必填、获取方式等**
4. ✅ **返回值注释**：必须有 `@return` 注释，**必须包含返回数据格式说明**
5. ✅ **示例注释**：**必须提供 `@example` 注释**，包含完整的请求和响应示例（参考 Test.php 格式）
6. ⚠️ **Acl权限注释**：后台API接口必须使用 `#[\Weline\Framework\Acl\Acl]` Attribute标记权限（公开接口和前端接口不需要）

**完整注释格式要求**（参考 `app\code\Weline\Api\Api\Rest\V1\Test.php`）：

```php
/**
 * 方法描述
 * 
 * 详细描述（可选）
 * 
 * @param 类型 $参数名 参数描述（必填/可选，获取方式等）
 * @return 类型 返回数据格式：{"code": 0, "msg": "success", "data": {}}
 * @throws \Exception 异常情况说明
 * @Document(summary='接口摘要', description='接口详细描述', tags=['标签'], category='分类')
 * @example
 * Method: GET|POST|PUT|DELETE|PATCH
 * Path: /api/rest/v1/resource/path
 * Header:
 * - Authorization: Bearer token_here
 * - Content-Type: application/json
 * Body:
 * {
 *   "key": "value"
 * }
 * Response:
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": {}
 * }
 * @example-end
 */
```

### 2.2 验证机制

**重要**：`app\code\Weline\Framework\Api` 内部功能会在系统更新时（`setup:upgrade`）检测所有API是否符合规范。

- **自动验证**：在路由注册时（`setup:upgrade`）自动验证所有API接口
- **严格检测**：不符合规范的接口会直接报错，**停止编译**，阻止系统更新
- **错误提示**：
  - **开发环境**：抛出异常，显示详细的错误信息，包括不符合规范的接口列表和具体原因
  - **生产环境**：同样会抛出异常，停止编译，确保生产环境不会部署不符合规范的API
- **拦截机制**：不符合规范的接口无法注册到路由系统，`is_enable = false`

**检测时机**：
- 执行 `php bin/w setup:upgrade` 时
- 执行 `php bin/w module:install` 时
- 执行 `php bin/w module:upgrade` 时
- 任何触发路由注册的操作时

**检测范围**：
- **只验证继承 `AbstractRestController` 的控制器方法**
- Observer、Helper、Service 等非 API 控制器类不需要遵循 API 规范
- 验证所有API接口的规范符合性
- 检查所有必填项（@Document注释、PHPDoc注释、@param、@return等）
- 检查后台API接口是否有Acl权限注释（公开接口和前端接口不需要）
- **不需要检查ApiDoc Attribute**（Acl注解已经足够）

**重要说明**：
- 只有继承 `Weline\Framework\Controller\AbstractRestController` 的控制器类的方法才需要遵循 API 规范
- Observer 类（如 `ControllerAttributes`）、Helper 类、Service 类等非 API 控制器不需要遵循 API 规范
- 验证器会自动检查类是否继承 `AbstractRestController`，只有继承的类才会进行验证

## 三、@Document 注释规范

### 3.1 基本语法

API文档信息统一使用PHPDoc的 `@Document` 标签。

**重要**：不需要 `ApiDoc` Attribute，因为：
- `Acl` 注解已经可以用于权限控制和API识别
- `Acl` 注解可以直接运行代码，功能完整
- `@Document` 注释提供文档信息即可

```php
/**
 * 方法描述
 * 
 * @Document(
 *   summary="API接口摘要",
 *   description="API接口详细描述（可选）",
 *   tags={"标签1", "标签2"},
 *   category="分类名称",
 *   deprecated=false,
 *   deprecatedReason="废弃原因（deprecated=true时必填）"
 * )
 */
```

### 3.2 版本号控制

**重要**：API版本号通过目录结构控制，**不在 `@Document` 注释中指定**。

**目录结构规范**：
- API控制器必须放在 `Api\Rest\V{版本号}` 目录下
- 版本号格式：`V1`, `V2`, `V3` 等（大写V + 数字）
- 示例：
  - `app\code\Weline\Example\Api\Rest\V1\User.php` → 版本 v1
  - `app\code\Weline\Example\Api\Rest\V2\User.php` → 版本 v2
  - `app\code\Weline\Framework\Api\Rest\V1\...` → 框架API版本 v1

**版本号提取规则**：
- 从命名空间路径中提取：`Weline\Example\Api\Rest\V1` → `v1`
- 自动转换为小写：`V1` → `v1`, `V2` → `v2`
- 如果目录中没有版本号，默认使用 `v1`

### 3.3 参数说明

| 参数 | 类型 | 必填 | 说明 | 示例 |
|------|------|------|------|------|
| `summary` | string | ✅ | API接口摘要（1-200字符） | `"获取用户信息"` |
| `description` | string | ❌ | API接口详细描述（0-1000字符） | `"根据用户ID获取用户详细信息"` |
| `deprecated` | bool | ❌ | 是否已废弃（默认false） | `true` |
| `deprecatedReason` | string | ⚠️ | 废弃原因（deprecated=true时必填） | `"请使用v2版本"` |
| `tags` | array | ❌ | 标签列表 | `{"用户管理", "用户信息"}` |
| `category` | string | ❌ | 分类名称（1-100字符） | `"用户"` |

**注意**：`version` 参数已移除，版本号由目录结构自动确定。

### 3.4 废弃接口标记

如果接口已废弃，需要设置 `deprecated=true` 并提供废弃原因：

```php
/**
 * 获取用户信息（已废弃）
 * 
 * @Document(
 *   summary="获取用户信息（已废弃）",
 *   description="此接口已废弃，请使用v2版本",
 *   deprecated=true,
 *   deprecatedReason="请使用 /api/v2/user/{id} 接口",
 *   tags={"用户管理"},
 *   category="用户"
 * )
 */
```

**注意**：版本号从目录结构自动提取（如 `Api\Rest\V1`），不需要在注释中指定。

## 四、Acl 权限注释规范

### 4.1 使用规则

**重要**：`Acl` 注解可以直接运行代码，用于权限控制和API识别，不需要额外的 `ApiDoc` Attribute。

1. **后台API接口**：必须使用 `#[\Weline\Framework\Acl\Acl]` Attribute标记权限
   - Acl注解用于权限控制
   - Acl注解可以用于API识别和路由注册
   - Acl注解可以直接运行代码，功能完整
   - **必须同时使用类级别和方法级别的Acl注解**，以构建层级结构
2. **公开接口**：不需要Acl注释（完全公开，无权限控制）
3. **前端接口**：不需要Acl注释（前端接口只需要登录验证，不需要权限控制）

### 4.1.1 Acl层级结构要求

**重要**：为了支持API文档的层级结构，后台API控制器必须同时使用类级别和方法级别的Acl注解。

**层级结构**（用于API文档组织）：
```
顶级：Api分类（固定）
  └─ 模块名（从扫描时的模块信息获取，如 Weline_Api）
      └─ 接口文档类分组（类级别Acl，使用类的@Document注释中的category或类名）
          └─ 接口方法（方法级别Acl，使用方法的@Document注释中的summary）
```

**模块名获取方式**：
- 在扫描API控制器时，使用 `Env::getInstance()->getModuleList()` 获取所有模块信息
- 根据控制器文件路径确定所属模块
- 使用模块信息中的 `name` 字段（格式：`Weline_Api`）
- **不需要从命名空间提取**，因为扫描时已经有模块信息

**类级别Acl**：
- 必须放在控制器类上
- 用于标识API文档的分组
- 作为方法级别Acl的父级
- source_id格式：`{Module}::api::{ClassName}`

**方法级别Acl**：
- 必须放在每个API方法上
- 用于标识具体的API接口
- parent_source指向类级别Acl的source_id
- source_id格式：`{Module}::api::{ClassName}::{MethodName}`

### 4.2 Acl Attribute 基本语法

```php
#[\Weline\Framework\Acl\Acl(
    source_id: 'Module::resource::action',
    source_name: '权限名称',
    icon: 'fa fa-icon',
    document: '权限描述',
    parent_source: '父级权限ID（可选）',
    rewrite: '重写路由（可选）'
)]
```

### 4.3 参数说明

| 参数 | 类型 | 必填 | 说明 | 示例 |
|------|------|------|------|------|
| `source_id` | string | ✅ | 权限资源ID（唯一标识） | `'Weline_Api::user::list'` |
| `source_name` | string | ✅ | 权限名称（显示名称） | `'用户列表'` |
| `icon` | string | ✅ | 图标（用于菜单显示） | `'fa fa-list'` |
| `document` | string | ❌ | 权限描述 | `'查看用户列表'` |
| `parent_source` | string | ❌ | 父级权限ID | `'Weline_Api::user'` |
| `rewrite` | string | ❌ | 重写路由 | `''` |

### 4.4 使用示例

**后台API接口（需要类级别和方法级别Acl）**：

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\Controller\AbstractRestController;

/**
 * 用户管理API
 * 
 * @Document(
 *   summary="用户管理API",
 *   description="提供用户相关的API接口，包括用户列表、创建、更新、删除等功能",
 *   tags={"用户管理"},
 *   category="用户"
 * )
 */
#[\Weline\Framework\Acl\Acl(
    'Weline_Example::api::User',
    '用户管理',
    'fa fa-users',
    '用户管理API分组',
    'Weline_Example::api'  // 父级：模块API分类
)]
class User extends AbstractRestController
{
    /**
     * 获取用户列表
     * 
     * @Document(
     *   summary="获取用户列表",
     *   description="获取API用户列表，支持分页和搜索",
     *   tags={"用户管理"},
     *   category="用户"
     * )
     * @param int $page 页码（可选，默认1）
     * @param int $pageSize 每页数量（可选，默认10）
     * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"items": [], "total": 0}}
     */
    #[\Weline\Framework\Acl\Acl(
        'Weline_Example::api::User::getList',
        '用户列表',
        'fa fa-list',
        '查看API用户列表',
        'Weline_Example::api::User'  // 父级：类级别Acl
    )]
    public function getList(int $page = 1, int $pageSize = 10): array
    {
        // 实现代码...
    }
    
    /**
     * 创建用户
     * 
     * @Document(
     *   summary="创建用户",
     *   description="创建新的API用户",
     *   tags={"用户管理"},
     *   category="用户"
     * )
     * @param string $name 用户名
     * @param string $email 邮箱
     * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"id": 1}}
     */
    #[\Weline\Framework\Acl\Acl(
        'Weline_Example::api::User::post',
        '创建用户',
        'fa fa-user-plus',
        '创建新的API用户',
        'Weline_Example::api::User'  // 父级：类级别Acl
    )]
    public function post(string $name, string $email): array
    {
        // 实现代码...
    }
}
```

**层级结构说明**：
- **顶级**：`Weline_Example::api`（模块API分类，由系统自动创建或手动创建）
- **类级别**：`Weline_Example::api::User`（接口文档类分组）
- **方法级别**：`Weline_Example::api::User::getList`（具体接口）

**公开接口（不需要Acl）**：
```php
/**
 * 获取公开信息
 * 
 * @Document(
 *   summary="获取公开信息",
 *   description="完全公开的接口，无需登录和权限验证",
 *   version="v1",
 *   tags={"公开接口"},
 *   category="公共"
 * )
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
 */
// 不需要Acl注释
public function getPublicInfo(): array
{
    // 实现代码...
}
```

**前端接口（不需要Acl）**：
```php
/**
 * 获取当前用户信息
 * 
 * @Document(
 *   summary="获取当前用户信息",
 *   description="前端接口，需要登录但不需要权限控制",
 *   version="v1",
 *   tags={"用户信息"},
 *   category="用户"
 * )
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
 */
// 不需要Acl注释（前端接口只需要登录验证）
public function getCurrentUser(): array
{
    // 实现代码...
}
```

## 五、PHPDoc注释规范

### 5.1 基本格式

```php
/**
 * 方法描述（可选，建议与summary保持一致）
 * 
 * @Document(
 *   summary="API接口摘要",
 *   description="API接口详细描述（可选）",
 *   tags={"标签1", "标签2"},
 *   category="分类名称"
 * )
 * @param 类型 $参数名 参数描述
 * @return 类型 返回值描述
 * @throws 异常类型 异常情况说明
 * @example
 * Method: GET
 * Path: /api/v1/user/1
 * Response:
 *   {
 *     "code": 0,
 *     "msg": "success",
 *     "data": {"id": 1, "name": "张三"}
 *   }
 * @example-end
 */
```

### 5.2 @param 注释规范

**格式**：`@param 类型 $参数名 参数描述`

**要求**：
- ✅ **所有方法参数必须有 `@param` 注释**（包括方法签名参数和通过request获取的参数）
- ✅ **参数类型必须与方法参数类型声明匹配**（如果提供了类型）
- ✅ **参数描述必须明确说明**：
  - 是否必填（必填/可选）
  - 默认值（如果有）
  - **参数获取方式**（通过方法签名、POST参数、GET参数、URL参数、请求头等）
  - 参数格式要求（如：邮箱格式、长度限制等）
- ⚠️ **【严重错误】需要登录的API（有ACL注解）必须明确说明所有请求参数**，包括通过 `request->getPost()`, `request->getParam()`, `request->getGet()`, `request->getBodyParam()` 等方法获取的参数
- ⚠️ **如果API需要登录但使用了请求参数却没有在@param注释中说明，验证器会报严重错误并阻止路由注册**

**参数获取方式说明**：

| 获取方式 | 说明 | 示例 |
|---------|------|------|
| 方法签名参数 | 通过方法参数直接传递 | `@param int $id 用户ID（必填）` |
| POST参数 | 通过 `request->getPost()` 获取 | `@param string $username 用户名（必填，通过POST参数获取）` |
| GET参数 | 通过 `request->getGet()` 获取 | `@param int $page 页码（可选，默认1，通过GET参数获取）` |
| URL参数 | 通过 `request->getParam()` 获取 | `@param string $token 访问令牌（必填，通过URL参数获取）` |
| 请求头 | 通过 `request->getHeader()` 或 `request->getAuth()` 获取 | `@param string $token 访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）` |
| Body参数 | 通过 `request->getBodyParam()` 获取 | `@param string $content 内容（必填，通过Body参数获取）` |

**标准示例**（参考 `app\code\Weline\Api\Api\Rest\V1\Test.php`）：

```php
/**
 * 获取测试信息
 * 
 * @param string $name 测试名称（可选，默认"test"，通过方法签名参数获取）
 * @param int $count 测试数量（可选，默认1，通过方法签名参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"name": "test", "count": 1, "timestamp": 1234567890}}
 */
public function getInfo(string $name = 'test', int $count = 1): array
{
    // 实现代码...
}
```

```php
/**
 * 创建测试数据
 * 
 * @param string $title 测试标题（必填，通过POST参数获取）
 * @param string $content 测试内容（必填，通过POST参数获取）
 * @param array $tags 标签列表（可选，默认空数组，通过POST参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "创建成功", "data": {"id": 1, "title": "测试标题", "content": "测试内容"}}
 */
public function postCreate(string $title, string $content, array $tags = []): array
{
    $title = trim($this->request->getPost('title') ?? '');
    $content = trim($this->request->getPost('content') ?? '');
    $tags = $this->request->getPost('tags') ?? [];
    // 实现代码...
}
```

```php
/**
 * 获取测试列表
 * 
 * @param int $page 页码（可选，默认1，通过GET参数获取）
 * @param int $pageSize 每页数量（可选，默认20，通过GET参数获取）
 * @param string $keyword 搜索关键词（可选，通过GET参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"list": [], "total": 0, "page": 1, "pageSize": 20}}
 */
public function getList(int $page = 1, int $pageSize = 20, string $keyword = ''): array
{
    $page = (int)($this->request->getGet('page') ?? 1);
    $pageSize = (int)($this->request->getGet('pageSize') ?? 20);
    $keyword = trim($this->request->getGet('keyword') ?? '');
    // 实现代码...
}
```

```php
/**
 * 登录并获取token
 * 
 * @param string $username 用户名（必填，通过POST参数获取）
 * @param string $password 密码（必填，通过POST参数获取）
 * @param int $expire_time 令牌过期时间（可选，秒数，通过POST参数获取，默认使用用户配置的过期时间）
 * @return array 返回数据格式：{"code": 200, "msg": "登录成功", "data": {"access_token": "...", "refresh_token": "...", "expire_time": 1234567890, "user": {...}}}
 */
public function postLogin()
{
    $username = trim($this->request->getPost('username') ?? '');
    $password = trim($this->request->getPost('password') ?? '');
    $expireTime = (int)($this->request->getPost('expire_time') ?? 0);
    // 实现代码...
}
```

```php
/**
 * 获取当前用户信息
 * 
 * @param string $token 访问令牌（必填，通过Authorization头、X-API-Token头、URL参数或POST参数获取）
 * @return array 返回数据格式：{"code": 200, "msg": "获取用户信息成功", "data": {"user": {"id": 1, "username": "...", "email": "...", "is_enabled": true}}}
 */
public function getMe()
{
    // 从请求中获取token（通过私有方法 getTokenFromRequest()）
    $token = $this->getTokenFromRequest();
    // 实现代码...
}
```

**错误示例**：

```php
// ❌ 错误：缺少参数说明
/**
 * 登录并获取token
 * 
 * @return array 返回数据
 */
public function postLogin()
{
    $username = trim($this->request->getPost('username') ?? '');
    $password = trim($this->request->getPost('password') ?? '');
    // 缺少 @param 注释说明 username 和 password 参数
}
```

**正确示例**：

```php
// ✅ 正确：明确说明所有参数
/**
 * 登录并获取token
 * 
 * @param string $username 用户名（必填，通过POST参数获取）
 * @param string $password 密码（必填，通过POST参数获取）
 * @return array 返回数据格式：{"code": 200, "msg": "登录成功", "data": {...}}
 */
public function postLogin()
{
    $username = trim($this->request->getPost('username') ?? '');
    $password = trim($this->request->getPost('password') ?? '');
    // 实现代码...
}
```

### 5.3 @return 注释规范

**格式**：`@return 类型 返回值描述`

**要求**：
- 必须提供 `@return` 注释
- 返回类型必须与方法返回类型声明匹配（如果提供了类型）
- 建议描述返回数据的格式

**示例**：
```php
/**
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
 */
public function getUser(int $id): array
```

### 5.4 @throws 注释规范

**格式**：`@throws 异常类型 异常情况说明`

**要求**：
- 如果方法可能抛出异常，建议提供 `@throws` 注释
- 说明什么情况下会抛出异常

**示例**：
```php
/**
 * @throws \Exception 用户不存在时抛出异常
 * @throws \Weline\Framework\App\Exception 参数验证失败时抛出异常
 */
public function getUser(int $id): array
```

### 5.5 @example 注释规范

**格式**：`@example` 和 `@example-end` 之间包含完整的请求和响应示例

**要求**：
- ✅ **必须提供 `@example` 注释**（必填）
- ✅ **必须使用 `@example` 开始，`@example-end` 结束**
- ✅ **必须包含完整的请求信息**（Method、Path、Header、Body等）和响应示例
- ✅ **使用标准格式，便于自动解析**
- ⚠️ **【严重错误】缺少 @example 注释或格式不完整会导致验证失败，阻止路由注册**

**参考示例**（`app\code\Weline\Api\Api\Rest\V1\Test.php`）：

```php
/**
 * 创建测试数据
 * 
 * @param string $title 测试标题（必填）
 * @param string $content 测试内容（必填）
 * @param array $tags 标签列表（可选）
 * @return array 返回数据格式：{"code": 0, "msg": "创建成功", "data": {"id": 1, "title": "测试标题", "content": "测试内容"}}
 * @throws \Exception 创建失败时抛出异常
 * @Document(summary='创建测试数据', description='用于测试POST请求的API文档导入功能。需要提供测试标题和内容，可选标签列表。', tags=['测试', 'API文档', '创建'], category='测试接口')
 * @example
 * Method: POST
 * Path: /api/rest/v1/weline-api/test/create
 * Header:
 * - Content-Type: application/json
 * - Authorization: Bearer token_here
 * Body:
 * {
 *   "title": "测试标题",
 *   "content": "测试内容",
 *   "tags": ["测试", "API"]
 * }
 * Response:
 * {
 *   "code": 0,
 *   "msg": "创建成功",
 *   "data": {
 *     "id": 1,
 *     "title": "测试标题",
 *     "content": "测试内容",
 *     "tags": ["测试", "API"]
 *   }
 * }
 * @example-end
 */
```

**标准格式**：
```
@example
Method: GET|POST|PUT|DELETE|PATCH
Path: /api/v1/resource/{id}
Header: 
  - Authorization: Bearer {token}
  - Content-Type: application/json
  - X-Custom-Header: value
Request Parameters:
  - page: 1 (可选，默认1)
  - pageSize: 20 (可选，默认20)
  - keyword: test (可选)
Cookie:
  - session_id: abc123
  - user_pref: dark
Body: 
  {
    "key": "value"
  }
Response: 
  {
    "code": 0,
    "msg": "success",
    "data": {}
  }
@example-end
```

**字段说明**：

| 字段 | 必填 | 说明 | 示例 |
|------|------|------|------|
| `Method` | ✅ | HTTP方法 | `GET`, `POST`, `PUT`, `DELETE`, `PATCH` |
| `Path` | ✅ | API路径 | `/api/v1/user/1` |
| `Header` | ❌ | 请求头（可选，多行格式：`- HeaderName: value`） | `- Authorization: Bearer token` |
| `Request Parameters` | ⚠️ | GET请求的URL参数（GET请求如果有参数建议提供，多行格式：`- param_name: value (description)`） | `- page: 1 (可选，默认1)` |
| `Cookie` | ❌ | Cookie信息（可选，多行格式：`- cookie_name: value`） | `- session_id: abc123` |
| `Body` | ⚠️ | 请求体（**POST/PUT/PATCH方法必填**，GET/DELETE不需要，JSON格式，如果没有请求体可以为空对象 `{}`） | `{"key": "value"}` 或 `{}` |
| `Response` | ✅ | 响应示例（JSON格式） | `{"code": 0, "msg": "success", "data": {}}` |

**重要要求**：
- ✅ **POST/PUT/PATCH方法必须包含Body字段**（即使为空对象：`Body: {}`）
- ✅ **GET请求如果有参数，建议提供Request Parameters字段**，方便开发者理解和使用
- ✅ **所有字段必须使用标准格式**，便于自动解析和加载到测试面板
- ✅ **请求体和请求参数会自动加载到测试面板**，方便开发者测试

**简化格式**（仅Path和Response）：
```
@example
Path: GET /api/v1/user/1
Response: {"code": 0, "msg": "success", "data": {"id": 1}}
@example-end
```

**完整示例**：
```php
/**
 * @example
 * Method: POST
 * Path: /api/v1/user
 * Header:
 *   - Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
 *   - Content-Type: application/json
 * Cookie:
 *   - session_id: abc123def456
 * Body:
 *   {
 *     "name": "张三",
 *     "email": "zhangsan@example.com",
 *     "password": "123456"
 *   }
 * Response:
 *   {
 *     "code": 0,
 *     "msg": "创建成功",
 *     "data": {
 *       "id": 1,
 *       "name": "张三",
 *       "email": "zhangsan@example.com"
 *     }
 *   }
 * @example-end
 */
```

## 六、完整示例

### 6.1 GET 接口示例（后台API，需要类级别和方法级别Acl）

**目录结构**：`app\code\Weline\Example\Api\Rest\V1\User.php`

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\Controller\AbstractRestController;

/**
 * 用户管理API
 * 
 * @Document(
 *   summary="用户管理API",
 *   description="提供用户相关的API接口，包括用户信息查询、创建、更新、删除等功能",
 *   tags={"用户管理"},
 *   category="用户"
 * )
 */
#[\Weline\Framework\Acl\Acl(
    'Weline_Example::api::User',
    '用户管理',
    'fa fa-users',
    '用户管理API分组',
    'Weline_Example::api'  // 父级：模块API分类
)]
class User extends AbstractRestController
{
    /**
     * 获取用户信息
     * 
     * @Document(
     *   summary="获取用户信息",
     *   description="根据用户ID获取用户详细信息，包括基本信息、邮箱等",
     *   tags={"用户管理", "用户信息"},
     *   category="用户"
     * )
     * @param int $id 用户ID（必填）
     * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"id": 1, "name": "张三", "email": "zhangsan@example.com"}}
     * @throws \Exception 用户不存在时抛出异常
     * @example
     * Method: GET
     * Path: /api/v1/user/1
     * Header:
     *   - Authorization: Bearer {token}
     * Response:
     *   {
     *     "code": 0,
     *     "msg": "success",
     *     "data": {
     *       "id": 1,
     *       "name": "张三",
     *       "email": "zhangsan@example.com"
     *     }
     *   }
     * @example-end
     */
    #[\Weline\Framework\Acl\Acl(
        'Weline_Example::api::User::get',
        '获取用户信息',
        'fa fa-user',
        '根据用户ID获取用户详细信息',
        'Weline_Example::api::User'  // 父级：类级别Acl
    )]
    public function get(int $id): array
    {
        // 实现代码...
        return $this->success([
            'id' => $id,
            'name' => '张三',
            'email' => 'zhangsan@example.com'
        ]);
    }
}
```

**层级结构**：
- 顶级：`Weline_Example::api`（模块API分类）
- 类级别：`Weline_Example::api::User`（接口文档类分组）
- 方法级别：`Weline_Example::api::User::get`（具体接口）

### 6.2 POST 接口示例（后台API，需要Acl）

**目录结构**：`app\code\Weline\Example\Api\Rest\V1\User.php`

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\Controller\AbstractRestController;

class User extends AbstractRestController
{
    /**
     * 创建用户
     * 
     * @Document(
     *   summary="创建用户",
     *   description="创建新用户，需要提供用户名、邮箱和密码，可选提供角色列表",
     *   tags={"用户管理", "用户创建"},
     *   category="用户"
     * )
     * @param string $name 用户名（必填，3-50字符）
     * @param string $email 邮箱（必填，邮箱格式）
     * @param string $password 密码（必填，最少6字符）
     * @param array $roles 角色列表（可选，默认空数组）
     * @return array 返回数据格式：{"code": 0, "msg": "创建成功", "data": {"id": 1}}
     * @throws \Exception 创建失败时抛出异常
     * @example
     * Method: POST
     * Path: /api/v1/user
     * Header:
     *   - Authorization: Bearer {token}
     *   - Content-Type: application/json
     * Body:
     *   {
     *     "name": "张三",
     *     "email": "zhangsan@example.com",
     *     "password": "123456",
     *     "roles": [1, 2]
     *   }
     * Response:
     *   {
     *     "code": 0,
     *     "msg": "创建成功",
     *     "data": {
     *       "id": 1
     *     }
     *   }
     * @example-end
     */
    #[\Weline\Framework\Acl\Acl(
        'Weline_Example::user::create',
        '创建用户',
        'fa fa-user-plus',
        '创建新用户',
        'Weline_Example::user'
    )]
    public function post(
        string $name,
        string $email,
        string $password,
        array $roles = []
    ): array {
        // 实现代码...
        return $this->success(['id' => 1]);
    }
}
```

### 6.3 PUT 接口示例（后台API，需要Acl）

**目录结构**：`app\code\Weline\Example\Api\Rest\V1\User.php`

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\Controller\AbstractRestController;

class User extends AbstractRestController
{
    /**
     * 更新用户信息
     * 
     * @Document(
     *   summary="更新用户信息",
     *   description="更新指定用户的信息，可以部分更新",
     *   tags={"用户管理", "用户更新"},
     *   category="用户"
     * )
     * @param int $id 用户ID（必填）
     * @param string $name 用户名（可选）
     * @param string $email 邮箱（可选）
     * @return array 返回数据格式：{"code": 0, "msg": "更新成功", "data": {"id": 1}}
     * @throws \Exception 用户不存在或更新失败时抛出异常
     * @example
     * Method: PUT
     * Path: /api/v1/user/1
     * Header:
     *   - Authorization: Bearer {token}
     *   - Content-Type: application/json
     * Body:
     *   {
     *     "name": "李四",
     *     "email": "lisi@example.com"
     *   }
     * Response:
     *   {
     *     "code": 0,
     *     "msg": "更新成功",
     *     "data": {
     *       "id": 1
     *     }
     *   }
     * @example-end
     */
    #[\Weline\Framework\Acl\Acl(
        'Weline_Example::user::update',
        '更新用户信息',
        'fa fa-user-edit',
        '更新指定用户的信息',
        'Weline_Example::user'
    )]
    public function put(
        int $id,
        string $name = '',
        string $email = ''
    ): array {
        // 实现代码...
        return $this->success(['id' => $id]);
    }
}
```

### 6.4 DELETE 接口示例（后台API，需要Acl）

**目录结构**：`app\code\Weline\Example\Api\Rest\V1\User.php`

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\Controller\AbstractRestController;

class User extends AbstractRestController
{
    /**
     * 删除用户
     * 
     * @Document(
     *   summary="删除用户",
     *   description="删除指定用户，删除后无法恢复",
     *   tags={"用户管理", "用户删除"},
     *   category="用户"
     * )
     * @param int $id 用户ID（必填）
     * @return array 返回数据格式：{"code": 0, "msg": "删除成功", "data": null}
     * @throws \Exception 用户不存在或删除失败时抛出异常
     * @example
     * Method: DELETE
     * Path: /api/v1/user/1
     * Header:
     *   - Authorization: Bearer {token}
     * Response:
     *   {
     *     "code": 0,
     *     "msg": "删除成功",
     *     "data": null
     *   }
     * @example-end
     */
    #[\Weline\Framework\Acl\Acl(
        'Weline_Example::user::delete',
        '删除用户',
        'fa fa-user-times',
        '删除指定用户',
        'Weline_Example::user'
    )]
    public function delete(int $id): array
    {
        // 实现代码...
        return $this->success(null);
    }
}
```

### 6.5 公开接口示例（不需要Acl）

**目录结构**：`app\code\Weline\Example\Api\Rest\V1\Public.php`

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Controller\AbstractRestController;

class Public extends AbstractRestController
{
    /**
     * 获取公开信息
     * 
     * @Document(
     *   summary="获取公开信息",
     *   description="完全公开的接口，无需登录和权限验证",
     *   tags={"公开接口"},
     *   category="公共"
     * )
     * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
     * @example
     * Method: GET
     * Path: /api/v1/public/info
     * Response:
     *   {
     *     "code": 0,
     *     "msg": "success",
     *     "data": {
     *       "version": "1.0.0",
     *       "name": "Weline Framework"
     *     }
     *   }
     * @example-end
     */
    // 不需要Acl注释（公开接口）
    public function getInfo(): array
    {
        // 实现代码...
        return $this->success([
            'version' => '1.0.0',
            'name' => 'Weline Framework'
        ]);
    }
}
```

### 6.6 前端接口示例（不需要Acl）

**目录结构**：`app\code\Weline\Example\Api\Rest\V1\FrontendUser.php`

```php
<?php

namespace Weline\Example\Api\Rest\V1;

use Weline\Framework\Controller\AbstractRestController;

class FrontendUser extends AbstractRestController
{
    /**
     * 获取当前用户信息
     * 
     * @Document(
     *   summary="获取当前用户信息",
     *   description="前端接口，需要登录但不需要权限控制",
     *   tags={"用户信息"},
     *   category="用户"
     * )
     * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
     * @example
     * Method: GET
     * Path: /api/v1/user/me
     * Header:
     *   - Authorization: Bearer {token}
     * Response:
     *   {
     *     "code": 0,
     *     "msg": "success",
     *     "data": {
     *       "id": 1,
     *       "name": "张三"
     *     }
     *   }
     * @example-end
     */
    // 不需要Acl注释（前端接口只需要登录验证）
    public function getMe(): array
    {
        // 实现代码...
        return $this->success([
            'id' => 1,
            'name' => '张三'
        ]);
    }
}
```

## 七、常见错误

### 7.1 ❌ 缺少@Document注释

```php
// ❌ 错误：缺少@Document注释
public function get(int $id): array
{
    return $this->success([]);
}
```

**修复**：
```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @param int $id 用户ID
 * @return array 返回数据
 */
public function get(int $id): array
{
    return $this->success([]);
}
```

### 7.2 ❌ 缺少PHPDoc注释

```php
// ❌ 错误：缺少PHPDoc注释
public function get(int $id): array
{
    return $this->success([]);
}
```

**修复**：
```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @param int $id 用户ID
 * @return array 返回数据
 */
public function get(int $id): array
{
    return $this->success([]);
}
```

### 7.3 ❌ 缺少@param注释

```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @return array 返回数据
 */
// ❌ 错误：参数 $id 缺少 @param 注释
public function get(int $id): array
{
    return $this->success([]);
}
```

**修复**：
```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @param int $id 用户ID（必填，通过方法签名参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"id": 1}}
 */
public function get(int $id): array
{
    return $this->success([]);
}
```

### 7.3.1 ❌ 需要登录的API缺少请求参数说明（严重错误）

```php
/**
 * 登录并获取token
 * 
 * @Document(
 *   summary="用户登录"
 * )
 * @return array 返回数据
 */
// ❌ 严重错误：需要登录的API（有ACL注解），使用了请求参数却没有在@param注释中说明
public function postLogin()
{
    $username = trim($this->request->getPost('username') ?? '');
    $password = trim($this->request->getPost('password') ?? '');
    // 缺少 @param 注释说明 username 和 password 参数
}
```

**验证器会报错**：
```
【严重错误】方法 postLogin 需要登录（有ACL注解），但使用了请求参数 'username'（通过request->getPOST获取）却没有在@param注释中说明。请添加 @param 注释说明此参数。
【严重错误】方法 postLogin 需要登录（有ACL注解），但使用了请求参数 'password'（通过request->getPOST获取）却没有在@param注释中说明。请添加 @param 注释说明此参数。
```

**修复**：
```php
/**
 * 登录并获取token
 * 
 * @Document(
 *   summary="用户登录"
 * )
 * @param string $username 用户名（必填，通过POST参数获取）
 * @param string $password 密码（必填，通过POST参数获取）
 * @return array 返回数据格式：{"code": 200, "msg": "登录成功", "data": {"access_token": "...", "refresh_token": "..."}}
 */
public function postLogin()
{
    $username = trim($this->request->getPost('username') ?? '');
    $password = trim($this->request->getPost('password') ?? '');
    // 实现代码...
}
```

### 7.4 ❌ 缺少@return注释或返回数据格式说明

```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @param int $id 用户ID（必填，通过方法签名参数获取）
 */
// ❌ 错误：缺少 @return 注释
public function get(int $id): array
{
    return $this->success([]);
}
```

**修复**：
```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @param int $id 用户ID（必填，通过方法签名参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"id": 1, "name": "张三", "email": "zhangsan@example.com"}}
 */
public function get(int $id): array
{
    return $this->success([]);
}
```

### 7.5 ❌ 缺少@example注释（严重错误）

```php
/**
 * 创建用户
 * 
 * @Document(
 *   summary="创建用户"
 * )
 * @param string $name 用户名（必填，通过POST参数获取）
 * @param string $email 邮箱（必填，通过POST参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "创建成功", "data": {"id": 1}}
 */
// ❌ 严重错误：缺少 @example 注释
public function post(string $name, string $email): array
{
    return $this->success(['id' => 1]);
}
```

**验证器会报错**：
```
【严重错误】方法 post 缺少 @example 注释。必须包含完整的请求和响应示例，参考 Test.php 的注释格式。
```

**修复**（参考 `app\code\Weline\Api\Api\Rest\V1\Test.php`）：

```php
/**
 * 创建用户
 * 
 * @Document(
 *   summary="创建用户"
 * )
 * @param string $name 用户名（必填，通过POST参数获取）
 * @param string $email 邮箱（必填，通过POST参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "创建成功", "data": {"id": 1}}
 * @throws \Exception 创建失败时抛出异常
 * @example
 * Method: POST
 * Path: /api/rest/v1/user
 * Header:
 * - Content-Type: application/json
 * - Authorization: Bearer token_here
 * Body:
 * {
 *   "name": "张三",
 *   "email": "zhangsan@example.com"
 * }
 * Response:
 * {
 *   "code": 0,
 *   "msg": "创建成功",
 *   "data": {
 *     "id": 1
 *   }
 * }
 * @example-end
 */
public function post(string $name, string $email): array
{
    return $this->success(['id' => 1]);
}
```

### 7.6 ❌ @example注释格式不完整（严重错误）

```php
/**
 * 创建用户
 * 
 * @example
 * Method: POST
 * Response: {"code": 0}
 * @example-end
 */
// ❌ 严重错误：@example 注释缺少 Path 和 Body 字段
public function post(string $name, string $email): array
{
    return $this->success(['id' => 1]);
}
```

**验证器会报错**：
```
【严重错误】方法 post 的 @example 注释缺少 Path 字段。必须包含 API 路径，例如：Path: /api/rest/v1/test/create
【严重错误】方法 post 是 POST/PUT/PATCH 方法，@example 注释必须包含 Body 字段。必须包含请求体示例，例如：Body: {"key": "value"}
```

**修复**：参考上面的完整示例格式。

### 7.7 ❌ 类型不匹配

```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @param string $id 用户ID  // ❌ 错误：类型不匹配，方法参数是 int
 * @return array 返回数据
 */
public function get(int $id): array
{
    return $this->success([]);
}
```

**修复**：
```php
/**
 * 获取用户信息
 * 
 * @Document(
 *   summary="获取用户信息"
 * )
 * @param int $id 用户ID  // ✅ 正确：类型匹配
 * @return array 返回数据
 */
public function get(int $id): array
{
    return $this->success([]);
}
```

### 7.6 ❌ 后台API缺少Acl注释

```php
/**
 * 获取用户列表
 * 
 * @Document(
 *   summary="获取用户列表"
 * )
 * @param int $page 页码
 * @return array 返回数据
 */
// ❌ 错误：后台API接口缺少Acl权限注释
public function getList(int $page = 1): array
{
    return $this->success([]);
}
```

**修复**：
```php
/**
 * 获取用户列表
 * 
 * @Document(
 *   summary="获取用户列表"
 * )
 * @param int $page 页码
 * @return array 返回数据
 */
#[\Weline\Framework\Acl\Acl(
    'Weline_Example::user::list',
    '用户列表',
    'fa fa-list',
    '查看用户列表',
    'Weline_Example::user'
)]
public function getList(int $page = 1): array
{
    return $this->success([]);
}
```

## 八、验证和调试

### 8.1 验证命令

运行路由注册命令时，会自动验证所有API接口：

```bash
php bin/w setup:upgrade
```

### 8.2 验证失败处理

**重要**：验证失败会直接报错，**停止编译**，阻止系统更新。

**所有环境（开发/生产）**：
- ✅ **验证失败会抛出异常，阻止路由注册**
- ✅ **异常信息会显示详细的错误原因**，包括：
  - 不符合规范的接口列表
  - 每个接口的具体错误原因
  - 修复建议
- ✅ **系统更新会被中断**，直到所有API接口符合规范
- ✅ **日志记录**：错误信息会记录到 `var/log/api_doc_validation.log`

**错误信息示例**：
```
[ERROR] API规范验证失败，发现 3 个不符合规范的接口：

1. Weline\Example\Controller\Api\User::get()
   - 缺少 @Document 注释
   - 缺少 @param 注释（参数：$id）
   - 缺少 @return 注释

2. Weline\Example\Controller\Api\Product::post()
   - @Document 注释缺少必填参数：summary
   - @param 注释类型不匹配（参数：$name，注释类型：string，实际类型：int）

3. Weline\Example\Controller\Api\Order::put()
   - 缺少 PHPDoc 注释

请修复以上问题后重新执行 setup:upgrade
```

**修复流程**：
1. 查看错误信息，了解不符合规范的接口
2. 修复所有不符合规范的接口
3. 重新执行 `php bin/w setup:upgrade`
4. 验证通过后，系统更新继续执行

### 8.3 查看验证结果

```bash
# 查看验证日志
tail -f var/log/api_doc_validation.log

# 查看路由注册情况
php bin/w router:list
```

## 九、最佳实践

### 9.1 注释编写建议

1. **summary要简洁明了**：一句话概括接口功能
2. **description要详细**：说明接口的用途、使用场景、注意事项
3. **参数描述要完整**：说明参数类型、是否必填、取值范围、默认值、**参数获取方式**
4. **返回值描述要清晰**：说明返回数据的结构和格式，建议使用JSON格式示例
5. **提供示例**：建议提供请求和响应示例（使用 `@example` 注释）
6. **【重要】需要登录的API必须明确说明所有请求参数**：
   - 所有通过 `request->getPost()`, `request->getParam()`, `request->getGet()`, `request->getBodyParam()` 等方法获取的参数
   - 所有通过 `request->getHeader()`, `request->getAuth()` 等方法获取的请求头参数
   - 必须在 `@param` 注释中明确说明参数名称、类型、是否必填、获取方式
   - 参考 `app\code\Weline\Api\Api\Rest\V1\Test.php` 的写法

**参考示例**（`app\code\Weline\Api\Api\Rest\V1\Test.php`）：

```php
/**
 * 获取测试信息
 * 
 * 这是一个测试接口，用于验证API文档导入功能是否正常工作
 * 
 * @param string $name 测试名称（可选，默认"test"，通过方法签名参数获取）
 * @param int $count 测试数量（可选，默认1，通过方法签名参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {"name": "test", "count": 1, "timestamp": 1234567890}}
 * @throws \Exception 参数错误时抛出异常
 * @Document(summary='获取测试信息', description='这是一个测试接口，用于验证API文档导入功能是否正常工作。返回测试名称、数量和当前时间戳。', tags=['测试', 'API文档'], category='测试接口')
 * @example
 * Method: GET
 * Path: /api/rest/v1/weline-api/test/getInfo
 * Response:
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": {
 *     "name": "test",
 *     "count": 1,
 *     "timestamp": 1234567890
 *   }
 * }
 * @example-end
 */
public function getInfo(string $name = 'test', int $count = 1): array
{
    // 实现代码...
}
```

```php
/**
 * 创建测试数据
 * 
 * 用于测试POST请求的API文档导入功能
 * 
 * @param string $title 测试标题（必填，通过POST参数获取）
 * @param string $content 测试内容（必填，通过POST参数获取）
 * @param array $tags 标签列表（可选，默认空数组，通过POST参数获取）
 * @return array 返回数据格式：{"code": 0, "msg": "创建成功", "data": {"id": 1, "title": "测试标题", "content": "测试内容"}}
 * @throws \Exception 创建失败时抛出异常
 * @Document(summary='创建测试数据', description='用于测试POST请求的API文档导入功能。需要提供测试标题和内容，可选标签列表。', tags=['测试', 'API文档', '创建'], category='测试接口')
 * @example
 * Method: POST
 * Path: /api/rest/v1/weline-api/test/create
 * Header:
 * - Content-Type: application/json
 * - Authorization: Bearer token_here
 * Body:
 * {
 *   "title": "测试标题",
 *   "content": "测试内容",
 *   "tags": ["测试", "API"]
 * }
 * Response:
 * {
 *   "code": 0,
 *   "msg": "创建成功",
 *   "data": {
 *     "id": 1,
 *     "title": "测试标题",
 *     "content": "测试内容",
 *     "tags": ["测试", "API"]
 *   }
 * }
 * @example-end
 */
public function postCreate(string $title, string $content, array $tags = []): array
{
    $title = trim($this->request->getPost('title') ?? '');
    $content = trim($this->request->getPost('content') ?? '');
    $tags = $this->request->getPost('tags') ?? [];
    // 实现代码...
}
```

### 9.2 版本管理

- **版本号通过目录结构控制**：必须放在 `Api\Rest\V{版本号}` 目录下
- 版本号格式：`V1`, `V2`, `V3` 等（大写V + 数字）
- 示例：
  - `Weline\Example\Api\Rest\V1\User.php` → 版本 v1
  - `Weline\Example\Api\Rest\V2\User.php` → 版本 v2
- 废弃接口要标记 `deprecated = true` 并提供废弃原因
- 新版本接口创建新的版本目录（如 `V2`）

### 9.3 分类和标签

- **category**：用于API文档的大分类（如：用户、商品、订单）
- **tags**：用于API文档的标签（如：用户管理、用户信息、用户创建）
- 建议使用统一的分类和标签命名规范

### 9.4 错误处理

- 使用 `@throws` 注释说明可能抛出的异常
- 返回统一的错误格式：`{"code": 错误码, "msg": "错误信息", "data": null}`

## 十、IDE支持

### 10.1 PHPStorm

PHPStorm 可以自动识别 `@Document` 注释和 `Acl` Attribute，并提供代码补全和提示。

**重要说明**：
- **不需要 `ApiDoc` Attribute**：`Acl` 注解已经可以直接运行代码，功能完整
- `Acl` 注解用于权限控制和API识别
- `@Document` 注释仅用于提供API文档信息

### 10.2 代码模板

可以在IDE中创建代码模板，快速生成符合规范的API接口代码。

**PHPStorm 模板示例（后台API）**：
```php
/**
 * $DESCRIPTION$
 * 
 * @Document(
 *   summary="$SUMMARY$",
 *   description="$DESCRIPTION$",
 *   tags={"$TAG$"},
 *   category="$CATEGORY$"
 * )
 * @param $PARAM_TYPE$ $PARAM_NAME$ $PARAM_DESC$
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
 * @throws \Exception $EXCEPTION_DESC$
 * @example
 * Method: $METHOD$
 * Path: $URL$
 * Header:
 *   - Authorization: Bearer {token}
 *   - Content-Type: application/json
 * Body:
 *   {
 *     "key": "value"
 *   }
 * Response:
 *   {
 *     "code": 0,
 *     "msg": "success",
 *     "data": {}
 *   }
 * @example-end
 */
#[\Weline\Framework\Acl\Acl(
    '$MODULE$::$RESOURCE$::$ACTION$',
    '$ACL_NAME$',
    'fa fa-icon',
    '$ACL_DESC$',
    '$PARENT_ACL$'
)]
public function $METHOD_NAME$($PARAMETERS$): array
{
    $END$
}
```

**PHPStorm 模板示例（公开/前端API）**：
```php
/**
 * $DESCRIPTION$
 * 
 * @Document(
 *   summary="$SUMMARY$",
 *   description="$DESCRIPTION$",
 *   tags={"$TAG$"},
 *   category="$CATEGORY$"
 * )
 * @param $PARAM_TYPE$ $PARAM_NAME$ $PARAM_DESC$
 * @return array 返回数据格式：{"code": 0, "msg": "success", "data": {}}
 * @throws \Exception $EXCEPTION_DESC$
 * @example
 * Method: $METHOD$
 * Path: $URL$
 * Header:
 *   - Authorization: Bearer {token}
 * Response:
 *   {
 *     "code": 0,
 *     "msg": "success",
 *     "data": {}
 *   }
 * @example-end
 */
// 不需要Acl注释（公开/前端接口）
public function $METHOD_NAME$($PARAMETERS$): array
{
    $END$
}
```

## 十一、相关文档

- [API注释文档规范设计方案](../../Weline/Api/doc/API注释文档规范设计方案.md)
- [Weline API 模块需求文档](../../Weline/Api/doc/需求文档-完整版.md)
- [模块开发完整指南](./模块开发完整指南.md)

---

**文档版本**: 1.0  
**创建日期**: 2025-01-XX  
**最后更新**: 2025-01-XX
