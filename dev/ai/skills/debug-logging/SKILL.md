---
name: debug-logging
description: Debug logging with Debug::env() + agent_log() for precise filtering. For loops (record only key items) and high-frequency functions (e.g. DB fetch/query — record only logs from specific caller key). 调试日志, debug log, 日志记录, agent log, 高频调用, 数据库 fetch, 按调用位置过滤, 只记录特定位置, caller key, Debug::env, Debug::target, 循环过滤, loop filter, 精确日志
globs:
alwaysApply: false
---

# 调试日志技能 (Debug Logging Skill)

## 何时使用此技能

**此技能必须在以下场景自动调用：**
- ✅ 用户要求添加调试日志
- ✅ 调试代码执行流程
- ✅ 追踪错误和异常
- ✅ 复杂环境中的日志过滤
- ✅ 循环中只记录关键日志
- ✅ 高频调用函数（如数据库 fetch/query）只记录来自特定调用位置的日志

**触发关键词（中英文）：**
- 调试日志, debug log, 日志记录, agent log, agent_log
- 调试, 追踪, trace, 日志过滤, log filter
- Debug::env, Debug::target, 环境标签, env tag
- 循环过滤, loop filter, 精确日志, precise log
- 高频调用, 数据库 fetch, 按调用位置过滤, 只记录特定位置, caller key
- 设置日志, 添加日志, 记录日志, 定位问题

---

## 核心：Debug::env() + agent_log() 组合

**这是精确日志过滤的核心机制，适合：**
- 循环中只记录关键日志
- **高频调用函数（如数据库 fetch/query）只记录来自特定调用位置的日志**

### agent_log() 函数签名

```php
agent_log(
    string $location,           // 日志位置（格式：File.php:method:tag）
    string $message,            // 日志消息
    array $data = [],           // 日志数据
    ?string $target = null,     // 目标标签（对应 Debug::env 的 key）
    mixed $targetValue = null,  // 目标值（对应 Debug::env 的 value，用于精确匹配）
    string $hypothesisId = 'H_default',
    string $sessionId = 'debug-session',
    string $runId = 'run1'
): void
```

### 日志输出位置

**日志写入：** `.cursor/debug.log`（BP 根目录下）

---

## 使用场景

### 场景1：按模块过滤（基础用法）

```php
// 启用路由模块日志
Debug::env('router_debug');

// 只有设置了 Debug::env('router_debug') 的日志才会记录
agent_log('Router/Core.php:route:entry', 'msg', [], 'router_debug');  // ✅ 记录
agent_log('Other.php:entry', 'msg', [], 'other_debug');               // ❌ 跳过
agent_log('Always.php:entry', 'msg', []);                             // ✅ 始终记录（无 target）
```

### 场景2：循环中精确过滤（核心用法）⭐

**问题：** 循环 100 次，只想记录 URL 为 '/admin' 的那条日志

```php
// 设置：只记录 URL 为 '/admin' 的日志
Debug::env('url', false, '/admin');

foreach ($urls as $url) {  // 假设有 100 个 URL
    // 第5参数 $url 与 Debug::env 的值 '/admin' 比较
    agent_log('Router.php:route', 'url matched', ['url' => $url], 'url', $url);
    // 只有 $url === '/admin' 时才记录，其他 99 条全部跳过！
}
```

### 场景3：循环中只记录特定 ID

**问题：** 循环 500 次，只想记录 ID 为 42 的那条日志

```php
// 设置：只记录 ID 为 42 的日志
Debug::env('item', false, 42);

foreach ($items as $item) {  // 假设有 500 个 item
    agent_log('Process.php:item', 'processing', ['id' => $item->id], 'item', $item->id);
    // 只有 $item->id === 42 时才记录，其他 499 条全部跳过！
}
```

### 场景4：多值匹配（数组）

**问题：** 循环中只记录几个关键 ID 的日志

```php
// 设置：记录 ID 为 1、42、100 的日志
Debug::env('item', false, [1, 42, 100]);

foreach ($items as $item) {
    agent_log('Process.php:item', 'processing', ['id' => $item->id], 'item', $item->id);
    // 只有 $item->id 是 1、42 或 100 时才记录
}
```

### 场景5：嵌套循环精确定位

