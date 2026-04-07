# WLS 启动阶段友好路由指南

## 功能概述

当新连接到达时，WLS Dispatcher 现在会执行以下智能路由：

1. **尝试业务 Worker** — 首先路由到正常业务 Worker 池
2. **检测维护 Worker** — 如果业务 Worker 不就位，立即检测是否有维护 Worker 可用
3. **友好提示或导流** — 如果有维护 Worker 就切换过去；如果没有就返回 503 提示"WLS 正在启动中..."

## 改动清单

### 改动 1：PassthroughCore 增强 — 维护 Worker 支持
**文件**：`app/code/Weline/Server/Dispatcher/PassthroughCore.php`

- **新增属性**：`$maintenancePort` — 维护 Worker 端口（单独跟踪，与业务 Worker 池分开）
- **新增方法**：
  - `setMaintenancePort(int $port)` — 设置维护 Worker 端口
  - `getMaintenancePort(): int` — 获取维护 Worker 端口
- **增强路由逻辑**（handleNewConnection）：
  - 在业务 Worker 全部失败后，立即尝试维护 Worker （新增第 9 步）
  - 成功则导流，记录 `maintenance_routed` 统计
  - 失败则继续返回 false，触发 Dispatcher 的 503 响应
- **统计增强**：添加 `maintenance_routed` 计数器

### 改动 2：Dispatcher 配置扩展
**文件**：`app/code/Weline/Server/Dispatcher/Dispatcher.php`

- **配置支持**（configure 方法）：
  - `'maintenance_port'` — 维护 Worker 端口配置
  - 自动调用 `setMaintenancePort()` 配置到 PassthroughCore
  - 添加日志记录维护端口的配置状态

### 改动 3：启动保护页面优化
**文件**：`app/code/Weline/Server/Dispatcher/Dispatcher.php`

- **页面文案改进**（buildFriendlyStartupMaintenancePage）：
  - 标题：**"WLS正在启动中..."** （明确提示）
  - 徽章：**"业务 Worker 启动中"** （清晰状态）
  - 正文：**"业务 Worker 正在初始化，系统正在检测维护 Worker 并切换入口。"** 
  - 细节："如果已有维护 Worker 就绪，请求会自动转接；否则请稍后刷新页面。"

---

## 使用方式

### 配置维护 Worker 端口

在 Dispatcher 配置中添加 `maintenance_port` 参数：

```php
// weline.env 或实例配置
$dispatcherConfig = [
    'maintenance_port' => 9699,  // 维护 Worker 监听端口
    // ... 其他配置
];
```

或在 env 配置文件中：
```env
WLS_DISPATCHER_MAINTENANCE_PORT=9699
```

### 执行流程

当系统启动时：

1. **Master 启动 Dispatcher** 并注入 `maintenance_port` 配置
2. **Master 启动业务 Workers** 并通过 IPC `SET_WORKER_POOL` 通知 Dispatcher
3. **业务 Workers 启动阶段**（SET_WORKER_POOL 未发送或端口全 fail）：
   - 新连接到达 → Dispatcher 无业务 Worker 可用
   - 自动尝试维护 Worker（如果配置了）→ **导流到维护 Worker**
   - 维护 Worker 返回"WLS 正在启动中..."页面
4. **业务 Workers 启动完成**：
   - Master 下发 `SET_WORKER_POOL` 命令
   - Dispatcher 更新池，后续新连接直接路由到业务 Worker

### 日志输出示例

```
[PassthroughCore] 维护 Worker 端口已设置: 9699
[Dispatcher] 维护 Worker 端口已配置: 9699
[PassthroughCore] 新连接到达时业务 Worker 全失败，尝试维护 Worker: 9699 → 成功
[Stats] maintenance_routed=15 (本轮新增)
```

---

## 场景对应

| 场景 | 响应 | 状态码 |
|------|------|--------|
| 业务 Worker 就位 | 正常业务响应 | 200/307... |
| 业务 Worker 启动中，维护 Worker 就绪 | "业务 Worker 启动中"页面 | 503 (维护页) |
| 业务 Worker 启动中，维护 Worker 未配置 | "WLS 正在启动中..."页面 | 503 (启动保护) |
| 业务 Worker 启动中，维护 Worker 也启动中 | "WLS 正在启动中..."页面 | 503 (启动保护) |

---

## 配置示例

### env.php 中的 Dispatcher 配置

