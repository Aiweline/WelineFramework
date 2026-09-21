# 规格：进程 L1 压力智能淘汰

```yaml
slug: process-l1-pressure-eviction
module: Weline_Framework
work_kind: feature
fe_be_scope: backend_only
ui_skill: skip
status: acceptance_passed
meeting: doc/开发/team/process-l1-pressure-eviction/meetings/技术方案会.md
huishen: doc/开发/team/process-l1-pressure-eviction/meetings/汇审.md
framework_version: '2.5.126'
server_version: '2.0.58'
```

## 1. 背景

Worker 长驻进程内，多语言词典与业务散落 `static` 袋抬高 RSS 直至 OOM。已有 `ProcessCacheResetter`、`WorkerResponseMemoryGuard`、`MemoryStoreInterface`、`MemoryReclaimableRegistry`，但缺**统一进程袋存储 + 可感知钉住的压力策略**。共享 Memory 服务自管淘汰，本需求不动。

## 2. 用户故事

作为平台运行时，我希望进程内可重建缓存统一由框架缓存类管理，并在 soft/hard 压力下按热度与钉住智能淘汰（尤其按语言桶踢词典），以便多语站点 Worker 不易 OOM，且不破坏共享缓存一致性。

## 3. EARS

1. WHEN 压力 ≥ soft(0.7) THEN 对已登记进程袋执行 pin=0 的分级驱逐，且不改共享 Memory 服务策略。  
2. WHEN 压力 ≥ hard(0.85) THEN 加强驱逐（pin=0 全清再目标字节），保留 pin>0 条目直至作用域结束或 drain。  
3. WHEN 进入 drain THEN 尽量排空可重建 L1，后续请求可从 L2/源重建。  
4. IF 条目 pin_count>0 THEN 压力驱逐 SHALL 跳过该条目（非 drain/进程退出）。  
5. IF 实现为 Adapter L1 THEN 它 SHALL 实现 `MemoryStoreInterface` 并接受 Policy 驱动的 relieve（禁止 soft 盲 `clearMemory` 绕过 pin）。  
6. IF 实现为纯进程袋 THEN 它 SHALL 使用 `ProcessMemoryStore` 并挂 `MemoryReclaimableInterface`，禁止再实现 MSI 与 Adapter 语义混用。  
7. WHEN Phrase 按 locale 缓存 THEN 高压淘汰 SHALL 优先冷 locale 桶；重语种驻留默认 ≤4。  
8. WHEN 显式 cache:clear THEN 经 `ProcessCacheResetter` 完整清理，严于压力部分驱逐。  
9. WHEN `!WlsConcurrency::canCompactProcessCaches()` THEN 所有进程袋 compact/evict SHALL no-op。  
10. IF fingerprint=null（事务）THEN SHALL 不向公共 L1 发布。  
11. WHEN 共享 Memory 服务配置未变 THEN 跨 Worker 命中/TTL 行为与基线一致。  
12. IF 新增大块进程可重建缓存 THEN MUST 接入 ProcessMemoryStore 或 MSI，禁止平行 static LRU。

## 4. 主用例

| ID | 名称 |
|----|------|
| UC-1 | soft 驱逐：RSS/条目下降，请求成功 |
| UC-2 | hard/drain：更强排空，可重建 |
| UC-3 | 语言桶：多 locale 填充后按桶淘汰，无 thrash |
| UC-4 | cache:clear 全清，可与压力路径区分 |
| UC-5 | pin 保护：作用域内条目不被 soft/hard 删除 |
| UC-6 | 共享服务基线无策略 diff |

## 5. 非目标

- 不改共享 Memory/Session 服务淘汰实现  
- 不恢复 CachePool processStore  
- 不改 UI；不扩 Phrase 业务语义  
- W3（FPC-L1/HotCache/菜单）不在首发强制范围  

## 6. 框架映射

| 能力 | 落点 |
|------|------|
| 纯进程袋 | `Cache/Store/ProcessMemoryStore*` |
| 淘汰策略 | `Cache/Policy/ProcessMemoryEvictionPolicy` |
| Adapter L1 | `MemoryStoreInterface` + WlsMemoryAdapter |
| 压力回收 | `MemoryReclaimableRegistry` + Guard |
| 显式清理 | `ProcessCacheResetter*` |
| 词典 | `DictionaryCacheNamespace` → Store（W2） |

## 7. 验收映射

- UT：Policy 序、pin 跳过、locale 桶上限、OM relieve 不再 soft 盲清  
- RT：Guard soft/hard 探针条目/字节下降  
- 回归：共享 Memory 命中基线  
- 代码门禁：新增进程袋不得只靠私有 LRU  

## 8. 待确认

见技术方案会「需用户确认」。
