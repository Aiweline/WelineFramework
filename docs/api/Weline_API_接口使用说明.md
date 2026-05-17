# Weline Framework API接口使用说明

## 1. 服务器启动方式

### 正确方式（后台运行）
```bash
# 后台运行（推荐）
php bin/w server:start

# 指定端口
php bin/w server:start -p 9982

# 强制重启
php bin/w server:start -r
```

### ❌ 错误方式（前台运行）
```bash
# 不要使用 -f 参数，会阻塞终端
php bin/w server:start -f
```

## 2. API访问地址结构

### 2.1 URL结构规范

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

### 2.2 基础配置
从 `app/etc/env.php` 获取：
- `api`: "api" 或 "api123" （前端API区域前缀）
- `api_admin`: "J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE" （后端API区域前缀）

### 2.3 访问地址示例

**前端API（带i18n）**：
- **完整URL格式**: `http://127.0.0.1:9981/{api_area}/{currency}/{locale}/{模块路由}/rest/v1/{控制器路径}`
- **示例**: `http://127.0.0.1:9981/api123/USD/en_US/weline_api/rest/v1/auth/login`

**前端API（不带i18n）**：
- **完整URL格式**: `http://127.0.0.1:9981/{api_area}/{模块路由}/rest/v1/{控制器路径}`
- **示例**: `http://127.0.0.1:9981/api123/weline_api/rest/v1/auth/login`

**后端API**：
- **完整URL格式**: `http://127.0.0.1:9981/{api_admin}/{模块路由}/rest/v1/{控制器路径}`
- **示例**: `http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/weline_api/rest/v1/backend/auth/login`

**说明**：
- 区域前缀（`{api_area}` 或 `{api_admin}`）从 `env.php` 配置中获取
- 货币前缀（`{currency}`）和语言前缀（`{locale}`）是可选的，通常用于前端API
- 后端API通常不需要货币和语言前缀
- 路由注册时只注册 `{模块路由}/rest/v1/{控制器路径}` 部分

## 3. API接口类型

### 3.1 前端API（无需登录）
**特点**: 继承 `FrontendRestController`，无需登录认证

**访问格式**: 
- **完整URL（带i18n）**: `http://127.0.0.1:9981/{api_area}/{currency}/{locale}/{模块路由}/rest/v1/{控制器路径}`
- **完整URL（不带i18n）**: `http://127.0.0.1:9981/{api_area}/{模块路由}/rest/v1/{控制器路径}`
- **模块路由格式**: `{模块router}/rest/v1/{控制器名}/{方法名}`

**示例**:
```bash
# 通用格式（带i18n）
http://127.0.0.1:9981/api123/USD/en_US/weline_api/rest/v1/auth/login
POST /api123/USD/en_US/weline_api/rest/v1/auth/login
{
  "param1": "value1",
  "param2": "value2"
}

# 通用格式（不带i18n）
http://127.0.0.1:9981/api123/weline_api/rest/v1/auth/login
POST /api123/weline_api/rest/v1/auth/login
{
  "param1": "value1",
  "param2": "value2"
}
```

**说明**：
- `{api_area}` 从 `env.php` 的 `api` 配置获取（如 `api123` 或 `api`）
- `{currency}` 是货币代码（如 `USD`、`CNY`），可选
- `{locale}` 是语言代码（如 `en_US`、`zh_Hans_CN`），可选

### 3.2 后端API（需要登录）
**特点**: 继承 `BackendRestController`，需要管理员登录

**认证机制**：
1. 自动检查登录状态（在 `BackendRestController` 构造函数中）
2. 如果未登录，返回 401 错误：`{"msg": "请先登录", "data": "", "code": 401}`
3. 支持通过 session ID 自动登录（如果 session 有效）
4. 需要先访问后端管理页面登录，或通过 API 登录接口获取 session

**访问格式**:
- **完整URL**: `http://127.0.0.1:9981/{api_admin}/{模块路由}/rest/v1/{控制器路径}`
- 替换 `{api_admin}` 为 `app/etc/env.php` 中配置的实际值
- 后端API通常不需要货币和语言前缀

