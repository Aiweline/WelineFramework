# API文档

## 📋 概述

Weline_AutoLeadAgent 提供RESTful API接口，支持Token认证和JSON格式数据交换。

**Base URL**: `/api/v1/auto-lead-agent`

**认证方式**: Token认证（通过 `X-Agent-Token` 请求头）

## 🔐 认证

### 生成Token

生成新的Agent Token。

**Endpoint**: `POST /api/v1/auto-lead-agent/token`

**请求头**:
```
Content-Type: application/json
```

**请求体**:
```json
{
  "domain": "your-domain.com",
  "ttl": 3600
}
```

**参数说明**:
- `domain` (string, 必需): 授权域名
- `ttl` (integer, 可选): Token有效期（秒），默认3600

**响应示例**:
```json
{
  "code": 200,
  "msg": "Token生成成功",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "domain": "your-domain.com",
    "ttl": 3600
  }
}
```

**错误响应**:
```json
{
  "code": 400,
  "msg": "域名参数不能为空",
  "data": []
}
```

### 验证Token

验证Token是否有效。

**Endpoint**: `GET /api/v1/auto-lead-agent/token/validate`

**请求参数**:
- `token` (string, 必需): Token字符串
- `domain` (string, 必需): 当前域名

**请求头**:
```
X-Agent-Token: your-token
```

**响应示例**:
```json
{
  "code": 200,
  "msg": "Token验证成功",
  "data": {
    "valid": true,
    "token_info": {
      "token_id": 1,
      "domain": "your-domain.com",
      "exp": 1234567890,
      "wasm_hash": "abc123...",
      "expires_at": "2024-01-01 12:00:00",
      "created_at": "2024-01-01 11:00:00"
    }
  }
}
```

## 🔍 搜索任务API

### 创建搜索任务

创建一个新的搜索任务。

**Endpoint**: `POST /api/v1/auto-lead-agent/search/create`

**请求头**:
```
Content-Type: application/json
X-Agent-Token: your-token
```

**请求体**:
```json
{
  "store_id": 1
}
```

**参数说明**:
- `store_id` (integer, 必需): 店铺ID

**响应示例**:
```json
{
  "code": 200,
  "msg": "搜索任务创建成功",
  "data": {
    "task_id": 1,
    "store_id": 1
  }
}
```

### 获取搜索结果

获取指定任务的搜索结果。

**Endpoint**: `GET /api/v1/auto-lead-agent/search/{taskId}`

**请求头**:
```
X-Agent-Token: your-token
```

**路径参数**:
- `taskId` (integer, 必需): 任务ID

**响应示例**:
```json
{
  "code": 200,
  "msg": "获取搜索结果成功",
  "data": {
    "task_id": 1,
    "store_id": 1,
    "status": "completed",
    "progress": 100.00,
    "candidates": [
      {
        "candidate_id": 1,
        "score": 85.50,
        "source_url": "https://example.com",
        "status": "pending",
        "profile_data": {
          "industry": "零售",
          "target_customers": ["个人", "家庭"],
          "product_features": ["价格", "质量"]
        }
      }
    ],
    "candidate_count": 1,
    "created_at": "2024-01-01 12:00:00",
    "updated_at": "2024-01-01 12:30:00"
  }
}
```

## 🔧 WASM API

### 获取WASM哈希

获取最新的WASM文件哈希值。

**Endpoint**: `GET /api/v1/auto-lead-agent/wasm/hash`

**请求头**:
```
X-Agent-Token: your-token
```

**响应示例**:
```json
{
  "code": 200,
  "msg": "获取WASM哈希成功",
  "data": {
    "hash": "abc123def456..."
  }
}
```

### 下载WASM文件

下载WASM文件。

**Endpoint**: `GET /api/v1/auto-lead-agent/wasm/download`

**请求头**:
```
X-Agent-Token: your-token
```

**响应**: 二进制WASM文件

**响应头**:
```
Content-Type: application/wasm
Content-Disposition: attachment; filename="agent-core.wasm"
Content-Length: 123456
```

## 💻 核心代码API

### 获取核心代码

获取核心JavaScript代码（动态加载）。

**Endpoint**: `GET /api/v1/auto-lead-agent/core`

**请求头**:
```
X-Agent-Token: your-token
```

**响应**: JavaScript代码文本

**响应头**:
```
Content-Type: application/javascript
Cache-Control: no-cache, no-store, must-revalidate
```

## 📊 状态码

| 状态码 | 说明 |
|--------|------|
| 200 | 请求成功 |
| 400 | 请求参数错误 |
| 401 | 未授权（Token无效或过期） |
| 404 | 资源不存在 |
| 500 | 服务器内部错误 |

## 🔒 安全说明

1. **Token安全**:
   - Token应通过HTTPS传输
   - Token应定期更新
   - 不要在前端代码中硬编码Token

2. **域名验证**:
   - 每个Token绑定特定域名
   - 跨域请求会被拒绝

3. **WASM验证**:
   - 始终验证WASM文件哈希
   - 确保WASM文件完整性

## 📝 请求示例

### cURL示例

```bash
# 生成Token
curl -X POST http://your-domain/api/v1/auto-lead-agent/token \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "your-domain.com",
    "ttl": 3600
  }'

# 创建搜索任务
curl -X POST http://your-domain/api/v1/auto-lead-agent/search/create \
  -H "Content-Type: application/json" \
  -H "X-Agent-Token: your-token" \
  -d '{
    "store_id": 1
  }'

# 获取搜索结果
curl http://your-domain/api/v1/auto-lead-agent/search/1 \
  -H "X-Agent-Token: your-token"
```

### JavaScript示例

```javascript
// 生成Token
const response = await fetch('/api/v1/auto-lead-agent/token', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    domain: window.location.hostname,
    ttl: 3600
  })
});

const data = await response.json();
const token = data.data.token;

// 创建搜索任务
const taskResponse = await fetch('/api/v1/auto-lead-agent/search/create', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Agent-Token': token,
  },
  body: JSON.stringify({
    store_id: 1
  })
});

const taskData = await taskResponse.json();
const taskId = taskData.data.task_id;
```

## 🔗 相关文档

- [使用指南](./使用指南.md) - 完整使用说明
- [快速开始](./快速开始.md) - 快速入门

