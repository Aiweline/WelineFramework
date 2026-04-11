# WLS Fiber 上下文串请求问题修复 V2

## 问题描述

在 WLS 模式下，当多个 Fiber 并发处理请求时，会出现响应串到其他请求的情况。

## V1 修复的问题

V1 修复在 Fiber 启动、resume、终止时都调用了 `StateManager::reset()`，但这导致了新问题：
- **间歇性"未登录"错误**：Fiber resume 前调用 reset() 会清理掉该 Fiber 已建立的 Session 等状态
- **刷新页面时有时正常，有时显示未登录**

## V2 修复策略

### 核心原则

**只在 Fiber 启动时调用 `StateManager::reset()`，resume 时只调用 `restore()`**

### 原理分析

1. **Fiber 启动时的 reset()**：
   - 清理上一个请求（或上一个 Fiber）的残留状态
   - 确保新 Fiber 从干净的全局状态开始
   - ✅ 这是必要的，不会影响当前 Fiber

2. **Fiber resume 时不调用 reset()**：
   - `WlsFiberContext::restore()` 会恢复该 Fiber 的所有关键状态：
     - `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_REQUEST`, `$_FILES`
     - Request 对象引用
     - RequestContext ID
     - HeaderCollector 状态
     - WelineEnv 状态
     - SseContext 状态
   - 这些状态的恢复足以隔离不同 Fiber 的请求上下文
   - ❌ 如果调用 reset()，会清理掉该 Fiber 已建立的 Session、数据库连接等状态

3. **为什么 restore() 足够？**
   - 每个 Fiber 的请求处理逻辑都是基于超全局变量（`$_SERVER` 等）和 Request 对象
   - `restore()` 恢复这些变量后，后续代码会基于正确的请求上下文执行
   - 即使其他 Fiber 修改了某些全局静态变量，只要超全局变量和 Request 对象正确，就不会影响当前 Fiber

4. **潜在的状态泄漏风险**：
   - 如果某些代码直接使用全局静态变量（而不是通过 Request 对象），可能会读取到其他 Fiber 的状态
   - 但这种情况应该通过修复代码来解决（使用 RequestContext 而不是静态变量）
   - 而不是在 resume 时调用 reset()（会破坏 Session 等状态）

## V2 修复内容

### 修复点 1: Fiber 启动时完全重置状态（保持不变）

**文件**: `app/code/Weline/Server/bin/worker.php:2560`  
**文件**: `app/code/Weline/Server/bin/worker_ssl.php:3203`

```php
function wlsFiberRequestContextEnter(mixed $conn): void
{
    // 关键修复：Fiber 启动时必须完全重置所有请求级状态
    \Weline\Framework\Runtime\StateManager::reset();

    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Http\Url::resetWlsFiberInterleavedParserScratch();
    \Weline\Framework\Http\Sse\SseContext::reset();
    \Weline\Framework\Http\Sse\SseContext::setConnection($conn);
    \Weline\Framework\Http\Sse\SseContext::clearWriteCallback();
}
```

### 修复点 2: Fiber resume 时只恢复快照（V2 修改）

**文件**: `app/code/Weline/Server/bin/worker.php:1396`  
**文件**: `app/code/Weline/Server/bin/worker_ssl.php:1948`

```php
$fiberScheduler->tick(function (\Fiber $fiber) use (&$activeFibers) {
    foreach ($activeFibers as $afData) {
        if ($afData['fiber'] === $fiber && isset($afData['context'])) {
            // Fiber resume：直接恢复该 Fiber 的快照
            // 不调用 reset()，避免清理掉该 Fiber 已建立的 Session 等状态
            $afData['context']->restore();
            return;
        }
    }
});
```

### 修复点 3: Fiber 终止时只恢复快照（V2 修改）

**文件**: `app/code/Weline/Server/bin/worker.php:1710`  
**文件**: `app/code/Weline/Server/bin/worker_ssl.php:1963`

```php
if ($af->isTerminated()) {
    if (isset($afData['context'])) {
        // Fiber 终止时恢复其上下文（不恢复响应状态）
        $afData['context']->restore(false);
    }
    // ...
}
```

