# Weline Multipass 模块

## 概述

Weline Multipass 是一个多通道登录模块，实现了类似 Shopify Multipass 的功能。它允许第三方站点通过加密的 token 安全地实现用户自动登录，支持前端用户（FrontendUser）和后端用户（BackendUser）两种类型。

## 功能特性

- ✅ **类似 Shopify Multipass 的实现**：使用 AES-128-CBC 加密和 HMAC-SHA256 签名
- ✅ **前后端分离**：支持前端用户和后端用户的独立登录
- ✅ **安全加密**：使用站点密钥进行加密和签名验证
- ✅ **灵活的站点管理**：支持多个站点配置，每个站点独立的密钥
- ✅ **API 接口**：提供 REST API 供第三方站点调用生成 token

## 工作原理

1. **站点配置**：在中控模块中配置站点信息和密钥
2. **Token 生成**：第三方站点调用 API 生成加密 token
3. **用户跳转**：第三方站点携带 token 跳转到目标站点
4. **自动登录**：目标站点验证 token 并自动登录用户

## 安装

1. 确保模块已注册到系统中
2. 执行安装命令：
```bash
php bin/w setup:upgrade
```

## 配置站点

1. 访问后台管理：`/admin/center/backend/site/backend`（后端站点）或 `/admin/center/backend/site/frontend`（前端站点）
2. 点击"新增站点"
3. 填写站点信息：
   - **站点名称**：站点的显示名称
   - **站点URL**：站点的完整URL地址
   - **密钥**：用于加密 token 的密钥（至少16个字符，建议32字符）
   - **用户类型**：选择前端或后端
   - **启用状态**：是否启用该站点
4. 保存配置

## 使用方法

### 第三方站点生成 Token

第三方站点需要调用 API 接口生成 token：

**接口地址**：`POST /rest/v1/multipass/token/generate`

**请求参数**：
```json
{
    "site_id": 1,
    "secret_key": "your_secret_key",
    "username": "user123",
    "email": "user@example.com",
    "avatar": "https://example.com/avatar.jpg"
}
```

**返回数据**：
```json
{
    "code": 200,
    "msg": "Token生成成功",
    "data": {
        "token": "encrypted_token_string",
        "login_url": "https://target-site.com/multipass/frontend/multipass?token=xxx&site_id=1",
        "site_id": 1,
        "user_type": "frontend"
    }
}
```

### 前端用户登录

前端站点登录 URL：
```
https://target-site.com/multipass/frontend/multipass?token={token}&site_id={site_id}&return_url=/frontend/account
```

### 后端用户登录

后端站点登录 URL：
```
https://target-site.com/{admin_key}/multipass/backend/multipass?token={token}&site_id={site_id}&return_url=/admin
```

### 参数说明

- `token`：由 API 生成的加密 token（必需）
- `site_id`：站点ID（必需）
- `return_url`：登录成功后的跳转URL（可选，默认为用户中心或后台首页）

## Token 加密说明

Multipass token 使用以下方式加密：

1. **加密算法**：AES-128-CBC
2. **密钥派生**：使用 SHA-256 哈希密钥，取前16字节
3. **签名算法**：HMAC-SHA256
4. **编码方式**：URL 安全的 Base64 编码

Token 结构：
```
[签名(32字节)][IV(16字节)][加密数据(变长)]
```

## 安全注意事项

1. **密钥管理**：
   - 密钥至少16个字符，建议32字符
   - 密钥应妥善保管，不要泄露
   - 每个站点使用独立的密钥

2. **HTTPS 传输**：
   - 生产环境必须使用 HTTPS
   - 避免在 HTTP 环境下传输敏感信息

3. **用户验证**：
   - Token 验证时会检查站点状态
   - 如果用户不存在，系统会返回错误（不自动创建）

4. **时间戳验证**：
   - Token 中包含创建时间戳
   - 可以根据业务需求添加过期时间验证

## API 接口

### 生成 Token

**接口**：`POST /rest/v1/multipass/token/generate`

**参数**：
- `site_id`（必需）：站点ID
- `secret_key`（必需）：站点密钥
- `username`（可选）：用户名
- `email`（可选）：邮箱
- `avatar`（可选）：头像URL
- 其他用户数据字段（可选）

**返回**：包含 token 和 login_url 的 JSON 数据

### 验证 Token（测试用）

**接口**：`POST /rest/v1/multipass/token/verify`

