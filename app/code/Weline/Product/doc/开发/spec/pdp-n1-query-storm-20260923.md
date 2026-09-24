---
status: ready-for-plan
work_kind: feature
feature_slug: pdp-n1-query-storm-20260923
module: Weline_Product
updated: 2026-09-23
session_path: ../session/pdp-n1-query-storm-20260923.md
advisor_stance_path: ../team/pdp-n1-query-storm-20260923/meetings/电商顾问-stance.md
fe_be_scope: backend_hot_path_primary; storefront_pdp_cold; chrome_header_search_in_scope; shelf_defer_ok; no_new_ui_surface
clarify_status: frozen
frozen: true
ops_acceptance_required: true
contracts_path: ../team/pdp-n1-query-storm-20260923/contracts.md
---

# Spec：PDP 单次请求数百 SQL（N+1 / 查询风暴）

> **对齐冻结（2026-09-23）**：UC-1～10 + 顾问 stance + `architect_joint` O1–O5 已写入 `contracts.md`。**施工未派**。  
> **已纳入** 电商顾问 stance：`../team/pdp-n1-query-storm-20260923/meetings/电商顾问-stance.md`（体验分层 / 卡数 / 骨架→真卡 / 导航解耦 / `ops_acceptance`）。  
> **禁伪加速比**：验收只认请求级绝对量（`db_span_count` / `db_duration_ms` / TTFB ms）。

## 0. 电商顾问产品边界（已纳入 · 已冻意图）

| 条款 | 规格写法 |
|------|----------|
| 首屏主职 | 当前 SKU 决策（主图、标题、价、规格、加购/购买）**必达** |
| 辅货架 | recently-viewed / you-may-like / cross-sell 为辅转化位，**默认可延迟 / 非阻塞**；不得与主职抢同一次同步全量优先级 |
| 同步满载 | **同屏同步全量不算硬需求**；禁止「首字节出满货架」作默认成功标准 |
| 骨架→真卡 | 允许骨架或静默占位 → 邻近视口/主内容就绪后再出真卡；**空态短文案优于拖死整页** |
| 卡数上限 | you-may-like / 相关 **≤8**（优先 **4～6**）；recently-viewed **≤6**；cross-sell **≤4** 组或卡 |
| 导航解耦 | 目录树 / 搜索下拉不得拖慢购买区到「像坏站」；体验与主职**解耦感知**（指标仍计入请求级） |
| 默认路径 | 降同步成本 + 控 N + 可延迟；**禁**默认砍/藏必装商机位 |
| 店面汇审 | 店面变更须电商顾问 **`ops_acceptance=pass`** 后方可汇审完成 |

## 1. 现象 vs 目标（请求级量级）

### 1.1 现象（已举证 · 本机 Official / Host `p05113ef3.test.weline.com:9555`）

| 观测面 | 量级（冷路径样本） | 口径 |
|--------|-------------------|------|
| 请求级 `trace_summary.db_span_count` | **≈ 666～936** | 整页一次 HTTP 响应内全部 DB span，**禁止**把各部件 span 简单相加算「整页」 |
| 请求级 `db_duration_ms` | **≈ 315～761** | 同上；绝对毫秒，非比例 |
| 相位热点（span 量级） | `product.catalog.live_request` ~206；`theme.partials.fetch.header` ~159；`storefront.category_tree.urls` ~102；搜索 type-dropdown ~130 | `wls_tpl_perf=1` 相位拆解 |
| 部件级 | `recently-viewed` / `you-may-like` / `cross-sell` / `product-info` 各数十～百余 span | 旁路徽标 total/db/wls/php |
| 判定（立项） | 异常 N+1 / 重复解析，**非**传输/带宽问题；顾问同意「一开详情就全店重算」异常 | 用户认定「数百 SQL 绝对不正常」 |

证据入口：

`https://p05113ef3.test.weline.com:9555/product/qi-yue-xi-fu-shi-yuan-chuang-zhang-le-gong-zhu-tang-zhi-bei-zi-fu-yuan-qi-y-3c58b9fe?size=s&style_type=hong-se-zhan&wls_tpl_perf=1`

### 1.2 目标（草案 · 绝对量级 + 体验分层 · 待冻）

