# Quickstart: Weline_Ai Module

**Purpose**: 快速验证 AI 模块核心功能

## Prerequisites

1. WelineFramework 环境已配置
2. AI 模块已安装 (`php bin/w setup:upgrade`)
3. 数据库迁移已完成
4. 测试用户和租户已创建

## HTTP Request 验证示例

### 1. Chat API 测试

```bash
# 测试 Chat API
php bin/w http:request POST /api/v1/chat \
  -H "Content-Type: application/json" \
  -H "X-API-Version: v1" \
  -H "X-API-Locale: zh-CN" \
  -d '{
    "prompt": "你好，AI！",
    "model_code": "gpt-3.5-turbo",
    "session_id": "user-session-123"
  }'

# 预期响应
# Status: 200
# Body: {
#   "success": true,
#   "data": {
#     "response": "你好！有什么可以帮助你的吗？",
#     "locale": "zh-CN",
#     "version": "v1"
#   }
# }
```

### 2. 模型拷贝测试

```bash
# 测试模型拷贝
php bin/w http:request POST /api/v1/model/1/copy \
  -H "Content-Type: application/json" \
  -H "X-API-Version: v1" \
  -d '{
    "new_name": "My Custom GPT-3.5 Turbo"
  }'

# 预期响应
# Status: 200
# Body: {
#   "success": true,
#   "data": {
#     "model_id": 101,
#     "origin_model_id": 1,
#     "name": "My Custom GPT-3.5 Turbo",
#     "is_copy": true
#   }
# }
```

### 3. 模型信息获取测试

```bash
# 测试获取模型信息
php bin/w http:request GET /api/v1/model/1 \
  -H "X-API-Version: v1"

# 预期响应
# Status: 200
# Body: {
#   "success": true,
#   "data": {
#     "id": 1,
#     "supplier": "OpenAI",
#     "name": "GPT-3.5 Turbo",
#     "model_code": "gpt-3.5-turbo",
#     "version": "1.0",
#     "is_copy": false,
#     "origin_model_id": null
#   }
# }
```

### 4. API Key 创建测试

```bash
# 测试创建 API Key
php bin/w http:request POST /api/v1/api-key \
  -H "Content-Type: application/json" \
  -H "X-API-Version: v1" \
  -d '{
    "name": "My New API Key",
    "user_id": 1
  }'

# 预期响应
# Status: 200
# Body: {
#   "success": true,
#   "data": {
#     "id": 201,
#     "name": "My New API Key",
#     "token": "sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
#     "status": "approved",
#     "is_active": true
#   }
# }
```

## 集成测试场景

### 场景 1: 完整的模型拷贝流程

```bash
# 1. 获取原始模型信息
php bin/w http:request GET /api/v1/model/1

# 2. 拷贝模型
php bin/w http:request POST /api/v1/model/1/copy \
  -d '{"new_name": "Test Copy Model"}'

# 3. 验证拷贝模型
php bin/w http:request GET /api/v1/model/{new_model_id}

# 4. 使用拷贝模型进行 Chat
php bin/w http:request POST /api/v1/chat \
  -d '{
    "prompt": "测试拷贝模型",
    "model_code": "test-copy-model",
    "session_id": "test-session"
  }'
```

### 场景 2: API Key 认证流程

```bash
# 1. 创建 API Key
php bin/w http:request POST /api/v1/api-key \
  -d '{"name": "Test API Key", "user_id": 1}'

# 2. 使用 API Key 进行认证请求
php bin/w http:request POST /api/v1/chat \
  -H "Authorization: Bearer {api_key_token}" \
  -d '{
    "prompt": "测试 API Key 认证",
    "model_code": "gpt-3.5-turbo",
    "session_id": "auth-test-session"
  }'
```

## 性能验证

### 响应时间测试

```bash
# 测试 P95 响应时间 (目标: ≤ 3秒)
time php bin/w http:request POST /api/v1/chat \
  -d '{
    "prompt": "性能测试请求",
    "model_code": "gpt-3.5-turbo",
    "session_id": "perf-test"
  }'
```

### 并发测试

```bash
# 并发请求测试 (目标: 支持 1000+ 并发)
for i in {1..10}; do
  php bin/w http:request POST /api/v1/chat \
    -d "{\"prompt\": \"并发测试 $i\", \"model_code\": \"gpt-3.5-turbo\", \"session_id\": \"concurrent-$i\"}" &
done
wait
```

## 错误处理验证

### 无效请求测试

```bash
# 测试无效的模型代码
php bin/w http:request POST /api/v1/chat \
  -d '{
    "prompt": "测试无效模型",
    "model_code": "invalid-model",
    "session_id": "error-test"
  }'

# 预期响应: 400 Bad Request
```

### 配额限制测试

```bash
# 测试超出配额限制
# (需要先设置低配额限制)
php bin/w http:request POST /api/v1/chat \
  -d '{
    "prompt": "配额测试",
    "model_code": "gpt-3.5-turbo",
    "session_id": "quota-test"
  }'

# 预期响应: 429 Too Many Requests
```

## 验证清单

- [ ] Chat API 返回正确响应格式
- [ ] 模型拷贝功能正常工作
- [ ] 模型信息获取正确
- [ ] API Key 创建和认证正常
- [ ] 响应时间满足 P95 ≤ 3s 要求
- [ ] 错误处理返回适当状态码
- [ ] 配额限制正常工作
- [ ] 多租户隔离正常
- [ ] 审计日志正确记录

## 故障排除

### 常见问题

1. **404 Not Found**: 检查路由配置和模块安装
2. **500 Internal Server Error**: 检查数据库连接和模型配置
3. **401 Unauthorized**: 检查 API Key 配置和认证中间件
4. **429 Too Many Requests**: 检查配额配置和限流设置

### 调试命令

```bash
# 检查模块状态
php bin/w module:status Weline_Ai

# 检查数据库表
php bin/w db:show-tables | grep ai_

# 检查路由
php bin/w route:list | grep api/v1

# 查看日志
tail -f var/log/ai.log
```