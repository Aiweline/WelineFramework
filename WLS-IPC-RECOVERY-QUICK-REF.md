# WLS IPC 自愈修复 - 快速参考

## 问题症状
```
[IPC-Worker#1:16903@default] [INFO] 连接 Master 失败 127.0.0.1:26895 - 由于目标计算机积极拒绝
[IPC-Worker#1:16903@default] [INFO] Worker 连接 Master 失败 (第 1/60 次)，100ms 后重试...
[IPC-Worker#1:16903@default] [INFO] 连接 Master 失败 (第 60/60 次)，无法连接
→ 无法自愈，Worker挂死
```

## 修复核心

### 1️⃣ Master IPC 心跳检查 (10秒快速失败)
```
旧逻辑: updated_at 超过 30 秒 → 继续轮询（等待新 Master）
新逻辑: updated_at 超过 10 秒 → 立即返回 0（快速失败）
效果: Worker 从 30 秒延迟 → 10 秒检测失败
```

### 2️⃣ Worker 连接失败 → 自动重连
```
旧逻辑: 连接失败 → 警告日志 → 独立运行（孤儿）× 无法自愈
新逻辑: 连接失败 → ERROR日志 → 重连循环
        重连间隔: 5秒, 6秒, 7秒... (最多15秒)
        重试次数: 30次 (共 150 秒窗口)
        成功后: 立即上报 READY 给 Master
效果: 即使 Master 启动晚 150 秒，Worker 也能自动恢复
```

### 3️⃣ Master IPC 启动失败 → 清晰诊断
```
旧逻辑: "无法启动 IPC 控制服务器，端口: 30081" ← 无助
新逻辑: "无法启动 IPC 控制服务器，端口: 30081
         （端口被占用，可能是前一个 Master 进程尚未完全退出...）
         这是严重错误，会导致所有 Worker 无法连接到 Master..."
效果: 用户知道问题原因 + 解决方案
```

## 测试命令

### 正常启动
```bash
php bin/w server:stop -n ai-test-ipc
php bin/w server:start -p 9502 -n ai-test-ipc
# 预期: Worker 连接成功，无重连日志
```

### 模拟并发启动延迟（验证自愈）
```bash
# 1. 启动Master
php bin/w server:start -p 9503 -n ai-test-ipc-delayed

# 2. 观察日志
#    你应该看到:
#    [IPC] IPC 控制通道初始连接失败 ...
#    [IPC] 第 1/30 次尝试与 Master 重新连接 ...
#    [IPC] 成功重新连接到 Master ✓

# 3. 停止并清理
php bin/w server:stop -n ai-test-ipc-delayed
```

### 模拟 Master 崩溃（验证孤儿检测）
```bash
# 1. 启动
php bin/w server:start -p 9504 -n ai-test-orphan

# 2. 找到 Master PID
ps aux | grep "weline-wls-master"          # Linux
tasklist | grep "php"                      # Windows

# 3. 杀死 Master
kill -9 <pid>                  # Linux
taskkill /PID <pid> /F         # Windows

# 4. 观察 Worker 日志
#    应该在 30 秒内检测到 Master 故障并退出
```

## 日志查看

### Linux/macOS
```bash
tail -f var/log/wls/*
# 或查看特定日期
tail -f "var/log/wls/wls-$(date +%Y-%m-%d).log"
```

### Windows PowerShell
```powershell
Get-Content "var\log\wls\$(Get-Date -Format 'yyyy-MM-dd').log" -Wait
```

### 关键日志模式

| 日志 | 症状判断 |
|------|---------|
| `[IPC] IPC 控制通道初始连接失败` | ⚠️ 并发启动延迟（预期，会自愈） |
| `[IPC] 第 1/30 次尝试...` | ℹ️ 自愈重连正在进行（正常） |
| `[IPC] 成功重新连接到 Master` | ✅ 自愈成功（这是好消息） |
| `IPC 控制通道已连接` + `已上报就绪` | ✅ 连接正常（最终目标状态） |
| `无法启动 IPC 控制服务器`（无诊断） | ❌ 故障（需要重启或检查端口） |

## 预期改进

| 场景 | 修复前 | 修复后 |
|------|--------|--------|
| 并发启动延迟 | Worker 永久失败 ❌ | Worker 自动重连 ✅ |
| Master 晚启 5 秒 | Worker 失败 ❌ | Worker 等待 + 连接 ✅ |
| Master 晚启 60 秒 | Worker 失败 ❌ | Worker 60秒内自动恢复 ✅ |
| Master 超晚启 150 秒 | Worker 失败 ❌ | Worker 独立运行（孤儿但正常） ⚠️ |
| Master 启动失败 | 所有 Worker 挂死 ❌ | Worker 进入孤儿检测，60秒后自杀 ⚠️ |

## 问题诊断

### 如果修复后仍然连接失败

**1. 检查 control_port 分配**
```bash
# 查看实例文件
cat var/server/instances/default.json

# 应该看到：
# {
#   "control_port": 30081,
#   "updated_at": <recent timestamp>,
#   ...
# }
```

**2. 检查端口是否真的监听**
```bash
# Linux
lsof -i :30081
netstat -tlnp | grep 30081

# Windows PowerShell
netstat -ano | findstr :30081
```

**3. 检查防火墙**
```bash
# Windows: 确保本地 127.0.0.1 不被阻止
# Linux: 检查 iptables/firewall-cmd
```

**4. 查看完整日志**
```bash
# 启用 DEBUG 日志
# 在 app/etc/env.php 中设置:
'wls' => [
    'log' => [
        'level' => 'DEBUG',  # 改为 DEBUG
    ],
]
```

## 回滚方法

如果修复有问题，恢复旧代码：

```bash
git checkout -- \
  app/code/Weline/Server/IPC/ChildControl/SubprocessControlKernel.php \
  app/code/Weline/Server/bin/worker.php \
  app/code/Weline/Server/bin/worker_ssl.php \
  app/code/Weline/Server/Service/ServiceOrchestrator.php
```

然后重启：
```bash
php bin/w server:stop -n default
php bin/w server:start
```

## 联系方式

如遇问题：
- 查看本文档的"问题诊断"章节
- 检查 `FIXES_2026-04-07-WLS-IPC-RECOVERY.md` 完整文档
- 收集日志信息后反馈