**参数**：
- `token`（必需）：要验证的 token
- `site_id`（必需）：站点ID

**返回**：包含解密后的用户数据的 JSON 数据

## 信任通信与互通授权 MVP

本模块同时提供框架核心通信授权能力，用于 `app`、`skill`、`bbs.a2a`、应用商城等可信域名之间的一键登录、账号关联和资料读取。该能力不替换旧 Multipass 加密登录，而是在同一模块内新增一条授权码 + Token 的互通信任链。

### 后台配置

后台入口：`Multipass管理 -> 互通应用`

可信应用需要配置：

- **应用名称**：展示给用户确认授权。
- **应用类型**：`app`、`skill`、`bbs`、`appstore`、`community` 或 `custom`。
- **回调地址**：授权码换 token 时精确匹配的 `redirect_uri`。
- **可信域名**：用于运营识别和域名信任管理，留空时从回调地址提取。
- **授权范围**：默认包含 `profile.basic`、`profile.email`、`account.bind`。
- **client_secret**：只在创建或轮换时返回一次，数据库只保存哈希。

### 授权流程

1. 可信应用跳转到官网授权页：`GET /multipass/frontend/identity/authorize`
2. 未登录用户先进入 `/customer/account/login`，登录成功后回到授权页。
3. 已登录用户确认授权：`POST /multipass/frontend/identity/authorize`
4. 官网发放短期授权码并回跳可信应用 `redirect_uri?code=...&state=...`
5. 可信应用使用授权码换取 token：`POST /{rest_frontend}/multipass/rest/v1/identity/token`
6. 可信应用使用 `Authorization: Bearer {access_token}` 读取资料：`GET /{rest_frontend}/multipass/rest/v1/identity/userinfo`
7. 可信应用按需回写外部账号绑定：`POST /{rest_frontend}/multipass/rest/v1/identity/bind`

### 子站接入官网登录

BBS、Skill、App、A2A 等 Office 子站更新内核后，可以在后台 `Multipass管理 -> 官网登录提供方` 配置官网生成的 `client_id`、`client_secret`、官网授权地址和官网 REST 地址。配置完成后，Customer 登录页会通过 `Weline_Customer::frontend::account::login::providers` hook 显示“使用官网登录”。

客户端流程：

1. 子站访问 `/multipass/frontend/identity/login`，生成 state 并跳转到官网 `/multipass/frontend/identity/authorize`。
2. 官网用户登录并同意授权后，携带授权码回跳子站 `/multipass/frontend/identity/callback`。
3. 子站用 `client_secret` 向官网 REST `/multipass/rest/v1/identity/token` 换取 token，再读取 `/userinfo`。
4. 子站按官网邮箱查找或创建本地 `Weline_Customer` 账号，写入前台会话，并调用官网 `/bind` 记录外部账号绑定。

目标站回调地址必须与官网“互通应用”中的回调地址完全一致，例如：

```text
https://bbs.example.com/multipass/frontend/identity/callback
```

### 前台授权页

**页面**：`GET /multipass/frontend/identity/authorize`

**参数**：

- `client_id`（必需）：后台生成的应用 Client ID。
- `redirect_uri`（可选）：不传时使用后台配置的默认回调地址；传入时必须精确匹配。
- `scope` 或 `scopes`（可选）：逗号分隔、数组或 JSON 数组。
- `state`（建议）：原样带回下游站点，用于防 CSRF 和恢复登录前状态。

页面会展示当前登录账号、可信应用、可信域名和请求权限。用户确认后直接回跳 `redirect_uri`，取消时回跳 `redirect_uri?error=access_denied`。

### 个人中心授权管理

`Weline_Multipass` 会通过 `account.sidebar` 与 `account.sidebar.content` hook 在 `/customer/account` 注入“授权应用”分区。用户可查看已授权的 App、Skill、BBS、A2A 等可信应用，并可撤销授权。撤销后对应绑定会置为 revoked，存量 Access Token / Refresh Token 会立即失效。

### 授权信息

**接口**：`GET /{rest_frontend}/multipass/rest/v1/identity/authorize`

**参数**：

- `client_id`（必需）：后台生成的应用 Client ID
- `redirect_uri`（可选）：不传时使用后台配置的默认回调地址
- `scopes` 或 `scope`（可选）：逗号分隔、数组或 JSON 数组

**返回**：应用信息、允许授权范围、当前登录状态和当前用户摘要。

