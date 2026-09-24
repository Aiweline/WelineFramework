# 性能检查 · 设计轨纪要

| 字段 | 值 |
|------|-----|
| slug | `pdp-n1-query-storm-20260923` |
| 席位 | Team:性能检查工程师: |
| 波次 | 立项 / 设计检查轨 |
| 时间 | 2026-09-23T23:50+08 |
| verdict | **objection**（异常 DB span 风暴成立；未授权施工） |
| architect_joint | **true**（架构师 msg-5 / `surfaces.md` O1–O5 联合已对齐 · 待 UC 冻结） |
| plan_id | P0-diagnose |
| issuer_acceptance | pending（本席为 issuer → PM 更新后本席盯验，勿标 closed） |

---

## 1. 证据核对（本机）

Host：`p05113ef3.test.weline.com:9555`  
URL：`/product/qi-yue-xi-fu-shi-yuan-chuang-zhang-le-gong-zhu-tang-zhi-bei-zi-fu-yuan-qi-y-3c58b9fe?size=s&style_type=hong-se-zhan&wls_tpl_perf=1`  
探针：`var/log/wls/timing.log` + 本席 curl（`-L`，HTTP→HTTPS 308 后 200）。

| 样本 | request_id（节选） | total_ms | db_span_count | db_duration_ms | wls_span_count | dropped_span_count |
|------|--------------------|----------|---------------|----------------|----------------|--------------------|
| 立项高峰 | `aacb46f0…` | 2200.91 | **936** | **761.31** | 532 | 4821 |
| 立项偏低 | `…7994` | 1821.41 | **666** | **315.28** | 620 | 4292 |
| 本席复测 | `28ea9e9c…` | 1749.54 | **729** | **304.91** | 1070 | 7704 |

口径提醒（合规）：

- `db_span_count` = 已埋点 DB 操作计数（含失败尝试），**≠** 物理 SQL 条数；明细截断后仍累计（本样本 `span_count=4096` 封顶，`dropped` 数千）。
- 父子 phase / 模板徽标 **inclusive**，禁止把部件 q 数相加算整页。
- `wls_tpl_perf=1` 旁路部件 HTML 缓存 → 偏「冷/诊断」口径，不能当 FPC HIT 暖样本。

**判定**：用户「数百 DB span 异常」成立。主因是 **N+1 / 重复解析 / 冷 chrome·目录·推荐链**，不是传输或单条慢 SQL 独因。`trace_db_top` 可见逐 `entity_id` 的 `w_product_ws_0_attribute_value`（如 `source_company_name`）与逐 `global_offer_uuid` 的 `w_weline_offer_identity_v2` find。

---

## 2. 业务特性摘要

| 项 | 结论 |
|----|------|
| 面 | **店面 PDP**（frontend / 默认站 website_id=0） |
| 读/写 | 读为主；变体 query（`size`/`style_type`）触发 live catalog |
| 个性化 | **部分**：guest 可访问；`recently-viewed` 依赖浏览态/会话 → **不可**进共享 HotCache HTML；价格/库存/可售须实时快照 |
| 热路径段 | ① 路由→商品解析→`product.catalog.live_request` ② Theme chrome：`header` + 搜索 type-dropdown + `category_tree.urls` ③ PDP 主信息 + 推荐部件（recently-viewed / you-may-like / cross-sell / related） |
| 缓存边界 | 公共：分类树 URL、搜索类型、发布态投影（channel+lang+currency）；私有：最近浏览、登录钩子、实时库存价 |

---

## 3. 热点排序（按 DB span / 诊断徽标）

### 3.1 请求级 phase（高峰样本 db_span=936）

| 序 | phase | db_span≈ | db_ms≈ | duration_ms≈ | 备注 |
|----|-------|----------|--------|--------------|------|
| 1 | `product.catalog.live_request` | **206** | 154.5 | 287 | 主商品+衍生 live 链；可多次出现 |
| 2 | `theme.partials.fetch.header` | **159** | 50.6 | 210 | chrome；内含搜索/导航 |
| 3 | `storefront.category_tree.urls` | **102** | 17.4 | 48 | 树 URL 解析风暴 |
| 4 | `storefront.cache.builder` | 53 | 37.1 | 228 | 冷构建瀑布（inclusive） |
| 5 | `theme.partials.fetch.head` | 52 | 12.7 | 73 | head 资源/配置 |

### 3.2 模板/部件（template_profile / wls_tpl_perf 徽标；勿累加）

| 序 | 表面 | db_q 峰值≈ | 说明 |
|----|------|------------|------|
| A | `partials/search/com_type-dropdown` | **~130** | 嵌在 header；搜索类型冷路径 |
| B | `com_recently-viewed` | **~161**（高峰）/ 暖时下降 | 个性化推荐；EAV/卡片 N+1 嫌疑 |
| C | `com_you-may-like` | **~50–66** | 多商品投影重复 |
| D | `com_cross-sell` | **~29–34** | 同上量级较低 |
| E | `com_product-info` | **~16–47** | 主信息；相对可控 |
| F | header `storefront-shell` | **~144–159** | 与 phase header 同向（inclusive） |

