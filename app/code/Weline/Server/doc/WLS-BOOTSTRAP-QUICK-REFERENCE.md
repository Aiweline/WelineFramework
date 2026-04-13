# WLS 启动优化 - 快速参考 (2026-04-13)

## 🎯 核心改进总结

| 改进点 | 文件 | 效果 |
|--------|------|------|
| **Windows 批启动非阻塞** | Processer.php | Master 不再被阻塞，IPC 立即可用 |
| **启动验收完成标志** | ServiceOrchestrator.php | 启动阶段和运行阶段分离，避免重复启动 |
| **并发启动逻辑** | ServiceOrchestrator.php | 所有 Provider 真正并发启动，不串行 |
| **InstanceInfoGateway** | ChildControl/* | Worker 自动发现最新 Master IPC 端口 |

---

## ❌ 之前的问题

```
Worker 日志：
  [HttpRedirect:80@default] [INFO] [IPC-Redirect] CONNECT FAILED 连接 Master 失败 127.0.0.1:26895
  [HttpRedirect:80@default] [INFO] [Redirect] 连接 Master 失败 (第 16/60 次)，100ms 后重试...
```

**原因链**：
1. batchCreateWindows 同步等待所有进程启动完成
2. Master 主线程被阻塞在 Processer::batchCreate()
3. Master 无法进入 mainLoop 初始化 IPC
4. Worker 启动后无法连接 Master

---

## ✅ 现在的流程

```
Master 启动
  ├─ bootstrapControlPlane()              [初始化 IPC socket]
  ├─ startAllChildServices()
  │  ├─ startProvidersBatch(allProviders) [一次启动所有 Provider]
  │  │  └─ Processer::batchCreate()      [快速返回，50ms]
  │  ├─ waitForStartupAcceptance()       [等待所有进程上报 READY]
  │  └─ 设置 startupAcceptanceComplete = true
  └─ runLoop()                            [主循环开始]
     └─ IPC 监听已就绪 ✓

Worker 启动
  └─ 通过 InstanceInfoGateway 连接 Master ✓
```

**关键**：Master 在 batchCreate 返回后立即进入 mainLoop，不阻塞！

---

## 📝 关键配置

### 启用并发启动模式

```ini
# weline.env
wls.orchestrator.concurrent_startup=true
```

**效果**：所有 Provider 的所有实例同时启动，而不是分两阶段。

### Windows 调试模式（等待模式）

```php
// 仅在调试时
define('WELINE_BATCH_CREATE_WAIT_RESULTS', true);
```

**效果**：batchCreate 会同步等待所有进程完成（用于排查问题时）。

### 其他配置

```ini
# 启动超时 (秒)
wls.orchestrator.startup_timeout_sec=30

# 健康检查间隔（仅在启动完成后启动）
wls.orchestrator.health_check_interval_sec=30

# Reconcile 协调间隔（仅在启动完成后启动）
wls.orchestrator.reconcile_interval_sec=5
```

---

## 🔍 验证成功启动

### 查看启动日志

```bash
php bin/w server:start --frontend 2>&1 | grep -E "并发启动|启动验收|服务器准备"
```

**期望输出**：
```
[Orchestrator] 进入并发启动模式：所有服务同时启动
[Orchestrator] 启动验收通过: 所有关键角色 READY 已达阈值
[Orchestrator] 服务器准备就绪
```

### 检查 Master 是否监听

```bash
# Windows
netstat -ano | findstr "26895"
# 应该看到 LISTENING 状态

# Linux/Mac
lsof -i :26895
# 应该看到 master 进程
```

### 测试 Worker 连接

```bash
# 查看 Worker 日志
php bin/w server:start --frontend 2>&1 | grep -E "READY|上报"
# 应该看到 Worker 快速上报 READY，没有连接失败
```

---

## ⚠️ 常见问题

### Q: Worker 仍无法连接 Master

**A: 检查点**
1. Master 是否进入主循环？
   ```bash
   netstat -ano | findstr "26895"  # 应该 LISTENING
   ```
2. instance 文件是否正确？
   ```bash
   cat var/server/instances/master.json
   ```
3. 是否无意中启用了 WELINE_BATCH_CREATE_WAIT_RESULTS？

### Q: 进程被重复启动

**A: 原因和解决**
- **原因**：启动验收未完成，健康检查就已开始
- **解决**：检查日志中是否出现 `启动验收完成` 再出现周期任务
- **排查**：查看 ServiceOrchestrator.php 中 startupAcceptanceComplete 的设置

### Q: Master 启动仍然卡顿

**A: 排查步骤**
1. 检查 WELINE_BATCH_CREATE_WAIT_RESULTS 是否被意外定义
2. 查看系统资源（CPU、内存、磁盘）
3. 检查 PowerShell 脚本执行速度（查看 var/log）

---

## 📋 修改检查清单

实施此优化时确保：

- [ ] Processer.php 的 batchCreateWindows 已更新为非阻塞模式
- [ ] ServiceOrchestrator.php 包含 $startupAcceptanceComplete 标志
- [ ] 所有周期任务都加了 startupAcceptanceComplete 条件检查
- [ ] startAllChildServicesBody 使用 startProvidersBatch(allProviders) 一次调用
- [ ] 测试了 --frontend 模式下 Worker 的启动和连接
- [ ] 确认日志中出现 "并发启动模式" 和 "启动验收通过"

---

## 🔗 详细文档

详见 [WLS 编排器启动流程优化 (2026-04-13)](WLS-ORCHESTRATOR-BOOTSTRAP-2026-04-13.md)

包含：
- 完整时序图
- 故障排查指南
- 向后兼容性说明
- 测试建议
- 性能对比

---

## 📞 问题反馈

如发现不正常现象：
1. 收集完整日志：`php bin/w server:start 2>&1 | tee /tmp/wls.log`
2. 查看启动耗时
3. 检查 Worker 连接状态
4. 参考详细文档中的故障排查部分
