# Fix 2026-04-07: 统一实例信息网关 - 动态读取最新 control_port

**根本原因：** Master 端口可能因为自动顺延（port fallback）发生变化，但各子进程缓存的旧值无法感知。

**解决方案：** 创建 `InstanceInfoGateway` 网关类，所有子进程在需要 IPC 连接时都从此网关读取最新信息，而不是使用缓存值。

## 🔧 实现细节

### 1. InstanceInfoGateway 类（新增）
**文件：** `app/code/Weline/Server/IPC/ChildControl/InstanceInfoGateway.php`

**职责：**
- 每次调用都从磁盘读取最新的 `var/server/instances/{instanceName}.json`
- 提供 `getLatestControlPort()` 方法获取最新端口
- 提供 `hasControlPortChanged()` 方法检测端口是否变化
- 检测到变化时自动记录警告日志

**关键 API：**
```php
$gateway = new InstanceInfoGateway($instanceName);
$latestPort = $gateway->getLatestControlPort($fallbackPort);
if ($gateway->hasControlPortChanged($currentPort)) {
    // 端口已变化，$currentPort 被自动更新
}
```

### 2. 改造的子进程

#### 2.1 worker.php（已改造）
- **初始化：** 创建 `$instanceInfoGateway` 实例
- **重连循环（1161-1186行）：** 每次重连前调用 `getLatestControlPort()` 获取最新值
- **变化检测：** 端口改变时打印日志，更新全局 `$controlPort`

#### 2.2 worker_ssl.php（已改造）
- **初始化：** 创建 `$instanceInfoGateway` 实例（1265行）
- **重连循环（1680-1704行）：** 每次重连前调用 `getLatestControlPort()` 获取最新值
- **效果：** 实时响应 Master 的端口更新（如 26895 → 26896）

## 🧪 测试场景

### 场景 1：Master 自动顺延端口

```
1. 启动 WLS 实例（Master 尝试 26895 失败，自动顺延到 26896）
2. JSON 文件更新为 control_port: 26896
3. Worker 重连循环每 5 秒检查一次文件
4. 第一次读取得到更新的 26896，成功连接 ✓
```

### 场景 2：多个 Worker 并发检测更新

```
1. 同时启动 5 个 Worker
2. Worker 1 尝试连接 26895 失败
3. Worker 2-5 也在重连循环中
4. 所有 Worker 都从 InstanceInfoGateway 读取更新的 26896
5. 所有 Worker 同时或先后成功连接 ✓
```

### 场景 3：Port 文件格式错误的降级

```
1. 如果 JSON 文件损坏或 control_port 字段不存在
2. getLatestControlPort() 返回 $fallbackPort
3. Worker 继续使用原有值重连，不崩溃 ✓
```

## 📋 集成检查清单

- [x] InstanceInfoGateway 类已创建
- [x] worker.php 已集成（初始化 + 重连循环）
- [x] worker_ssl.php 已集成（初始化 + 重连循环）
- [ ] dispatcher.php - 预计在动态服务发现时使用
- [ ] session_server.php - 初始连接通常稳定，可选集成
- [ ] http_redirect_worker.php - 初始连接通常稳定，可选集成

## 🔍 验证命令

```bash
# 启动 WLS 实例并观察日志
php bin/w s:start -n test-instance

# 在另一个终端主动占用 26895，迫使 Master 顺延
python3 -c "import socket; s=socket.socket(); s.bind(('127.0.0.1',26895)); input()"

# 观察 Worker 日志中是否出现：
# [IPC] 检测到 control_port 已更新: 26895 → 26896
```

## 🎯 架构优势

| 方面 | 之前 | 之后 |
|-----|------|------|
| 端口获取 | 启动时缓存一次 | 每次需要都读最新 |
| 变化检测 | 无法自动感知 | 自动检测并记录 |
| 代码复用 | 各进程各写一遍 | 统一网关实现 |
| 测试难度 | 难以模拟 | 易于单元测试 |
| 未来扩展 | 困难 | 在网关中统一改造 |

## 📝 相关文件

- [FIXES_2026-04-07-JSON-CONCURRENT-WRITE.md](FIXES_2026-04-07-JSON-CONCURRENT-WRITE.md) - Master JSON 原子写入修复
- [FIXES_2026-03-30.md](FIXES_2026-03-30.md) - 历史修复记录
