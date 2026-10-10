---
status: ready-for-plan
work_kind: feature
feature_slug: storefront-xscope-hotcache-wave
module: Weline_Product
updated: 2026-10-11
session_path: ../../../../../../../dev/session/storefront-xscope-hotcache-wave.md
team_path: ../../../../../../../dev/team/storefront-xscope-hotcache-wave/
related_specs:
  - search-projection-scope-hotcache-prime.md
  - homepage-shelf-offers-locale-hotcache-prime.md
  - ../../Theme/doc/开发/spec/chrome-warmer-l2-shelf-locale-warm.md
freeze_gate: clear_advisor_block
advisor_constraints_status: merged
align_freeze_prep: ready
mechanism_freeze: pending_architect_joint
covers_use_cases:
  - UC-search-query-result
  - UC-plp-filter-offers
  - UC-xscope-chrome-hit
advisor_sources:
  - dev/team/storefront-xscope-hotcache-wave/channel/kickoff.md#msg-3
  - dev/team/storefront-xscope-hotcache-wave/meetings/电商顾问-design.md
mcp_note: prepare_project / get_skill(requirement_clarify_use_case) / aoci_rules timed out prior seat turn; continued via AI硬规则索引.md + 需求澄清与用例规格.md + 扩展点选型.md + findings/inventory + advisor channel/meetings on disk.
---

# Spec: 店面跨范围 HotCache 波（search / PLP / 非 home chrome）

## 1. 背景（证据锚定）

Host：`https://p05113ef3.test.weline.com`  
证据（本波权威）：

- `dev/tmp/perf-cold-chaos-p05113ef3-20261011-020404-findings.md`
- `dev/tmp/perf-cold-chaos-p05113ef3-20261011-020404-inventory.json`
- 同前缀 dig/tsv/xscope 产物

### 先验边界（已收口，本波不得重验为主 KPI）

SESSION `chrome-warmer-l2-shelf-locale-warm` / 规格 `Theme/.../chrome-warmer-l2-shelf-locale-warm.md`：

- home tip 语种（fr/it/es）Quiet：`chrome_n=0`（issuer dig `perf-accept-chrome-l2-issuer-20261011-015331-dig.json`）
- Clear 后 warmer：`gate_ok` fr/it/es；`shelf_l2=hit`；`offers_l2_hit=40`；`search_projection` peek_hit

**本波明确不把「home tip chrome L2 HIT」当作主验收**；先验只证明 homepage tip 暖键对齐已过，**不等于** search / plp / blog / pdp 同语种 chrome HIT，也不等于 tip `q=hanfu` 的 `search.query_result` 已暖。

### 跨范围安静库存（hit1 主证据，摘自 findings + inventory）

| surface | locale/cur | FR hit1 | chrome_n | 主税（exclusive builder） |
|---------|------------|---------|----------|---------------------------|
| search | de_DE/USD | **3081** | 1 | **`search.query_result`≈1030** + `catalog_offers_targeted`≈350 + chrome≈256 + `product_card_html`≈207 |
| search | ja_JP/USD | **3247** | 2 | **`search.query_result`≈2005** + chrome≈425 |
| search | fr_FR/EUR | **3469** | 2 | **chrome Σ≈1891** + query_result≈629 + offers_targeted≈215 |
| plp | de_DE/USD | **2599** | 2 | chrome≈952 + **`filter_panel.v3`≈276** + facet_counts≈143 + catalog_offers≈112 |
| plp | ja_JP/GBP | 2296 | 2 | chrome≈649 + **catalog_offers≈493** + filter_panel≈148 |
| plp_cat | ar_SA/USD | **3012** | 1 | chrome≈1013 + product_card_html≈241 + offers_targeted≈201 + filter_panel≈129 |

粘性：多数 surface hit2 墙钟可掉到 ~0.6–1.4s 且 `chrome_n=0`（粘成立）；**hit2 只证粘，不证冷修**。矩阵 tip 可含叠建尖峰（home de_DE 6.46s / search 12s）——**禁当稳态冷税**。

