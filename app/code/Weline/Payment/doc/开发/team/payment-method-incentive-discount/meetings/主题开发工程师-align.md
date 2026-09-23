# 主题开发工程师 — 对齐冻结表态

- **席位**：`Team:主题开发工程师:`
- **日期**：2026-09-22
- **通道**：`channel/align-freeze.md`
- **规格**：`../../spec/payment-method-incentive-discount.md`（clarified · 顾问约束 merged）
- **顾问 design_brief**：`meetings/电商顾问.md`（视觉交主题席）
- **技能权威**：`dev/ai-command/ai/主题开发.md` + 薄镜 `weline-theme-development`（本回合 MCP `get_skill` 索引 DISABLED，以仓内指令为准，未编造规则）
- **硬规则引用**：`theme_engineer_for_theme_work` · `weline_ui_theme_first` · `theme_base_components_token_only` · `theme_design_must_not_override_core_runtime_assets` · `ui_skill_requires_theme_skill`

---

## work_mode（本会）

| 项 | 声明 |
|----|------|
| **work_mode** | **本会仅讨论对齐，不落盘**（未进入 `default_theme` / `design_theme` / `theme_module_runtime` 任一施工 mode） |
| **area** | 讨论假定首期 UI 面 = `frontend`（结账支付列表 + 订单摘要）；后台激励配置若含可见样式，施工波另开时再声明 `backend` |
| **落盘范围** | **禁止**改 `Theme/view/theme`、`app/design/**`、Payment 模块 checkout 模板/私有 CSS、生成物 |
| **施工波前置** | 冻结通过且 PM 开施工波后，本席须**重新显式声明**合法 `work_mode∈{default_theme,design_theme,theme_module_runtime}` + `area` 方可改皮肤/Token；未声明禁止动手 |

---

## stance

**同意按顾问约束 + 主题 Token 门禁冻结**（非否决）。

结账「支付方式激励」展示（列表「减 X」/「可选减」、选中态、订单摘要「支付方式优惠」行）**必须消费 Theme Token + Weline UI**，**禁止** Payment（或任何施工席）另造私有调色盘 / hex·rgb 色阶 / 平行 spacing·radius·shadow 套件。

---

## 主题冻结条件（须写入 contracts / surfaces）

1. **Token 唯一真相源**  
   颜色 / 强调态 / 禁用态 / 选中态 / 间距 / 圆角 / 字阶 / 阴影：仅用 `--color-*`、`--weline-theme-*`（及既有语义矩阵）与 Weline UI 2.0 类名（`w-*` 等）。禁止在 Payment checkout 模板内新增「激励绿/促销橙」等硬编码色。

2. **禁止私有调色盘**  
   禁止为本 feature 新增模块级 palette CSS、BEM 块内联色值、或「激励专用」平行 design token 文件。品牌差异只走既有 Theme `colors/_*.css` / `variables/_*.css` 叶子（属 `default_theme`/`design_theme` 施工，且 **design 禁同 key 覆盖** `theme.css` / `theme.js`）。

3. **与现有结账壳对齐**  
   现状 `payment-methods.phtml` 已存在大量 `payment-method-card*` / `payment-methods__*` 私有类；**激励展示不得加深该私有视觉债**。施工波须优先：  
   - 复用/映射到 Weline UI 组件与主题变量；或  
   - 由主题席在声明的 `work_mode` 下把激励相关视觉收束到 Token + 公共组件，再由前端挂数据。  
   本会不指定类名补丁步骤（对齐冻结，不写生产码）。

4. **结构 vs 视觉分工**  
   - **前端**：列表项/摘要行 DOM、payload 字段绑定、切换重算交互。  
   - **原型 / UI**：构图与层次（顾问 design_brief：一行一方式、右侧「减 X」、摘要分列）。  
   - **主题**：Token 消费门禁、选中/强调态语义色、禁用态可读性；**不定运营文案**（交翻译工程师）。  
   - **部件**：若激励条走 widget/slot，交 `Team:部件开发工程师:`（本席不越权改 placement）。

5. **合规可见性（主题侧）**  
   「减 X」与摘要行须足够对比度、选中态可感知；禁止用弱对比或装饰色制造「假优惠感」。不可用 method **不得**用激励色仍暗示「可减」（与顾问 US-1.6 一致；主题用禁用态 Token，不发明警示色板）。

6. **后台激励配置页（若本 feature 含）**  
   须 `w-backend-page` / `w-card` / `w-field` / `w-button` 等；禁止裸表单私有皮肤。施工时 `area=backend` + 对应 `work_mode`。

---

## 对照规格 / 顾问（本席认可）

| 来源 | 本席态度 |
|------|----------|
| 顾问约束 1–5 + 禁 surcharge 叙事 | 同意；主题不参与金额叙事，仅保证展示态不暗示 surcharge |
| design_brief：列表「减 X」+ 摘要分列 + 即时重算 | 同意；视觉字面量必须落 Theme Token |
| US-1 展示可兑现「减 X」 | 同意；主题不审金额真伪（支付/测试席），但禁用态不得误导「可减」 |
| 可见串翻译工程师 | 同意；主题模板只用 `<lang>` / `@lang()`，不硬编码多语 |

---

## open_questions（主题 · 非阻断冻结）

| # | 问题 | 建议 |
|---|------|------|
| T1 | 激励强调态用哪条语义色（`--color-success` vs `--color-accent` 等）？ | 施工波对照 `theme-semantic-color-matrix` 选型；**禁止新造色名** |
| T2 | 私有 `payment-method-card*` 是否本 feature 顺带收束？ | 非首期必做；但**新增激励 UI 不得继续硬编码色**；收束可进独立 theme 债项 |
| T3 | 摘要「支付方式优惠」行是否复用结账已有 summary 行组件？ | 前端/架构师定 DOM；主题只要求同行 Token |

以上不阻断对齐冻结；金额/叠加/PayPal 映射属支付/架构席。

---

## 否决线（施工若触碰则本席改 stance）

- 在 Payment checkout CSS/模板内引入私有 hex/rgb 激励色板或平行 token。  
- `design_theme` 同 key 覆盖核心 `theme.css` / `theme.js`。  
- 未声明合法 `work_mode` 即改 Theme / design / 模块皮肤。  
- 用视觉手段把「其它方式」标成贬损加价（实质 surcharge 观感）。

---

## notify_pm

`result=delivered` · `notify_pm:true` · `stance=同意（Token/Weline UI 门禁）` · `work_mode=仅讨论不落盘`

**@项目经理**：本席已交付对齐表态。请将「结账激励展示须 Theme Token / Weline UI、禁私有调色盘」写入 `contracts.md` / `surfaces.md`；施工波开启前勿默认本席已选 `default_theme`/`design_theme`。本会未改生产码。

**paths_changed**

- `meetings/主题开发工程师-align.md`（本文件）
- `channel/align-freeze.md`（stance 条）