### 批准授权

**接口**：`POST /{rest_frontend}/multipass/rest/v1/identity/authorize`

**参数**：

- `client_id`（必需）
- `redirect_uri`（可选，必须与后台配置精确一致）
- `scopes`（可选）
- `state`（可选，原样回传到 `redirect_url`）

**返回**：

```json
{
    "success": true,
    "data": {
        "code": "mpc_xxx",
        "expires_at": 1760000000,
        "redirect_uri": "https://app.example.com/oauth/callback",
        "redirect_url": "https://app.example.com/oauth/callback?code=mpc_xxx&state=abc",
        "scopes": ["profile.basic", "profile.email"]
    }
}
```

### 换取 Token

**接口**：`POST /{rest_frontend}/multipass/rest/v1/identity/token`

**参数**：

- `client_id`（必需）
- `client_secret`（必需）
- `code`（必需）
- `redirect_uri`（可选；传入时必须与授权码内记录一致）

**返回**：

```json
{
    "success": true,
    "data": {
        "access_token": "mpt_xxx",
        "refresh_token": "mpt_xxx",
        "token_type": "Bearer",
        "expires_in": 3600,
        "expire_time": 1760000000,
        "scopes": ["profile.basic", "profile.email"],
        "binding": {
            "binding_id": 1,
            "local_customer_id": 10,
            "external_subject_id": ""
        }
    }
}
```

### 刷新与撤销

- 刷新：`POST /{rest_frontend}/multipass/rest/v1/identity/refresh`
- 撤销：`POST /{rest_frontend}/multipass/rest/v1/identity/revoke`

刷新参数：`client_id`、`client_secret`、`refresh_token`。

撤销参数：`Authorization: Bearer {token}`、`X-API-Token` 或 `token` 参数。

### 用户资料

**接口**：`GET /{rest_frontend}/multipass/rest/v1/identity/userinfo`

**鉴权**：`Authorization: Bearer {access_token}`

返回包含 `sub`、`customer_id`、`username`、`avatar`、应用信息、绑定信息和 token 过期时间；只有授权范围包含 `profile.email` 时返回 `email`。

### 外部账号绑定

**接口**：`POST /{rest_frontend}/multipass/rest/v1/identity/bind`

**鉴权**：`Authorization: Bearer {access_token}`

**参数**：

- `external_subject_id`（必需）：外部系统用户 ID
- `external_display_name`（可选）：外部系统显示名称
- `metadata`（可选）：JSON 对象或表单数组

该接口要求 token scopes 包含 `account.bind`、`appstore.account` 或 `community.account`。

## 示例代码

### PHP 示例

```php
// 生成 token
$data = [
    'site_id' => 1,
    'secret_key' => 'your_secret_key',
    'username' => 'user123',
    'email' => 'user@example.com'
];

$ch = curl_init('https://api.example.com/rest/v1/multipass/token/generate');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$result = json_decode($response, true);

if ($result['code'] === 200) {
    // 跳转到登录URL
    header('Location: ' . $result['data']['login_url']);
    exit;
}
```

### JavaScript 示例

```javascript
// 生成 token
fetch('https://api.example.com/rest/v1/multipass/token/generate', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        site_id: 1,
        secret_key: 'your_secret_key',
        username: 'user123',
        email: 'user@example.com'
    })
})
.then(response => response.json())
.then(data => {
    if (data.code === 200) {
        // 跳转到登录URL
        window.location.href = data.data.login_url;
    }
});
```

## 故障排查

### Token 验证失败

1. 检查站点密钥是否正确
2. 检查站点是否已启用
3. 检查站点ID是否正确
4. 检查 token 是否已过期（如果设置了过期时间）

### 用户登录失败

1. 检查用户是否存在
2. 检查用户状态是否正常（后端用户检查是否启用）
3. 检查用户类型是否匹配（前端/后端）

### API 调用失败

1. 检查 API 地址是否正确
2. 检查请求参数是否完整
3. 检查站点密钥是否正确
4. 检查站点是否已启用

## 技术支持

如有问题，请访问：
- 论坛：https://bbs.aiweline.com
- 邮箱：aiweline@qq.com

## Runtime Route Note

Identity endpoints must include the configured frontend REST prefix before
`/multipass/rest/v1/identity/*`. In this local environment the prefix is
`api123`, so the browser smoke URL is:

```text
/api123/multipass/rest/v1/identity/authorize
```

