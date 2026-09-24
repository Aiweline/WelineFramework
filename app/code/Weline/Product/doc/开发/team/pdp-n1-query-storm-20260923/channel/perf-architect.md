# channel: perf-architect（立项）

## msg-1 · 项目经理 · 2026-09-23T23:45+08

@性能检查工程师 @架构师 @领域探查 @需求分析

**用户诉求**：PDP 单次请求数百次 SQL span「绝对不正常」→ 团队看问题（先诊断与方向，未授权盲目大改）。

**证据（本机 Official / Host `p05113ef3.test.weline.com:9555`）**

- URL：`/product/qi-yue-xi-fu-…?size=s&style_type=hong-se-zhan&wls_tpl_perf=1`
- 请求级：`db_span_count` ≈ 666～936；`db_duration_ms` ≈ 315～761
- 相位：`product.catalog.live_request` ~206；`theme.partials.fetch.header` ~159；`storefront.category_tree.urls` ~102；搜索 type-dropdown ~130
- 部件：`recently-viewed` / `you-may-like` / `cross-sell` / `product-info` 各数十～百余 span
- 工具：Framework `wls_tpl_perf` 徽标拆 total/db/wls/php（2.5.173）
- SESSION：`Product/doc/开发/session/pdp-n1-query-storm-20260923.md`

**本波交付**

1. 性能席：热点排序 + 业务特性摘要 + 缓存合规初判 + escalate 建议（禁拆壳）
2. 探查席：上述相位/部件的代码落点（模块路径）
3. 需求席：EARS 异常现象 → 目标 UC 草案（未冻）
4. 架构师：与性能联合 `architect_joint` 优化方向候选（框架机制：批量 Query / HotCache / 预取；禁平行袋）

**禁**：私自 reload 施工；拆 chrome/必装 widget；把部件 q 数相加算整页。

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION

---

## msg-2 · 架构师 · 2026-09-23T23:47+08

@性能检查工程师 @项目经理 @领域探查 @需求分析

**本席交付（立项 · 未冻）**

1. 已读 msg-1。`meetings/性能检查-design.md` **尚不存在**（team 目录仅本 channel）→ **无法升冻结联合**。
2. 写 surfaces 草案：`app/code/Weline/Product/doc/开发/team/pdp-n1-query-storm-20260923/surfaces.md`（标 **draft**）。
3. **architect_joint=pending**（待性能热点排序 + 缓存合规初判落盘后回写 true）。

**与性能联合方向候选（framework_first · ≤5 · 禁平行袋 · 禁拆 chrome）**

1. **复用 catalog 批量入口**：`publishedOfferSummaries` / `rememberPublishedOffers` / `prefetchForProducts`；推荐部件禁重复 `live_request` 全投影。
2. **PDP 请求级预取协调器**：一次汇集 related / you-may-like / cross-sell / recently-viewed IDs → 单批 hydrate（HotCache `rememberForRequest` / Trace 相位，非进程 static 袋）。
3. **category_tree.urls + header/搜索**：核验既有 `getFrontendUrls` 批量预取与 `headerSearchTypesPolicy` / Partials HotCache 是否 miss/绕过；先证据再修。
4. **QueryProvider 批量面**：RecentlyViewed→Product cards 只扩既有 Query/Interface；禁跨模块直调 Model。
5. **收紧 live_request 边界**：PDP 主链一次；可用性走轻投影；禁推荐区再触发 live 全扫。

**禁**：施工改码、reload、拆 chrome、部件 q 相加算整页、单方「加缓存」。

architect_joint: **pending**  
notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION（P1-surfaces 草案路径已落；待性能纪要后升 joint）

---

## msg-2 · 电商顾问 · 2026-09-23T23:48+08

@项目经理 @需求分析 @性能检查工程师 @架构师

**席位交付**：运营/领域 stance（禁写码）已落盘。

**纪要**：`meetings/电商顾问-stance.md`

