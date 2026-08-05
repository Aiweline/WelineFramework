# Scope 限流（TASK-P1D-004-RATE）

## 类

| 类 | 职责 |
|---|---|
| `ScopeRateLimitKey` | `rl|{canonicalScope}|{bucket}|subject:{sha256}` |
| `ScopeRateLimitStateStore` | 专用共享池、窗口/指标的有界 CAS、健康检查与精确清理 |
| `ScopeRateLimiter` | 共享窗口计数；超限 / limit≤0 → fail-closed |

## 不变量

- key **必须**含完整 Scope（含 `store_mode`）
- Store A 耗尽不影响 Store B
- `clearKey` 仅清一个 Scope + bucket + subject 配额；
  `clearDeniedMetrics` 仅清指定 bucket 的聚合/Scope 指标，不清其他业务桶。
- 开启后只接受同时实现 `AtomicCacheAdapterInterface` 与
  `CacheAdapterHealthInterface` 的驱动；非原子、无法健康探测或共享状态
  不可用时返回 `503 scope_rate_limit_unavailable`，禁止回退进程数组或
  本地文件。
- 单 WLS 多 Worker 默认使用 `wls_memory`；多节点必须显式配置
  `security.scope_rate_limit.driver=redis` 并使用所有节点共享的 Redis。
- 窗口最长 86400 秒并由 TTL 自动回收；拒绝指标最多保留 256 个 key、
  TTL 7 天。指标快照通过 no-op CAS 校验，不能返回 Worker 本地 stale 值。
- Observer 只挂载在 `storefront_scope_ready_gate`，即完整 Storefront Scope
  冻结后、任何 FPC 查询前；同一请求通过 RequestContext 标记只计数一次。

入口：`Weline\Framework\Http\ScopeRateLimiter`