```php
// 只记录 category_id=5 且 product_id=123 的日志
Debug::env('cat', false, 5);
Debug::env('prod', false, 123);

foreach ($categories as $cat) {
    agent_log('Cat.php', 'cat', ['id' => $cat->id], 'cat', $cat->id);
    
    foreach ($cat->products as $prod) {
        agent_log('Prod.php', 'prod', ['id' => $prod->id], 'prod', $prod->id);
        // 精确定位到 cat=5, prod=123 的那条日志
    }
}
```

### 场景6：高频调用函数 — 只记录来自特定位置的日志 ⭐

**问题：** 数据库的 `fetch()`、`query()` 等会被整站无数次调用，若每次都打日志会产生大量无用日志。我们只想看「来自某几个调用位置」的日志。

**做法：** 在高频函数内用「调用者标识」作为 `targetValue`，外部用 `Debug::env('key', false, '调用者key')` 指定只记录哪些调用者的日志。

**示例：在 Query::fetch() 中只记录来自 Router 的调用**

```php
// ========== 框架层：数据库 Query::fetch()（会被无数次调用） ==========
public function fetch(string $model_class = ''): mixed
{
    // 获取调用者标识（如 "Router/Core.php" 或 "Model/Post.php::load"）
    $callerKey = $this->getCallerKey();  // 见下方实现

    // #region agent log
    // 只有 Debug::env('db_caller', false, 'Router/Core.php') 且调用者匹配时才记录
    agent_log(
        'Database/Query.php:fetch',
        'fetch executed',
        ['sql' => $this->sql, 'model' => $model_class],
        'db_caller',
        $callerKey
    );
    // #endregion

    // ... 原有 fetch 逻辑
}

/** 获取调用者标识，用于日志过滤 */
private function getCallerKey(): string
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
    foreach ($trace as $i => $t) {
        if (isset($t['file']) && !str_contains($t['file'], 'Query.php')) {
            $file = str_replace(BP . DIRECTORY_SEPARATOR, '', $t['file'] ?? '');
            $file = str_replace('\\', '/', $file);
            $class = $t['class'] ?? '';
            $func  = $t['function'] ?? '';
            return $class ? "{$file}::{$class}::{$func}" : $file;
        }
    }
    return 'unknown';
}
```

**使用方：只记录来自 Router 的数据库调用**

```php
// 调试时：只关心「来自 Router/Core.php 的 fetch」
Debug::env('db_caller', false, 'Framework/Router/Core.php');

// 或者只关心多个位置（数组）
Debug::env('db_caller', false, [
    'Framework/Router/Core.php',
    'Blog/Model/Post.php',
]);
```

这样整站成千上万次 `fetch()` 中，只有来自你指定的「调用位置 key」的那几条会写入 `.cursor/debug.log`，其余全部跳过。

---

## 工作原理

```
Debug::env('key', false, 'value')  -->  设置 $_ENV['w-debug']['key'] = 'value'
                                              ↓
agent_log(..., 'key', $currentValue)  -->  检查 $_ENV['w-debug']['key'] 是否存在
                                              ↓
                                         如果存在且 $currentValue !== null
                                              ↓
                                         比较 $_ENV['w-debug']['key'] === $currentValue
                                              ↓
                                         匹配才记录，否则跳过
```

**匹配规则：**
1. `$target` 为 null → 始终记录
2. `$target` 不为 null 但 `$_ENV['w-debug'][$target]` 不存在 → 跳过
3. `$target` 存在且 `$targetValue` 为 null → 记录（按模块过滤）
4. `$target` 存在且 `$targetValue` 不为 null → 值匹配才记录（精确过滤）
   - 单值：`$envValue === $targetValue`
   - 数组：`in_array($targetValue, $envValue, true)`

---

## 日志格式

**输出位置：** `.cursor/debug.log`

**JSON NDJSON 格式：**
```json
{
  "sessionId": "debug-session",
  "runId": "run1",
  "hypothesisId": "H_route_exec",
  "location": "Router/Core.php:route:entry",
  "message": "route method entry",
  "data": {"router": {...}, "is_admin": true},
  "timestamp": 1770212102883,
  "target": "router_debug"
}
```

---

## 代码包装规范

**使用 `#region` 包装，保持编辑器整洁：**

```php
// #region agent log
agent_log('Router/Core.php:route:entry', 'route entry', ['router' => $this->router], 'router_debug');
// #endregion
```

---

## 迁移指南