**排序结论（给架构联合用）**：  
`catalog.live_request` ≥ `header·搜索 type-dropdown` ≥ `category_tree.urls` ≥ `recently-viewed` ≥ `you-may-like` ≥ `cross-sell`。

---

## 4. 缓存合规初判

| 检查项 | 初判 | 说明 |
|--------|------|------|
| HotCache / CachePolicy 对公共 chrome·分类树 | **疑似未充分命中或 builder 过重** | phase 仍出现百级 DB；应走 website/channel + lang/currency + catalog deps，禁平行 static 袋 |
| 批量 Query vs N+1 | **fail 倾向** | 逐 entity EAV、逐 offer identity find = 典型 N+1；应用 IN 批量 / 已有 catalog bulk projection |
| 个性化进共享池 | **禁止** | recently-viewed HTML/会话事实不得标可共享；仅请求内 memo + 候选 ID 批量拉取 |
| 可变 Model / 草稿进池 | 未见本波主张 | — |
| 把 DB N+1 换成 WLS RPC N+1 | 须避免 | 批量须真 MGET/MSET 或 DB IN |
| 拆壳药方 | **否决** | 禁止移除 header/footer/必装 widget 当优化 |

---

## 5. 与架构师同向 · 优化方向清单（候选 · 未冻）

`architect_joint=true`（架构师已回写 `surfaces.md` O1–O5）— 下列为性能席原候选，联合冻结映射见 surfaces。

1. **Catalog live_request**：发布态读模型 / 请求内单次 live + 投影复用；变体实时快照与详情字段拆分（库存价不进长期共享 JSON）。
2. **category_tree.urls**：一次批量解析 URL + HotCache（scope website/channel，deps `catalog`）；禁逐节点查 rewrite。
3. **Header / 搜索 type-dropdown**：`area=frontend` 搜索类型 CachePolicy 预热 + single-flight；与既有 storefront cache builder 对齐，勿平行袋。
4. **推荐三件套**（recently-viewed / you-may-like / cross-sell）：候选 ID 收集 → **批量** catalog/EAV/offer identity；卡片渲染共用投影；recently-viewed 仅 request memo。
5. **EAV 属性**：`source_company_name` 等卡片字段走 bulk fields，禁 per-id per-code select。
6. **验收口径**：同 URL 冷/暖两档；报告 `db_span_count`+`db_duration_ms`+关键 phase；禁止徽标 q 相加；`wls_tpl_perf` 诊断样本与 FPC HIT 样本分开陈述。

**禁止项（硬）**：拆 chrome / 删无卸载记录的 required widget；业务类进程内 static 袋；本席私自排施工。

### 5.1 与架构 `surfaces.md` draft 对接（同向 · 未冻）

已读架构 msg-2 / `surfaces.md` draft。性能热点排序与下列候选 **对齐**，建议冻结时一一映射：

| 架构候选 | 性能席证据对接 | 合规注 |
|----------|----------------|--------|
| 复用 catalog 批量（publishedOfferSummaries / prefetchForProducts） | `live_request` 206 + 推荐区重复投影 | 实时价库不进长期共享 |
| PDP 请求级预取协调器（汇 ID→单批 hydrate） | recently-viewed~161 / you-may-like~66 / cross-sell~34 | recently-viewed 仅 request memo，禁共享 HTML |
| category_tree.urls + headerSearchTypes / Partials HotCache 核验 | category_tree~102；type-dropdown~130；header~159 | 疑似 miss/绕过，先证据再修 |
| QueryProvider 扩面（RecentlyViewed→cards） | 逐 entity EAV / offer identity N+1 | 禁跨模块直调 Model |
| 收紧 live_request 边界（主链一次） | live 可多次出现；推荐勿再全扫 | 与顾问 stance：辅货架可延迟/限卡数一致 |

电商顾问 stance（辅位非阻塞、限卡）**不与**拆壳冲突：优化是降同步成本+批量，不是删 chrome/必装。

---

## 6. Escalate / 下游

- **result**：`waiting_acceptance`（P0-diagnose 交付物已齐：证据+热点+合规初判+方向候选；联合冻结属 P1，待架构师）。
- **suggested_seats**：`架构师`（机制/surfaces）、探查（代码落点）、后端/Product（施工归属待 PM 派）、主题/部件（仅当 chrome 缓存边界需协同——**不**拆壳）。
- **@项目经理：请立刻组队解决**（对齐冻结会：性能×架构 `architect_joint`；未授权前禁止 reload 大改）。

---

## 7. 自检清单

- [x] 业务特性摘要
- [x] 框架热路径映射（phase 名级）
- [x] 缓存合规初判
- [x] 优化方向候选（禁拆壳）
- [x] architect_joint=true（架构师 msg-5 / surfaces O1–O5）
