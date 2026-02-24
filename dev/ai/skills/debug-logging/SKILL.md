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

## 相关技能

- `error-learning` - 错误自学习
- `error-tracking` - 错误追踪
- `module-development` - 模块开发

---

**创建日期**: 2026-02-04
**最后更新**: 2026-02-04
**版本**: 2.0.0（新增精确过滤功能）