**旧方式：**
```php
$agentLogPath = \defined('BP') ? BP . '.cursor' . \DIRECTORY_SEPARATOR . 'debug.log' : '';
if ($agentLogPath !== '') {
    @\file_put_contents($agentLogPath, \json_encode([...]) . "\n", \FILE_APPEND);
}
```

**新方式：**
```php
// #region agent log
agent_log('File.php:method:tag', 'message', ['data' => $data], 'target', $matchValue);
// #endregion
```

---

## 最佳实践

### 1. 循环日志策略

```php
// ❌ 错误：循环中无差别记录（可能产生几百条日志）
foreach ($items as $item) {
    agent_log('Process.php', 'processing', ['id' => $item->id]);
}

// ✅ 正确：精确过滤，只记录关键数据
Debug::env('item', false, 42);  // 只关注 id=42
foreach ($items as $item) {
    agent_log('Process.php', 'processing', ['id' => $item->id], 'item', $item->id);
}
```

### 2. 多模块调试

```php
// 同时调试路由和 ACL
Debug::env('router_debug');
Debug::env('acl_debug');

// 路由日志
agent_log('Router.php:route', 'msg', [], 'router_debug');

// ACL 日志
agent_log('Acl.php:check', 'msg', [], 'acl_debug');
```

### 3. 快速切换调试目标

```php
// 调试不同问题时，只需修改 Debug::env 的值
// Debug::env('item', false, 42);   // 调试 id=42
Debug::env('item', false, 100);     // 改为调试 id=100
```

### 4. 高频函数：用「调用者 key」过滤

**适用：** 数据库 `fetch()`、缓存 `get()`、事件 `dispatch()` 等会被整站无数次调用的函数。

**要点：** 在函数内部用 `debug_backtrace` 得到调用者（文件+类+方法），作为 `agent_log` 的 `targetValue`；调试时用 `Debug::env('db_caller', false, 'Router/Core.php')` 指定只记录来自该位置的日志，避免记录大量无用日志。

---

## 标签命名建议

| 标签 | 用途 |
|------|------|
| `router_debug` | 路由调试 |
| `acl_debug` | ACL 权限调试 |
| `cache_debug` | 缓存调试 |
| `db_debug` | 数据库调试 |
| `event_debug` | 事件系统调试 |
| `wls_debug` | WLS 服务器调试 |
| `url_debug` | URL 解析调试 |

---

## 统一日志系统（唯一标准）⭐⭐⭐

**框架日志 API 已统一为 `w_log_*()` 全局函数，这是唯一推荐的日志方式！**

### 设计原则（SOLID）

- **单一职责 (SRP)**：`w_log_*` 专注日志记录，不混杂文件名参数
- **开闭原则 (OCP)**：通过 LoggerFactory 可扩展不同 Handler
- **依赖倒置 (DIP)**：依赖 LoggerInterface 抽象，不依赖具体实现

### 全局日志函数（唯一标准）

```php
// 记录任意级别的日志
w_log(string $level, string $message, array $context = [], ?string $channel = null): void

// 便捷方法（PSR-3 风格）
w_log_error(string $message, array $context = [], ?string $channel = null): void
w_log_warning(string $message, array $context = [], ?string $channel = null): void
w_log_info(string $message, array $context = [], ?string $channel = null): void
w_log_debug(string $message, array $context = [], ?string $channel = null): void
w_log_notice(string $message, array $context = [], ?string $channel = null): void
w_log_critical(string $message, array $context = [], ?string $channel = null): void
w_log_alert(string $message, array $context = [], ?string $channel = null): void
w_log_emergency(string $message, array $context = [], ?string $channel = null): void

// 异常日志
w_log_exception(\Throwable $exception, ?string $message = null, ?string $channel = null): void

// SQL 日志
w_log_sql(string $sql, array $bindings = [], ?float $executionTime = null): void

// 获取日志实例
w_logger(?string $channel = null): LoggerInterface
```

### 使用示例

```php
// 简单日志
w_log_info('User logged in');

// 带上下文的日志
w_log_info('User {user} logged in from {ip}', [
    'user' => $username,
    'ip' => $clientIp,
]);

// 指定通道
w_log_error('Payment failed', ['order_id' => $orderId], 'payment');

// 异常日志
try {
    // ...
} catch (\Exception $e) {
    w_log_exception($e, 'Failed to process order');
}

// 获取日志实例
$logger = w_logger('my_module');
$logger->info('Processing started');
$logger->error('Processing failed');
```

