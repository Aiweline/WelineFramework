# API 接口文档

## REST API 端点

### 基础信息

- **Base URL**: `http://127.0.0.1:9981/<ENC_API>/rest`
- **认证方式**: Cookie Session (需先登录后台)
- **响应格式**: JSON
- **字符编码**: UTF-8

---

## 模型管理 API

### 1. 获取模型列表

**GET** `/ai/rest/ai/models`

获取所有可用的AI模型列表。

**请求参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| page | int | 否 | 页码，默认1 |
| page_size | int | 否 | 每页数量，默认20 |
| supplier | string | 否 | 供应商筛选 |
| is_active | int | 否 | 是否激活 (0/1) |

**响应示例**:
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
        "version": "0613",
        "max_tokens": 4096,
        "is_active": 1,
        "is_default": 0,
        "created_at": 1698123456
      }
    ],
    "total": 5,
    "page": 1,
    "page_size": 20
  }
}
```

### 2. 获取模型详情

**GET** `/ai/rest/ai/models/:id`

获取指定模型的详细信息。

**路径参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 模型ID |

**响应示例**:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "supplier": "OpenAI",
    "model_code": "gpt-3.5-turbo",
    "name": "GPT-3.5 Turbo",
    "description": "最新的GPT-3.5模型",
    "version": "0613",
    "capabilities": ["chat", "completion"],
    "max_tokens": 4096,
    "cost_per_token": 0.000002,
    "config": {
      "temperature": 0.7,
      "top_p": 1.0
    },
    "is_active": 1,
    "created_at": 1698123456
  }
}
```

### 3. 扫描供应商模型

**POST** `/ai/rest/ai/models/collect`

从供应商API扫描并导入新模型。

**请求体**:
```json
{
  "supplier": "OpenAI",
  "force": false
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "成功收集 12 个模型",
  "data": {
    "collected": 12,
    "skipped": 3,
    "failed": 0
  }
}
```

---

## API密钥管理 API

### 1. 获取密钥列表

**GET** `/ai/rest/ai/apikeys`

获取当前用户的API密钥列表。

**请求参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| status | string | 否 | 状态筛选 (pending/approved/rejected) |

**响应示例**:
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "name": "开发测试密钥",
        "token": "weline_***************abc",
        "status": "approved",
        "quota_monthly": 1000.00,
        "usage_monthly": 45.32,
        "created_at": 1698123456,
        "expires_at": null
      }
    ],
    "total": 3
  }
}
```

### 2. 创建API密钥

**POST** `/ai/rest/ai/apikeys`

创建新的API密钥。

**请求体**:
```json
{
  "name": "生产环境密钥",
  "quota_monthly": 5000.00,
  "expires_at": null
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "API密钥创建成功",
  "data": {
    "id": 2,
    "name": "生产环境密钥",
    "token": "weline_abc123def456ghi789jkl",
    "status": "pending",
    "quota_monthly": 5000.00
  },
  "warning": "请妥善保管密钥，此密钥只显示一次！"
}
```

### 3. 撤销API密钥

**DELETE** `/ai/rest/ai/apikeys/:id`

撤销指定的API密钥。

**路径参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 密钥ID |

**响应示例**:
```json
{
  "success": true,
  "message": "API密钥已撤销"
}
```

---

## AI助手 API

### 1. 获取助手列表

**GET** `/ai/rest/ai/assistants`

获取可用的AI助手列表。

**请求参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| is_public | int | 否 | 是否公开 (0/1) |
| status | string | 否 | 状态 (active/inactive) |

**响应示例**:
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "name": "代码助手",
        "description": "帮助编写和优化代码",
        "model_id": 1,
        "is_public": 1,
        "usage_count": 156,
        "status": "active"
      }
    ]
  }
}
```

### 2. 与助手对话

**POST** `/ai/rest/ai/assistants/:id/chat`

向指定助手发送消息。

**路径参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 助手ID |

**请求体**:
```json
{
  "message": "帮我写一个快速排序函数",
  "context": {
    "language": "php",
    "style": "modern"
  }
}
```

