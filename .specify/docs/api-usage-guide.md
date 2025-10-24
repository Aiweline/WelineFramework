# AI API计费系统使用指南

## 📚 概述

WelineFramework AI模块提供完整的API计费系统，支持：
- 用户余额管理
- API密钥认证
- 精确Token计费
- 配额控制
- 调用日志追踪

## 🚀 快速开始

### 1. 用户充值

访问后台充值页面：
```
/ai/backend/recharge
```

功能：
- 查看账户余额
- 选择充值套餐（10/50/100/500/1000/5000元）
- 选择支付方式（支付宝/微信/银行转账）
- 查看充值记录

### 2. 创建API密钥

访问API密钥管理页面：
```
/ai/backend/apikey
```

创建步骤：
1. 点击"创建API密钥"
2. 填写密钥名称
3. 选择用户和租户
4. 设置配额限制：
   - **每日配额**: 每日最大消费金额（单位：元）
   - **每月配额**: 每月最大消费金额（单位：元）
5. 保存并获取API密钥

**示例**：
- 名称：`测试密钥`
- 每日配额：`100`（元）
- 每月配额：`3000`（元）

### 3. 调用AI服务

#### 3.1 认证方式

**方式1：Authorization Header（推荐）**
```bash
Authorization: Bearer YOUR_API_KEY
```

**方式2：X-API-Key Header**
```bash
X-API-Key: YOUR_API_KEY
```

**方式3：Query Parameter**
```bash
?api_key=YOUR_API_KEY
```

#### 3.2 Chat Completions API

**端点**：
```
POST /ai/api/v1/chat/completions
```

**请求示例**：
```bash
curl -X POST http://your-domain.com/ai/api/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -d '{
    "model": "gpt-4",
    "messages": [
      {
        "role": "user",
        "content": "你好，请介绍一下自己"
      }
    ],
    "temperature": 0.7,
    "max_tokens": 1000
  }'
```

**请求参数**：
- `model` (必需): 模型代码，如 `gpt-4`, `gpt-3.5-turbo`, `deepseek-v3.1`
- `messages` (必需): 消息数组
  - `role`: `system`, `user`, 或 `assistant`
  - `content`: 消息内容
- `temperature` (可选): 温度参数 (0-2)，默认 0.7
- `max_tokens` (可选): 最大生成Token数

**响应示例**：
```json
{
  "id": "chatcmpl-abc123",
  "object": "chat.completion",
  "created": 1704067200,
  "model": "gpt-4",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "你好！我是AI助手..."
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 15,
    "completion_tokens": 50,
    "total_tokens": 65,
    "cost": {
      "prompt_cost": 0.00045,
      "completion_cost": 0.0015,
      "total_cost": 0.00195,
      "currency": "CNY"
    }
  }
}
```

## 💰 计费说明

### 计费公式

```
总费用 = (输入Token数 / 1000) × 模型单价 + (输出Token数 / 1000) × 模型单价
```

### 模型价格表

| 模型 | 单价（元/1000 tokens） |
|------|---------------------|
| GPT-4 | 0.0015 |
| GPT-3.5 Turbo | 0.0005 |
| DeepSeek-V3.1 | 0.000001 |
| DeepSeek-R1 | 0.0000008 |

**示例计算**：
- 模型：GPT-4
- 输入：50 tokens
- 输出：100 tokens
- 费用：`(50/1000 × 0.0015) + (100/1000 × 0.0015) = 0.000075 + 0.00015 = 0.000225` 元

### 配额控制

**每日配额**：
- 用户设置的每日消费上限
- 每天0点自动重置
- 达到上限后拒绝调用

**每月配额**：
- 用户设置的每月消费上限
- 每月1号0点自动重置
- 达到上限后拒绝调用

## 🔒 安全建议

### API密钥安全

1. **不要在前端代码中暴露API密钥**
   ```javascript
   // ❌ 错误示例
   const apiKey = "sk_live_abc123...";
   
   // ✅ 正确示例：通过后端代理
   fetch('/api/proxy/ai-chat', {
     method: 'POST',
     body: JSON.stringify(data)
   });
   ```

2. **定期轮换API密钥**
   - 建议每3个月更换一次
   - 如果怀疑泄露，立即吊销

3. **设置合理的配额**
   - 根据实际需求设置每日/每月配额
   - 避免设置过高的配额导致异常消费

4. **监控API使用情况**
   - 定期检查调用日志
   - 关注异常调用模式
   - 设置余额告警

## 📊 监控和日志

### 查看API调用日志

访问：
```
/ai/backend/apikey
```

功能：
- 查看每个密钥的调用统计
- 查看消费金额
- 查看配额使用情况

### 账单查询

访问：
```
/ai/backend/recharge
```

功能：
- 查看账户余额
- 查看累计充值和消费
- 查看充值记录

## 🛠️ 错误处理

### 常见错误码

| 错误码 | 含义 | 解决方法 |
|--------|------|---------|
| 401 | 缺少或无效的API密钥 | 检查API密钥是否正确 |
| 402 | 余额不足 | 充值账户余额 |
| 403 | API密钥未激活或已禁用 | 联系管理员激活 |
| 429 | 配额已用尽 | 等待配额重置或增加配额 |
| 500 | 服务器错误 | 联系技术支持 |

### 错误响应格式

```json
{
  "error": {
    "message": "账户余额不足，请充值",
    "type": "authentication_error",
    "code": 402
  }
}
```

## 🔄 最佳实践

### 1. 错误重试

```javascript
async function callAIWithRetry(data, maxRetries = 3) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      const response = await fetch('/ai/api/v1/chat/completions', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${apiKey}`
        },
        body: JSON.stringify(data)
      });
      
      if (response.ok) {
        return await response.json();
      }
      
      if (response.status === 429) {
        // 配额用尽，等待后重试
        await new Promise(r => setTimeout(r, 1000 * (i + 1)));
        continue;
      }
      
      throw new Error(await response.text());
    } catch (error) {
      if (i === maxRetries - 1) throw error;
    }
  }
}
```

### 2. 成本优化

1. **选择合适的模型**
   - 简单任务使用 GPT-3.5 或 DeepSeek
   - 复杂任务使用 GPT-4

2. **控制Token使用**
   - 设置合理的 `max_tokens`
   - 精简提示词
   - 避免重复调用

3. **使用缓存**
   - 缓存常见问题的回答
   - 避免重复计算

### 3. 余额监控

```javascript
// 定期检查余额
async function checkBalance() {
  const response = await fetch('/ai/api/account/balance', {
    headers: {
      'Authorization': `Bearer ${apiKey}`
    }
  });
  
  const data = await response.json();
  
  if (data.balance < 10) {
    alert('余额不足10元，请及时充值！');
  }
}

// 每小时检查一次
setInterval(checkBalance, 3600000);
```

## 📞 技术支持

如有问题，请联系：
- 技术支持邮箱：support@example.com
- 文档中心：https://docs.example.com
- GitHub Issues：https://github.com/example/weline-ai

