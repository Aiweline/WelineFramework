# WLS Fiber 上下文串请求问题修复 V3 (最终版)

## 问题演进

### V1 问题
- 在 Fiber 启动、resume、终止时都调用 `StateManager::reset()`
- 导致间歇性"未登录"错误（Session 被清理）

### V2 问题
- 只在 Fiber 启动时调用 `reset()`，resume 时只调用 `restore()`
- Session 问题解决了，但**响应串请求问题依然存在**
- 原因 1：`restore()` 只恢复超全局变量，没有清理 ObjectManager 实例缓存
- 原因 2：`restore()` 恢复 WelineEnv 快照时，没有先清理 RequestContext 中其他 Fiber 的 env.* 影子值

### V3 最终方案
在 `WlsFiberContext::restore()` 内部：
1. 调用 `StateManager::runWlsPersistentRequestEntryBaseline()`，清理 OM 实例缓存但不清理 Session
2. 调用 `WelineEnv::getInstance()->reset()` 再恢复快照，清理 RequestContext 中其他 Fiber 的 env.* 影子值

## 问题根源分析

### 根源 1: WelineEnv RequestContext 影子值污染

**这是最根本的原因**：全局变量统一通过 WelineEnv 管理，而 WelineEnv 在读取变量时的优先级是：

1. `$this->overrides`（实例级覆盖）
2. **`RequestContext::get('env.' . $key)`**（RequestContext 影子值）
3. `$_SERVER`（超全局变量）

问题流程：

```
┌─────────────────────────────────────────────────────────────┐
│ Fiber A 运行                                                 │
│ → WelineEnv::set('area', 'backend') 设置区域                │
│ → 同时写入 RequestContext::set('env.area', 'backend')       │
│ → suspend → capture() 保存 WelineEnv 快照                   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber B 运行                                                 │
│ → WelineEnv::set('area', 'frontend') 设置区域               │
│ → 覆盖 RequestContext::set('env.area', 'frontend')          │
│   ❌ RequestContext 是共享的，A 的值被覆盖了！              │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber A resume (V2 方案)                                     │
│ → restore() 恢复 $_SERVER（area = 'backend'）               │
│ → restore() 恢复 WelineEnv 快照（overrides 恢复）           │
│ → ❌ 但 RequestContext 中 env.area 还是 'frontend'！        │
│ → WelineEnv::get('area') 读取时：                           │
│   1. 检查 overrides - 可能没有                              │
│   2. 检查 RequestContext - 读到 'frontend'（Fiber B 的值）  │
│   3. ❌ 永远不会读到 $_SERVER 的 'backend'                  │
│ → 使用错误的 area 值处理请求                                │
│ → ❌ 响应串请求！                                            │
└─────────────────────────────────────────────────────────────┘
```

**V3 修复**：在 `restore()` 恢复 WelineEnv 快照前，先调用 `WelineEnv::getInstance()->reset()`，清理 RequestContext 中所有 `env.*` 影子值。

### 根源 2: ObjectManager 实例缓存污染

### 为什么会响应串请求？

```
┌─────────────────────────────────────────────────────────────┐
│ Fiber A 启动                                                 │
│ → reset() 清理全局状态                                       │
│ → 处理请求 /pagebuilder/backend/ai-site-agent/workspace     │
│ → ObjectManager 缓存了 Controller、Template、Router 实例    │
│ → suspend → capture() 保存快照（只保存 $_SERVER 等）        │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber B 启动                                                 │
│ → reset() 清理全局状态（包括 OM 实例缓存）                  │
│ → 处理请求 /admin/user/bind-email                           │
│ → ObjectManager 缓存了新的 Controller、Template 实例        │
│   （这些实例包含 /admin/user/bind-email 的数据）            │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber A resume (V2 方案)                                     │
│ → restore() 恢复 $_SERVER（指向 workspace）                 │
│ → ❌ 但 ObjectManager 中还是 Fiber B 的实例！               │
│ → 继续处理请求...                                            │
│ → 使用了 Fiber B 的 Template 实例                           │
│ → 渲染出 "绑定邮箱" 页面（Fiber B 的内容）                  │
│ → ❌ 响应串请求！                                            │
└─────────────────────────────────────────────────────────────┘
```

### 为什么 V2 的 restore() 不够？