**示例**:
```bash
# 通用格式
http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/weline_api/rest/v1/backend/auth/login
POST /J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/weline_api/rest/v1/backend/auth/login
{
  "param1": "value1",
  "param2": "value2"
}
```

**说明**：
- `{api_admin}` 从 `env.php` 的 `api_admin` 配置获取
- 后端API通常不包含货币和语言前缀

## 4. 路由注册机制

### 4.1 自动生成路由
路由文件自动生成在：
- `generated/routers/frontend_rest_api.php` (前端API)
- `generated/routers/backend_rest_api.php` (后端API)

### 4.2 路由注册流程
1. 创建API控制器文件: `Api/Rest/V1/{控制器名}.php`
2. 继承正确的基类:
   - 前端API: `FrontendRestController`
   - 后端API: `BackendRestController`
3. 运行模块升级命令:
   ```bash
   php bin/w setup:upgrade -m {模块名}
   ```

### 4.3 路由命名规则
- URL路径: `{模块router}/rest/v1/{控制器名}/{方法名}`
- 方法名规则:
  - `get{方法名}` → GET请求，路径为 `/{方法名}`（转换为kebab-case）
  - `post{方法名}` → POST请求，路径为 `/{方法名}`（转换为kebab-case）
  - `put{方法名}` → PUT请求，路径为 `/{方法名}`（转换为kebab-case）
  - `delete{方法名}` → DELETE请求，路径为 `/{方法名}`（转换为kebab-case）
- 命名转换规则：
  - 控制器名和方法名都会自动转换为 kebab-case
  - 例如：`UserController` → `user-controller`，`postFields()` → `fields`
  - 例如：`postUserData()` → `user-data`，`getItemList()` → `item-list`

## 5. 查看已注册的路由

### 5.1 查看所有路由
使用以下命令查看所有已注册的路由：
```bash
php bin/w route:list
```

### 5.2 路由格式说明
路由列表中的格式为：
```
{路由路径}  {HTTP方法}  {完整类名}::{方法名}
```

例如：
```
{模块router}/rest/v1/{控制器名}/{方法名}  POST  {命名空间}\Api\Rest\V1\{控制器名}::{方法名}
```

## 6. JavaScript调用方式

### 6.1 在页面中的调用
站内浏览器前端业务 API 必须通过 `Weline.Api.resource()/graph()/stream()` 进入 worker；`window.api()` 只保留给 External REST API 或后台浏览器 API 的 URL 解析，不作为站内前端业务请求入口。

**重要提示**：
- 站内浏览器业务请求：使用 `Weline.Api.resource()/graph()/stream()`，不使用 URL。
- External REST API：可使用文档中给出的 REST 路径和 OAuth/API Token 认证。
- 后台浏览器 API：仍按后台权限与 `api_admin` 区域处理。
- `window.api(path)` 只保留给 External REST API 或后台浏览器 API 的 URL 解析。
- cart、checkout、customer、account 等站内前端业务接口不得使用 direct REST URL。

```javascript
// 站内前端业务 API
const CartApi = await Weline.Api.resource('cart');
await CartApi.add({ product_id, qty });
await CartApi.options({ product_id });
await CartApi.miniItems({ limit: 10 });

// 后端API调用 (需要登录)
// 注意：后端页面中，api_host 已包含 {api_admin} 前缀
window.api('weline_api/rest/v1/backend/auth/login')  
// 生成: http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/weline_api/rest/v1/backend/auth/login
```

### 6.2 站内前端 API 调用参数
```javascript
const CartApi = await Weline.Api.resource('cart', {
    addItem: 'add',
    getOptions: 'options'
});

await CartApi.addItem({ product_id, qty });
```

**注意**：`/api/framework/query-bin` 是 worker 协议实现细节，业务 JS 不得手写该 URL；第三方对接继续走 External REST API / OAuth App / Webhook / External Frontend Bridge。

### 6.3 Deprecated frontend REST business endpoints

旧 cart 浏览器 REST 入口已标记 `deprecated/browser_direct=false`，直接请求会被服务端拒绝：