前序 Product 规格 `search-projection-scope-hotcache-prime.md` 已覆盖 **`product.search_projection_scope`** warmer（禁预热高基数全 q）；本波 escalate 仍见 tip `q=hanfu` 的 **`search.query_result` 空袋冷建**——证明 projection warmer ≠ query_result tip 已暖。

---

## 2. 目标 / 非目标

### 目标

1. soft-clear + 既有 chrome/product warmer 后，**搜索 tip**（默认 `q=hanfu`，与 findings 对齐）安静窗 cold hit1：**不再**以 `search.query_result` 空袋 exclusive 为主税（见 UC / EARS）。
2. **PLP**（`/products` 及分类 plp）安静窗 cold hit1：`product.filter_panel.v3` / `product.catalog_facet_counts` / `product.catalog_offers`（及 search 侧 `catalog_offers_targeted`）空袋税下降或请求侧 HIT。
3. **非 home 表面**（至少 search / plp；blog/pdp 作扩展证据）启用语种安静窗 cold hit1：`theme.storefront_chrome` **请求侧 HIT**（`chrome_n=0` 或无 L1/L2 absent 大 builder）——**禁止**只报 warmer N / 只验 home tip。
4. 全程 **禁拆壳**（`theme_seat_integrity_over_peer_requests` + 顾问 OPS-S2）。
5. 结果正确性：给定 locale+currency+网站范围，集合/价/币种/可售可见性与未缓存基线一致（OPS-S3）；**禁止**把「实时库存必准」写进 UC。

### 非目标（硬）

- **禁止**把拆 header / footer / 必装 widget / 搜索壳 / PLP 壳 / 卡片部件当作优化药方（运营确认无「简化壳」要求；拆壳 → 运营否决）。
- **禁止**把先验 home tip chrome L2 PASS 宣称成全站 chrome 已过。
- **禁止**把 homepage shelf warm / `search_projection` peek_hit 当作 `search.query_result` tip 已暖。
- **禁止**把 warmer「已预热 N 条」单独当作请求侧 HIT 证明。
- **禁止**把粘性 hit2 / 矩阵 tip 叠建尖峰当作冷修主证据（OPS-S5）。
- **禁止**把 chrome 与 Product catalog/search bags 混成一袋。
- **禁止**在 Runtime / 编排层硬编码业务路径、slug、站专用 tip 列表（`runtime_orchestrator_no_business_hardcode`）。
- **禁止**另造平行进程内袋绕开 `CachePolicy` / `StorefrontScopeHotCache`；禁删依赖假 HIT。
- **禁止**本波改买家可见的默认排序 / 筛选项语义 / 结果集合定义（顾问偏好只做 HotCache HIT；若施工需改可见语义 → 另 escalate 电商顾问）。
- **禁止**本澄清席改 PHP / phtml / CSS。
- **本波不主攻**：PDP 跨语种超时/无 FR 分诊（findings #7，可另开计划项）；词典预取残税（#6）；卡片 HTML 袋最终归属以对齐冻结后 `P-card-html` 为准。

---

## 3. 澄清记录

| # | 问题 | 结论（已确认） | 来源 |
|---|------|----------------|------|
| C1 | 主验收面？ | **search tip** + **PLP** + **非 home chrome**（search/plp 必测；blog/pdp 扩展） | PM kickoff + findings + OPS-S4 |
| C2 | 与先验 chrome-warmer 边界？ | home tip 已过 → **本波不以 home tip 为主 KPI**；非 home 须独立证明请求侧 chrome HIT | SESSION + 先验 dig + OPS-S4 |
| C3 | projection warmer 是否已解决搜索？ | **否**；projection peek_hit ≠ tip `search.query_result` 已暖 | findings #1 + 前序 spec |
| C4 | 主证据窗？ | soft-clear 后 **安静窗 cold hit1**（`wls_tpl_perf=1` + `_wb_nc`）；hit2 仅辅证粘 | findings + OPS-S1/S5 |
| C5 | tip 查询词？ | 默认 `q=hanfu`（冻结会可改，须同步 acceptance） | inventory + 顾问 msg-3 |
| C6 | 成功墙钟绝对值？ | **不设唯一墙钟 KPI**；以独占 builder 族阈值 + chrome_n + OPS-S1 可感知改善为主 | 避免叠建尖峰误杀 |
| C7 | 电商主路径 UC 可否进对齐冻结筹备？ | **顾问阻塞已解除**；UC 意图可进对齐冻结筹备。**机制尚未冻**——须架构师 ∥ 性能检查工程师 `architect_joint` 后再冻 contracts/how | channel msg-3 + 性能席 stance |
| C8 | HARD 禁拆壳？ | **是**；运营无简化壳要求；拆壳 → 运营否决 + 主题席 escalate | OPS-S2 + msg-3 Q4 |
| C9 | 新鲜度 / 实时库存？ | HotCache + 正式失效可接受；TTL 内最终一致 OK；**禁止承诺实时库存必准**；库存权威在加购/结账 | msg-3 Q1 + OPS-S3 |
| C10 | 只做 HIT 不改排序/筛选语义？ | **允许且本波偏好**；改可见语义须另 escalate 顾问 | msg-3 Q3 |

