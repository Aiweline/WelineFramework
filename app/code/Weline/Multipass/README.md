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