| 目标 ID | 可观察指标 | 草案目标 | 说明 |
|---------|------------|----------|------|
| G-DB-SPAN | 冷 PDP 请求级 `db_span_count` | **≤150**（理想 **≤100**） | 单次完整 HTML；冷口径见 §3.2 |
| G-DB-MS | 同请求 `db_duration_ms` | **≤120**（理想 **≤80**） | 绝对 ms；不报倍速 |
| G-TTFB | 同条件冷 TTFB | 基线绝对 ms → **可复现下降** | 不作「提速 X%」门禁；≥3 次中位 |
| G-PHASE | 四相位 + 三辅货架部件 | 不再「单部件百级 span」 | 旁路不得伪装请求级达标 |
| G-LAYER | 体验分层 | **主职必达**；辅货架可延迟 | 见 §0 |
| G-CARD | 卡数上限 | 喜欢≤8（优4～6）/ 最近≤6 / 交叉≤4 | 产品边界，非施工单 |
| G-DEFER | 骨架→真卡 | 占位允许；空态优于拖死整页 | 延迟不得永久假「无推荐」 |
| G-DECOUPLE | 导航 vs 购买区 | 购买区优先可用感知 | 目录/搜索 span 仍计入 G-DB-SPAN |
| G-OPS | 店面运营验收 | `ops_acceptance=pass` | **未过签禁汇审完成** |
| G-NO-STRIP | 禁拆壳/禁砍位 | 无 `user_deleted` 保持必装 | 默认路径≠卸转化位 |

**非目标（本波）**

- 不改商品业务规则、不改价库存语义、不新开 PDP UI 表面。
- 不以 FPC 全页 HIT 冒充冷路径 SQL 目标已达成（见 §5）。
- 不拆主题 chrome / 无 `user_deleted` 的必装默认注入；删/藏辅货架须先过顾问运营验收。
- 不把「热 Worker 二次请求」当冷路径验收；不报伪加速比。
- **不**把「首字节同步出满货架真卡」写成默认成功标准。

## 2. 范围

| 维度 | 纳入 | 排除 |
|------|------|------|
| 路径 | 店面 **商品详情（PDP）冷路径**；含可延迟辅货架体验 | 列表页、购物车、结账、后台 Admin |
| 数据面 | 目录 live；分类树 URL；顶栏；搜索 type-dropdown；`recently-viewed` / `you-may-like` / `cross-sell` | 支付、物流、订单写路径 |
| 体验 | 主职必达 + 辅位延迟/限卡 + 导航解耦感知 | 「一切同屏瞬间全出」硬需求 |
| 角色 | 匿名访客为主 | 主题编辑器预览/草稿（可另记回归） |
| 环境 | 本机 `*.test.weline.com`（例 `:9555`） | 未明示不得用生产 SSH 样本当门禁 |
| 汇审 | 须顾问 `ops_acceptance` | 未运营过签宣称完成 |

## 3. 澄清记录

### 3.1 已确认

1. 请求级数百 DB span = 异常（N+1/重复解析）；顾问同意辅货架/导航不应「一开详情全店重算」。  
2. 证据：`wls_tpl_perf`（≥2.5.173）。  
3. 波次 `clarifying`；禁私自 reload 大改；禁拆壳药方。  
4. **顾问 stance 已写入本规格 §0 / 目标 / EARS / UC**（本回合 DoD 补齐）。  
5. 优化目标 = 可售体验（快、稳、可信）+ 绝对量级；默认 **降同步成本 + 控 N + 可延迟**。

### 3.2 待答（≤5 · 对齐冻结前）

1. **G-DB-SPAN / G-DB-MS**：是否采纳 ≤150 / ≤120，或性能席分位？  
2. **冷口径**：新 PID / `no-store` / bypass FPC 组合？  
3. **搜索 type-dropdown**：仅 SSR 还是含首次 AJAX？  
4. **辅货架延迟抽检**：滚动至槽 / 等待可见真卡的运营主观标准如何记？  
5. **登录态**：本波是否强制抽检登录访客 PDP？

## 4. 用户故事 + EARS（草案）

### US-1 访客冷开 PDP（量级）

As a **店面匿名访客**, I want **冷开 PDP 不以数百 SQL 拖死首屏**, so that **能尽快完成当前商品决策**.