### 已冻结默认（顾问已答）

| # | 项 | 冻结默认 |
|---|-----|----------|
| Q1 | 最低必验 locale/currency | search：`de_DE/USD`、`ja_JP/USD`、`fr_FR/EUR`；plp：`de_DE/USD`、`ja_JP/GBP`；可加抽 ar_SA/ko_KR 等，加抽错串仍按 OPS-S3 fail |
| Q2 | PLP 通过线意图 | filter/facet/offers 族税降或 HIT；绝对阈值数字由对齐冻结会与性能席签发 |
| Q3 | 非 home chrome 通过线 | quiet hit1：`chrome_n=0` 或无大 absent builder；禁 home-only |
| Q4 | 卡片 HTML | P-card-html 次优先；归属冻结会定 |
| Q5 | 预热陈旧窗口 | TTL/失效内最终一致可接受；禁长期展示正式失效后错价/错站；禁「实时库存」进 UC |

---

## 4. 顾问约束（已并入 · 2026-10-11）

> **状态：`merged`** — 来源：channel `kickoff.md` **msg-3**（reply）+ `meetings/电商顾问-design.md`（含「答需求分析四问」与 OPS-S1…S5）。  
> **顾问侧 freeze_gate**：`blocked_pending_ecommerce_advisor` **已解除**。  
> **对齐冻结筹备**：UC 意图 + 顾问约束可进；**机制/how 以架构师 ∥ 性能检查工程师冻结为准**（性能席 `architect_joint=pending`）——**勿假装机制已冻**。  
> 施工后须电商顾问 `ops_acceptance=pass` 方可汇审宣称完成。

| 字段 | 内容 |
|------|------|
| stance | **同意方向**（不否决）；四问边界同意并入；禁拆壳运营确认 |
| supported_countries | 本机 `RegionService::getCountries()` 活跃 **251** ISO（2026-10-11）；本波验收子集对齐矩阵 DE/JP/FR/AR·SA/KR 等；本波无结账/文案合规变更 → **政策深检 N/A**；可成交子集待对齐冻结补 |
| policy_notes | 本波不改退换货/广告/隐私/支付披露文案；正确性 = 不得因缓存导致错语种/错币种/错价/错库存可见性/错类目商品（误导展示 → 运营否决） |
| ops_notes | 发现层（搜索+PLP）是可逛主入口；home tip 已暖 ≠ 搜/类目首触可接受；禁只报 warmer N；禁拆导航/搜索壳/卡片 |
| design_brief | 要开发「跨范围发现层热缓存与正确性」：① tip 搜索首触可逛（非仅 shelf warm）② PLP filter/facet/offers 可控 ③ 非 home chrome 请求侧 HIT ④ 正确性硬门槛高于单纯加速；**本波偏好不改可见排序/筛选语义、只做 HotCache HIT** |
| 逻辑约束 | 正式 HotCache（CachePolicy scope/vary/dependencies + **正式失效**）；禁平行袋、禁删依赖假 HIT；TTL 内最终一致 OK；**禁止承诺实时库存必准**；库存权威在加购/结账；列表不得因 HIT 长期错价/错站 |
| 合并记录 | msg-2 ask → msg-3 reply；design.md「答需求分析四问」 |

