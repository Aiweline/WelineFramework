# surfaces — pdp-n1-query-storm-20260923

team: `pdp-n1-query-storm-20260923`  
updated: 2026-09-23 · from:架构师 · **architect_joint=true**（与性能 `meetings/性能检查-design.md` 联合已对齐 · **待 UC 冻结**）  
选型权威：`Framework/doc/3-开发/扩展点选型.md`  
缓存权威：`Framework/doc/统一缓存范围与性能优化.md` · `Framework/doc/3-开发/缓存使用指南.md`  
继承：`framework-perf-baseline-20260922`（R1 批量 / CachePolicy）· `wls-perf-regression-20260923`（种袋辅 · **禁拆 chrome**）  
证据：性能纪要 §1–§4（`db_span_count`≈666～936；复测 729）· 探查 `meetings/探查-code-loci.md` · 顾问 stance（辅货架可延迟/限卡）

**本文件状态**：**联合已对齐 · 待 UC 冻结** · 禁施工改码 / 禁 reload。  
性能席纪要：`meetings/性能检查-design.md`（verdict=objection · 热点+合规+方向候选）— 已对照并合并。

---

## 硬边界（双方 · 冻结）

| 硬 | 含义 |
|----|------|
| framework_first | 优先复用 Framework HotCache / CachePolicy / QueryProvider / 既有 URL 批量与 catalog 批量入口；禁业务旁路 Model |
| 禁平行进程内袋 | 禁业务 `static` / 无 Policy 进程袋；升格须 `CachePolicy` + 正式 deps / changed |
| 禁拆 chrome | 禁拆 header/footer/必装 widget / 固化壳主链；header 热点只允许 Policy/种袋命中与编排减负 |
| 禁部件 q 数相加算整页 | 验收看请求级 `db_span_count` / 相位 inclusive，不把部件 span 相加冒充 total |
| 个性化禁共享 HTML | `recently-viewed` 仅请求内 memo + ID 批量拉取；禁进共享 HotCache HTML 池 |
| 实时价库不进长期共享 | 库存/可售/最终价走 live 轻投影或请求快照；不得用长期共享 JSON 冒充真值 |
| 禁单方「加缓存」 | 机制选型以本 surfaces 为准；施工前须 UC/contracts 冻结 |

---

## 机制面映射（联合已对齐）

| ID | 机制（选型表） | Owner / 落点 | 本波用途 | 禁止 |
|----|----------------|--------------|----------|------|
| **A** | 复用已有 catalog 批量入口 | `StorefrontCatalogViewService::publishedOfferSummaries` / `rememberPublishedOffers`；`StorefrontProductDetailProjector::prefetchForProducts`；`StorefrontProductWidgetCatalog::{related,youMayLike,bundle}Cards` | 压推荐区重复投影与二次 live 全扫 | 部件各自冷扫 full；假 HIT |
| **B** | 请求级预取协调器 | Product PDP page-scope（并入 ViewService 或 `StorefrontPdpCatalogPrefetch`）；相位建议 `product.catalog.pdp_prefetch` | 汇 seed+related+recently-viewed IDs → 单批 hydrate / EAV / offer identity | 进程 static 袋；第二套 catalog |
| **C** | HotCache + CachePolicy | `StorefrontCategoryTreeIndex` + `Url::getFrontendUrls` / rewrite prefetch；`headerSearchTypesPolicy` / Partials header / BagWarmup | 削 `category_tree.urls`(~102)、header(~159)、type-dropdown(~130)；先核验 miss/绕过再修 | 私写 L1；拆 chrome；删 deps 保 HIT |
| **D** | QueryProvider 批量面 | RecentlyViewed→Product cards 扩既有 Query/Interface；卡片字段 bulk | 跨模块禁直调 Model；消逐 entity EAV / offer find | 伪批量 RPC；无关 Event 总线 |
| **E** | live_request 边界收紧 | `livePublishedOffersForProduct` 主链一次；可用性 `liveVariantAvailabilityForProduct`；推荐禁再 live 全投影 | 削 `product.catalog.live_request`(~206，可多次) | remember 冒充 live 可用性 |

