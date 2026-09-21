# 任务拆分表 · controller-extra-fpc

| 卡 | 目标 | 边界 | 禁止 | 验收 |
|----|------|------|------|------|
| A Extra | ExtraTypeRegistry + `@Extra` 收集 | 只读策略 | 不碰 w_changed | 未知 type 升级失败 |
| B Changed 骨架 | extends.php Changed + Pipeline 接入 w_changed | 不含业务 Enricher | 不删 Seo event | Pipeline 可跑空 Capability |
| C Fpc/Cdn Cap | FpcCapability + CdnCapability | Effect phase | 业务模块 purge_all | bump sync；urls after_commit |
| D Enricher | 按类型分卡：projection / theme / cms | 信封推导 | 读 Request | 缺料 error |
| E Recipe | publish 全清 vs upsert 按 urls | 预览隔离 | — | 配方表用例 |
| F patterns | public_path_patterns + ns 指纹 | 可后置 | — | slug 页继承 Extra |
| V 验收 | 硬切断言 + HIT/MISS | — | 甩用户测 | 汇审 |

细节外置：计划正文 / 总逻辑.md；本表不复制矩阵全文。