### OPS-S1…S5（须进 UC / contracts）

| ID | 标准 | 判定 |
|----|------|------|
| OPS-S1 | **首触体验**：search / PLP / plp_cat 在冻结验收子集安静窗 hit1 相对本 findings 基线有可感知改善；粘性 hit2 保持可逛；秒级尖峰不得当稳态 PASS | 性能席数字 + 顾问 Browser 观感 |
| OPS-S2 | **禁拆导航/壳**：顶栏、导航、必装 chrome、搜索壳、商品卡片部件身份保持；无「去壳加速」药方 | 主题席 + 顾问目视；违者否决 |
| OPS-S3 | **结果正确性**：冻结 tip 与至少一个类目 PLP，抽检标题/价/币种/可点进 PDP；错语种错币种错品 → fail；**≠ 实时库存 SLA** | 测试证据 + 顾问抽检 |
| OPS-S4 | **分表面验收**：禁止仅用 home tip / shelf L2 宣称完成；须 search + PLP（+ 至少一非 home chrome）**请求侧**证据 | 汇审门禁 |
| OPS-S5 | **叠建尖峰隔离**：矩阵并行 `singleflight_acquire` 尖峰不得记优化失败，也不得记优化成功 | 性能席标注 |

### 四问裁定（摘要）

1. **新鲜度/失效**：可接受 soft-clear 后 tip 搜索与 PLP 袋走正式 HotCache；禁止「实时库存必准」进 UC。  
2. **最低必验集**：search `de_DE/USD` · `ja_JP/USD` · `fr_FR/EUR`；plp `de_DE/USD` · `ja_JP/GBP`。  
3. **只做 HIT**：允许且偏好；改可见排序/筛选/相关性语义 → 停并 escalate 顾问。  
4. **禁拆壳**：运营无简化壳要求；拆壳 → 否决。

---

## 5. 架构级映射（mechanism · owning · not_to_do）

> **机制冻结状态：`pending_architect_joint`** — 下表为澄清级 what/归属/禁做，供对齐冻结筹备；**chrome 键维、跨表面预热边界、query_result tip 策略、PLP 三袋/失效、测法数字**等 how 须架构师 ∥ 性能检查工程师共定制后写入 contracts，本席不假装已冻。

框架优先：复用 **CachePolicy + StorefrontScopeHotCache（HotCache）+ CacheWarmerRegistry / warmUp pools**；Clear 编排只 `warmUp('…')`，禁 soft-pull 业务 FQCN。扩展点选型：旁路预热走既有 Warmer/Event 贡献面，**禁止**跨模块直调对方 Service/Model。

| plan_id | mechanism（what） | owning_module | not_to_do |
|---------|-------------------|---------------|-----------|
| P-search-query-result | tip 搜索结果袋 `search.query_result`：在投影 warmer 之外补齐 **可证请求侧 HIT** 的预热/键对齐/失效边界（HotCache + CachePolicy；Warmer 属 Product 族扩展或等价 product pool warmer——**具体 how 留给架构+性能**） | `Weline_Product` | 勿把 homepage shelf / projection peek 当 query_result 已暖；禁拆搜索壳；禁 Runtime 硬编码 tip 列表；禁平行 static 袋；禁实时库存 SLA |
| P-plp-filter-offers | PLP 筛选/面税袋：`product.filter_panel.v3` + `product.catalog_facet_counts` + `product.catalog_offers`；搜索定向 `product.catalog_offers_targeted` 同波证明 HIT 或税降 | `Weline_Product` | 勿与 chrome 混袋；禁拆筛选/列表壳；禁改可见筛选语义（本波） |
| P-theme-xscope-chrome | 非 home 表面 chrome 键（layout/page / 货币变体等）与 warmer 写入对齐，使 search/plp/(blog/pdp) quiet hit1 **请求侧 HIT** `theme.storefront_chrome` | `Weline_Theme`（Warmer / chrome coordinator）；Clear 编排 `Weline_Framework` | 禁拆壳；禁只验 home tip；禁只报 warmer N；禁 Runtime 硬编码语种白名单 |
| P-card-html | `theme.product_card_html` 冷税（次优先） | `Weline_Theme`（± Product 协作）— 冻结会钉归属 | 禁拆卡片部件当优化 |

