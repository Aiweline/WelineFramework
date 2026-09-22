# surfaces（wave1 · 机制面最小版）

team: `framework-perf-baseline-20260922`  
updated: 2026-09-22 · from:架构师

选型权威：`Framework/doc/3-开发/扩展点选型.md`。本波**不新建**跨模块 Event/Query；禁止跨模块直调 Service/Model。

| 面 | 机制（选型表） | Owner / 落点 | 本波用途 | 禁止 |
|----|----------------|--------------|----------|------|
| R1 批量缓存传输 | **Interface**（Framework 内） | `BatchCacheAdapterInterface` ← `WlsMemoryAdapter`；调用方 `CachePool` | Pool 优先批量；非批量 Adapter 逐键 fallback | 业务旁路直调 Memory facade；伪批量 RPC；无 epoch 进程袋 |
| R2 店面冷首击 / FPC | **Interface** + Runtime 编排 | `FpcWarmupProviderInterface`（路径贡献）；`WlsRuntime` deferred warmup；`FullPageCacheCoordinator` | 保留 homepage fail-open；deferred 必跑且 `/` 首槽；公网 Host；真实 HIT | 默认强制严格 READY prime；假 HIT；回环 Host 预热错 key |
| R3 已发布布局/Slot（并行辅轨） | **CachePolicy / HotCache** | Theme 发布态投影 + Framework HotCache | 已发布布局/Slot 进 Policy；预览/草稿禁入 | 草稿进共享池；平行 static 袋；单方「加缓存」 |
| R4（本波不做） | — | Search / ACL / Fiber | 下一波 | 本波施工 |

跨模块读/写若后续波次触及业务列表聚合：仍走 **QueryProvider / Interface**，不得用直调换性能。
