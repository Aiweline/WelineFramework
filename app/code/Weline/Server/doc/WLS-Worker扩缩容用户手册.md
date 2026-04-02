# WLS Worker 动态扩缩容用户手册

## 目录

1. [功能概述](#功能概述)
2. [快速开始](#快速开始)
3. [手动扩缩容](#手动扩缩容)
4. [自动扩缩容](#自动扩缩容)
5. [监控和状态查询](#监控和状态查询)
6. [配置参考](#配置参考)
7. [最佳实践](#最佳实践)
8. [故障排查](#故障排查)

---

## 功能概述

WLS Worker 动态扩缩容功能允许您根据负载动态调整 Worker 进程数量，实现：

- **手动扩缩容**：通过 CLI 命令即时调整 Worker 数量
- **自动扩缩容**：根据 CPU、内存、请求队列等指标自动调整
- **平滑扩容**：新 Worker 无缝加入，无请求丢失
- **安全缩容**：等待请求处理完成，优雅退出
- **并发安全**：防止多个扩缩容操作冲突

---

## 快速开始

### 1. 启动服务器

```bash
# 启动 2 个 Worker
php bin/w server:start --workers=2
```

### 2. 手动扩容

```bash
# 扩容到 4 个 Worker
php bin/w server:scale --workers=4
```

### 3. 查看状态

```bash
# 查看当前扩缩容状态
php bin/w server:scale --status
```

输出示例：

```
=== Worker Scaling Status ===

Auto-scaling: Disabled
Current workers: 4
Min workers: 1
Max workers: 8
Scaling locked: No

=== Load Metrics ===

Average CPU: 45.23%
Max CPU: 67.89%
Average memory: 128.45 MB
Total queue: 12
Max queue: 5
Total connections: 48
Average response time: 123.45ms
```

---

## 手动扩缩容

### 扩容

```bash
# 扩容到指定数量
php bin/w server:scale --workers=8
```

**注意事项**：
- 扩容会立即启动新 Worker，等待注册后加入路由池
- 扩容超时时间：10 秒
- 扩容失败会自动清理已启动的 Worker

### 缩容

```bash
# 缩容到指定数量
php bin/w server:scale --workers=2
```

**注意事项**：
- 缩容会优先选择 ID 最大的 Worker（最后启动的）
- 发送优雅关闭信号，等待请求处理完成
- 缩容超时时间：30 秒
- 超时后强制 kill

### 边界限制

```bash
# 缩容到 0 会失败（最小 1 个 Worker）
php bin/w server:scale --workers=0
# 错误：Target workers (0) is less than min workers (1)

# 扩容超过最大值会失败
php bin/w server:scale --workers=100
# 错误：Target workers (100) exceeds max workers (8)
```

---

## 自动扩缩容

### 启用自动扩缩容

**方法 1：修改配置文件**

编辑 `app/etc/env.php`：

```php
'wls' => [
    'scaling' => [
        'enabled' => true,           // 启用自动扩缩容
        'min_workers' => 2,          // 最小 2 个 Worker
        'max_workers' => 8,          // 最大 8 个 Worker
        'cooldown_seconds' => 60,    // 冷却期 60 秒
    ],
],
```

然后重启服务器：

```bash
php bin/w server:restart -r
```

**方法 2：CLI 命令（未来支持）**

```bash
# 启用自动扩缩容
php bin/w server:scale --auto --min=2 --max=8

# 禁用自动扩缩容
php bin/w server:scale --no-auto
```

### 扩容触发条件

满足以下**任一**条件时触发扩容：

1. **平均 CPU 使用率** > 80%（可配置）
2. **最大 CPU 使用率** > 90%
3. **总请求队列长度** > 10 * Worker 数（可配置）
4. **最大请求队列长度** > 20

### 缩容触发条件

同时满足以下**所有**条件时触发缩容：

1. **平均 CPU 使用率** < 30%（可配置）
2. **最大 CPU 使用率** < 50%
3. **总请求队列长度** < 2 * Worker 数
4. **当前 Worker 数** > 最小 Worker 数

### 冷却期

两次扩缩容操作之间的最小间隔，防止频繁扩缩容导致抖动。

- 默认：60 秒
- 推荐：60-120 秒

### 扩缩容策略

- **扩容**：每次 +1 个 Worker
- **缩容**：每次 -1 个 Worker
- **检查频率**：每 30 秒检查一次

---

## 监控和状态查询

### 查看实时状态

```bash
php bin/w server:scale --status
```

### 状态字段说明

| 字段 | 说明 |
|------|------|
| Auto-scaling | 是否启用自动扩缩容 |
| Current workers | 当前 Worker 数量 |
| Min workers | 最小 Worker 数量 |
| Max workers | 最大 Worker 数量 |
| Scaling locked | 是否有扩缩容操作正在进行 |
| Average CPU | 所有 Worker 的平均 CPU 使用率 |
| Max CPU | 单个 Worker 的最大 CPU 使用率 |
| Average memory | 所有 Worker 的平均内存使用量 |
| Total queue | 所有 Worker 的总请求队列长度 |
| Max queue | 单个 Worker 的最大请求队列长度 |
| Total connections | 所有 Worker 的总活跃连接数 |
| Average response time | 平均响应时间（毫秒） |

### 查看 Worker 进程

```bash
# Linux/Mac
ps aux | grep weline-wls-worker

# Windows
tasklist | findstr weline-wls-worker
```

---

## 配置参考

### 完整配置示例

```php
'wls' => [
    'scaling' => [
        // 是否启用自动扩缩容
        'enabled' => false,

        // 最小 Worker 数量
        'min_workers' => 1,

        // 最大 Worker 数量（默认：CPU 核心数 * 2）
        'max_workers' => 8,

        // 扩容 CPU 阈值（百分比）
        'scale_up_threshold_cpu' => 80.0,

        // 缩容 CPU 阈值（百分比）
        'scale_down_threshold_cpu' => 30.0,

        // 扩容队列阈值（倍数）
        'scale_up_threshold_queue' => 10,

        // 冷却期（秒）
        'cooldown_seconds' => 60,
    ],
],
```

### 配置场景推荐

#### 开发环境

```php
'wls' => [
    'scaling' => [
        'enabled' => false,          // 禁用自动扩缩容
        'min_workers' => 1,
        'max_workers' => 4,
    ],
],
```

#### 生产环境（保守策略）

```php
'wls' => [
    'scaling' => [
        'enabled' => true,
        'min_workers' => 2,
        'max_workers' => 16,
        'scale_up_threshold_cpu' => 70.0,
        'scale_down_threshold_cpu' => 30.0,
        'cooldown_seconds' => 120,
    ],
],
```

#### 生产环境（激进策略）

```php
'wls' => [
    'scaling' => [
        'enabled' => true,
        'min_workers' => 4,
        'max_workers' => 32,
        'scale_up_threshold_cpu' => 80.0,
        'scale_down_threshold_cpu' => 20.0,
        'scale_up_threshold_queue' => 5,
        'cooldown_seconds' => 60,
    ],
],
```

---

## 最佳实践

### 1. 选择合适的最小/最大 Worker 数

**最小 Worker 数**：
- 开发环境：1-2
- 生产环境：2-4（保证高可用）
- 高流量环境：4-8（应对突发流量）

**最大 Worker 数**：
- CPU 密集型：CPU 核心数 × 1.5 ~ 2
- I/O 密集型：CPU 核心数 × 2 ~ 4
- 混合型：CPU 核心数 × 2 ~ 3

**注意事项**：
- 考虑内存限制：每个 Worker 约占用 50-200MB 内存
- 考虑端口限制：Dispatcher 模式下每个 Worker 占用一个端口
- 考虑数据库连接限制：每个 Worker 可能持有多个数据库连接

### 2. 调整扩缩容阈值

**扩容阈值（CPU）**：
- 过低（< 60%）：资源浪费，频繁扩容
- 过高（> 90%）：响应慢，用户体验差
- **推荐：70-80%**

**缩容阈值（CPU）**：
- 过低（< 20%）：缩容不及时，资源浪费
- 过高（> 50%）：频繁缩容，不稳定
- **推荐：20-30%**

**阈值差距**：
- 扩容阈值 - 缩容阈值应 >= 30%，避免抖动

### 3. 设置合理的冷却期

**流量稳定场景**：
- 推荐：60-120 秒，避免频繁扩缩容

**流量波动场景**：
- 推荐：30-60 秒，快速响应

**流量突发场景**：
- 推荐：30-45 秒，快速扩容

### 4. 监控和调优流程

1. 启用自动扩缩容，使用保守配置
2. 观察 1-2 周，收集负载数据
3. 使用 `php bin/w server:scale --status` 查看实时指标
4. 根据实际负载调整阈值
5. 逐步优化，避免激进调整

### 5. 应对突发流量

**方法 1：提高最小 Worker 数**

```php
'min_workers' => 8,  // 提前预留足够的 Worker
```

**方法 2：降低扩容阈值**

```php
'scale_up_threshold_cpu' => 60.0,  // 更早触发扩容
```

**方法 3：手动预扩容**

```bash
# 活动前手动扩容
php bin/w server:scale --workers=16

# 活动后手动缩容
php bin/w server:scale --workers=4
```

---

## 故障排查

### Q1: Worker 数量频繁变化（抖动）

**原因**：冷却期太短，或扩缩容阈值差距太小

**解决方案**：
```php
'cooldown_seconds' => 120,           // 增加冷却期
'scale_up_threshold_cpu' => 80.0,   // 增大阈值差距
'scale_down_threshold_cpu' => 30.0,
```

### Q2: 负载高但不扩容

**原因**：
1. 已达到 `max_workers` 限制
2. 扩容阈值太高
3. 冷却期内

**解决方案**：
```bash
# 检查状态
php bin/w server:scale --status

# 如果达到 max_workers，增加限制
# 编辑 app/etc/env.php
'max_workers' => 16,

# 如果阈值太高，降低阈值
'scale_up_threshold_cpu' => 70.0,

# 重启服务器
php bin/w server:restart -r
```

### Q3: 负载低但不缩容

**原因**：
1. 已达到 `min_workers` 限制
2. 缩容阈值太低
3. 冷却期内

**解决方案**：
```php
# 如果达到 min_workers，降低限制
'min_workers' => 1,

# 如果阈值太低，提高阈值
'scale_down_threshold_cpu' => 40.0,
```

### Q4: 扩容后负载仍然高

**原因**：
1. 性能瓶颈（数据库、缓存、网络）
2. `max_workers` 太小
3. 单个 Worker 性能问题

**解决方案**：
```bash
# 1. 检查数据库连接池
# 2. 检查缓存命中率
# 3. 检查网络延迟
# 4. 增加 max_workers
# 5. 优化代码性能
```

### Q5: 内存占用过高

**原因**：
1. `max_workers` 太大
2. 内存泄漏

**解决方案**：
```php
# 减少 max_workers
'max_workers' => 8,

# 检查内存泄漏
# 使用 php bin/w server:scale --status 查看内存使用量
# 如果持续增长，检查代码是否有内存泄漏
```

### Q6: 扩缩容命令失败

**错误：No WLS server is running**

```bash
# 启动服务器
php bin/w server:start
```

**错误：Another scaling operation is in progress**

```bash
# 等待当前扩缩容操作完成
# 或检查是否有僵尸锁文件
rm var/wls/scaling.lock
```

**错误：Timeout waiting for response from Master**

```bash
# 检查 Master 进程是否正常
ps aux | grep weline-wls-master

# 重启服务器
php bin/w server:restart -r
```

### Q7: Worker 启动失败

**原因**：
1. 端口被占用
2. 内存不足
3. 权限问题

**解决方案**：
```bash
# 检查端口占用
netstat -tuln | grep <port>

# 检查内存
free -h

# 检查日志
tail -f var/log/wls.log
```

---

## 附录

### A. CLI 命令参考

```bash
# 手动扩缩容
php bin/w server:scale --workers=N

# 查看状态
php bin/w server:scale --status

# 启用自动扩缩容（未来支持）
php bin/w server:scale --auto --min=2 --max=8

# 禁用自动扩缩容（未来支持）
php bin/w server:scale --no-auto
```

### B. 配置参数参考

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `enabled` | bool | false | 是否启用自动扩缩容 |
| `min_workers` | int | 1 | 最小 Worker 数量 |
| `max_workers` | int | CPU核心数×2 | 最大 Worker 数量 |
| `scale_up_threshold_cpu` | float | 80.0 | 扩容 CPU 阈值（%） |
| `scale_down_threshold_cpu` | float | 30.0 | 缩容 CPU 阈值（%） |
| `scale_up_threshold_queue` | int | 10 | 扩容队列阈值（倍数） |
| `cooldown_seconds` | int | 60 | 冷却期（秒） |

### C. 负载指标说明

| 指标 | 单位 | 说明 |
|------|------|------|
| CPU 使用率 | % | 所有 Worker 的平均/最大 CPU 使用率 |
| 内存使用量 | MB | 所有 Worker 的平均内存使用量 |
| 请求队列长度 | 个 | 等待处理的请求数量 |
| 响应时间 | ms | 平均请求响应时间 |
| 活跃连接数 | 个 | 当前正在处理的连接数 |

### D. 相关文档

- [WLS 架构设计](./WLS-Worker动态扩缩容架构设计.md)
- [集成示例代码](./WLS-Worker扩缩容集成示例.php)
- [配置示例](./WLS-Worker扩缩容配置示例.php)

---

**版本**：1.0.0
**更新日期**：2026-04-01
**作者**：Aiweline