## V2 修复原理

### Fiber 生命周期中的状态管理

```
┌─────────────────────────────────────────────────────────────┐
│ Fiber A 启动                                                 │
│ → StateManager::reset() 清理上一个请求的残留                │
│ → 初始化 A 的状态（Session、Request 等）                     │
│ → 处理请求...                                                │
│ → suspend → capture() 保存 A 的快照                          │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber B 启动                                                 │
│ → StateManager::reset() 清理 A 的残留                       │
│ → 初始化 B 的状态（Session、Request 等）                     │
│ → 处理请求...                                                │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber A resume                                               │
│ → restore() 恢复 A 的快照（$_SERVER、Request 等）           │
│   ✅ Session 等状态保持不变（不调用 reset）                  │
│ → 继续处理请求...                                            │
│ → 返回响应                                                   │
└─────────────────────────────────────────────────────────────┘
```

### 关键差异：V1 vs V2

| 时机 | V1 | V2 | 说明 |
|------|----|----|------|
| Fiber 启动 | reset() + 初始化 | reset() + 初始化 | ✅ 相同，清理上一个请求的残留 |
| Fiber resume | reset() + restore() | restore() | ⚠️ V1 会清理 Session，V2 保持 Session |
| Fiber 终止 | reset() + restore(false) | restore(false) | ⚠️ V1 会清理状态，V2 保持状态 |

## 测试验证

### 测试步骤

1. **启动测试实例**：
   ```bash
   php bin/w server:start -p 9502 -n ai-test-fiber-fix-v2
   ```

2. **Session 持久性测试**（重点）：
   - 登录后台
   - 打开 AI Site Agent 页面（SSE 长连接）
   - 在其他标签页并发访问其他后台页面
   - **验证**：不应该出现"未登录或会话令牌无效"错误

3. **并发访问测试**：
   - 多个标签页同时访问不同页面
   - **验证**：每个请求返回正确的内容，不串请求

4. **SSE 并发测试**：
   - 打开 AI Site Agent 页面
   - 同时在其他标签页访问其他页面
   - **验证**：SSE 响应正确，不混入其他请求内容

5. **停止测试实例**：
   ```bash
   php bin/w server:stop -n ai-test-fiber-fix-v2
   ```

### 预期结果

- ✅ 不再出现间歇性"未登录"错误
- ✅ Session 在 Fiber suspend/resume 过程中保持有效
- ✅ 每个请求返回正确的响应内容
- ✅ SSE 响应不会混入其他请求的内容

## 性能影响

- **V1**：每次 Fiber resume 都调用 reset()，开销较大
- **V2**：只在 Fiber 启动时调用 reset()，resume 时只调用 restore()，开销更小
- **结论**：V2 性能更好，且不会破坏 Session

## 潜在风险与缓解

### 风险：全局静态变量泄漏

如果某些代码直接使用全局静态变量存储请求级数据，可能会在 Fiber 之间泄漏。

**缓解措施**：
1. 代码审查：确保请求级数据存储在 RequestContext 而不是静态变量
2. 使用 `StateManager::registerStaticReset()` 注册需要重置的静态变量
3. 使用 `RequestContext::set/get` 存储 Fiber 本地数据（通过 WeakMap 隔离）

### 风险：ObjectManager 实例缓存

如果某些单例对象缓存了请求级数据，可能会在 Fiber 之间泄漏。

**缓解措施**：
1. 使用 `StateManager::registerSingletonReset()` 注册需要重置的单例
2. 在 Fiber 启动时的 reset() 会清理这些单例
3. 确保单例对象不缓存请求级数据，或使用 RequestContext 存储

## 总结

V2 修复通过**只在 Fiber 启动时调用 reset()**，避免了 V1 中 Fiber resume 时清理 Session 的问题。

核心思想：
- **Fiber 启动**：清理上一个请求的残留（reset）
- **Fiber resume**：恢复该 Fiber 的快照（restore），保持 Session 等状态
- **Fiber 终止**：恢复上下文（restore），不清理状态

这样既解决了响应串请求的问题，又避免了 Session 丢失的问题。
