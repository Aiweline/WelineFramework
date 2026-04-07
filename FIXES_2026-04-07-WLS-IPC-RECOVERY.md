# WLS Master/Worker IPC 自愈修复

**日期**：2026-04-07  
**问题**：并发启动时，Worker连接Master IPC失败，无法自动恢复，导致Worker变成孤儿进程  
**症状**：`[IPC-Worker#1] CONNECT FAILED 连接 Master 失败 127.0.0.1:26895 - 由于目标计算机积极拒绝`  

## 根本原因

### 架构设计缺陷
1. **分布式并发启动**：Master和Worker同时由Start.php启动，各自独立进程
2. **时序窗口**：
   - Master需要时间启动IPC服务器 + 写入JSON文件
   - Worker立即调用 `resolveControlPort()` 尝试发现Master IPC端口
3. **无自愈能力**：Worker连接失败一次后就放弃，无法重连

### 连接失败的具体流程
```
Master启动:
  [1ms] 计算control_port = 20000 + 9981 + 100 = 30081
  [10ms] 创建MasterControlServer
  [50ms] bootstrapControlPlane() - 启动IPC TCP监听
  [100ms] saveMasterInfo('bootstrapping') - 写JSON文件 (control_port=30081, updated_at=now)
  [200ms] 进入runLoopWithDeferredChildStartup()
  [1500ms] Fiber内真正拉起Worker

Worker启动（并发，由Start.php启动）:
  [5ms] resolveControlPort() 轮询等待JSON
  [6000ms] 如果6秒内拿到control_port，连接到IPC
       但如果JSON中的updated_at已过期（>30秒），函数之前会继续轮询而不是返回端口
  [6500-10000ms] connectAndRegister() 失败
  [10100ms] 静默继续独立运行 ← 源头问题！没有重连机制
```

## 修复方案

### 修复1：改进 `resolveControlPort()` 的心跳检查
**文件**：`app/code/Weline/Server/IPC/ChildControl/SubprocessControlKernel.php`

**改动**：
- 前2秒 = 宽限期，接受任何control_port（即使updated_at有点旧）
- 2秒后 = 严格模式，只有updated_at ≤ 10秒的才接受
- 超过10秒未更新 = 立即返回0（快速失败而非继续轮询）

**效果**：
- 避免Worker长时间等待过期的Master信息
- 快速检测Master故障（10秒vs之前的30秒）

---

### 修复2：Worker IPC连接失败的自愈机制
**文件**：
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`

**改动**：
1. 连接失败时改为ERROR日志 + 标记孤立模式（而非无声失败）
2. 主业务循环中添加定期重连逻辑：
   - 初始延迟：5秒
   - 指数退避：每次尝试加1秒延迟（5秒、6秒、7秒...最多15秒）
   - 最大重试：30次（共约150秒窗口）
   - 成功后立即上报READY给Master

**伪代码效果**：
```php
// 初始化时，若IPC连接失败
if (!$kernel->connectAndRegister($controlPort)) {
    WlsLogger::error_("[IPC] IPC 控制通道初始连接失败 (控制端口: {$controlPort})");
    $ipcReconnectAttempts = 0;
    $ipcReconnectMaxAttempts = 30;
    $ipcReconnectDueTime = now + 5.0;  // 5秒后第一次重连
}