framework_first 触点：`StorefrontScopeHotCache` / `CachePolicy` / `warmUp('weline_theme_storefront_chrome'|'product')` / Theme chrome bags / Product search & catalog bags。

---

## 6. 用户故事

### US-1 搜索 tip 结果袋

As a **店面访客（跨语种搜索首触）**,  
I want **soft-clear 预热后 tip 搜索结果袋已在 durable HotCache**,  
so that **安静窗首击不再为 `search.query_result` 空袋支付 ~1–2s 独占 builder**.

### US-2 PLP 筛选与 offers

As a **店面访客（列表/筛选首触）**,  
I want **PLP filter/facet/offers 袋已预热或键对齐**,  
so that **冷切 PLP 不再为筛选面与报价袋叠建主税**.

### US-3 非 home chrome HIT

As a **店面访客（非首页表面）**,  
I want **search/plp 等表面的 storefront chrome 与 home tip 一样可证 L2/请求侧 HIT**,  
so that **跨范围冷切不再把 chrome 空袋当成「全站已暖」的盲区**.

---

## 7. EARS（验收标准）

### Product — search（P-search-query-result）

- **E-S1** WHEN soft-clear（含 product warmer）完成后对 tip URL `/{locale}/search?q=hanfu&currency=…&wls_tpl_perf=1` 执行安静窗 cold hit1 THEN 系统 SHALL **不**再以 `search.query_result` L1/L2 absent 的大 exclusive builder 为主税（基线：de_DE≈1030ms / ja_JP≈2005ms；通过线默认 exclusive≪基线 40% 或无大 builder——**数字由对齐冻结会与性能席签发**）。
- **E-S2** IF `product.search_projection_scope` 已 peek_hit THEN 系统 SHALL **仍**对 tip `search.query_result` 单独举证（禁止用 projection 证据替代）。
- **E-S3** WHILE 验收进行时 系统 SHALL 保持搜索壳/结果列表部件完整（禁拆壳 · OPS-S2）。
- **E-S4** WHEN 抽检 tip 结果 THEN 系统 SHALL 使给定 locale+currency 下标题/价/币种/可点进 PDP 与未缓存基线一致（OPS-S3）；SHALL NOT 将「实时库存必准」列为通过条件。

### Product — PLP（P-plp-filter-offers）

- **E-P1** WHEN soft-clear 后对 `/{locale}/products?currency=…&wls_tpl_perf=1`（及至少一条分类 plp）安静窗 cold hit1 THEN 系统 SHALL 使 `product.filter_panel.v3` / `product.catalog_facet_counts` / `product.catalog_offers` 族不再构成主税空袋（相对基线 de_DE≈276+143+112 显著下降或 HIT）。
- **E-P2** WHEN 搜索 tip 安静窗 cold hit1 THEN 系统 SHALL 对 `product.catalog_offers_targeted` 同步举证税降或 HIT（基线 de_DE≈350ms）。
- **E-P3** IF 预热/HIT 成立 THEN 系统 SHALL **不**改变买家可见的默认排序/筛选项语义/结果集合定义（顾问 msg-3 Q3；若需改语义须另 escalate 顾问）。
- **E-P4** WHEN 抽检 PLP/类目结果 THEN 系统 SHALL 满足 OPS-S3 正确性（集合 ⊆ 类目+筛选约束；无错串）。

### Theme — 非 home chrome（P-theme-xscope-chrome）

- **E-T1** WHEN soft-clear（含 chrome warmer）完成后对 **非 home** 表面（至少 search + plp；含最低必验语种中的 fr_FR search）安静窗 cold hit1 THEN 系统 SHALL 使 `theme.storefront_chrome` **请求侧 HIT**（`chrome_n=0` 或无 L1/L2 absent 大 builder）。
- **E-T2** IF home tip 语种已 `chrome_n=0` THEN 系统 SHALL **不得**仅凭该证据宣称 search/plp chrome 已过（OPS-S4）。
- **E-T3** WHILE 验收进行时 系统 SHALL 禁止拆 header/footer/必装 widget（OPS-S2）。

