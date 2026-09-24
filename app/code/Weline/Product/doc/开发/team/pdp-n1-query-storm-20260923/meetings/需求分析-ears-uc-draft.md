# 需求分析 · EARS / UC 草案（未冻）

- **席位**：Team:需求分析:（禁写业务码）
- **SESSION**：`pdp-n1-query-storm-20260923`
- **时间**：2026-09-23T23:52+08
- **规格指针**：`../../spec/pdp-n1-query-storm-20260923.md`（`status: draft`，与本文同步意图）
- **必读已纳入**：`meetings/电商顾问-stance.md`（体验分层 / 卡数 / 骨架→真卡 / 导航解耦 / `ops_acceptance`）
- **frozen**：false · 对齐冻结会前不得当施工验收合同

---

## 0. 顾问约束纳入摘要（产品边界）

| 顾问条款 | 澄清/UC 如何写 |
|----------|----------------|
| 首屏主职 = 当前 SKU 决策；辅货架可延迟 | **体验分层**：必达主路径 vs 可延迟辅位；禁止「首字节满货架」作默认成功标准 |
| 同屏同步全量不算硬需求 | 辅位允许骨架/静默占位 → 邻近视口或主内容就绪后再出真卡 |
| 卡数上限 | 猜你喜欢/相关 **≤8**（优先 4～6）；最近浏览 **≤6**；交叉销售 **≤4** 组或卡 |
| 导航与主职解耦 | 目录树/搜索下拉不得拖成「购买区像坏站」；体验上与主职解耦感知 |
| 默认路径 | 降同步成本 + 控 N + 可延迟；**禁**默认砍/藏必装商机位 |
| 店面 | 变更后须 **`ops_acceptance`**；未过签 **禁汇审完成** |

---

## 1. 现象 → 目标（请求级绝对量 · 禁伪加速比）

### 1.1 异常现象（已举证）

| 观测 | 量级 | 口径 |
|------|------|------|
| 请求级 `db_span_count` | ≈ **666～936** | 单次冷 PDP HTML；禁部件 span 相加算整页 |
| 请求级 `db_duration_ms` | ≈ **315～761** | 绝对 ms |
| 相位 | catalog.live ~206；header ~159；category_tree.urls ~102；搜索下拉 ~130 | `wls_tpl_perf` |
| 辅货架部件 | recently-viewed / you-may-like / cross-sell 各数十～百余 span | 立项判定：N+1/重复解析 |

证据 URL：`https://p05113ef3.test.weline.com:9555/product/qi-yue-xi-fu-shi-yuan-chuang-zhang-le-gong-zhu-tang-zhi-bei-zi-fu-yuan-qi-y-3c58b9fe?size=s&style_type=hong-se-zhan&wls_tpl_perf=1`

### 1.2 目标草案（待冻）

| ID | 目标 | 量级/边界 |
|----|------|-----------|
| G-DB-SPAN | 冷请求级 DB span | **≤150**（理想 **≤100**） |
| G-DB-MS | 冷请求级 DB 耗时 | **≤120 ms**（理想 **≤80**） |
| G-TTFB | 冷 TTFB | 基线绝对 ms → 可复现下降；**不作倍速/% 门禁** |
| G-LAYER | 体验分层 | 主职（主图/价/规格/购买）优先可用；辅货架可不阻塞首屏全量 |
| G-CARD | 卡数上限 | you-may-like ≤8（优先 4～6）；recently-viewed ≤6；cross-sell ≤4 |
| G-DEFER | 骨架→真卡 | 辅位允许占位后填真卡；延迟不得永久伪装「无推荐」 |
| G-DECOUPLE | 导航解耦 | 目录/搜索相关查询不得拖垮购买区可用性感知 |
| G-OPS | 运营验收 | 店面变更 `ops_acceptance=pass` 后才可汇审 |
| G-NO-STRIP | 禁拆壳 | 无 `user_deleted` 不得卸必装 chrome/商机位凑 SQL |

**非目标**：改价库存语义；新开 PDP UI 表面；用 FPC HIT 顶替冷路径 SQL 门禁；伪加速比宣称。

---

## 2. 澄清记录（要点）

### 已确认

1. 数百请求级 span = 异常（顾问同意：辅货架/导航不应「一开详情就全店重算」）。
2. 成功标准 = **可售体验**（快、稳、可信）+ 绝对量级，不是卸转化位。
3. 施工前须对齐冻结；性能药方禁拆壳。

### 待答（≤5 · 冻结会）

1. G-DB-* 数值是否采纳草案，或改性能席分位？  
2. 冷口径：新 PID / `no-store` / bypass FPC 组合？  
3. 搜索 type-dropdown：仅 SSR 还是含首次 AJAX？  
4. 辅货架「延迟上限」运营主观标准如何抽检（滚动至槽 / 等待 N 秒可见真卡）？  
5. 本波是否强制登录态 PDP 抽检？

---

## 3. 用户故事 + EARS（草案）

### US-1 冷路径量级

As a **匿名访客**, I want **冷开 PDP 不以数百 SQL 拖死首屏**, so that **能尽快完成当前商品决策**.

- WHEN 匿名访客按冻结冷口径 GET 可售 PDP THEN 系统 SHALL 使该次请求级 `db_span_count` ≤ 冻结门禁（草案 ≤150）。
- WHEN 同上 THEN 系统 SHALL 使 `db_duration_ms` ≤ 冻结门禁（草案 ≤120），并以绝对毫秒记账，禁止仅用加速比宣称成功。
- IF 样本为 FPC HIT 或热 Worker THEN 验收方 SHALL 不得用该样本判定冷路径 G-DB-*。

### US-2 体验分层（主职 / 辅位）

