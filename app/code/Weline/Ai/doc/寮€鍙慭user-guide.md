# Weline AI 模块用户指南

**版本**: 1.0.0  
**更新日期**: 2025-10-10

---

## 目录

1. [快速开始](#快速开始)
2. [模型管理](#模型管理)
3. [API Key 管理](#api-key-管理)
4. [助手管理](#助手管理)
5. [聊天功能](#聊天功能)
6. [高级功能](#高级功能)
7. [常见问题](#常见问题)
8. [故障排查](#故障排查)

---

## 快速开始

### 系统要求

- PHP 8.2 或更高版本
- WelineFramework 已安装
- MySQL 5.7+ 或 SQLite 3+
- Redis (可选，用于缓存)

### 安装步骤

1. **安装模块**

```bash
php bin/w setup:upgrade
```

2. **验证安装**

```bash
php bin/w module:status Weline_Ai
```

应该看到：
```
Weline_Ai: Enabled
```

3. **创建第一个 API Key**

访问后台管理界面：
```
http://your-domain.com/admin/ai/api-key/new
```

填写表单创建 API Key。

4. **测试 API**

使用命令行工具测试：

```bash
php bin/w http:request POST /api/v1/chat \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "你好，AI！",
    "model_code": "gpt-3.5-turbo"
  }'
```

---

## 模型管理

### 查看可用模型

**后台界面**:
1. 登录后台
2. 导航到 `AI 管理 > 模型管理`
3. 查看所有可用模型列表

**API 方式**:
```bash
php bin/w http:request GET /api/v1/model \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### 创建自定义模型

通过拷贝现有模型创建自定义配置：

1. **后台界面**:
   - 进入 `AI 管理 > 模型管理`
   - 点击模型右侧的 `拷贝` 按钮
   - 设置自定义名称和参数
   - 保存

2. **API 方式**:
```bash
php bin/w http:request POST /api/v1/model/1/copy \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "new_name": "我的定制 GPT-3.5",
    "config": {
      "temperature": 0.8,
      "max_tokens": 2000
    }
  }'
```

### 模型参数说明

| 参数 | 类型 | 范围 | 说明 |
|------|------|------|------|
| temperature | float | 0.0-2.0 | 控制输出随机性，值越大越随机 |
| max_tokens | integer | 1-4096 | 最大生成令牌数 |
| top_p | float | 0.0-1.0 | 核采样参数 |
| frequency_penalty | float | -2.0-2.0 | 降低重复内容的频率 |
| presence_penalty | float | -2.0-2.0 | 鼓励谈论新话题 |

### 模型状态管理

模型有三种状态：

- **active**: 激活状态，可正常使用
- **deprecated**: 已弃用，建议迁移到新版本
- **maintenance**: 维护中，暂时不可用

---

## API Key 管理

### 创建 API Key

**后台创建**:

1. 导航到 `AI 管理 > API Key 管理`
2. 点击 `创建新 API Key`
3. 填写表单：
   - **名称**: 为 API Key 命名（如"生产环境密钥"）
   - **每日配额**: 每天最多调用次数（可选）
   - **每月配额**: 每月最多调用次数（可选）
   - **过期时间**: API Key 过期时间（可选）
4. 点击保存

⚠️ **重要提示**: API Key 只会在创建时显示一次，请立即复制并妥善保管！

**API 创建**:
```bash
php bin/w http:request POST /api/v1/api-key \
  -H "Authorization: Bearer YOUR_MASTER_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Production API Key",
    "quota_daily": 1000,
    "quota_monthly": 30000,
    "expires_at": "2026-10-10 00:00:00"
  }'
```

### 查看 API Key 使用情况

**后台查看**:
1. 导航到 `AI 管理 > API Key 管理`
2. 查看列表中的使用量统计
3. 点击 `详情` 查看详细使用记录

**API 查看**:
```bash
php bin/w http:request GET /api/v1/api-key \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### 管理 API Key 状态

API Key 有以下状态：

- **pending**: 等待审核
- **approved**: 已批准，可正常使用
- **suspended**: 已暂停，暂时不可用
- **revoked**: 已撤销，永久失效

**暂停 API Key**:
```bash
php bin/w http:request PUT /api/v1/api-key/{id} \
  -H "Authorization: Bearer YOUR_ADMIN_KEY" \
  -d '{"status": "suspended"}'
```

**撤销 API Key**:
```bash
php bin/w http:request DELETE /api/v1/api-key/{id} \
  -H "Authorization: Bearer YOUR_ADMIN_KEY"
```

### 配额管理最佳实践

1. **设置合理的配额**:
   - 开发环境：每日 100-500 次
   - 生产环境：根据实际需求设置

2. **监控配额使用**:
   - 设置配额使用率告警（80%、90%）
   - 定期审查使用趋势

3. **多环境分离**:
   - 为开发、测试、生产环境创建独立的 API Key
   - 使用不同的配额限制

---

## 助手管理

### 创建 AI 助手

AI 助手是预配置的对话模板，可以快速创建特定用途的 AI 应用。

**后台创建**:

1. 导航到 `AI 管理 > 助手管理`
2. 点击 `创建新助手`
3. 填写表单：
   - **名称**: 助手名称（如"客服助手"）
   - **描述**: 助手功能描述
   - **提示词模板**: 定义助手的行为
   - **选择模型**: 选择使用的 AI 模型
   - **参数配置**: 设置 temperature、max_tokens 等
   - **是否公开**: 是否允许其他用户使用

4. 保存助手

**提示词模板示例**:

**客服助手**:
```
你是一个专业、友好的客服人员。你的任务是：
1. 理解客户的问题和需求
2. 提供准确、清晰的解答
3. 保持礼貌和耐心
4. 如果无法解决问题，引导客户联系人工客服

客户问题：{input}
```

**技术支持助手**:
```
你是一个技术支持专家。你需要：
1. 诊断技术问题
2. 提供详细的解决步骤
3. 使用通俗易懂的语言解释技术概念
4. 必要时提供代码示例

问题描述：{input}
产品版本：{version}
```

**销售顾问助手**:
```
你是一个产品销售顾问。你的职责是：
1. 了解客户需求
2. 推荐合适的产品或方案
3. 解答产品相关问题
4. 促进销售转化

客户咨询：{input}
```

### 使用助手

**API 方式**:
```bash
php bin/w http:request POST /api/v1/assistant/301/chat \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "我想了解产品功能",
    "version": "2.0",
    "context": {
      "user_type": "premium"
    }
  }'
```

### 助手参数变量

在提示词模板中可以使用变量：

- `{input}`: 用户输入
- `{context}`: 上下文信息
- `{user_name}`: 用户名称
- `{timestamp}`: 当前时间戳
- 自定义变量：在请求中传递

---

## 聊天功能

### 基本聊天

**单次对话**:
```bash
php bin/w http:request POST /api/v1/chat \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "什么是人工智能？",
    "model_code": "gpt-3.5-turbo"
  }'
```

**带会话的对话**:
```bash
# 第一次请求
php bin/w http:request POST /api/v1/chat \
  -d '{
    "prompt": "我想学习 Python",
    "model_code": "gpt-3.5-turbo",
    "session_id": "session-123"
  }'

# 后续请求（AI 会记住上下文）
php bin/w http:request POST /api/v1/chat \
  -d '{
    "prompt": "从哪里开始学习？",
    "model_code": "gpt-3.5-turbo",
    "session_id": "session-123"
  }'
```

### 流式响应

对于长文本生成，使用流式响应可以实时显示输出：

```bash
php bin/w http:request POST /api/v1/chat \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "写一篇关于人工智能的文章",
    "model_code": "gpt-3.5-turbo",
    "stream": true
  }'
```

### 自定义参数

```bash
php bin/w http:request POST /api/v1/chat \
  -d '{
    "prompt": "创作一首诗",
    "model_code": "gpt-3.5-turbo",
    "parameters": {
      "temperature": 0.9,
      "max_tokens": 500,
      "top_p": 0.95,
      "frequency_penalty": 0.5
    }
  }'
```

---

## 高级功能

### 多租户管理

系统支持多租户隔离，确保数据安全：

1. **创建租户**:
   - 后台：`系统管理 > 租户管理 > 创建租户`
   - 设置租户名称、域名、配额

2. **租户配置**:
   - 每个租户有独立的配额限制
   - 数据完全隔离
   - 可设置不同的计费计划

3. **租户切换**:
   - 通过请求头 `X-Tenant-Code` 指定租户

### 缓存管理

系统自动缓存相同的请求以提高性能：

**清除缓存**:
```bash
php bin/w cache:flush ai_service
```

**查看缓存统计**:
```bash
php bin/w cache:status ai_service
```

### 队列任务

异步处理耗时任务：

**查看队列状态**:
```bash
php bin/w queue:status ai_chat
```

**处理队列任务**:
```bash
php bin/w queue:work ai_chat
```

### 性能优化

1. **启用缓存**:
   - Redis 缓存提高响应速度
   - 配置: `app/etc/env.php`

2. **批量请求**:
   - 合并多个请求减少网络开销

3. **流式输出**:
   - 对于长文本使用流式响应

4. **配额预警**:
   - 设置配额使用告警
   - 及时调整配额限制

---

## 常见问题

### Q1: API Key 丢失了怎么办？

**答**: API Key 无法找回，请：
1. 撤销丢失的 API Key
2. 创建新的 API Key
3. 更新应用程序中的配置

### Q2: 如何提高响应速度？

**答**: 
1. 启用 Redis 缓存
2. 使用流式响应
3. 减少 max_tokens 参数
4. 选择更快的模型

### Q3: 配额用完了怎么办？

**答**:
1. 查看配额使用情况
2. 后台提升配额限制
3. 等待配额重置（每日/每月）
4. 升级计费计划

### Q4: 如何保证数据安全？

**答**:
1. API Key 加密存储
2. HTTPS 传输
3. 多租户数据隔离
4. 审计日志记录
5. 定期安全审计

### Q5: 支持哪些 AI 模型？

**答**: 当前支持：
- OpenAI GPT-3.5/GPT-4
- Anthropic Claude
- 可通过模型管理添加其他模型

---

## 故障排查

### 问题: API 返回 401 Unauthorized

**原因**:
- API Key 无效或已过期
- API Key 被撤销
- 请求头格式错误

**解决方案**:
1. 检查 API Key 是否正确
2. 验证 Authorization 头格式
3. 检查 API Key 状态
4. 创建新的 API Key

### 问题: API 返回 429 Too Many Requests

**原因**:
- 超出速率限制
- 超出配额限制

**解决方案**:
1. 检查速率限制设置
2. 查看配额使用情况
3. 实施请求节流
4. 升级配额限制

### 问题: 响应时间过长

**原因**:
- 网络延迟
- 模型响应慢
- 未启用缓存
- 参数设置不当

**解决方案**:
1. 启用 Redis 缓存
2. 使用流式响应
3. 减少 max_tokens
4. 优化提示词长度
5. 选择更快的模型

### 问题: 模块安装失败

**原因**:
- PHP 版本过低
- 数据库连接失败
- 权限不足

**解决方案**:
1. 检查 PHP 版本 (需要 8.2+)
2. 验证数据库配置
3. 检查文件权限
4. 查看错误日志

---

## 获取帮助

### 文档资源

- **API 文档**: `docs/api.md`
- **开发文档**: `docs/development.md`
- **更新日志**: `CHANGELOG.md`

### 技术支持

- **邮箱**: support@aiweline.com
- **论坛**: https://bbs.aiweline.com
- **GitHub**: https://github.com/weline/ai

### 反馈建议

欢迎通过以下方式提供反馈：
- GitHub Issues
- 技术支持邮箱
- 用户论坛

---

**最后更新**: 2025-10-10  
**文档版本**: 1.0.0