**Stance 摘要（3～5）**
1. PDP 首屏主职=当前 SKU 决策；推荐/最近浏览/交叉销售为辅位，默认可非阻塞。
2. **不要求**同屏同步全量算完辅货架；可接受骨架/延迟出真卡；空态优于拖死整页。
3. 产品上限建议：猜你喜欢 ≤8（优 4～6）、最近浏览 ≤6、交叉销售 ≤4；多卡≠高转化。
4. 运营底线：主路径可读可点、真卡可信；目录/搜索不得拖成「坏站感」；删货架须先 ops 过签，默认是降同步成本+控 N+可延迟。
5. 用户「数百 span 不正常」——顾问同意：辅位/导航不应表现为详情一开就全店重算。

**请 PM「要开发什么」**（非技术细节）：体验分层冻结；三货架卡数/空态；骨架→真卡验收口径；导航与主职解耦目标；店面 ops_acceptance 门禁。

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION（澄清纳入 stance；对齐冻结前参考）

---

## msg-3 · 项目经理 · 2026-09-23T23:49+08

@性能检查工程师 @领域探查 @需求分析 @架构师 @电商顾问

**PM DoD**
- 电商顾问：`meetings/电商顾问-stance.md` → **pass**（澄清采纳；店面变更须 ops_acceptance；计划项 `P-ops-stance`）
- 架构师：`surfaces.md` draft → **pass 记档**；`architect_joint` 仍 **pending**（等性能纪要）

**阻塞 / 催办**：性能、探查、需求席冷启动未落盘。请**跳过卡住的 MCP 等待**，用 SESSION 已举证摘要 + 只读代码直接交付：
1. 性能 → `meetings/性能检查-design.md`（热点排序 + 缓存合规 + escalate）
2. 探查 → `meetings/探查-code-loci.md`（相位/部件模块路径）
3. 需求 → `meetings/需求分析-ears-uc-draft.md`（EARS + UC 草案，纳入顾问 stance A～E）

收齐后开对齐冻结。禁施工。

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION

---

## msg-4 · 领域探查 · 2026-09-23T23:50+08

@项目经理 @性能检查工程师 @架构师

**探查交付**：`meetings/探查-code-loci.md`（只读 Grep/Read，无 MCP 等待，业务码未改）。

落点摘要（相位→符号）：
| 相位 | 关键 |
|------|------|
| `product.catalog.live_request` | Product `StorefrontCatalogViewService::livePublishedOffersForProduct` ← Detail |
| `theme.partials.fetch.header` | Theme `Partials::renderPartials` → header.phtml（包容） |
| `storefront.category_tree.urls` | Product `StorefrontCategoryTreeIndex::applyLocalizedNames` → `Url::getFrontendUrls` |
| 搜索 type-dropdown | Theme `HeaderCommerceData::resolveSearchTypes` → Search `listTypes` → Product `ProductSearchProvider::listScopeOptions` |
| recently-viewed | RecentlyViewed `RecentlyViewedService::cards` **×N live**（最硬 N+1） |
| you-may-like / cross-sell | Product `StorefrontProductWidgetCatalog::youMayLikeCards` / `bundleCards` |

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION

---

## msg-4 · 性能检查工程师 · 2026-09-23T23:51+08

@项目经理 @架构师 @领域探查 @需求分析 @电商顾问

**本席交付（立项 · 设计检查轨 · 跳过 MCP 等待）**

1. 纪要已落：`meetings/性能检查-design.md`（`architect_joint=pending`）
2. 证据复核：timing.log + curl `wls_tpl_perf=1` → `db_span_count` **666～936**（本席复测 **729**）；异常成立
3. 热点排序：`live_request`(206) ≥ `header`(159) / 搜索 type-dropdown(~130) ≥ `category_tree.urls`(102) ≥ recently-viewed(~161峰) ≥ you-may-like(~50–66) ≥ cross-sell(~29–34)（徽标 inclusive，禁相加）
4. 业务特性：店面 PDP；部分个性化（recently-viewed 禁共享池）；热路径= catalog live + chrome/搜索/分类树 + 三推荐
5. 缓存合规初判：公共 chrome/树 **疑似 miss/过重**；推荐/EAV **N+1 fail 倾向**；**禁拆壳**
6. 与架构 surfaces draft **同向对接**（纪要 §5.1）；待架构升 `architect_joint=true`

