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

### 基础配置
从 `app/etc/env.php` 获取：
- `api`: "api" （前端API基础路径）
- `api_admin`: "J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE" （后端API基础路径）

### 访问地址
- **前端首页**: `http://127.0.0.1:9981/`
- **前端API基础路径**: `http://127.0.0.1:9981/api/`（注意：不包含 `/rest`）
- **前端API完整路径**: `http://127.0.0.1:9981/api/rest/v1/{模块路由}/{控制器路径}`
- **后端管理**: `http://127.0.0.1:9981/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin/login`
- **后端API基础路径**: `http://127.0.0.1:9981/J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/`（注意：不包含 `/rest`）
- **后端API完整路径**: `http://127.0.0.1:9981/{api_admin}/rest/v1/{模块路由}/{控制器路径}`

## 3. API接口类型

### 3.1 前端API（无需登录）
**特点**: 继承 `FrontendRestController`，无需登录认证

**访问格式**: 
- 完整URL: `http://127.0.0.1:9981/api/rest/v1/{模块路由}/{控制器路径}`
- 模块路由格式: `{模块router}/rest/v1/{控制器名}/{方法名}`

**示例**:
```bash
# 通用格式
http://127.0.0.1:9981/api/rest/v1/{模块router}/{控制器名}/{方法名}
POST /api/rest/v1/{模块router}/{控制器名}/{方法名}
{
  "param1": "value1",
  "param2": "value2"
}
```

### 3.2 后端API（需要登录）
**特点**: 继承 `BackendRestController`，需要管理员登录

**认证机制**：
1. 自动检查登录状态（在 `BackendRestController` 构造函数中）
2. 如果未登录，返回 401 错误：`{"msg": "请先登录", "data": "", "code": 401}`
3. 支持通过 session ID 自动登录（如果 session 有效）
4. 需要先访问后端管理页面登录，或通过 API 登录接口获取 session

**访问格式**:
- 完整URL: `http://127.0.0.1:9981/{api_admin}/rest/v1/{模块路由}/{控制器路径}`
- 替换 `{api_admin}` 为 `app/etc/env.php` 中配置的实际值

**示例**:
```bash
# 通用格式
http://127.0.0.1:9981/{api_admin}/rest/v1/{模块router}/{控制器名}/{方法名}
POST /{api_admin}/rest/v1/{模块router}/{控制器名}/{方法名}
{
  "param1": "value1",
  "param2": "value2"
}
```

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
框架提供了 `window.api()` 辅助函数:

**重要提示**：
- `window.api(path)` 会根据当前页面类型（前端/后端）自动选择正确的 `api_host`
- 前端页面：`api_host = http://127.0.0.1:9981/api/`
- 后端页面：`api_host = http://127.0.0.1:9981/{api_admin}/`
- **路径参数需要包含完整的路由路径（包括 `/rest/v1/` 部分）**

```javascript
// 前端API调用 (无需登录)
// 注意：path 参数需要包含完整的路由路径
window.api('{模块router}/rest/v1/{控制器名}/{方法名}')  
// 生成: http://127.0.0.1:9981/api/{模块router}/rest/v1/{控制器名}/{方法名}

// 后端API调用 (需要登录)
// 注意：后端页面中，api_host 已包含 {api_admin} 前缀
window.api('{模块router}/rest/v1/{控制器名}/{方法名}')  
// 生成: http://127.0.0.1:9981/{api_admin}/{模块router}/rest/v1/{控制器名}/{方法名}
```

### 6.2 API调用参数
```javascript
// 通用API调用示例
fetch(window.api('{模块router}/rest/v1/{控制器名}/{方法名}'), {
    method: 'POST',  // 或 GET, PUT, DELETE
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        param1: 'value1',
        param2: 'value2'
    })
})
.then(response => response.json())
.then(data => {
    console.log('响应数据:', data);
})
.catch(error => {
    console.error('请求错误:', error);
});
```

**注意**：`window.api()` 只返回URL字符串，需要使用 `fetch()` 或其他HTTP客户端发送请求。

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
- **路径结构**: `{base}/{rest}/{version}/{module}/{controller}`
- **参数格式**: JSON格式的请求体

### 8.3 实际使用示例
```bash
# 启动服务器（后台）
php bin/w server:start

# 测试前端API（通用格式）
curl -X POST "http://127.0.0.1:9981/api/rest/v1/{模块router}/{控制器名}/{方法名}" \
  -H "Content-Type: application/json" \
  -d '{"param1": "value1", "param2": "value2"}'

# 测试后端API（需要先登录获取session）
curl -X POST "http://127.0.0.1:9981/{api_admin}/rest/v1/{模块router}/{控制器名}/{方法名}" \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=your_session_id" \
  -d '{"param1": "value1", "param2": "value2"}'
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

1. **前端API**: `http://127.0.0.1:9981/api/rest/v1/{模块路由}/{接口路径}`
2. **后端API**: `http://127.0.0.1:9981/{api_admin}/rest/v1/{模块路由}/{接口路径}`
3. **调用方式**: 标准REST API格式
4. **参数格式**: JSON请求体
5. **认证方式**: 前端无需登录，后端需要管理员认证

接口架构完整且规范，各模块可根据此规范实现自己的API接口。
