# Weline AI 模块测试指南

## 目录

1. [单元测试](#单元测试)
2. [集成测试](#集成测试)
3. [性能测试](#性能测试)
4. [HTTP 端点验证](#http-端点验证)
5. [Quickstart 验证](#quickstart-验证)

---

## 单元测试

### 运行所有单元测试

```bash
php bin/w phpunit:run app/code/Weline/Ai/tests/unit/
```

### 运行特定测试文件

```bash
# AI Model 验证测试
php bin/w phpunit:run app/code/Weline/Ai/tests/unit/test_ai_model_validation.php

# AI API Key 验证测试
php bin/w phpunit:run app/code/Weline/Ai/tests/unit/test_ai_api_key_validation.php

# AI Tenant 验证测试
php bin/w phpunit:run app/code/Weline/Ai/tests/unit/test_ai_tenant_validation.php
```

---

## 集成测试

### 运行所有集成测试

```bash
php bin/w phpunit:run app/code/Weline/Ai/tests/integration/
```

### 常见集成测试场景

```bash
# 模型管理流程测试
php bin/w phpunit:run app/code/Weline/Ai/tests/integration/ModelManagementTest.php

# API Key 认证测试
php bin/w phpunit:run app/code/Weline/Ai/tests/integration/ApiKeyAuthTest.php

```

---

## 性能测试

### 运行性能测试套件

```bash
php bin/w phpunit:run app/code/Weline/Ai/tests/performance/test_performance.php
```

### 性能目标

- **P95 响应时间**: ≤ 3 秒
- **P99 响应时间**: ≤ 5 秒
- **并发支持**: 1000+ 用户

### 性能测试覆盖

- 模型查询性能
- API Key 验证性能
- 缓存读写性能
- 批量查询性能
- 内存使用
- 并发请求模拟
- 加密/解密性能

---

## HTTP 端点验证

### Windows (PowerShell)

```powershell
powershell -ExecutionPolicy Bypass -File app/code/Weline/Ai/tests/http_endpoints_verification.ps1
```

### Linux/Mac (Bash)

```bash
bash app/code/Weline/Ai/tests/http_endpoints_verification.sh
```

### 前置准备

1. **启动服务器**:
   ```bash
   php bin/w server:start
   ```

2. **创建测试 API Key**:
   - 方法 1: 通过后台管理界面创建
   - 方法 2: 使用 API 创建
   ```bash
   php bin/w http:request POST /api/v1/api-key \
     -H "Content-Type: application/json" \
     -d '{"name":"Test Key","user_id":1}'
   ```

3. **更新脚本中的 API Key**:
   编辑验证脚本，将 `$API_KEY` 或 `API_KEY` 变量设置为实际的 API Key。

### 验证的端点

1. POST /api/v1/chat - Chat API
2. GET /api/v1/model/{id} - 获取模型信息
3. POST /api/v1/model/{id}/copy - 模型拷贝
4. POST /api/v1/api-key - 创建 API Key
5. GET /api/v1/api-key - 获取 API Key 列表
6. 认证失败测试（401）
7. CORS 预检请求（OPTIONS）
8. 自定义请求头验证
9. 性能监控响应头

---

## Quickstart 验证

### 运行 Quickstart 验证

```bash
php app/code/Weline/Ai/tests/quickstart_validation.php
```

### 验证场景

1. Chat API 测试
2. 模型拷贝测试
3. 模型信息获取测试
4. API Key 创建测试
5. 完整的模型管理流程
6. API Key 认证流程

---

## 合约测试

### 运行合约测试

```bash
php bin/w phpunit:run app/code/Weline/Ai/tests/contract/
```

### 合约文件位置

合约定义文件位于：`specs/001-app-code-weline/contracts/`

- `chat_post.json` - Chat API 合约
- `model_get.json` - 模型获取合约
- `model_copy.json` - 模型拷贝合约
- `api_key_post.json` - API Key 创建合约

---

## 测试最佳实践

### 1. 测试隔离

- 每个测试应该独立运行
- 使用 `setUp()` 和 `tearDown()` 方法
- 清理测试数据

### 2. 测试数据

- 使用测试专用数据
- 避免污染生产数据
- 使用事务回滚

### 3. 测试命名

- 使用描述性名称
- 遵循 `test{Feature}{Scenario}` 格式
- 例如：`testModelCopyRequiresOriginId()`

### 4. 断言

- 每个测试包含明确的断言
- 提供有意义的错误信息
- 使用适当的断言方法

---

## 持续集成

### GitHub Actions 示例

```yaml
name: AI Module Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      
      - name: Install Dependencies
        run: composer install
      
      - name: Run Unit Tests
        run: php bin/w phpunit:run app/code/Weline/Ai/tests/unit/
      
      - name: Run Integration Tests
        run: php bin/w phpunit:run app/code/Weline/Ai/tests/integration/
      
      - name: Run Performance Tests
        run: php bin/w phpunit:run app/code/Weline/Ai/tests/performance/
```

---

## 故障排查

### 测试失败

1. 检查数据库连接
2. 验证模块是否已安装
3. 查看错误日志：`var/log/error.log`
4. 检查 PHP 版本（需要 8.2+）

### HTTP 端点验证失败

1. 确认服务器已启动
2. 检查 API Key 是否有效
3. 验证路由配置
4. 查看网络日志

### 性能测试超时

1. 调整测试样本大小
2. 启用缓存优化
3. 检查数据库性能
4. 优化查询语句

---

## 测试覆盖率

### 生成覆盖率报告

```bash
php bin/w phpunit:run --coverage-html coverage/ app/code/Weline/Ai/tests/
```

### 查看报告

打开 `coverage/index.html` 查看详细的覆盖率报告。

### 覆盖率目标

- **行覆盖率**: ≥ 80%
- **分支覆盖率**: ≥ 70%
- **方法覆盖率**: ≥ 90%

---

## 支持

如有问题，请：

- 查看文档：`app/code/Weline/Ai/docs/`
- 提交 Issue：https://github.com/weline/ai/issues
- 联系支持：support@aiweline.com

---

**最后更新**: 2025-10-10