**Escalate**：`@项目经理：请立刻组队解决`（对齐冻结：性能×架构；suggested：架构师/探查/后端·Product）

**plan**：P0-diagnose → `result=waiting_acceptance`（本席 issuer，勿 closed）

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION

---

## msg-5 · 架构师 · 2026-09-23T23:52+08

@项目经理 @性能检查工程师 @领域探查 @需求分析 @电商顾问

**联合回执（机制对齐 · 未授权施工）**

1. 已对照 `meetings/性能检查-design.md`（verdict=objection · §3 热点 · §4 合规 · §5/§5.1）与探查锚点。
2. **`architect_joint=true`** 已回写 `surfaces.md`（去掉 draft 阻塞；标 **联合已对齐 · 待 UC 冻结**）。
3. 共同定制优化方向已合并为 **O1–O5**（≤5）：
   - **O1** live_request 单次 + catalog 复用（主）
   - **O2** PDP 预取协调器 + 推荐三件套批量 + EAV bulk / QueryProvider（主；recently-viewed 仅 request memo）
   - **O3** category_tree.urls 批量 + CachePolicy（辅）
   - **O4** header / type-dropdown Policy 预热 + single-flight（辅 · **禁拆壳**）
   - **O5** 验收口径：冷暖分述；禁徽标相加；`wls_tpl_perf` ≠ FPC HIT
4. 硬边界继承：framework_first · 禁平行袋 · 禁拆 chrome · 实时价库不进长期共享 · 个性化禁共享 HTML。
5. 顾问 stance（辅货架延迟/限卡）已纳入 O2，**不**构成拆壳。

**禁**：施工改码、reload、数值伪加速比。

architect_joint: **true**  
路径：`app/code/Weline/Product/doc/开发/team/pdp-n1-query-storm-20260923/surfaces.md`

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION（P1-surfaces → joint=true；可开对齐冻结会钉 UC）  
@性能检查工程师：请将纪要 `architect_joint` 勾为 true / 收 issuer 验收指针（本席 joint 已立；纪要头字段已代同步）

---

## msg-5 · 项目经理 · 2026-09-23T23:51+08

@架构师 @性能检查工程师 @领域探查 @需求分析 @电商顾问

**PM DoD**
- 性能纪要 → **pass**（P0 `waiting_acceptance`，未 closed）
- 探查 `meetings/探查-code-loci.md` → **pass**（落点齐；recently-viewed ×N live 最硬）
- 架构师 → **已续派**升 `architect_joint=true`
- 需求 → **conditional**：spec 须补电商顾问 stance A～E（已 interrupt 催补）

禁施工。joint + stance 补齐后开对齐冻结。

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION

---

## msg-6 · 项目经理 · 2026-09-23T23:52+08

@架构师 @性能检查工程师 @需求分析 @电商顾问

**PM DoD**
- [架构师] msg-5 / `surfaces.md` **`architect_joint=true` · O1–O5** → **pass**（联合已对齐 · 待 UC；禁施工）
- 性能/探查此前已 pass，不再重验
- **唯一阻塞**：需求分析将电商顾问 stance 写入 spec 后方可开对齐冻结会

@性能检查工程师：P0 可签 `issuer_acceptance`（joint 已立；项仍勿 closed 至 UC 冻）

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION

---

## msg-7 · 项目经理 · 2026-09-23T23:55+08

@全体

**对齐冻结完成（意图层）**
- 需求分析 stance→spec → **PM DoD pass**（UC-1～10）
- 架构师 joint=true → 此前已 pass
- 产出：`contracts.md`（O1–O5 → C-O1…C-O5 queued；C-OPS blocked_until_impl）
- spec → `frozen: true` / `ready-for-plan`
- **施工闸门关闭**：用户未明示开工 → 禁派工改码 / reload

诊断结论一句话：冷 PDP 数百 DB span = N+1/重复投影（RV×N live 最硬）；方向 = catalog 单次+预取批量+树/header Policy；辅货架可延迟限卡；禁拆壳。

notify_pm: true  
@项目经理：本席已交付/上报，请检查并更新 SESSION
