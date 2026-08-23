# Weline_Search

`Weline_Search` 是 Product current projection 的 Website 分片、可重建搜索
投影。Product 始终是商品目录事实源。

当前已实现范围是 `TASK-P3C-001`、`TASK-P3C-002` 与
`TASK-MIG-P3C`：

- `search.website` durable registry 与 v2 shard schema；
- family checkpoint 固定使用 Website `0` 的稳定模板，新增 Website 只展开实际分片 DDL；
- staging generation 全量构建与 source-watermark fencing；
- Product ResourceChange → scoped Queue → durable incremental apply；
- Website/Store/Channel 精确隔离和幂等重放；
- storefront 只接受 `q`，完整 Scope 由服务端 `RequestContext` 冻结；
- mode off 读取 Product current，索引异常时显式
  `product_direct_degraded`，并返回 watermark/hash/count 防假空证据；
- durable per-Website degrade marker 与“Search incremental ==
  Product current”恢复门。
- registry full clone preflight、不可变 checkpoint/journal、shadow 全量
  重建与增量精确追平；
- fresh-process verify、canonical shadow report、per-Website 持久 alias
  CAS、精确 Website/Store/Channel allowlist；
- rollback 恢复 Product direct，同时保留 Search generation 与 checkpoint。

详细设计与验证见 [`search-index.md`](search-index.md)；代码与文档上下文由
`prepare_project` 就绪后的 `resolve_task_context` 动态返回。

`TASK-MIG-P3C` 已完成工程验收，`GATE-P3C=GO` 已由独立任务重跑
TEST-P3C-01..04 后签署；P3A/P3B/P3C 聚合复验后 `GATE-P3=GO`。
本文档不把 P3 Gate 解释为生产 `mode=on` 或 `PG-8` 完成。