- **WHEN** 匿名访客按冻结冷口径 GET 可售 PDP **THEN** 系统 **SHALL** 使请求级 `db_span_count` ≤ 冻结门禁（草案 ≤150）。  
- **WHEN** 同上 **THEN** 系统 **SHALL** 使 `db_duration_ms` ≤ 冻结门禁（草案 ≤120），以绝对毫秒记账，**不得**仅用加速比宣称成功。  
- **WHEN** `wls_tpl_perf=1` **THEN** 系统 **SHALL** 仍能拆相位/部件 db，且纳范围内辅货架/顶栏搜索相关相位 **不得**单独百级 span。  
- **IF** 样本为 FPC HIT 或热 Worker **THEN** 验收方 **SHALL** 不得用该样本判定冷路径 G-DB-*。

### US-2 体验分层：主职必达 / 辅位可延迟

As a **店面访客**, I want **打开详情时先能看清并操作当前商品**, so that **辅货架计算不阻塞购买决策**.

- **WHEN** 冷 PDP 首屏可达 **THEN** 系统 **SHALL** 使主图、标题、价、规格、加购/购买区可读可点（主职必达）。  
- **WHEN** 存在 recently-viewed / you-may-like / cross-sell 槽 **THEN** 系统 **SHALL** **不**把「首字节同步出满货架真卡」作为默认成功标准。  
- **IF** 辅货架数据未就绪 **THEN** 系统 **SHALL** 允许骨架或静默占位；**空态短文案优于拖死整页**。

### US-3 卡数上限与诚实空态

As a **店面访客**, I want **辅货架卡数克制、空态诚实**, so that **不因多卡卡顿或假空损伤购买意愿**.

- **WHILE** 渲染 you-may-like / 相关推荐 **THEN** 系统 **SHALL** 展示卡数 **≤8**（优先 **4～6**）。  
- **WHILE** 渲染 recently-viewed **THEN** 系统 **SHALL** 展示卡数 **≤6**。  
- **WHILE** 渲染 cross-sell / 经常一起买 **THEN** 系统 **SHALL** 展示 **≤4** 组或卡。  
- **IF** 无候选 **THEN** 系统 **SHALL** 使用短空态（或合法空），不得为降 SQL 永久卸槽并冒充「站点无推荐策略」。

### US-4 骨架 → 真卡

As a **店面访客**, I want **辅货架可先占位再出真卡**, so that **主路径不被同步全量拖死**.

- **WHEN** 辅货架采用延迟填充 **THEN** 系统 **SHALL** 在占位阶段不误导为永久无货架（除非真实无候选并已空态）。  
- **WHEN** 真卡出现 **THEN** 系统 **SHALL** 保证价/图/语种可信（非假价/假图/错语种），供运营抽检。

### US-5 导航与购买区体验解耦

As a **店面访客**, I want **目录树与搜索不拖垮购买区**, so that **详情页不像坏站**.

- **WHEN** 冷开 PDP **THEN** 系统 **SHALL** 使购买决策区可用性优先于导航类全量解析的同步完成感。  
- **WHEN** 测量请求级 SQL **THEN** 系统 **SHALL** 仍把 header / category_tree / 搜索相关 span 计入整页门禁（体验解耦 ≠ 指标忽略）。

### US-6 禁拆壳 + 运营验收

As a **电商顾问（运营）**, I want **店面变更经 ops_acceptance**, so that **古风商城感与卡片可信度不被性能施工毁掉**.

- **WHILE** 无人工卸载记录 **THEN** 优化方案 **SHALL** 不得拆必装 chrome / 必装商机位达标。  
- **WHEN** 辅货架限卡/延迟或导航查询收敛已合入店面 **THEN** 汇审前流程 **SHALL** 取得电商顾问 `ops_acceptance=pass`；**未过签不得宣称汇审完成**。

### US-7 验收可复现

As a **性能检查工程师 / 测试席**, I want **用同一 URL 与冷口径复测**, so that **门禁可对账**.

- **WHEN** 按冻结冷口径采样 ≥3 次 **THEN** 中位 `db_span_count` / `db_duration_ms` / TTFB **SHALL** 可写入验收记录。  
- **IF** Worker 热身导致 span 显著偏低 **THEN** 验收方 **SHALL** 标为热路径并排除出冷门禁判定。

