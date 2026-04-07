# 维护模式快速进入验证指南

## 测试目标
确保 Dispatcher 维护快速通道（改动 3.2 + 3.3）能够快速将请求路由到维护 Worker，解决"派遣器维护 Worker 进不去"问题。

---

## 测试前提

1. 确保 WLS 系统已启动，包括 Master、Dispatcher、业务 Worker、Session/Memory 服务
2. 业务 Worker 处于正常状态（多个端口，如 9502, 9503, 9504 等）
3. 需要能够发送 Master 控制命令（通过 Dispatcher 的控制通道或直接 IPC）
4. 准备监控日志，特别是 Dispatcher 日志

---

## 测试步骤

### 步骤 1：基线测试（维护模式前）
```bash
# 在维护模式前，发送普通 HTTP 请求
curl -v http://127.0.0.1:9501/
# 预期：连接到业务 Worker（如 9502），返回 200 OK（业务页面）

# 记录时间（应 <200ms）
time curl -s http://127.0.0.1:9501/ > /dev/null
```

**预期结果**：
- 连接到业务 Worker
- 响应时间 <200ms

---

### 步骤 2：进入维护模式
```bash
# 方式 1：通过 Master 命令（若可用）
php bin/w server:maint -n <instance_name>

# 方式 2：直接下发 IPC 控制消息到 Master（需要内部工具）
# 或者通过管理后台界面下发维护命令
```

**预期结果**：
- Master 接收维护命令
- Master 停止业务 Worker，启动维护 Worker（单个端口，如 9602）
- Dispatcher 接收 SET_WORKER_POOL 消息，修改池为仅包含维护 Worker (9602)
- **关键：维护 Worker 端口立即在池中，无需等待5s黑名单恢复**

---

### 步骤 3：验证维护模式快速进入
```bash
# 立即发送请求到维护 Worker（未来应 <100ms）
for i in {1..10}; do
    echo "Request $i:"
    time curl -s http://127.0.0.1:9501/ | head -c 100
    echo ""
done

# 查看 Dispatcher 日志
tail -f /path/to/wls/logs/dispatcher.log | grep -E "maintenance|9602|connectToWorker"
```

**预期日志**：
```
[Dispatcher] SET_WORKER_POOL received: [9602] (maintenance mode)
[Dispatcher] 维护快速通道已激活：workerCount=1, maintenancePort=9602
[Dispatcher] connectToWorker(9602) 连接成功 (0.3-0.5s timeout)
[Dispatcher] registerConnection(..., port=9602, ...)
```

**预期结果**：
- 所有请求立即路由到维护 Worker（9602）
- 响应时间 <300ms（首次可能稍长，因为 Dispatcher 需要建立连接）
- **无 5s 延迟**（这是改动前的行为）
- 维护页面正常显示（如 503，或自定义维护说明）

---

### 步骤 4：验证黑名单快速恢复（如果维护 Worker 曾失败）

**模拟场景**：维护 Worker 启动但尚未完全就绪

```bash
# 快速连续发送请求（在维护 Worker 从黑名单恢复期间）
for i in {1..20}; do
    curl -s http://127.0.0.1:9501/health &
done
wait
```

**预期日志**：
```
[Dispatcher] 维护 Worker 9602 连接失败，加入临时黑名单
[Dispatcher] probeBlacklistedWorkers() 维护模式下立即探活 (无需等 5s)
[Dispatcher] 维护 Worker 9602 探活成功，从黑名单移出
```

**预期结果**：
- 初期请求可能有失败（维护 Worker 启动中）
- **第一个探活周期（~100ms 后）就检测到恢复**，而非 5s
- 后续请求全部成功

---

### 步骤 5：验证性能指标

```bash
# 测量维护模式下的连接时间分布（对比改动前后）
for i in {1..100}; do
    { time curl -s http://127.0.0.1:9501/ > /dev/null; } 2>&1 | grep real
done | awk -F'[ms]' '{print $(NF-1)}' | sort | \
    awk '{
        count++; 
        min = (count==1) ? $1 : min; 
        max = (count==1) ? $1 : max; 
        if ($1 < min) min = $1; 
        if ($1 > max) max = $1; 
        sum += $1
    } 
    END {print "平均: " sum/count "ms, 最小: " min "ms, 最大: " max "ms"}'
```

**预期结果（改动后）**：
| 指标 | 改动前 | 改动后 | 改善 |
|------|------|------|------|
| 平均响应时间 | 800ms | 200ms | 75% ↓ |
| 最大响应时间 | 5000ms | 500ms | 90% ↓ |
| P95 响应时间 | 3000ms | 300ms | 90% ↓ |

---

### 步骤 6：恢复正常模式
```bash
# 退出维护模式
php bin/w server:maint -n <instance_name> --exit

# 或通过管理界面
```

**预期结果**：
- Master 停止维护 Worker，重启业务 Worker 池
- Dispatcher 接收新的 SET_WORKER_POOL 消息，恢复大池
- 业务流量正常恢复

---

## 故障诊断

### 问题 1：维护模式仍需 5s 才能接受请求
**原因**：黑名单恢复仍使用旧的时间基方式  
**验证**：
```bash
# 查看 Dispatcher 日志中是否有"维护模式下立即探活"或"维护快速通道"  
grep -i "maintenance\|quick\|fast" /path/to/dispatcher.log
```
**解决**：确认代码改动 3.3 已应用

### 问题 2：维护 Worker 连接立即失败
**原因**：维护 Worker 尚未启动或端口不对  
**验证**：
```bash
# 检查维护 Worker 是否在指定端口监听
netstat -tlnp | grep 9602  # 假设维护端口是 9602

# 从 Dispatcher 日志查看具体错误
grep "9602.*error\|9602.*fail\|9602.*refused" /path/to/dispatcher.log
```
**解决**：确保维护 Worker 正确启动，可用 `php bin/w server:logs` 查看 Worker 启动日志

### 问题 3：维护快速通道未被激活（仍走多 Worker 流程）
**原因**：存在多个 Worker 端口，或池未正确更新  
**验证**：
```bash
# 在 Dispatcher 日志中查找"workerCount"
grep "workerCount" /path/to/dispatcher.log
# 应显示 workerCount=1（维护模式）
```
**解决**：确认代码改动 3.2 已应用，检查 SET_WORKER_POOL 消息是否正确下发

---

## 成功标志

✅ 维护模式下响应时间 <300ms（无 5s 等待）  
✅ Dispatcher 日志显示"维护快速通道"激活  
✅ 维护 Worker 立即接受所有请求  
✅ 即使初期失败也在 <100ms 恢复  
✅ 退出维护模式后业务正常恢复  

---

## 注意事项

1. **仓测试前备份**：维护模式可能影响业务，建议在测试环境进行
2. **监控日志**：所有 Dispatcher 日志应在 `wls.log` 或 `dispatcher.log` 中
3. **灾备验证**：确保维护命令下发、确认、完成的整个流程可追踪
4. **性能基线**：建议在改动前后各进行一次性能测试，便于量化改善
