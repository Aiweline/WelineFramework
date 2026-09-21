# surfaces — 进程 L1 压力智能淘汰（冻结）

> 席位：扩展点 + 技术方案会确认 | slug: `process-l1-pressure-eviction`

## 意图

进程内可重建 L1 在 Worker 内存高压时统一回收；**不**改共享 Memory 服务；**不**恢复 `CachePool processStore`。

## 冻结表面

### S1 — Adapter 进程 L1（既有）

| 项 | 值 |
|----|-----|
| 机制 | `MemoryStoreInterface` |
| 拥有 | `Weline_Framework` |
| 实现范例 | `WlsMemoryAdapter` |
| 编排 | `ObjectManager::relieveMemoryPressure`（**W1 必改：Policy 驱动，禁止 soft 无视 pin 全清**） |
| 禁止 | 在 CachePool 层加 processStore |

### S2 — 纯进程袋（新建存储类）

| 项 | 值 |
|----|-----|
| 机制 | `ProcessMemoryStore` + `ProcessMemoryStoreInterface`（Cache/Store） |
| 策略 | `ProcessMemoryEvictionPolicy`：heat（容量）+ pin（压力保护）+ bucket（locale） |
| 回收挂载 | **`MemoryReclaimableInterface` 适配器** → `MemoryReclaimableRegistry`（priority &lt; 100） |
| 明确 | ProcessMemoryStore **不**再实现 `MemoryStoreInterface`（避免与 Adapter `clearMemory` 语义撞车） |

### S3 — 清理协议（既有）

| 项 | 值 |
|----|-----|
| 机制 | `ProcessCacheResetterInterface` + `ModuleProcessCacheResetterRegistry` |
| soft | `REASON_MEMORY_PRESSURE` + `aggressive=false` |
| hard / 显式 clear | aggressive 或 `REASON_CACHE_CLEAR` |
| 禁止 | Resetter 跨模块直调业务 Service |

### S4 — Worker 编排（既有入口）

| 项 | 值 |
|----|-----|
| 机制 | `WorkerResponseMemoryGuard` + `WorkerHostPressureApplier` |
| 阈值 | soft≥0.70 / hard≥0.85 / drain（不新增 0.90） |
| 门禁 | `WlsConcurrency::canCompactProcessCaches()` |
| FPC L1 | 仅 hard/critical last-resort（priority≥100） |

### S5 — Phrase 语言桶（W2）

| 项 | 值 |
|----|-----|
| 机制 | `DictionaryCacheNamespace` 契约保留；袋实现迁 `ProcessMemoryStore` |
| 桶 | `locale`；重语种驻留默认 4（可 env 配置） |
| 事务 | fingerprint=null → ephemeral，不进公共 L1 |

### S6 — 业务读写 L2（不变）

`CachePool` / `w_cache()` → Adapter → 共享服务；本需求零改服务侧。

## 调用链

```text
压力探测
  → Guard / HostPressureApplier
      → MemoryReclaimableRegistry::compact|evictBytes   # ProcessMemoryStore 适配器
      → ObjectManager::relieveMemoryPressure            # MemoryStoreInterface（Policy）
      → ModuleProcessCacheResetterRegistry::reset
      → （hard）FPC reclaim last-resort
```

## anti-patterns

- 恢复 CachePool `processStore`
- 改共享 Memory 服务淘汰
- 业务自建平行 static LRU
- 新压力 Event 广播
- pin 做成跨请求永久租约 / 对不可信路径开放
- soft 清 FPC process L1