---

## 证据相位 → 机制（对齐性能热点排序）

| 序 | 热点（性能 §3） | 机制 | 探查锚点（摘要） |
|----|-----------------|------|------------------|
| 1 | `product.catalog.live_request` ~206 | **E**+**A** | `livePublishedOffersForProduct` ← Detail |
| 2 | `theme.partials.fetch.header` ~159 / type-dropdown ~130 | **C** | Partials header；`HeaderCommerceData::resolveSearchTypes` |
| 3 | `storefront.category_tree.urls` ~102 | **C** | `StorefrontCategoryTreeIndex::applyLocalizedNames` → `getFrontendUrls` |
| 4 | recently-viewed ~161峰 | **B**+**D**+**A** | `RecentlyViewedService::cards` ×N live |
| 5 | you-may-like ~50–66 / cross-sell ~29–34 | **B**+**A** | `youMayLikeCards` / `bundleCards` |

---

## 共同定制优化方向（**联合冻结候选 · 待 UC** · ≤5）

与性能 §5 / §5.1 一一合并；施工归属待 PM 派；数值 SLA 待冻结会钉。

1. **O1 · live_request + catalog 复用（主）**  
   发布态读模型；请求内主链 **单次** live + 投影复用（`publishedOfferSummaries` / `prefetchForProducts`）；变体实时快照与详情字段拆分；推荐区 **禁止** 再触发 live 全投影。  
   机制：**E**+**A** · 热点序 1

2. **O2 · PDP 预取协调器 + 推荐三件套批量 + EAV bulk（主）**  
   候选 ID 一次收集 → 批量 catalog / EAV / offer identity；卡片共用投影；`source_company_name` 等走 bulk fields（禁 per-id per-code）；RecentlyViewed 经 **QueryProvider**，仅 request memo（禁共享 HTML）。卡数上限对齐顾问 stance（猜你喜欢≤8、最近浏览≤6、交叉≤4；可骨架延迟）。  
   机制：**B**+**D**+**A** · 热点序 4–5

3. **O3 · category_tree.urls 批量 + CachePolicy（辅）**  
   确保 `getFrontendUrls` / rewrite prefetch 生效；HotCache scope=website/channel，deps含 `catalog`；禁逐节点查 rewrite / 私袋。  
   机制：**C** · 热点序 3

4. **O4 · Header / 搜索 type-dropdown Policy（辅 · 禁拆壳）**  
   `area=frontend` 搜索类型走既有 `headerSearchTypesPolicy` 预热 + single-flight；与 storefront cache builder 对齐；只修 miss/过重，不拆 chrome/必装。  
   机制：**C** · 热点序 2

5. **O5 · 验收口径（硬）**  
   同 URL **冷/暖两档**分述；报告 `db_span_count` + `db_duration_ms` + 关键 phase；禁止徽标 q 相加；`wls_tpl_perf` 诊断样本与 FPC HIT 样本分开陈述。数值目标待冻结会钉。

---

## 禁止项（双方硬 · 重申）

- 平行 static / 无 Policy 进程袋  
- 拆 chrome / 必装 widget / 固化壳  
- 假 HIT；删依赖保 HIT；把 DB N+1 换成 WLS RPC N+1  
- recently-viewed HTML/会话进共享池  
- 跨模块直调 Model；新建无关 Event 换性能  
- 私自 reload / 未冻 UC 施工  
- 部件 span 相加算整页  

---

## 验收口径（立项预期 · 未钉数值 SLA）

| 指标 | 口径 |
|------|------|
| 主 | 同 URL 冷/暖；`db_span_count` / `db_duration_ms` 前后对照 |
| 相位 | live_request / header / category_tree.urls / 推荐相关 phase（父子 inclusive，不求和冒充 total） |
| 合规 | CachePolicy deps 仍在；无平行袋；chrome 仍在；个性化未进共享 HTML |

数值 SLA：对齐冻结会由性能×PM 钉（本 surfaces 不发明加速比）。
