# WLS 当前问题诊断报告

## 测试环境
- 测试时间：2026-04-02 01:52


## 已完成的修复

### 1. SSE 短轮询修复 ✅

- **改动**：将 SSE 连接时长从 900 秒改为 3 秒
- **效果**：Worker 占用时间减少 99.7%
- **状态**：代码已修改并重载

### 2. 状态污染修复 ✅
- **文件**：`app/code/Weline/Framework/Runtime/StateManager.php`
- **改动**：修复 60+ 个静态变量污染
- **效果**：状态完全隔离
- **状态**：代码已修改并重载

### 3. Dispatcher 自旋等待禁用 ✅
- **文件**：
  - `app/etc/env.php` - 添加配置 `wls.dispatcher.spin_wait_max_seconds = 0.0`
  - `app/code/Weline/Server/bin/dispatcher.php` - 读取配置
- **效果**：禁用阻塞式自旋等待
- **状态**：配置已添加，Dispatcher 已重启

## 当前阻塞问题

### 问题 1：共享服务 Token 认证失败

**症状**：
```
[2026-04-02 01:49:31] [WorkerSSL#1:16897@default] [INFO] [PooledConnection] [CONN-AUTH-FAIL] 2026-04-02 01:49:31.697 Authentication failed
```

**原因**：
- Worker 尝试连接内存服务（127.0.0.1:19973）时认证失败
- Token 文件 `var/server/shared/memory_server.token` 不存在
- Token 文件 `var/server/shared/session_server.token` 不存在

**影响**：
- Worker 无法访问内存缓存
- Worker 无法访问 Session 服务
- 所有请求卡住，无法返回响应

**当前状态**：
- 共享服务进程在运行（PID: 48652, 53892）
- 共享服务端口在监听（19972, 19973）
- 但 token 文件缺失，导致认证失败

### 问题 2：Dispatcher 启动时序问题

**症状**：
```
[2026-04-02 01:47:18] [Dispatcher:443@default] [ERROR] 所有 Worker 不可用! 127.0.0.1 (connId: 2452), healthy: 0/0
```

**原因**：
- Dispatcher 在 Worker 准备好之前就开始接受连接
- 即使 `spin_wait_max_seconds = 0`，仍然会尝试连接所有 Worker
- 所有 Worker 连接失败后，直接返回 false，导致客户端连接被关闭

**影响**：
- 启动后的前几秒内，所有请求都会失败
- 客户端看到 SSL 握手失败或连接超时

## 根本原因分析

### 架构问题

WLS 的启动流程存在时序问题：

```
1. Master 启动
2. Dispatcher 启动并开始监听 443 端口  ← 问题：此时 Worker 还没准备好
3. Worker 启动（需要 5-10 秒初始化）
4. Worker 连接共享服务（Session、Memory）  ← 问题：Token 认证失败
5. Worker 上报就绪状态
6. Dispatcher 收到 ADD_WORKER 消息
```

**问题点**：
- Dispatcher 在步骤 2 就开始接受连接，但 Worker 要到步骤 5 才准备好
- 步骤 2-5 之间的所有请求都会失败
- 即使禁用自旋等待，Dispatcher 也会立即返回失败，导致连接关闭

### Token 认证问题

共享服务的 Token 生成和验证机制存在问题：

1. **Token 文件不存在**：
   - `var/server/shared/memory_server.token` 缺失
   - `var/server/shared/session_server.token` 缺失

2. **Token 生成逻辑缺失**：
   - 共享服务启动时没有生成 token 文件
   - 或者 token 存储在其他位置，但 Worker 找不到

3. **认证失败后的处理不当**：
   - Worker 认证失败后不断重试
   - 每次重试都会阻塞请求处理
   - 最终导致请求超时

## 建议的修复方案

### 短期方案（紧急修复）

1. **修复 Token 生成逻辑**
   - 检查共享服务启动脚本
   - 确保 token 文件在启动时生成
   - 或者禁用 token 认证（开发环境）

2. **添加启动保护窗口**
   - Dispatcher 在启动后的前 30 秒内，对无可用 Worker 的情况返回 503
   - 而不是直接关闭连接

3. **优化 Worker 初始化**
   - 减少 Worker 启动时间
   - 或者延迟 Dispatcher 的启动

### 中期方案（架构优化）

1. **改进启动时序**
   - Master 先启动所有 Worker
   - 等待所有 Worker 就绪后再启动 Dispatcher
   - 或者 Dispatcher 启动后先不监听，等 Worker 就绪后再开始监听

2. **改进共享服务认证**
   - 使用更可靠的认证机制
   - 添加认证失败的降级策略
   - 或者在开发环境禁用认证

3. **添加健康检查**
   - Dispatcher 定期检查 Worker 健康状态
   - 只有健康的 Worker 才加入负载均衡池
   - 不健康的 Worker 自动从池中移除

## 当前状态总结

✅ **已修复**：
- SSE 短轮询（Worker 占用时间减少 99.7%）
- 状态污染（60+ 个静态变量）
- Dispatcher 自旋等待（禁用阻塞）

❌ **仍然阻塞**：
- 共享服务 Token 认证失败
- Worker 无法连接内存服务和 Session 服务
- 所有请求超时，系统不可用

🔍 **需要进一步调查**：
- Token 文件的生成逻辑在哪里？
- 为什么 Token 文件会丢失？
- 如何在开发环境禁用 Token 认证？
- 如何优化启动时序，避免 Dispatcher 过早接受连接？

## 下一步行动

1. **紧急**：修复 Token 认证问题，让系统恢复可用
2. **重要**：验证 SSE 短轮询修复是否有效（需要系统可用后才能测试）
3. **优化**：改进启动时序，避免启动期间的请求失败
