# Weline AI 模块 API 文档

**版本**: 1.0.0  
**更新日期**: 2025-10-10

---

## 目录

1. [认证](#认证)
2. [Chat API](#chat-api)
3. [Model API](#model-api)
4. [API Key API](#api-key-api)
5. [Assistant API](#assistant-api)
6. [错误处理](#错误处理)
7. [速率限制](#速率限制)
8. [最佳实践](#最佳实践)

---

## 认证

所有 API 请求都需要有效的 API Key 进行认证。

### 认证方式

**Bearer Token**:
```http
Authorization: Bearer sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**直接使用 API Key**:
```http
Authorization: sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### 获取 API Key

通过后台管理界面或 API Key 管理接口创建 API Key。

---

## Chat API

### POST /api/v1/chat

发送聊天请求到 AI 模型。

**请求头**:
```http
Content-Type: application/json
Authorization: Bearer YOUR_API_KEY
X-API-Version: v1
X-API-Locale: zh-CN
```

**请求体**:
```json
{
  "prompt": "你好，AI！",
  "model_code": "gpt-3.5-turbo",
  "session_id": "user-session-123",
  "stream": false,
  "parameters": {
    "temperature": 0.7,
    "max_tokens": 2000,
    "top_p": 0.9
  }
}
```

**参数说明**:
- `prompt` (string, 必填): 用户输入的提示词
- `model_code` (string, 必填): 模型代码
- `session_id` (string, 可选): 会话ID，用于上下文关联
- `stream` (boolean, 可选): 是否流式返回，默认 false
- `parameters` (object, 可选): 模型参数配置

**成功响应** (200 OK):
```json
{
  "success": true,
  "data": {
    "response": "你好！有什么可以帮助你的吗？",
    "model_code": "gpt-3.5-turbo",
    "session_id": "user-session-123",
    "usage": {
      "prompt_tokens": 10,
      "completion_tokens": 15,
      "total_tokens": 25
    },
    "locale": "zh-CN",
    "version": "v1"
  }
}
```

**错误响应** (400 Bad Request):
```json
{
  "success": false,
  "error": {
    "code": "INVALID_MODEL",
    "message": "指定的模型代码无效"
  }
}
```

---

## Model API

### GET /api/v1/model/{id}

获取指定模型的详细信息。

**请求头**:
```http
Authorization: Bearer YOUR_API_KEY
X-API-Version: v1
```

**路径参数**:
- `id` (integer): 模型ID

**成功响应** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "supplier": "OpenAI",
    "model_code": "gpt-3.5-turbo",
    "name": "GPT-3.5 Turbo",
    "version": "1.0",
    "is_copy": false,
    "origin_model_id": null,
    "config": {
      "temperature": 0.7,
      "max_tokens": 4096
    },
    "capabilities": {
      "chat": true,
      "completion": true,
      "streaming": true
    },
    "max_tokens": 4096,
    "cost_per_token": 0.000002,
    "status": "active",
    "created_at": "2025-10-10 10:00:00",
    "updated_at": "2025-10-10 10:00:00"
  }
}
```

### GET /api/v1/model

获取模型列表。

**查询参数**:
- `status` (string, 可选): 过滤状态 (active, deprecated, maintenance)
- `supplier` (string, 可选): 过滤供应商
- `page` (integer, 可选): 页码，默认 1
- `per_page` (integer, 可选): 每页数量，默认 20

**成功响应** (200 OK):
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "supplier": "OpenAI",
        "model_code": "gpt-3.5-turbo",
        "name": "GPT-3.5 Turbo",
        "status": "active"
      }
    ],
    "pagination": {
      "total": 100,
      "page": 1,
      "per_page": 20,
      "total_pages": 5
    }
  }
}
```

### POST /api/v1/model/{id}/copy

拷贝现有模型创建自定义模型。

**请求体**:
```json
{
  "new_name": "My Custom GPT-3.5 Turbo",
  "config": {
    "temperature": 0.8,
    "max_tokens": 2000
  }
}
```

**成功响应** (200 OK):
```json
{
  "success": true,
  "data": {
    "model_id": 101,
    "origin_model_id": 1,
    "name": "My Custom GPT-3.5 Turbo",
    "is_copy": true,
    "config": {
      "temperature": 0.8,
      "max_tokens": 2000
    }
  }
}
```

---

## API Key API

### POST /api/v1/api-key

创建新的 API Key。

**请求头**:
```http
Content-Type: application/json
Authorization: Bearer YOUR_API_KEY
```

**请求体**:
```json
{
  "name": "My New API Key",
  "quota_daily": 1000,
  "quota_monthly": 30000,
  "expires_at": "2026-10-10 00:00:00"
}
```

**参数说明**:
- `name` (string, 必填): API Key 名称
- `quota_daily` (integer, 可选): 每日配额限制
- `quota_monthly` (integer, 可选): 每月配额限制
- `expires_at` (string, 可选): 过期时间

**成功响应** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 201,
    "name": "My New API Key",
    "token": "sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "status": "approved",
    "quota_daily": 1000,
    "quota_monthly": 30000,
    "usage_daily": 0,
    "usage_monthly": 0,
    "expires_at": "2026-10-10 00:00:00",
    "created_at": "2025-10-10 10:00:00"
  }
}
```

⚠️ **重要**: `token` 字段只在创建时返回一次，请妥善保管！

### GET /api/v1/api-key

获取当前用户的 API Key 列表。

**查询参数**:
- `status` (string, 可选): 过滤状态 (pending, approved, suspended, revoked)

**成功响应** (200 OK):
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 201,
        "name": "My API Key",
        "token_preview": "sk-****...****",
        "status": "approved",
        "usage_daily": 50,
        "quota_daily": 1000,
        "usage_monthly": 1500,
        "quota_monthly": 30000,
        "last_used_at": "2025-10-10 09:30:00",
        "created_at": "2025-10-10 08:00:00"
      }
    ]
  }
}
```

### DELETE /api/v1/api-key/{id}

撤销 API Key。

**成功响应** (200 OK):
```json
{
  "success": true,
  "message": "API Key 已成功撤销"
}
```

---

## Assistant API

### POST /api/v1/assistant

创建新的 AI 助手。

**请求体**:
```json
{
  "name": "客服助手",
  "description": "专业的客服支持助手",
  "prompt_template": "你是一个专业的客服人员。用户问题：{input}",
  "model_id": 1,
  "config": {
    "temperature": 0.7,
    "max_tokens": 2000
  },
  "is_public": false
}
```

**成功响应** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 301,
    "name": "客服助手",
    "description": "专业的客服支持助手",
    "prompt_template": "你是一个专业的客服人员。用户问题：{input}",
    "model_id": 1,
    "status": "active",
    "usage_count": 0,
    "created_at": "2025-10-10 10:00:00"
  }
}
```

### GET /api/v1/assistant

获取助手列表。

### GET /api/v1/assistant/{id}

获取助手详情。

### PUT /api/v1/assistant/{id}

更新助手配置。

### DELETE /api/v1/assistant/{id}

删除助手。

---

## 错误处理

### 错误响应格式

所有错误响应遵循统一格式：

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "错误描述",
    "details": {}
  }
}
```

### 常见错误码

| HTTP 状态码 | 错误码 | 描述 |
|------------|--------|------|
| 400 | INVALID_REQUEST | 请求参数无效 |
| 401 | UNAUTHORIZED | 未授权访问，API Key 无效 |
| 403 | FORBIDDEN | 禁止访问，权限不足 |
| 404 | NOT_FOUND | 资源不存在 |
| 429 | QUOTA_EXCEEDED | 配额超限 |
| 429 | RATE_LIMIT_EXCEEDED | 速率限制超限 |
| 500 | INTERNAL_ERROR | 服务器内部错误 |
| 503 | SERVICE_UNAVAILABLE | 服务暂不可用 |

---

## 速率限制

API 实施以下速率限制：

### 默认限制

- **每秒请求数**: 10 requests/second
- **每分钟请求数**: 100 requests/minute
- **每小时请求数**: 1000 requests/hour

### 响应头

速率限制信息通过响应头返回：

```http
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1696982400
```

### 超限响应

```json
{
  "success": false,
  "error": {
    "code": "RATE_LIMIT_EXCEEDED",
    "message": "请求过于频繁，请稍后再试",
    "retry_after": 60
  }
}
```

---

## 最佳实践

### 1. 错误处理

始终检查 `success` 字段：

```javascript
const response = await fetch('/api/v1/chat', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${apiKey}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify(requestData)
});

const data = await response.json();

if (!data.success) {
  console.error('API Error:', data.error);
  // 处理错误
}
```

### 2. 重试策略

对于临时错误（500, 503），实施指数退避重试：

```javascript
async function fetchWithRetry(url, options, maxRetries = 3) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      const response = await fetch(url, options);
      if (response.ok) return response;
      
      if (i < maxRetries - 1 && [500, 503].includes(response.status)) {
        await new Promise(resolve => setTimeout(resolve, Math.pow(2, i) * 1000));
        continue;
      }
      
      throw new Error(`HTTP ${response.status}`);
    } catch (error) {
      if (i === maxRetries - 1) throw error;
    }
  }
}
```

### 3. 配额监控

定期检查配额使用情况：

```javascript
const response = await fetch('/api/v1/api-key', {
  headers: { 'Authorization': `Bearer ${apiKey}` }
});

const { data } = await response.json();
const usagePercent = (data.usage_monthly / data.quota_monthly) * 100;

if (usagePercent > 80) {
  console.warn('配额使用率已超过 80%');
}
```

### 4. 缓存响应

对于相同的请求，使用缓存减少 API 调用：

```javascript
const cache = new Map();

async function getCachedResponse(prompt, modelCode) {
  const cacheKey = `${modelCode}:${prompt}`;
  
  if (cache.has(cacheKey)) {
    return cache.get(cacheKey);
  }
  
  const response = await fetch('/api/v1/chat', {
    method: 'POST',
    body: JSON.stringify({ prompt, model_code: modelCode })
  });
  
  const data = await response.json();
  cache.set(cacheKey, data);
  
  return data;
}
```

### 5. 性能优化

- 使用流式响应处理大量输出
- 批量请求多个资源
- 启用 HTTP/2 连接复用
- 使用 CDN 缓存静态响应

---

## 支持与反馈

- **文档**: [https://docs.weline.com/ai](https://docs.weline.com/ai)
- **问题反馈**: [https://github.com/weline/ai/issues](https://github.com/weline/ai/issues)
- **技术支持**: support@aiweline.com

---

**更新历史**:
- 2025-10-10: 初始版本发布