```php
'dispatcher' => [
    'port' => 9500,                            // Dispatcher 监听端口
    'worker_host' => '127.0.0.1',
    'worker_base_port' => 9502,
    'worker_pool_size' => 4,
    'maintenance_port' => 9699,                // 维护 Worker 备选端口
    'startup_protection_enabled' => true,      // 启用启动保护
    'startup_protection_window_sec' => 45,     // 45s 内启用保护
    'startup_protection_ready_ratio' => 0.8,   // 80% Worker 就位后才退出保护
    // ...其他配置
],
```

---

## 工作原理

### 关键决策树

```
新连接 (TCP 建立)
  ↓
尝试业务 Worker 池中的 Worker
  ├─ 成功 → 双向透传 + 学习路由 → 普通响应
  └─ 全失败 ↓
   
尝试维护 Worker（如果已配置 maintenancePort > 0）
  ├─ 成功 → 双向透传到维护 Worker
  │           (维护 Worker 返回"WLS启动中..."页面)
  │           记录: stats['maintenance_routed']++
  │
  └─ 失败或未配置 ↓
   
返回 Dispatcher 硬编码的启动保护页面
  (HTTP/1.1 503 Service Unavailable)
  内容: "WLS 正在启动中..."
  记录: stats['all_workers_down']++
```

### 时序图

```
┌─ 系统启动
│
├─ Master 启动 Dispatcher，注入 maintenance_port=9699 配置
│
├─ Master 启动维护 Worker（监听 9699）
│
├─ Master 启动业务 Workers（监听 9502-9505，还在启动中）
│  （尚未下发 SET_WORKER_POOL）
│
├─ 用户发起请求 → 连接到 Dispatcher:9500
│
├─ Dispatcher.handleNewConnection()
│  ├─ 尝试业务 Worker (9502-9505) → 全失败
│  ├─ 尝试维护 Worker (9699) → 成功 ✓
│  └─ 导流到维护 Worker，返回"WLS启动中..."页面 (503)
│
├─ 业务 Workers 启动完成
│
├─ Master 下发 SET_WORKER_POOL [9502, 9503, 9504, 9505]
│
├─ 用户重试请求 → 连接到 Dispatcher:9500
│
├─ Dispatcher.handleNewConnection()
│  ├─ 尝试业务 Worker (9502) → 成功 ✓
│  └─ 导流到业务 Worker，返回正常响应
│
└─ [维护模式结束]
```

---

## 故障诊断

### 维护 Worker 未被使用

**问题**：即使配置了 `maintenance_port`，仍显示"WLS 启动中..."而非维护页面

**检查步骤**：
1. 确认维护 Worker 在 `maintenance_port` 端口上监听：
   ```bash
   netstat -tlnp | grep 9699
   ```
2. 查看 Dispatcher 日志是否有 `[PassthroughCore] 维护 Worker 端口已设置`
3. 查看是否有 `maintenance_routed` 统计增长：
   ```bash
   curl -s http://dispatcher:port/health/stats | jq '.maintenance_routed'
   ```
4. 手动测试维护 Worker 是否可访问：
   ```bash
   curl -v http://127.0.0.1:9699/
   ```

### 维护 Worker 导流不稳定

**问题**：有时显示维护页面，有时显示"WLS 启动中..."

**原因**：维护 Worker 间歇性不可用

**解决**：
- 检查维护 Worker 的日志和资源状态
- 增加维护 Worker 的连接超时或重试次数
- 确保维护 Worker 能稳定处理并发连接

---

## 部署清单

- [ ] 确认维护 Worker 单独部署或内置（端口 9699+）
- [ ] 在 Dispatcher 配置中添加 `maintenance_port` 参数
- [ ] 验证维护 Worker 启动脚本正确（应早于或与业务 Worker 同时启动）
- [ ] 测试启动流程：访问 Dispatcher 应立即展示维护页面
- [ ] 确认 503 响应中的提示文案符合需求
- [ ] 检查日志输出是否包含维护 Worker 配置和路由统计

---

## 下一步

- 🔍 **监控**：建议在 Dispatcher 统计中持续监控 `maintenance_routed` 计数
- 📊 **分析**：分析启动阶段的请求分布，评估维护 Worker 的负载
- 🔧 **优化**：根据实际启动时间调整 `startup_protection_window_sec` 和 `startup_protection_ready_ratio`
- 📈 **扩展**：考虑在维护页面中增加"系统状态"或"预计恢复时间"等动态信息