| 旧入口 | 替代方式 |
|---|---|
| `/api/rest/v1/weshop/cart/add` | `Weline.Api.resource('cart').add()` |
| `/api/rest/v1/weshop/cart/options` | `Weline.Api.resource('cart').options()` |
| `/api/rest/v1/weshop/cart/mini-items` | `Weline.Api.resource('cart').miniItems()` |
| `/api/rest/v1/weshop/cart/update` | `Weline.Api.resource('cart').update()` |
| `/api/rest/v1/weshop/cart/remove` | `Weline.Api.resource('cart').remove()` |

## 7. 故障排查

### 7.1 常见问题
1. **404错误**: 检查路由是否注册，控制器是否存在
2. **权限错误**: 后端API需要先登录管理员
3. **模块未找到**: 确认模块已正确安装和注册

### 7.2 调试命令
```bash
# 查看所有路由
php bin/w route:list

# 搜索特定路由（Windows）
php bin/w route:list | findstr -i "{关键词}"

# 搜索特定路由（Linux/Mac）
php bin/w route:list | grep -i "{关键词}"

# 重新注册模块
php bin/w setup:upgrade -m {模块名}

# 查看服务器状态
php bin/w server:status
```

## 8. 总结

### 8.1 正确的API访问步骤
1. ✅ 启动服务器（后台模式）
2. ✅ 获取正确的API路径
3. ✅ 使用HTTP POST/GET请求
4. ✅ 提供正确的JSON参数
5. ✅ 前端API直接访问，后端API需要登录

### 8.2 重要提示
- **不要使用 `-f` 参数**，会导致终端阻塞
- **前端API** 无需登录，可直接访问
- **后端API** 需要管理员登录
- **URL结构**: `[网站前缀]/{区域前缀}/{货币前缀}/{语言前缀}/[模组前缀]/[路由]`
  - `[]` 表示必然存在
  - `{}` 表示可选
- **路径结构**: `{区域前缀}/{货币}/{语言}/{模块路由}/rest/v1/{控制器路径}`
- **参数格式**: JSON格式的请求体

### 8.3 实际使用示例
```bash
# 启动服务器（后台）
php bin/w server:start

# 测试前端API（带i18n）
curl -X POST "http://127.0.0.1:9981/api123/USD/en_US/weline_api/rest/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username": "test", "password": "123456"}'

# 测试前端API（不带i18n）
curl -X POST "http://127.0.0.1:9981/api123/weline_api/rest/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username": "test", "password": "123456"}'

# 测试后端API（需要先登录获取session）
curl -X POST "http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/weline_api/rest/v1/backend/auth/login" \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=your_session_id" \
  -d '{"username": "admin", "password": "admin"}'
```

## 9. API响应格式

### 9.1 成功响应
```json
{
  "msg": "请求成功！",
  "data": {...},
  "code": 200
}
```

### 9.2 错误响应
```json
{
  "msg": "错误信息",
  "data": "",
  "code": 400
}
```

### 9.3 未授权响应（后端API）
```json
{
  "msg": "请先登录",
  "data": "",
  "code": 401
}
```

## 10. 结论

经过实际测试验证，Weline Framework的API接口访问方式如下：

1. **前端API（带i18n）**: `http://127.0.0.1:9981/{api_area}/{currency}/{locale}/{模块路由}/rest/v1/{接口路径}`
2. **前端API（不带i18n）**: `http://127.0.0.1:9981/{api_area}/{模块路由}/rest/v1/{接口路径}`
3. **后端API**: `http://127.0.0.1:9981/{api_admin}/{模块路由}/rest/v1/{接口路径}`
4. **URL结构**: `[网站前缀]/{区域前缀}/{货币前缀}/{语言前缀}/[模组前缀]/[路由]`
   - `[]` 表示必然存在
   - `{}` 表示可选
5. **调用方式**: 标准REST API格式
6. **参数格式**: JSON请求体
7. **认证方式**: 前端无需登录，后端需要管理员认证

接口架构完整且规范，各模块可根据此规范实现自己的API接口。
