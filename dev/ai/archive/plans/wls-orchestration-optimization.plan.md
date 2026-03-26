---
name: WLS 编排框架级优化
overview: 在 Weline Server 编排层引入分级故障策略、滚动重启稳定期、可配置策略与退出归因，避免单实例 IPC 断开直接触发整组重启。本计划已结合代码深度审查，纳入修正项。
status: completed
todos:
  - id: config-policy
    content: 可配置策略：读取 single_restart_first、escalation_*、stabilization_sec、critical_roles
    status: completed
  - id: escalation-handler
    content: 分级故障：handleIpcDisconnect 内按角色调用 scheduleResurrection，超阈值才 requestFullRestart
    status: completed
  - id: stabilization-window
    content: 滚动重启稳定期：rollingRestartStabilizingUntil + 稳定期内新实例断开仅单实例重启
    status: completed
  - id: exit-attribution
    content: Worker 退出归因：ControlMessage.TYPE_EXIT_REASON，Worker 发送，Master 记录
    status: completed
  - id: phase4-optimization
    content: 阶段4优化：MasterControlServer.closeClient，进程已退出时主动关闭 socket
    status: completed
  - id: fix-restarts-pass
    content: 修复 processResurrectionQueue 中 restarts 传递（removeInstance 前保存）
    status: completed
  - id: health-check-policy
    content: 健康检查与分级策略一致：可恢复角色走 scheduleResurrection
    status: completed
---

# WLS 编排框架级优化计划

## 目标

将当前「IPC 断开即整组重启」改为**分级故障处理**：Worker 等可恢复角色先单实例重启，仅在达到升级条件或核心服务失败时才整组重启；滚动重启结束后增加**稳定期**；策略**可配置**；Worker 退出时向 Master **上报原因**。已结合代码审查修正原计划的若干疏漏。

## 代码审查结论（必读）

| 问题 | 结论 | 计划修正 |
|------|------|----------|
| scheduleResurrection 从未被调用 | 复活队列逻辑存在但无触发点 | 在 handleIpcDisconnect 中**新增**调用，而非"复用" |
| fullRestartOnFailure 恢复时机 | finally 立即执行，poll 可能在其后收到断开 | 稳定期独立于 fullRestartOnFailure，用 rollingRestartStabilizingUntil |
| startedAt vs readyAt | startedAt 是进程启动时刻，非 READY 时刻 | 在 metadata 记录 readyAt，或文档注明 getUptime() 边界情况 |
| 健康检查也走 requestFullRestart | 与分级策略不一致 | 健康检查中可恢复角色改为 scheduleResurrection |
| 退出归因在 Fatal 场景 | shutdown 时 IPC 可能不可用 | 注明 best-effort，Master 兼容缺失 |
| 阶段4 优化依赖 | 需主动关闭 socket 才能提前结束等待 | 新增 MasterControlServer.closeClient |
| processResurrectionQueue 中 restarts | removeInstance 后 getInstance 为 null，restarts 丢失 | 在 removeInstance 前保存 oldInstance->restarts |

---

## 1. 分级故障策略（Escalation）

**设计**：Worker（及可扩展的其他可恢复角色）先单实例重启，超阈值才整组重启；核心角色保持或可配置。

**关键修正**：`scheduleResurrection` 与 `scheduleResurrectionWithDelay` 当前**从未被调用**，需在 `handleIpcDisconnect` 中**新增**调用。

**实现要点**：

- [ServiceOrchestrator.php](app/code/Weline/Server/Service/ServiceOrchestrator.php) `handleIpcDisconnect`（约 2611-2640 行）：
  - 对可恢复角色（如 worker，由 `getResurrectionPriority() > 0` 判定）：若未超 escalation 阈值，调用 `scheduleResurrection($instance)` 或 `scheduleResurrectionWithDelay($instance, $delay)`，然后 return。
  - 超阈值或核心角色：调用 `requestFullRestart`。
- 新增「按角色的最近断开记录」用于 escalation 计数：例如 `array<string, array{count: int, windowStart: float}>`。
- 配置项：`escalation_window_sec`、`escalation_threshold`、`critical_roles`。

---

## 2. 滚动重启稳定期

**设计**：`gracefulReloadInstances` 完成后进入稳定期；稳定期内对新就绪实例的 IPC 断开仅单实例重启。

**关键修正**：稳定期判断**独立于** `fullRestartOnFailure`，使用 `rollingRestartStabilizingUntil`，避免 `finally` 先恢复导致误触发整组重启。

**实现要点**：