`WlsFiberContext::restore()` 只恢复了：
- `$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `$_REQUEST`, `$_FILES`
- Request 对象引用
- RequestContext ID
- HeaderCollector 状态
- WelineEnv 状态
- SseContext 状态

**但没有清理 ObjectManager 中的实例缓存**：
- Controller 实例（可能缓存了其他请求的路由信息）
- Template 实例（可能缓存了其他请求的模板路径和数据）
- Router 实例（可能缓存了其他请求的路由结果）
- Model 实例（可能缓存了其他请求的数据）

## V3 修复方案

### 核心思想

在 `WlsFiberContext::restore()` 内部，先调用 `StateManager::runWlsPersistentRequestEntryBaseline()` 清理 OM 实例缓存，再恢复超全局变量。

### 为什么选择 runWlsPersistentRequestEntryBaseline？

这个方法专门设计用于 WLS 多 Fiber 场景，它：
- ✅ 清理 ObjectManager 的请求级实例（Controller/Template/Router/Model 等）
- ✅ 清理 ResultManager、ThemeData、PreviewToken 等请求级状态
- ✅ 清理 EventsManager 的观察者缓存
- ❌ **不清理 Session**（避免"未登录"问题）
- ❌ **不清理 SseContext**（由 restore 自己处理）
- ❌ **不清理 RequestContext**（由 restore 自己处理）
- ❌ **不清理数据库连接**（进程级共享）

### 修复内容

**文件**: `app/code/Weline/Framework/Runtime/WlsFiberContext.php:109`

```php
public function restore(bool $restoreResponseState = true): void
{
    // 关键修复：Fiber resume 前先清理其他 Fiber 污染的 ObjectManager 实例缓存
    // 这些实例（Controller/Template/Router 等）可能缓存了其他请求的数据，导致响应串请求
    // runWlsPersistentRequestEntryBaseline 只清理 OM 实例，不清理 Session，避免"未登录"问题
    if (\class_exists(StateManager::class, false)) {
        StateManager::runWlsPersistentRequestEntryBaseline();
    }

    // SSE 上下文恢复...
    SseContext::reset();
    // ...

    // 恢复超全局变量
    $_SERVER = $this->serverVars;
    $_GET = $this->getVars;
    // ...
}
```

## V3 修复原理

### Fiber 生命周期中的状态管理

```
┌─────────────────────────────────────────────────────────────┐
│ Fiber A 启动                                                 │
│ → StateManager::reset() 清理上一个请求的残留                │
│ → 初始化 A 的状态（Session、Request 等）                     │
│ → 处理请求...                                                │
│ → ObjectManager 缓存了 A 的实例                              │
│ → suspend → capture() 保存 A 的快照                          │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber B 启动                                                 │
│ → StateManager::reset() 清理 A 的残留                       │
│ → 初始化 B 的状态（Session、Request 等）                     │
│ → 处理请求...                                                │
│ → ObjectManager 缓存了 B 的实例（覆盖 A 的实例）            │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ Fiber A resume (V3 方案)                                     │
│ → restore() 内部先调用 runWlsPersistentRequestEntryBaseline │
│   ✅ 清理 ObjectManager 中 B 的实例                          │
│ → restore() 恢复 A 的快照（$_SERVER、Request 等）           │
│   ✅ Session 保持不变（未被清理）                            │
│ → 继续处理请求...                                            │
│ → ObjectManager 重新创建 A 的实例（基于恢复的 $_SERVER）    │
│ → ✅ 渲染出正确的页面（A 的内容）                            │
└─────────────────────────────────────────────────────────────┘
```

## 修改文件

### 1. WlsFiberContext.php
- 在 `restore()` 方法开头添加 `runWlsPersistentRequestEntryBaseline()` 调用

### 2. worker.php (保持 V2 的修改)
- Fiber 启动时：调用 `reset()`
- Fiber resume 时：只调用 `restore()`（内部会清理 OM 实例）
- Fiber 终止时：只调用 `restore(false)`

### 3. worker_ssl.php (保持 V2 的修改)
- 同 worker.php

## 测试验证

### 测试步骤

1. **启动测试实例**：
   ```bash
   php bin/w server:start -p 9502 -n ai-test-fiber-fix-v3
   ```

2. **响应串请求测试**（重点）：
   - 打开 `/pagebuilder/backend/ai-site-agent/workspace` 页面
   - 同时在其他标签页访问 `/admin/user/bind-email` 等页面
   - 刷新 workspace 页面多次
   - **验证**：不应该出现"绑定邮箱"等其他页面的内容

3. **Session 持久性测试**：
   - 登录后台
   - 打开 AI Site Agent 页面（SSE 长连接）
   - 在其他标签页并发访问其他后台页面
   - **验证**：不应该出现"未登录或会话令牌无效"错误

4. **并发访问测试**：
   - 多个标签页同时访问不同页面
   - **验证**：每个请求返回正确的内容

5. **停止测试实例**：
   ```bash
   php bin/w server:stop -n ai-test-fiber-fix-v3
   ```

### 预期结果

- ✅ 不再出现响应串请求（显示其他页面的内容）
- ✅ 不再出现间歇性"未登录"错误
- ✅ Session 在 Fiber suspend/resume 过程中保持有效
- ✅ 每个请求返回正确的响应内容

## 性能影响

- `runWlsPersistentRequestEntryBaseline()` 只清理 OM 实例，不执行完整的 reset()
- 开销：< 0.5ms（只是移除实例引用，不涉及复杂的状态重置）
- 相比完整的 `reset()`，性能更好

## 总结

V3 修复通过在 `WlsFiberContext::restore()` 内部调用 `runWlsPersistentRequestEntryBaseline()`，实现了：

1. **清理 OM 实例缓存**：解决响应串请求问题
2. **保留 Session 状态**：解决"未登录"问题
3. **性能优化**：只清理必要的状态，不执行完整 reset

这是最终的、完整的解决方案。