As a **访客**, I want **打开详情时先能看清并操作当前商品**, so that **辅货架计算不阻塞购买决策**.

- WHEN 冷 PDP 首屏可达 THEN 系统 SHALL 使主图、标题、价、规格、加购/购买区可读可点（主职必达）。
- WHEN 存在 recently-viewed / you-may-like / cross-sell 槽 THEN 系统 SHALL **不**把「首字节同步出满货架真卡」作为默认成功标准。
- IF 辅货架数据未就绪 THEN 系统 SHALL 允许骨架或静默占位，且后续真卡填入后内容真实（非假价/假图/错语种）。

### US-3 卡数与空态

As a **访客**, I want **辅货架卡数克制、空态诚实**, so that **不因多卡卡顿或假空损伤购买意愿**.

- WHILE 渲染 you-may-like / 相关推荐 THEN 系统 SHALL 展示卡数 ≤8（优先 4～6）。
- WHILE 渲染 recently-viewed THEN 系统 SHALL 展示卡数 ≤6。
- WHILE 渲染 cross-sell / 经常一起买 THEN 系统 SHALL 展示 ≤4 组或卡。
- IF 无候选 THEN 系统 SHALL 使用短空态文案（或合法空），不得为降 SQL 永久卸槽且冒充「站点无推荐策略」。

### US-4 骨架 → 真卡

As a **访客**, I want **辅货架可先占位再出真卡**, so that **主路径不被同步全量拖死**.

- WHEN 辅货架采用延迟填充 THEN 系统 SHALL 在占位阶段不误导为永久无货架（除非真实无候选并已空态）。
- WHEN 真卡出现 THEN 系统 SHALL 保证卡片价/图/语种可信，供运营抽检。

### US-5 导航与主职解耦

As a **访客**, I want **目录树与搜索能力不拖垮购买区**, so that **详情页不像坏站**.

- WHEN 冷开 PDP THEN 系统 SHALL 使购买决策区可用性优先于导航类全量解析的同步完成感。
- WHEN 测量请求级 SQL THEN 系统 SHALL 仍把 header / category_tree / 搜索相关 span 计入整页门禁（体验解耦 ≠ 指标忽略）。

### US-6 运营验收与禁拆壳

As a **电商顾问（运营）**, I want **店面变更经 ops_acceptance**, so that **古风商城感与卡片可信度不被性能施工毁掉**.

- WHEN 辅货架限卡/延迟或导航查询收敛已合入店面 THEN 汇审前系统（流程）SHALL 取得 `ops_acceptance=pass`。
- WHILE 无人工卸载记录 THEN 优化方案 SHALL 不得拆必装 chrome / 必装商机位达标。

---

## 4. 目标 UC 标题列表（未冻）

| ID | 标题 |
|----|------|
| UC-1 | 冷路径请求级 DB 量级达标 |
| UC-2 | 主职首屏必达（主图/价/规格/购买） |
| UC-3 | 辅货架体验分层：允许非同步满载 |
| UC-4 | 卡数上限（喜欢≤8 / 最近≤6 / 交叉≤4） |
| UC-5 | 辅货架骨架 → 真卡且不假空 |
| UC-6 | 顶栏/目录/搜索与主职解耦感知 |
| UC-7 | 推荐与交叉销售降查询后仍可信可见 |
| UC-8 | FPC HIT 不顶替冷门禁 |
| UC-9 | 必装 chrome/商机位不被拆卸 |
| UC-10 | 店面 ops_acceptance 过签后方可汇审 |

### UC 摘要（可喂 e2e / Browser / 运营）

**UC-1** 冷口径 ≥3 次中位 `db_span`/`db_ms`/TTFB 对照 G-DB-* / G-TTFB。  
**UC-2** 打开 PDP → 主职控件可读可点，不依赖辅货架真卡已出。  
**UC-3** 首屏可存在辅位骨架/占位；不得把「瞬间满货架」当失败以外的硬失败条件。  
**UC-4** 目测/DOM 卡数不超过顾问上限。  
**UC-5** 占位 → 真卡；有数据则最终非永久空；内容可信。  
**UC-6** 购买区先可用；导航相关 span 仍计入请求级，但体验上不拖成坏站感。  
**UC-7** 有数据出真卡 / 无数据短空态；部件不再百级 span。  
**UC-8** 冷/HIT 样本分账。  
**UC-9** 无卸载记录则 chrome/商机位仍在。  
**UC-10** 顾问 `ops_acceptance=pass` 写入 SESSION/审查索引后才允许汇审完成。

---

## 5. 框架抽象映射（提示 · 非施工单）

| 面 | 抽象 | 意图 |
|----|------|------|
| 重复单条读 | Query 批量 / 预取 | 目录、URL、推荐 ID→事实一次装载 |
| 可共享投影 | HotCache | 正式 changed；禁假 HIT / 空投影 |
| 整页热路径 | FPC | 可加速；**不**顶替冷 SQL 门禁 |
| 辅货架延迟 | （产品允许）骨架→真卡；实现归架构，本席不点类名 | 与 G-LAYER / G-DEFER 对齐 |

---

## 6. 就绪与下一步

- [x] EARS 异常→目标草稿  
- [x] UC 草案标题 ≥1（本文件 10）  
- [x] 顾问 stance 五件套已纳入  
- [ ] §2 待答题已答 → 升规格 `clarified`  
- [ ] 对齐冻结 → `ready-for-plan` / contracts  

**下一步（非本席）**：PM 收待答 + 开冻结会；性能/架构 `architect_joint`；店面合入后顾问 ops 过签。

---

## 回报

- result: closed（澄清文档交付）  
- paths: 本文件；规格草案已存在可对照  
- notify_pm: true  
- @项目经理：本席已交付/上报，请检查并更新 SESSION
