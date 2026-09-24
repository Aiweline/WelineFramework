# contracts — pdp-n1-query-storm-20260923

status: **aligned_frozen**（机制 + UC 意图已冻；**施工未派**）  
frozen_at: 2026-09-23T23:55+08 · by:项目经理  
权威：`spec/pdp-n1-query-storm-20260923.md` · `surfaces.md`（architect_joint=true · O1–O5）· `meetings/探查-code-loci.md` · `meetings/电商顾问-stance.md`

---

## 冻结结论（诊断）

| 项 | 冻结内容 |
|----|----------|
| 现象 | 冷 PDP 请求级 `db_span_count` ≈666～936（复测 729）；异常 N+1 / 重复解析，非传输 |
| 最硬落点 | `RecentlyViewedService::cards` ×N `livePublishedOffersForProduct` |
| 机制方向 | **O1–O5**（surfaces）；framework_first；禁拆 chrome；禁平行袋 |
| 体验 | 主职必达；辅货架可延迟/限卡/骨架→真卡；导航解耦；汇审须 `ops_acceptance` |
| 数值门禁（冻意图） | 冷 `db_span` ≤150（理想≤100）；`db_ms` ≤120（理想≤80）；禁伪加速比；禁部件相加算整页 |

---

## UC 冻结清单（意图）

| UC | 标题 | 映射 |
|----|------|------|
| UC-1 | 冷路径请求级 DB 量级达标 | O1–O5 / G-DB-* |
| UC-2 | 主职首屏必达 | stance / G-LAYER |
| UC-3 | 辅货架允许非同步满载 | O2 / G-DEFER |
| UC-4 | 卡数上限（≤8 / ≤6 / ≤4） | O2 / G-CARD |
| UC-5 | 骨架→真卡且不假空 | O2 / G-DEFER |
| UC-6 | 导航与购买区解耦感知 | O3–O4 |
| UC-7 | 推荐降查询后仍可信可见 | O2 |
| UC-8 | FPC HIT 不顶替冷门禁 | O5 |
| UC-9 | 必装 chrome/商机位不被拆卸 | 硬边界 |
| UC-10 | 店面 ops_acceptance 过签后方可汇审 | 顾问 |

---

## 施工契约（待派 · 未开工）

| plan_id | 内容 | 负责人席 | issuer_seat | deps | 状态 |
|---------|------|----------|-------------|------|------|
| C-O1 | live_request 单次 + catalog 复用 | 后端·Product | 性能检查工程师 | surfaces O1 · 探查 live_request | **queued** |
| C-O2 | PDP 预取协调器 + 推荐三件套批量 + EAV bulk + RV QueryProvider | 后端·Product（+RecentlyViewed 协同） | 性能检查工程师 | O1 · 探查 RV/yml/xsell · stance 卡数 | **queued** |
| C-O3 | category_tree.urls 批量 + CachePolicy | 后端·Product | 性能检查工程师 | O3 · 探查 category_tree | **queued** |
| C-O4 | header / type-dropdown Policy（禁拆壳） | 主题/前端（+Search 协同） | 性能检查工程师 | O4 · 探查 header/搜索 | **queued** |
| C-O5 | 验收样本冷暖分述 + e2e/trace 门禁 | 测试·性能 | 性能检查工程师 | O5 · UC-1/8 | **queued** |
| C-OPS | 店面 ops_acceptance | 电商顾问 | 电商顾问 | UC-10；C-O* 合入后 | **blocked_until_impl** |

**禁**：未 PM 派工私自 reload / 大改；拆 chrome；平行袋；假 HIT。

---

## deps 图（简）

```
C-O1 ──┬──► C-O2 ──► C-OPS
C-O3 ──┤
C-O4 ──┘
         └──► C-O5（可与实现并行准备夹具）
```
