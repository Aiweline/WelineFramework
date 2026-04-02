# 路由缓存功能测试文档

## 概述

本文档描述了 WLS 路由缓存功能的自动化测试，确保重定向响应不被缓存，以及缓存清除命令正常工作。

## 测试文件

### 1. RoutingCacheServiceTest.php
**路径**: `app/code/Weline/Server/Test/Unit/Dispatcher/RoutingCacheServiceTest.php`

**测试内容**:
- ✅ 301 永久重定向不应被缓存
- ✅ 302 临时重定向不应被缓存
- ✅ 303 See Other 重定向不应被缓存
- ✅ 307 临时重定向不应被缓存
- ✅ 308 永久重定向不应被缓存
- ✅ 带 X-Weline-Route-Hint 的 200 响应应被缓存
- ✅ 没有 Route-Hint 的响应不应被缓存
- ✅ 重定向响应即使有 Route-Hint 也不应被缓存（优先级测试）
- ✅ purgeAll() 方法应清除所有路由
- ✅ purgeRouteCache() 方法存在性
- ✅ 空响应不应导致崩溃
- ✅ 格式错误的响应不应导致崩溃

**测试统计**: 13 个测试用例

### 2. CacheClearCommandTest.php
**路径**: `app/code/Weline/Server/Test/Unit/Console/CacheClearCommandTest.php`

**测试内容**:
- ✅ 命令实例创建成功
- ✅ IPC 消息格式正确（JSON 格式）
- ✅ 命令提示信息正确
- ✅ ControlMessage 常量定义正确
- ✅ 命令能处理不同实例名称
- ✅ IPC 消息以换行符结尾（NDJSON 协议）
- ✅ 命令继承自 CommandAbstract
- ✅ execute() 方法存在
- ✅ tip() 方法存在
- ✅ IPC 消息完整性验证
- ✅ ServerInstanceManager 依赖存在
- ✅ ServerInstanceInfo 接口存在
- ✅ 命令能处理空参数
- ✅ 相关 ControlMessage 常量定义

**测试统计**: 15 个测试用例

## 运行测试

### 方法 1: 使用测试脚本（推荐）

**Windows**:
```bash
test-routing-cache.bat
```

**Linux/Mac**:
```bash
chmod +x test-routing-cache.sh
./test-routing-cache.sh
```

### 方法 2: 手动运行

**运行所有测试**:
```bash
vendor/bin/phpunit \
  app/code/Weline/Server/Test/Unit/Dispatcher/RoutingCacheServiceTest.php \
  app/code/Weline/Server/Test/Unit/Console/CacheClearCommandTest.php \
  --testdox
```

**只运行路由缓存服务测试**:
```bash
vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Dispatcher/RoutingCacheServiceTest.php
```

**只运行命令测试**:
```bash
vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Console/CacheClearCommandTest.php
```

## 测试结果示例

```
Cache Clear Command (Weline\Server\Test\Unit\Console\CacheClearCommand)
 ✔ Should parse default instance name
 ✔ Should generate correct ipc message
 ✔ Should have correct tip
 ... (共 15 个测试)

Routing Cache Service (Weline\Server\Test\Unit\Dispatcher\RoutingCacheService)
 ✔ Redirect response should not be cached
 ✔ Temporary redirect should not be cached
 ✔ Success response with route hint should be cached
 ... (共 13 个测试)

Tests: 28, Assertions: 51
```

## 集成到 CI/CD

可以将测试脚本集成到 CI/CD 流程中：

```yaml
# .github/workflows/test.yml 示例
- name: Run Routing Cache Tests
  run: |
    vendor/bin/phpunit \
      app/code/Weline/Server/Test/Unit/Dispatcher/RoutingCacheServiceTest.php \
      app/code/Weline/Server/Test/Unit/Console/CacheClearCommandTest.php
```

## 测试覆盖的功能

### 核心功能
1. **重定向检测**: 确保所有 3xx 状态码响应不被缓存
2. **正常响应缓存**: 确保带 Route-Hint 的正常响应被正确缓存
3. **缓存清除**: 确保 purgeAll() 和 purgeRouteCache() 方法正常工作
4. **IPC 通信**: 确保命令能生成正确的 IPC 消息格式

### 边界情况
1. 空响应处理
2. 格式错误的响应处理
3. 重定向响应带 Route-Hint 的优先级处理
4. 没有 Route-Hint 的响应处理

## 维护建议

1. **每次修改路由缓存相关代码后运行测试**
2. **添加新功能时同步更新测试用例**
3. **定期运行测试确保功能稳定**
4. **在提交代码前确保所有测试通过**

## 相关文件

- 实现文件: `app/code/Weline/Server/Dispatcher/RoutingCacheService.php`
- 命令文件: `app/code/Weline/Server/Console/Server/CacheClear.php`
- IPC 协议: `app/code/Weline/Server/IPC/ControlMessage.php`
- 服务编排: `app/code/Weline/Server/Service/ServiceOrchestrator.php`

## 问题排查

如果测试失败，检查：
1. RoutingCacheService 的 learnFromResponse() 方法是否正确实现重定向检测
2. ControlMessage 常量是否正确定义
3. CacheClear 命令是否正确继承 CommandAbstract
4. IPC 消息格式是否符合 NDJSON 协议（以换行符结尾）