### 日志级别

| 级别 | 值 | 说明 |
|------|-----|------|
| EMERGENCY | 800 | 系统不可用 |
| ALERT | 700 | 必须立即采取行动 |
| CRITICAL | 600 | 紧急情况 |
| ERROR | 500 | 运行时错误 |
| WARNING | 400 | 警告 |
| NOTICE | 300 | 普通但重要的事件 |
| INFO | 200 | 有趣的事件 |
| DEBUG | 100 | 详细的调试信息 |

### 日志配置（env.php）

```php
'log' => [
    'min_level' => 'INFO',           // 全局最小级别
    'path' => 'var/log',             // 日志目录
    'rotate' => [
        'strategy' => 'daily',       // 轮转策略：daily, size, none
        'max_files' => 7,            // 最大保留文件数
    ],
    'channels' => [
        'sql' => ['enabled' => false, 'min_level' => 'DEBUG'],
    ],
    'module_levels' => [
        'Weline_Framework' => 'WARNING',  // 模块级别覆盖
    ],
    'include_trace' => true,         // 启用链路追踪 ID（默认开启）
],
```

---

## 链路追踪（Tracing）⭐

框架内置链路追踪支持，每个请求自动生成唯一的 Trace ID，便于：
- 跨服务/模块的日志关联
- 请求链路分析
- 分布式系统调试

### 日志输出示例

**紧凑格式：**
```
[2026-03-01 12:00:00.123456] [INFO] [abc12345] payment:Order.php:50 - Order created
[2026-03-01 12:00:00.234567] [INFO] [abc12345] payment:Payment.php:100 - Payment processed
[2026-03-01 12:00:00.345678] [ERROR] [abc12345] payment:Email.php:200 - Failed to send email
```

同一请求的日志有相同的 Trace ID `[abc12345]`，可以快速过滤。

**详细格式：**
```
================================================================================
[2026-03-01 12:00:00.123456] [INFO] [payment]
Trace ID: abc1234567890def1234567890abcdef
Span ID: 1234567890abcdef
Request Duration: 123.45ms
File: app/code/Payment/Service/Order.php
Line: 50
Message: Order created
Context: {"order_id": "12345"}
--------------------------------------------------------------------------------
```

### 分布式追踪（W3C 标准）

支持通过 HTTP 请求头传入外部 Trace ID：

```bash
# 自定义请求头
curl -H "X-Trace-Id: my-trace-id-12345" https://example.com/api

# W3C traceparent 标准
curl -H "traceparent: 00-abc1234567890def1234567890abcdef-1234567890abcdef-01" https://example.com/api
```

### 在代码中使用 TraceContext

```php
use Weline\Framework\Log\Context\TraceContext;

// 获取当前请求的 Trace ID
$traceId = TraceContext::getTraceId();
// 返回：abc1234567890def1234567890abcdef

// 获取短格式（前 8 位，用于日志显示）
$shortId = substr(TraceContext::getTraceId(), 0, 8);
// 返回：abc12345

// 获取 Span ID
$spanId = TraceContext::getSpanId();

// 获取请求耗时（毫秒）
$duration = TraceContext::getRequestDuration();

// 创建子 Span（用于追踪子操作）
$childSpan = TraceContext::createChildSpan();
// 返回：['span_id' => '...', 'parent_span_id' => '...']

// 设置响应头（返回 Trace ID 给客户端）
TraceContext::setResponseHeaders();
// 设置：X-Trace-Id: ..., X-Span-Id: ...

// 重置（WLS 模式下每个请求结束后调用）
TraceContext::reset();
```

### 配置开关

```php
// env.php
'log' => [
    'include_trace' => true,  // 开启（默认）
    // 'include_trace' => false, // 关闭链路追踪
],
```

### JSON 格式日志中的链路追踪

使用 `JsonLineFormatter` 时，链路追踪信息会自动包含：

```json
{
  "@timestamp": "2026-03-01T12:00:00.123456+08:00",
  "level": "info",
  "channel": "payment",
  "message": "Order created",
  "trace_id": "abc1234567890def1234567890abcdef",
  "span_id": "1234567890abcdef",
  "parent_span_id": "abcdef1234567890",
  "request_duration_ms": 123.45,
  "context": {"order_id": "12345"},
  "file": "app/code/Payment/Service/Order.php",
  "line": 50,
  "pid": 12345
}
```

