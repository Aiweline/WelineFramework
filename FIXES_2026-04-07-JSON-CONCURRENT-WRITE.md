# JSON 文件并发写入导致的 IPC 连接失败 - 修复

**时间**：2026-04-07  
**问题**：当 control_port 自动顺延时（26895→26896），JSON文件中仍记录旧的端口，导致 Worker 连接失败  
**原因**：Master 和 Start.php 的 JSON 写入机制不一致（竞态条件）

## 问题诊断

### 日志证据
```
[2026-04-07 07:02:19] [Master] 控制端口: 26896（首选 26895 不可用，已自动顺延）
[2026-04-07 07:02:19] [Orchestrator] IPC 控制服务器已启动，端口: 26896

但 default.json 中记录的是：
  "control_port": 26895  ← 过时值！

结果：
Worker 尝试连接 127.0.0.1:26895 → 失败！（Master 实际在 26896）
```

### 根本原因

| 流程 | 工具 | 机制 |
|------|------|------|
| Start.php 写入初始JSON | `ServerInstanceManager::atomicWriteJsonStatic()` | ✅ 原子 + 5秒锁 |
| Master 更新control_port | `@file_put_contents()` | ❌ 直接写入，无锁 |

**竞态条件**：
1. Start.php 获取 lock，写入初始JSON（不含control_port或值为0）
2. Start.php 启动 Master 进程，然后释放 lock
3. Master 计算 control_port = 26896（26895被占用）
4. Master 调用 saveMasterInfo()，使用 file_put_contents() 直接写入
5. **但此时 lock 文件可能仍被其他进程持有或新的 Start 进程需要更新JSON**
6. Master 的写入被忽略或与其他写操作冲突
7. **最终 default.json 记录的仍是旧的 control_port**

## 修复方案

### 修复1：Master 的 saveMasterInfo() 使用原子写入

**文件**：`app/code/Weline/Server/Service/MasterProcess.php` L764-803

**改动**：
```php
// 修复前（不安全）：
@\file_put_contents($instanceFile, \json_encode($data, JSON_PRETTY_PRINT));

// 修复后（原子写入）：
\Weline\Server\Service\ServerInstanceManager::atomicWriteJsonStatic($instanceFile, $data, 10);
```

**效果**：
- 使用与 Start.php 相同的锁机制
- 10秒超时（比Start.php的5秒更宽松，给Master充足时间）
- 避免竞态条件

---

### 修复2：Start.php 初始化 control_port 字段

**文件**：`app/code/Weline/Server/Console/Server/Start.php` L3628-3631

**改动**：
```php
// 新增：
'control_port' => 0,  // 由 Master 进程计算并更新
```

**效果**：
- Worker 启动时，JSON中至少包含 control_port 字段
- 即使值为 0，也比缺失好
- Worker 的 resolveControlPort() 可以区分"字段不存在"和"字段值为0"

---

## 修复验证

### 测试方法

**场景1：control_port 正常分配**
```bash
php bin/w server:stop -n default
php bin/w server:start -p 9501 -n default
```

预期日志：
```
[Master] 控制端口: 26895
[Orchestrator] IPC 控制服务器已启动，端口: 26895

检查 default.json：
  "control_port": 26895  ✓ 匹配
```

**场景2：control_port 被占用（自动顺延）**
```bash
# 1. 先启动一个实例占用26895
php bin/w server:start -p 9501 -n test1

# 2. 再启动一个实例，应该自动顺延到26896
php bin/w server:start -p 9502 -n test2
```

预期日志：
```
[Master-test2] 控制端口: 26896（首选 26895 不可用，已自动顺延）

检查 test2.json：
  "control_port": 26896  ✓ 正确值
  "updated_at": <当前时间>  ✓ 证明被Master更新过
```

**场景3：Worker 连接**
```bash
# 启动后查看日志
tail -f var/log/wls/debug.log | grep "IPC"
```

预期（修复前）：
```
[Worker] CONNECT FAILED 连接 Master 失败 127.0.0.1:26895
[Worker] 连接 Master 失败 (第 1/60 次)...
```

预期（修复后）：
```
[Worker] [IPC-Worker#1] CONNECT 已连接 Master 127.0.0.1:26896  ✓
[Worker] IPC 控制通道已连接 (控制端口: 26896)  ✓
```

---

## 深层问题分析

### 设计缺陷

1. **两套不同的写入机制**
   - Start.php 用 atomicWriteJsonStatic()（有锁）
   - Master 原来用 file_put_contents()（无锁）
   - 混合使用导致竞态条件

2. **缺乏初始值**
   - Start.php 没有生成 control_port 字段
   - Master 计算后才写入
   - 时间窗口内 Worker 可能读到 undefined

3. **lock 文件持久化**
   - `.lock` 文件需要手动清理
   - 如果没有正确清理，可能导致长期锁定

### 长期改进方向

- [ ] 统一所有 JSON 写入为 atomicWriteJsonStatic()
- [ ] 在 Start.php 添加 lock 文件自动清理
- [ ] 添加超时机制，避免死锁
- [ ] 在 Worker 启动前，验证 control_port 是否存在

---

## 修改清单

| 文件 | 行号 | 修改 | 版本 |
|------|------|------|------|
| MasterProcess.php | 803 | 使用 atomicWriteJsonStatic() | Done ✓ |
| Start.php | 3630 | 初始化 control_port = 0 | Done ✓ |
| SubprocessControlKernel.php | 41-75 | 改进心跳检查逻辑 | Done ✓ |
| worker.php | 888-1173 | 添加重连循环 | Done ✓ |
| worker_ssl.php | 1490-1703 | 添加重连循环 | Done ✓ |
| ServiceOrchestrator.php | 1218-1222 | IPC启动失败诊断 | Done ✓ |

---

## 预期效果

| 症状 | 修复前 | 修复后 |
|------|--------|--------|
| control_port 被占用 | JSON不更新，Worker连接失败 | JSON正确更新到新端口，Worker成功连接 |
| 并发启动 | 竞态导致JSON数据不一致 | 原子写入保证一致性 |
| Worker 重连 | 无 | 自动重连最多30次 |
| 启动失败提示 | 模糊 | 清晰的端口占用诊断 |