### 通用

- **E-G1** WHEN 汇报验收 THEN 证据 SHALL 含 quiet cold hit1 timing（resource/builder + rid）；禁止只报 warmer N。
- **E-G2** IF 出现叠建尖峰（`singleflight_acquire` 数秒）THEN 验收 SHALL 改用安静窗样本，不得把尖峰当失败或成功稳态（OPS-S5）。
- **E-G3** WHEN 最低必验集未覆盖的加抽样本出现错串 THEN 系统 SHALL 仍按 OPS-S3 fail（不得用「不在最低集」豁免）。

---

## 8. 用例（UC）

### UC-search-query-result

| 字段 | 内容 |
|------|------|
| id | UC-search-query-result |
| 名称 | 搜索 tip query_result 冷袋消除 |
| 角色 | 店面访客（guest） |
| 前置 | soft-clear + product/chrome warmer 完成；FPC bypass 诊断窗；tip `q=hanfu` |
| 主成功步骤 | 1) 安静窗 cold hit1：`/{locale}/search?q=hanfu&currency=…&wls_tpl_perf=1`，最低必验 `de_DE/USD`、`ja_JP/USD`、`fr_FR/EUR` 2) 断言无主导 `search.query_result` 空袋（E-S1） 3) 抽检价/币种/可进 PDP（E-S4 / OPS-S3） 4) hit2 仅辅证粘 |
| 备选/异常 | Worker 争用无 FR → 重采安静窗；不得用 tip 尖峰代替（OPS-S5） |
| 期望结果 | tip 可逛；主税不再是 query_result 空袋；壳完整；无错串；无实时库存 SLA |
| 映射 acceptance | `A-search-query-result`（+ OPS-S1/S2/S3/S4） |
| 冻结门禁 | **顾问阻塞已解除**；意图可进对齐冻结筹备；**机制待 architect_joint** |

### UC-plp-filter-offers

| 字段 | 内容 |
|------|------|
| id | UC-plp-filter-offers |
| 名称 | PLP filter/facet/offers 冷袋税降 |
| 角色 | 店面访客 |
| 前置 | 同 UC-search；PLP 有可筛选商品数据 |
| 主成功步骤 | 1) 安静窗：`/{locale}/products?currency=…` 最低必验 `de_DE/USD`、`ja_JP/GBP` 2) 至少一条分类 plp（可加抽 ar_SA） 3) 断言 filter_panel.v3 / facet / catalog_offers 税降或 HIT 4) 抽检正确性（OPS-S3）；筛选 UI 仍可用且语义未改（E-P3） |
| 备选/异常 | 空分类：N/A+理由，另选有货分类 |
| 期望结果 | 列表+筛选可逛；E-P1/E-P2/E-P4；壳完整 |
| 映射 acceptance | `A-plp-filter-offers`（+ OPS-S1…S4） |
| 冻结门禁 | **顾问阻塞已解除**；机制待 architect_joint |

### UC-xscope-chrome-hit

| 字段 | 内容 |
|------|------|
| id | UC-xscope-chrome-hit |
| 名称 | 非 home 表面 chrome 请求侧 HIT |
| 角色 | 店面访客 |
| 前置 | soft-clear + chrome warmer；先验 home tip 可 `chrome_n=0`（对照，非本 UC 主断言） |
| 主成功步骤 | 1) quiet hit1：search（含 fr_FR/EUR）+ plp 2) 断言 `theme.storefront_chrome` 请求侧 HIT（E-T1） 3) home tip 不得单独替代（OPS-S4） |
| 备选/异常 | hit2 偶发 rebuild：记风险，主证据仍 cold hit1 |
| 期望结果 | 非 home chrome HIT；壳完整 |
| 映射 acceptance | `A-xscope-chrome-hit`（+ OPS-S2/S4） |
| 冻结门禁 | **顾问阻塞已解除**；机制待 architect_joint |

