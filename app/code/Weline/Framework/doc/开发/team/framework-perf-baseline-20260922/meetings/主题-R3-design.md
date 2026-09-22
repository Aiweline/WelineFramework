# 主题 R3 — 已发布布局 / Slot 投影 HotCache

- seat: 主题
- date: 2026-09-22
- team: framework-perf-baseline-20260922
- related: `meetings/性能检查-design.md` 优化方向第 3 条；`channel/pm-arrange-wave1.md`
- R3_status: **patched**（首批可施工小步；店面主链 Entity 路径既有 HotCache 保留）

---

## 1. 探查落点（现状）

| 路径 | 缓存落点 | CachePolicy | 备注 |
|------|----------|-------------|------|
| 店面 Entity 填槽（`ThemeLayoutEntitySlotFiller`） | `ThemeLayoutEntityConfigStore` / `PointerResolver` → `StorefrontScopeHotCache` | `theme.layout_entity.config` / `.pointer`（channel，`vary=[]`，deps `theme`） | 店面 hard cut；**不**走 `getLayoutData` |
| `ThemeScopedWorkspace::readPublishedSnapshot` | HotCache | `theme.published_snapshot`（**scope=global** + `identityHash` 含 area/theme/layout/target/站店渠身份） | 结构投影读入口已有 |
| `SlotRendererService::getLayoutData`（编辑器/遗留） | 请求袋 + **私有静态袋** `$publishedLayoutDataCache`；`runtimeCacheGet/Set` **恒 no-op stub** | 本波前无 Policy | 架构师异议点：空 stub / 私有袋 |
| 呈现层 chrome HTML | HotCache | `storefrontChromePolicy`（channel + lang/currency） | 非结构层；本波不改 |

权威键约束：`Theme/doc/layout-slot-cache-keys.md` — 结构键禁 lang/currency/request_id；仅 area/theme/layout/page_type/target + 站店渠。

---

## 2. 最小可施工方案（已落地）

1. **Policy**：`StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy()`
   - resource `theme.layout.published`
   - pool `weline_theme_published_layout_structure`
   - scope `channel`，`vary=[]`，deps `['theme']`，`staleTtl=0`
2. **读路径**：`getLayoutData` 在 `cacheablePublished`（非 draft、非 page target）时经 `rememberPolicy`；逻辑键 `pub_layout|{area}|{theme}|{page_type}|{layout_option}|{scope}|{target_type}|{target_id}`
3. **预览/草稿禁入**：沿用既有 `$cacheablePublished = !$isDraft && !$hasTargetIdentity`；Scope fence 时 HotCache 仅请求 memo，不写共享 L2
4. **失效**：复用 Theme 发布 `w_changed(theme|theme_layout)` → namespace generation；`ThemeRuntimeCacheCleaner` 增 `published_layout_structure_hot_cache` 池清理
5. **不碰**：Token/调色盘、跨模块业务部件、浏览器 BinQuery、FPC/warmup（R2 归后端）

进程内静态袋暂保留作同 Worker 短 TTL L1（与 HotCache L1 并存）；**跨 Worker 复用改为 Policy 池**，不再假装 theme_runtime SharedState。

---

## 3. 建议后端协作点（不阻塞本席）

| # | 协作 | Owner | Why |
|---|------|-------|-----|
| B1 | `CachePool` 真 `getMultiple/setMultiple`（R1） | 后端 | HotCache miss 批量写/预热仍可能逐键 RPC |
| B2 | `rollbackReleaseBatch` 事务内补与 publish 同形的 `w_changed` | 主题+后端 | 文档 backlog：回滚成功提交须推进 theme 代次，否则共享投影脏读 |
| B3 | 评估 `publishedSnapshotPolicy` scope=`global` vs channel 是否双编键 | 架构师表态 | identityHash 已含 storageScope；保持 global 需写明，避免误改 |
| B4 | Entity 冷路径 profile：structure.json / config HotCache HIT 率 | 性能检查复审 | 店面主耗时若在 widget SSR/HTML 体积，则 R3 结构缓存收益有上限 |

---

## 4. 证据

- 代码：`StorefrontThemeCacheCoordinator`、`SlotRendererService::getLayoutData`、`ThemeRuntimeCacheCleaner`
- UT：`SlotRendererHotPathCacheContractTest`、`StorefrontHeaderNavFragmentCacheTest::testPublishedLayoutStructurePolicy…`、`ThemeLayoutScopeSlotMergeContractTest`（cleaner 步骤）
- Theme 版本：`2.2.537`
