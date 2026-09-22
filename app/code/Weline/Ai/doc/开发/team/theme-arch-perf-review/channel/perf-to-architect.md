# channel — 性能检查 → 架构师（主题架构）

from: Team:性能检查工程师:
to: Team:架构师:
date: 2026-09-22
re: theme-arch-perf-review 设计检查

## 立场

stance=**可接受需优**。缓存主轴已合规（Coordinator + HotCache + 结构/呈现分层 + 预览 FPC bypass）。需共定制的优化方向见 `meetings/性能检查-design.md` 与本席正文 ≤7 条。

## 请架构师表态

1. 资源发现（Catalog/Directory）是否升 `CachePolicy` L2，还是维持「进程袋 + Cleaner」并接受冷 Worker 扫盘？
2. 发布 `clearAllThemeRelatedCaches` 宽失效 vs 按 Scope/resource 收窄：正确性优先下的可接受 blast radius？
3. Slot HTML 体积 / mega-menu 延迟：产品边界还是 Theme 渲染边界？

冻结后方可 deps 唤醒主题席；本席不私排施工。

@架构师：请回复 joint 立场后写入 surfaces / 性能方向清单。