---

## 9. acceptance ids（计划期绑定）

| acceptance id | 覆盖 UC | type（意图） | 通过要点 |
|---------------|---------|--------------|----------|
| A-search-query-result | UC-search-query-result | e2e / 冷切 dig（+ Browser 可见搜索结果） | quiet hit1 无主导 `search.query_result` 空袋；OPS-S1/S3；证据含 rid/builder |
| A-plp-filter-offers | UC-plp-filter-offers | e2e / 冷切 dig（+ Browser 筛选/列表可见） | filter/facet/offers 税降或 HIT；OPS-S1/S3；语义未改 |
| A-xscope-chrome-hit | UC-xscope-chrome-hit | e2e / 冷切 dig | 非 home 请求侧 chrome HIT；禁只报 warmer N / 禁只验 home（OPS-S4） |
| A-no-shell-strip | 全部 UC | 契约/复审 + dig 对照 + 顾问目视 | OPS-S2；无拆壳药方 |
| A-ops-acceptance | 全部 UC（顾问签收） | ops_acceptance | 电商顾问施工后 `ops_acceptance=pass`；缺则禁汇审完成 |

计划 `acceptance[].covers_use_cases` 必须列入全部 `UC-*`。收口前全 UC 闭环；冷切数字须写入回报（`automation_test_report_durable_evidence`）。

**最低必验 URL（字面）**：

- `https://p05113ef3.test.weline.com/de_DE/search?q=hanfu&currency=USD`
- `https://p05113ef3.test.weline.com/ja_JP/search?q=hanfu&currency=USD`
- `https://p05113ef3.test.weline.com/fr_FR/search?q=hanfu&currency=EUR`
- `https://p05113ef3.test.weline.com/de_DE/products?currency=USD`
- `https://p05113ef3.test.weline.com/ja_JP/products?currency=GBP`

---

## 10. 隐形需求摘要

- 既有 `CachePolicy` / `StorefrontScopeHotCache` / pool warmer（`weline_theme_storefront_chrome`、`product`）与 Clear `warmStorefrontFpcAfterClear` 编排。
- 前序：`search-projection-scope-hotcache-prime`、`homepage-shelf-offers-locale-hotcache-prime`、`chrome-warmer-l2-shelf-locale-warm`。
- 诊断：`wls_tpl_perf=1`、`_wb_nc`、FPC BYPASS 协议与性能冷切扫描技能。
- HARD：`theme_seat_integrity_over_peer_requests`、`architecture_grade_change_only`、`runtime_orchestrator_no_business_hardcode`、禁平行进程袋。
- 顾问 OPS-S1…S5 + msg-3 四问；性能席 `architect_joint=pending`。

---

## 11. 就绪检查

- [x] 草稿落盘后已并入顾问约束（msg-3 + design.md）
- [x] `status: ready-for-plan`（澄清轨完成；**可进对齐冻结筹备**）
- [x] ≥1 用户故事 + 每故事 ≥2 条 EARS
- [x] ≥1 用例且含主成功路径（3 条 UC）
- [x] 每条 UC 已填映射 acceptance id
- [x] 非目标明确（含禁改可见排序语义、禁实时库存 SLA）
- [x] 已点名 e2e / 冷切 dig / ops_acceptance 意图
- [x] 未把具体类补丁步骤写成强制 how
- [x] 顾问约束节已并入（含 OPS-S1…S5）
- [x] 顾问阻塞 `blocked_pending_ecommerce_advisor` **已解除**
- [ ] **机制已冻** → **否**（`mechanism_freeze: pending_architect_joint`；架构∥性能共定制后由对齐冻结会落 contracts）
- [ ] 收口前全 UC 闭环 + 顾问 ops_acceptance（施工后）

**冻结结论（本席）**：规格 **`ready-for-plan`**；顾问约束已并入；**可进对齐冻结筹备**。机制/阈值数字 **以架构师 + 性能检查工程师冻结为准**——本席不宣称机制已冻。澄清轨本批完成。