// 主循环中，定期检查是否需要重连
if (now >= $ipcReconnectDueTime && $ipcReconnectAttempts < $ipcReconnectMaxAttempts) {
    $ipcReconnectAttempts++;
    if ($kernel->connectAndRegister($controlPort)) {
        // 成功！清理变量，恢复正常
        unset($ipcReconnectDueTime, $ipcReconnectAttempts, ...);
        WlsLogger::info_("[IPC] 成功重新连接到 Master");
    } else {
        // 失败，指数退避后重试
        $nextRetryDelay = 5 + min($ipcReconnectAttempts, 10);
        $ipcReconnectDueTime = now + $nextRetryDelay;
    }
}
```

---

### 修复3：Master IPC启动失败的诊断
**文件**：`app/code/Weline/Server/Service/ServiceOrchestrator.php`

**改动**：
```php
if (!$this->controlServer->start('127.0.0.1', $context->controlPort)) {
    // 新增：诊断端口占用
    $portInUseMsg = '';
    if (Processer::isPortInUse($context->controlPort)) {
        $portInUseMsg = " （端口被占用，可能是前一个 Master 进程尚未完全退出...）";
    }
    throw new RuntimeException(
        "无法启动 IPC 控制服务器，端口: {$context->controlPort}{$portInUseMsg}. " .
        "这是严重错误，会导致所有 Worker 无法连接到 Master，系统无法正常运行。"
    );
}
```

**效果**：
- 清晰提示为什么IPC启动失败
- 建议解决方案（等待、杀死占用进程等）

---

## 修复验证

### 预期行为

**场景1：正常启动**
```
Master: IPC 控制服务器已启动，端口: 30081
Worker: IPC 控制通道已连接 (控制端口: 30081)
Worker: 已上报就绪状态
```

**场景2：并发启动延迟**（修复前会失败）
```
Master: IPC 控制服务器已启动，端口: 30081
Master: 启动中...
Worker: [错误] IPC 控制通道初始连接失败 (控制端口: 30081)
Worker: [警告] 进入重连循环
Worker: 独立运行中...
Master: 完成启动
Worker: [重连] 第 1/30 次尝试与 Master 重新连接
Worker: [成功] 重新连接到 Master，已上报就绪状态
```

**场景3：Master IPC启动失败**
```
Master: [错误] 无法启动 IPC 控制服务器，端口: 30081
Master: （端口被占用，可能是前一个 Master 进程尚未完全退出...）
Master: 这是严重错误，会导致所有 Worker 无法连接到 Master...
Worker: [错误] IPC 控制通道初始连接失败
Worker: [重连] 第 1/30 次尝试... [失败]
Worker: [重连] 第 2/30 次尝试... [失败]
...（150秒后）
Worker: [警告] 达到最大重试次数，Worker 将继续独立运行（处于孤儿模式）
```

### 测试步骤

1. **启动WLS系统**：
   ```bash
   php bin/w server:start -p 9502 -n ai-test-ipc-recovery
   ```

2. **观察日志**：
   - 查看Worker连接IPC的日志
   - 确认没有长时间重试错误

3. **模拟故障**：
   ```bash
   # 杀死Master
   taskkill /PID <master-pid> /F
   ```
   - Worker应该进入孤儿模式
   - 定期尝试重连（最多150秒）

4. **恢复测试**：
   ```bash
   php bin/w server:start -p 9502 -n ai-test-ipc-recovery
   ```
   - 新Master启动
   - 孤儿Worker应该成功重连

---

## 修改文件清单

| 文件 | 修改行数 | 修复内容 |
|------|--------|--------|
| `SubprocessControlKernel.php` | 41-75 | 改进心跳检查逻辑 |
| `worker.php` | 888-891 初始化 / 1156-1173 主循环 | 自愈重连机制 |
| `worker_ssl.php` | 1490-1510 初始化 / 1687-1703 主循环 | 自愈重连机制 |
| `ServiceOrchestrator.php` | 1218-1222 | IPC启动失败诊断 |

---

## 后续优化建议

### 短期（现有修复）
✅ 已完成：自愈重连机制，让Worker能在Master故障后恢复

### 中期（可选）
- [ ] 在Master端添加孤儿Worker定期清理机制
- [ ] 添加监控告警，当Worker频繁重连时发出警告
- [ ] 调整重连参数为可配置（环境变量或env.php）

### 长期（架构改进）
- [ ] 考虑Master/Dispatcher多副本设计（HA）
- [ ] 使用更可靠的服务发现机制（如配置中心）
- [ ] 实现Worker启动延迟策略（等待Master ready信号）

---

## 相关日志关键词

监控以下关键词判断修复是否生效：

| 日志 | 含义 |
|------|------|
| `[IPC] IPC 控制通道初始连接失败` | Worker初始连接失败，进入自愈模式 |
| `[IPC] 第 X/30 次尝试与 Master 重新连接` | 自愈重连正在进行 |
| `[IPC] 成功重新连接到 Master` | 自愈成功，恢复正常 |
| `无法启动 IPC 控制服务器` | Master IPC启动失败（严重） |
| `端口被占用...前一个 Master 进程` | 端口占用诊断信息 |