**响应示例**:
```json
{
  "success": true,
  "data": {
    "response": "当然！这里是一个现代PHP快速排序实现：\n\n```php\nfunction quickSort(array $arr): array {\n    ...\n}\n```",
    "usage": {
      "tokens": 245,
      "cost": 0.00049
    }
  }
}
```

---

## 场景适配器 API

### 1. 获取适配器列表

**GET** `/ai/rest/ai/adapters`

获取所有场景适配器。

**响应示例**:
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "code": "code_generation",
        "name": "代码生成适配器",
        "version": "1.0.0",
        "supported_models": ["chat", "completion"],
        "is_active": 1
      },
      {
        "id": 2,
        "code": "translation",
        "name": "翻译适配器",
        "version": "1.0.0",
        "supported_models": ["chat"],
        "is_active": 1
      }
    ]
  }
}
```

### 2. 使用适配器

**POST** `/ai/rest/ai/adapters/:code/execute`

通过适配器执行AI任务。

**路径参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| code | string | 是 | 适配器代码 |

**请求体（代码生成）**:
```json
{
  "language": "php",
  "description": "实现一个单例模式类",
  "context": {
    "namespace": "App\\Patterns",
    "use_strict_types": true
  }
}
```

**请求体（翻译）**:
```json
{
  "text": "Hello, World!",
  "source_lang": "en",
  "target_lang": "zh-CN",
  "strategy": "professional"
}
```

**响应示例**:
```json
{
  "success": true,
  "data": {
    "result": "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Patterns;\n\nclass Singleton {...}",
    "adapter": "code_generation",
    "model_used": "gpt-3.5-turbo",
    "tokens_used": 312,
    "cost": 0.000624
  }
}
```

---

## 监控与统计 API

### 1. 获取使用统计

**GET** `/ai/rest/ai/stats`

获取AI服务使用统计。

**请求参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| start_date | string | 否 | 开始日期 (YYYY-MM-DD) |
| end_date | string | 否 | 结束日期 (YYYY-MM-DD) |
| model_id | int | 否 | 模型ID |

**响应示例**:
```json
{
  "success": true,
  "data": {
    "overview": {
      "total_requests": 1250,
      "total_tokens": 456789,
      "total_cost": 91.36,
      "avg_response_time": 1.23
    },
    "by_model": [
      {
        "model": "gpt-3.5-turbo",
        "requests": 980,
        "tokens": 356789,
        "cost": 71.36
      }
    ],
    "daily_trend": [
      {
        "date": "2025-10-20",
        "requests": 125,
        "cost": 9.13
      }
    ]
  }
}
```

### 2. 获取模型性能

**GET** `/ai/rest/ai/monitoring/models/:id`

获取指定模型的性能监控数据。

**路径参数**:
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 模型ID |

**响应示例**:
```json
{
  "success": true,
  "data": {
    "model_id": 1,
    "health_status": "healthy",
    "metrics": {
      "avg_response_time": 1.23,
      "p95_response_time": 2.45,
      "p99_response_time": 3.67,
      "success_rate": 99.5,
      "error_rate": 0.5
    },
    "recent_errors": []
  }
}
```

---

## 错误码说明

| 错误码 | 说明 |
|--------|------|
| 200 | 成功 |
| 400 | 请求参数错误 |
| 401 | 未授权（未登录） |
| 403 | 权限不足 |
| 404 | 资源不存在 |
| 429 | 请求过于频繁 |
| 500 | 服务器内部错误 |
| 503 | 服务不可用 |

## 测试API

使用`http:request`命令测试API：

```bash
# 测试模型列表
php bin/w http:request ai/rest/ai/models -b --login -n=1

# 测试密钥列表
php bin/w http:request ai/rest/ai/apikeys -b --login -n=1

# 测试统计数据
php bin/w http:request ai/rest/ai/stats -b --login -n=1
```

---

**提示**: 所有API都需要先登录后台才能访问。使用 `--login` 参数自动处理认证。