- 新增 `rollingRestartStabilizingUntil: float = 0`。
- `gracefulReloadInstances` 的 try 块末尾（foreach 之后）设置：`$this->rollingRestartStabilizingUntil = microtime(true) + $this->stabilizationSec`。
- `handleIpcDisconnect` 开头：若 `now < rollingRestartStabilizingUntil` 且 `instance->getUptime() < stabilizationSec`（或 `metadata['ready_at']` 若实现），则调用 `scheduleResurrection` 并 return。
- 主循环中：每次检查 `rollingRestartStabilizingUntil` 过期则置 0，不修改 `fullRestartOnFailure`。

**新实例判断**：优先在设置 `STATE_READY` 时写入 `instance->metadata['ready_at']`，用 `now - readyAt` 判断；否则用 `getUptime()` 并接受边界误判。

---

## 3. 可配置策略

**新增配置项**（`server.orchestrator`）：

- `single_restart_first` (bool，默认 true)
- `escalation_window_sec` (float，默认 60)
- `escalation_threshold` (int，默认 3)
- `stabilization_sec` (float，默认 15)
- `critical_roles` (array，如 `['dispatcher','session_server','redirect']`)

在 `app/etc/env.sample.php` 中补充说明与默认值。

---

## 4. Worker 退出归因

**设计**：Worker 退出前发送 `TYPE_EXIT_REASON`；Master 记录到 instance metadata 或日志。

**修正**：Fatal Error 场景下归因为 **best-effort**，Master 需兼容缺失 reason。

**协议**：[ControlMessage.php](app/code/Weline/Server/IPC/ControlMessage.php) 新增 `TYPE_EXIT_REASON`，payload 含 `reason`、`code`（可选）。

**Worker 侧**：[worker_ssl.php](app/code/Weline/Server/bin/worker_ssl.php) 在 `$gracefulExit` 及 `register_shutdown_function` 中，在关闭 IPC 前尽可能发送；Fatal 时若发送失败则忽略。

---

## 5. 阶段4 优化（等待断开）

**设计**：进程已退出时，Master 主动关闭其 socket，避免 5s 超时。

**修正**：需在 [MasterControlServer.php](app/code/Weline/Server/IPC/MasterControlServer.php) 新增 `closeClient(int $clientId): void`（或等价方法），内部关闭 socket 并调用现有 removeClient 逻辑。

**实现**：`waitForAllDisconnectedWithProgress` 中，对 `!isProcessRunning(pid)` 且 `ipcClientId` 有效的实例，调用 `controlServer->closeClient(ipcClientId)`。

---

## 6. 修复 processResurrectionQueue 中 restarts 传递

**问题**：约 1699 行 `removeInstance` 后，1706 行 `getInstance` 返回 null，`restarts` 被置为 0。

**修正**：在 `removeInstance` 之前读取 `$oldRestarts = $oldInstance?->restarts ?? 0`，创建新实例后设置 `$newInstance->restarts = $oldRestarts`。

---

## 7. 健康检查与分级策略一致

**设计**：`performHealthChecks` 中，对可恢复角色（Worker 等）在 `dead_without_ipc`、`no_ipc_timeout` 等场景下，优先调用 `scheduleResurrection` 而非 `requestFullRestart`；超 `maxRestarts` 或 escalation 阈值再整组重启。核心角色保持 `requestFullRestart`。

---

## 实施顺序

| 阶段 | 内容 | 依赖 |
|------|------|------|
| 1 | 可配置策略 + 修复 restarts 传递 | 无 |
| 2 | 分级故障：handleIpcDisconnect 调用 scheduleResurrection | 1 |
| 3 | 滚动重启稳定期 | 1, 2 |
| 4 | Worker 退出归因 | 无 |
| 5 | 阶段4 优化：closeClient | 无 |
| 6 | 健康检查与分级策略一致 | 2 |

---

## 涉及文件

- [app/code/Weline/Server/Service/ServiceOrchestrator.php](app/code/Weline/Server/Service/ServiceOrchestrator.php)
- [app/code/Weline/Server/Service/Contract/ServiceInstance.php](app/code/Weline/Server/Service/Contract/ServiceInstance.php)（可选 readyAt）
- [app/code/Weline/Server/IPC/ControlMessage.php](app/code/Weline/Server/IPC/ControlMessage.php)
- [app/code/Weline/Server/IPC/MasterControlServer.php](app/code/Weline/Server/IPC/MasterControlServer.php)
- [app/code/Weline/Server/bin/worker_ssl.php](app/code/Weline/Server/bin/worker_ssl.php)
- [app/etc/env.sample.php](app/etc/env.sample.php)