## 5. 框架抽象映射（Query / HotCache / FPC）

> what→机制选型提示；施工细节归架构师 `surfaces` / 对齐冻结。

| 问题面 | 框架抽象 | 映射意图（草案） | 验收注意 |
|--------|----------|------------------|----------|
| 同类实体/URL 反复单条 SELECT | **Query / QueryProvider 批量 + 预取** | 目录、分类 URL、搜索候选、推荐 ID→事实 | 语义等价；禁平行袋 |
| 可共享已发布投影 | **HotCache** | Scope + 正式 `changed`；fence 下仅请求 memo | 禁假 HIT / 空投影 |
| 整页匿名 HTML | **FPC** | 热路径加速；**不**顶替冷 SQL 门禁 | 冷/HIT 分账 |
| 辅货架同步过重 | 产品允许骨架→真卡 + 批量 hydrate | 控 N（G-CARD）+ 可延迟（G-DEFER） | 真卡可信；ops 过签 |

**耦合提示**：URL/分类树走框架批量入口；性能药方与必装冲突 → escalate，禁拆壳。  
**需求纠偏**：字面「砍查询」与必装/商机位冲突时，以「主职必达 + 辅位可延迟限卡 + 请求级量级 + ops_acceptance」为准，禁止卸功能凑数。

## 6. 用例草案（未冻）

### UC-1 冷路径请求级 DB 量级达标

| 字段 | 内容 |
|------|------|
| 角色 | 匿名访客 / 性能验收 |
| 前置 | 可售 PDP；冻结冷口径；可开 `wls_tpl_perf=1` |
| 主成功步骤 | 冷 GET ≥3 次 → 中位对照 G-DB-* / G-TTFB |
| 备选/异常 | 热样本排除；5xx 失败 |
| 期望结果 | 绝对量级达标；无伪加速比 |
| 映射 acceptance | 性能/e2e trace 断言 |

### UC-2 主职首屏必达

| 字段 | 内容 |
|------|------|
| 角色 | 匿名访客 |
| 前置 | 可售 SKU |
| 主成功步骤 | 打开 PDP → 断言主图/标题/价/规格/购买区可读可点，**不依赖**辅货架真卡已出 |
| 备选/异常 | 主职缺失 → fail |
| 期望结果 | G-LAYER 主职满足 |
| 映射 acceptance | Browser WB-OP |

### UC-3 辅货架体验分层（允许非同步满载）

| 字段 | 内容 |
|------|------|
| 角色 | 匿名访客 / 运营 |
| 前置 | 布局含辅货架槽 |
| 主成功步骤 | 首屏可存在骨架/占位；不得因「未瞬间满货架」判产品失败 |
| 备选/异常 | 整页被辅货架拖死不可购 → fail |
| 期望结果 | 同步满载非硬需求 |
| 映射 acceptance | Browser + 顾问抽检 |

### UC-4 卡数上限

| 字段 | 内容 |
|------|------|
| 角色 | 访客 / 运营 |
| 前置 | 有足够候选数据 |
| 主成功步骤 | 断言 you-may-like ≤8（优 4～6）、recently-viewed ≤6、cross-sell ≤4 |
| 备选/异常 | 超限 → fail（产品边界） |
| 期望结果 | G-CARD |
| 映射 acceptance | Browser DOM/目测 |

### UC-5 骨架 → 真卡且不假空

| 字段 | 内容 |
|------|------|
| 角色 | 访客 / 运营 |
| 前置 | 有推荐数据的 SKU |
| 主成功步骤 | 见占位 → 真卡；价/图/语种可信；有数据则非永久空 |
| 备选/异常 | 真实无候选 → 短空态 OK；永久假空 → fail |
| 期望结果 | G-DEFER |
| 映射 acceptance | Browser + ops |

### UC-6 导航与购买区解耦感知

| 字段 | 内容 |
|------|------|
| 角色 | 访客 |
| 前置 | chrome 未卸载 |
| 主成功步骤 | 冷开后购买区先可用；顶栏/搜索入口仍在；相关 span 计入请求级且相对立项下降 |
| 备选/异常 | 购买区像坏站 → fail |
| 期望结果 | G-DECOUPLE |
| 映射 acceptance | Browser + trace |