### 日志文件位置

| 日志类型 | 默认路径 | 用途 |
|----------|----------|------|
| 错误日志 | `var/log/error.log` | `w_log_error()` |
| 异常日志 | `var/log/exception.log` | `w_log_exception()` |
| 警告日志 | `var/log/warning.log` | `w_log_warning()` |
| 通知日志 | `var/log/notice.log` | `w_log_notice()` |
| 调试日志 | `var/log/debug.log` | `w_log_debug()` |
| SQL 日志 | `var/log/sql.log` | `w_log_sql()` |
| Agent 调试 | `.cursor/debug.log` | `agent_log()` |
| WLS 日志 | `var/log/wls.log` | WLS 请求/错误日志 |
| 自定义通道 | `var/log/{channel}.log` | `w_log_*(..., 'channel')` |

---

## 禁止使用（强制）⛔

```php
// ❌ 绝对禁止 - 原生 PHP 日志/调试输出
error_log('错误信息');                              // 原生 PHP error_log
error_log('[Module] Error: ' . $e->getMessage());   // 禁止！
echo "debug: " . $var;                              // echo 调试
print_r($data);                                     // print_r 调试
var_dump($data);                                    // var_dump 调试

// ❌ 已废弃（框架内禁止使用）
Env::log_error('module_name', '错误信息');          // 旧 API
Env::log_warning('module_name', '警告信息');        // 旧 API
Env::log_debug('module_name', '调试信息');          // 旧 API
Env::log_info('module_name', '信息');               // 旧 API
Env::log_notice('module_name', '通知');             // 旧 API

// ✅ 唯一正确方式 - w_log_*() 函数
w_log_error('错误信息', ['context' => $data], 'module_name');
w_log_warning('警告信息', [], 'module_name');
w_log_debug('调试信息', ['sql' => $sql], 'module_name');
w_log_info('处理完成', ['result' => $result], 'module_name');
w_log_exception($exception, '操作失败', 'module_name');
```

### error_log() → w_log_*() 替换规则

| error_log 内容特征 | 替换为 |
|-------------------|--------|
| 包含 `error`/`fail`/`failed`/`exception` | `w_log_error()` |
| 包含 `warn`/`warning` | `w_log_warning()` |
| 包含 `debug`/`🔵` | `w_log_debug()` |
| 其他一般日志 | `w_log_info()` |

**示例替换：**
```php
// ❌ 旧写法
error_log('[Module] Failed to process: ' . $e->getMessage());
error_log('Debug: processing item ' . $id);
error_log('Warning: deprecated feature used');
error_log('Item saved successfully');

// ✅ 新写法
w_log_error('[Module] Failed to process: ' . $e->getMessage());
w_log_debug('Debug: processing item ' . $id);
w_log_warning('Warning: deprecated feature used');
w_log_info('Item saved successfully');
```

---

## 迁移对照表

| 旧方式（已废弃） | 新方式（唯一标准） |
|------------------|-------------------|
| `Env::log_error('channel', $msg)` | `w_log_error($msg, [], 'channel')` |
| `Env::log_warning('channel', $msg)` | `w_log_warning($msg, [], 'channel')` |
| `Env::log_info('channel', $msg)` | `w_log_info($msg, [], 'channel')` |
| `Env::log_debug('channel', $msg)` | `w_log_debug($msg, [], 'channel')` |
| `Env::log_notice('channel', $msg)` | `w_log_notice($msg, [], 'channel')` |
| `Env::sql_log('channel', $sql)` | `w_log_sql($sql, [], null)` |
| `Env::log('file', $msg, 'ERROR')` | `w_log_error($msg, [], 'file')` |

---

## Env 日志方法（已废弃）⚠️

**`Env::log_*` 方法已废弃，框架内代码已全部迁移到 `w_log_*`。**

旧 API 仍保留向后兼容（内部代理到 `w_log_*`），但：
- ❌ **框架内代码禁止使用**
- ❌ **新代码禁止使用**
- ⚠️ 仅允许第三方模块过渡期使用

---

## 相关技能

- `error-learning` - 错误自学习
- `error-tracking` - 错误追踪
- `module-development` - 模块开发

---

**创建日期**: 2026-02-04
**最后更新**: 2026-03-01
**版本**: 3.3.0（新增 error_log 替换规则、迁移示例）