### UC-7 推荐/交叉销售降查询后仍可信可见

| 字段 | 内容 |
|------|------|
| 角色 | 访客 |
| 前置 | 有/无候选两种各至少一测 |
| 主成功步骤 | 有数据出真卡；无数据短空态；部件不再百级 span |
| 备选/异常 | 卸槽凑 SQL → fail（除非顾问 ops 授权） |
| 期望结果 | 功能+量级双达标 |
| 映射 acceptance | Browser + perf |

### UC-8 FPC HIT 不顶替冷门禁

| 字段 | 内容 |
|------|------|
| 角色 | 性能验收 |
| 前置 | 可识别 HIT（若环境支持） |
| 主成功步骤 | 冷/HIT 分账；仅冷样本判 G-DB-* |
| 备选/异常 | FPC disabled → UC N/A，冷样本仍必做 |
| 期望结果 | 无冒充 |
| 映射 acceptance | 性能报告字段 |

### UC-9 必装 chrome/商机位不被拆卸

| 字段 | 内容 |
|------|------|
| 角色 | 主题/性能/顾问 |
| 前置 | 无 `user_deleted` |
| 主成功步骤 | 优化后顶栏与必装辅货架槽仍在 |
| 备选/异常 | 有合法卸载 → 按卸载后布局 |
| 期望结果 | G-NO-STRIP |
| 映射 acceptance | Browser + 必装契约 |

### UC-10 店面 ops_acceptance 过签后方可汇审

| 字段 | 内容 |
|------|------|
| 角色 | 电商顾问（issuer/运营） |
| 前置 | 店面可见变更已合入（限卡/延迟/查询收敛等） |
| 主成功步骤 | 顾问按 stance 底线抽检 → 写 `ops_acceptance=pass|fail`；pass 后 PM 才可汇审完成 |
| 备选/异常 | fail → 返工，禁汇审完成 |
| 期望结果 | G-OPS |
| 映射 acceptance | SESSION 审查索引 / 顾问签收 |

## 7. 隐形需求摘要

- 主题必装 / `required_default_always_present`；禁拆壳达标。  
- 性能热路径复审；HotCache/FPC 合规。  
- 店面变更 **`ops_acceptance`**（顾问）硬门禁。  
- 验收 Host `*.test.weline.com`；Browser 非抢占 / 禁缓存 / 抹 webdriver（若 WB-OP）。  
- 本波预期无新用户可见文案；若有则翻译席规则。

## 8. 就绪检查

- [x] `status: draft`（**未冻**；未升 `clarified` / `ready-for-plan`）  
- [x] 电商顾问 stance 五件套已写入 §0 / 目标 / EARS / UC  
- [x] ≥1 用户故事 + 每故事 ≥2 条 EARS  
- [x] ≥1 用例；本草案 **UC-1～UC-10**  
- [x] 非目标明确（含「同步满载非硬需求」）  
- [x] 已点名 e2e/性能/Browser/`ops_acceptance` 验收意图  
- [x] 未把施工补丁步骤写进正文  
- [ ] §3.2 待答题已答 → 可升 `clarified`  
- [ ] 对齐冻结 → `ready-for-plan`

## 9. 下一步（非本席）

1. 性能 × 架构：`architect_joint` + 门禁数值冻结。  
2. PM：收 §3.2 → 对齐冻结会 → contracts。  
3. 店面合入后：**电商顾问 `ops_acceptance`** 过签再汇审。  
4. **本席禁写业务码**；本规格保持 `draft` 直至冻结。

## 10. UC 标题速览（回报用）

1. UC-1 冷路径请求级 DB 量级达标  
2. UC-2 主职首屏必达  
3. UC-3 辅货架体验分层：允许非同步满载  
4. UC-4 卡数上限（喜欢≤8 / 最近≤6 / 交叉≤4）  
5. UC-5 辅货架骨架 → 真卡且不假空  
6. UC-6 导航与购买区解耦感知  
7. UC-7 推荐/交叉销售降查询后仍可信可见  
8. UC-8 FPC HIT 不顶替冷门禁  
9. UC-9 必装 chrome/商机位不被拆卸  
10. UC-10 店面 ops_acceptance 过签后方可汇审
