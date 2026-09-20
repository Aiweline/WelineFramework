---
status: ready-for-plan
work_kind: feature
feature_slug: pdp-purchase-widgets-must-exist
module: Weline_Theme
fe_be_scope: both
related_req: REQ-THEME-0036
related_team: required-default-injection
updated: 2026-09-20
---

# PDP 购买部件必须存在（加购 / 结账 / 快捷结账）

## 归属说明

| 层 | 模块 | 职责 |
|----|------|------|
| **契约与保证（本规格主归属）** | `Weline_Theme` | required `default_injections` 在店面已发布渲染中必须出现；版本级卸载决策；主题切换 / 编译 / 发布后仍在；对齐 `REQ-THEME-0036` |
| 槽声明 | `Weline_Product` | `product-info` 嵌套槽 `product-purchase-actions`、`product-express-payment` |
| 加购按钮 | `Weline_Cart` | `product-add-to-cart`，`required:true` → `product-purchase-actions` |
| 立即结账 | `Weline_Checkout` | `product-buy-now`，`required:true` → `product-purchase-actions` |
| 快捷结账 | `Weline_Payment` | `product-express-payment`，`required:true` → `product-express-payment` |

选 Theme 而非 Product/Cart：丢失根因是 **布局实体 / required 注入 / 版本卸载** 架构面，不是单个按钮业务文案。Product 只声明空槽；按钮由默认应用注入。

## 澄清记录

| # | 问题 | 结论（本回合据用户原话 + 代码契约自洽） |
|---|------|----------------------------------------|
| 1 | 「完全没有按钮」指什么？ | 店面 PDP 购买区 **看不到** `product-add-to-cart` / `product-buy-now` / `product-express-payment` 部件根（或等价可点 CTA）；不是「钮灰掉 / 文案变暂不可售」 |
| 2 | 「除非人为卸载」如何认定？ | 仅当前已发布 `ThemeLayoutVersion` 上存在 **版本键卸载**（`user_deleted@{versionId}`）。无版本后缀的旧 `user_deleted`、漏发布、漏页型、主题切换未带注入、编译丢节点 —— **不算**人为卸载 |
| 3 | 与业务开关的边界？ | 见下方「需求纠偏」；`quote_only` / 货币不可用 / 不可售 / 支付方式未启用等 **禁止**当成「部件丢失」 |
| 4 | 主题切换 / 编译后？ | 新装或切换到带 `product` 布局的前台主题后，required 购买部件仍须在对应槽；`php bin/w` 主题/静态编译不得抹掉该保证 |
| 5 | 与已有停工项关系？ | 承接 `doc/开发/team/required-default-injection/`（立项会曾停工）。用户本诉求是 **PDP 购买三件套** 的可验收切片；架构解须与 `REQ-THEME-0036` 一致，并覆盖 **容器部件嵌套槽**（`product-info` 内槽） |

## 需求纠偏

| 用户字面 | 更合理表述 | 禁止混为一谈 |
|----------|------------|--------------|
| 「商品详情完全没有加购/结账/快捷结账按钮」 | **注入 / 布局实体 / 发布 / 嵌套槽填充** 失败，导致 required 部件未进入渲染树（或仅剩模板占位） | 业务关开关：询价-only 故意不渲染加购/立即结账；当前货币不可用；offer 不可售导致 **禁用**；快捷支付无可用 method 时方法列表空但仍可有壳 |
| 「像架构问题导致部件跑着跑着丢失」 | 非人为卸载场景下，required `default_injections` 在店面消失 = **缺陷**（发布烘焙漏页型、漏 `is_active_frontend`、嵌套槽 accept 误判、overlay 未覆盖容器槽、编译/主题切换丢节点等） | 运营在主题编辑器对本版本 **显式卸载**；模块被禁用/卸载；站级关闭整站购物 |
| 「应由默认应用注入，除非人为卸载否则必须存在」 | 对齐 `REQ-THEME-0036`：店面已发布渲染保证出现；**不写布局**；**不**在编辑器打开/刷新时静默 `applyMissing`（`REQ-THEME-0012` 对草稿仍有效） | 用 Product `purchase-failsafe` 或 `quickAdd` 直 `fetch` 模板长期顶替架构保证（failsafe 仅可作过渡证据，不得当终态方案） |

**纠偏结论**：本需求是 **Theme required 注入架构在 PDP 购买槽上的强制保证**，不是「给按钮加业务开关」或「再写一套 Product 内嵌 CTA」。

## work_kind / fe_be_scope

| 字段 | 值 | 说明 |
|------|-----|------|
| `work_kind` | `feature` | 改变店面可观察行为与注入契约；须 EARS+UC、计划、e2e |
| `fe_be_scope` | `both` | **BE**：Theme 布局实体填充 / required overlay / 版本卸载决策 / 发布与主题激活路径；Cart/Checkout/Payment 注册与契约。**FE**：PDP 槽内可见 CTA；主题编辑器卸载/恢复；主题切换后店面 Browser/e2e |

## 目标 / 非目标

- **目标**：可售零售 PDP（非人为卸载）上，加购、立即结账、快捷结账 **部件必须存在**；新装默认有；主题切换与编译后仍在；丢失可判定为缺陷并有契约/e2e 锁死。
- **非目标**：改写加购/结账业务 API；强制询价商品显示加购；在 Theme 布局内嵌 Cart/Checkout/Payment 模板；把 `required=false` 推荐件抬成必装；编辑器草稿空槽刷新自动回填（仍守 `REQ-THEME-0012`）。

## 角色

- 店面访客：在 PDP 完成加购 / 立即结账 / 快捷支付入口可见。
- 主题编辑者：可对本布局版本 **显式卸载** 购买部件；卸载后可不出现；可再从应用 Tab / 插槽初始化恢复。
- 站点运维 / 开发者：新装、切主题、编译后仍期望默认购买 CTA 在。

## 用户故事

1. 作为访客，我希望打开可售商品详情时默认看到加购与结账入口，以便完成购买。
2. 作为访客，我希望在支付能力可用时看到快捷结账入口，以便少步支付。
3. 作为主题编辑者，我希望只有在我主动卸载后购买钮才消失，以免主题切换或发布后「跑丢」。
4. 作为开发者，我希望非卸载丢失被测出并视为缺陷，以便架构保证可回归。

## 隐形需求摘要

- 已有：`product-add-to-cart` / `product-buy-now` / `product-express-payment` 均声明 `required:true` `default_injections`。
- 已有：`REQ-THEME-0036` + `RequiredDefaultInjectionContract` / `RequiredDefaultInjectionStorefrontOverlay` / `ThemeLayoutEntitySlotFiller`。
- 已有：Product `product-info` 嵌套槽；历史曾出现嵌套槽 `layout_slot_missing` 误判（须覆盖容器部件槽）。
- 已有：Product failsafe CTA（`data-purchase-failsafe`）——架构达标后应可收敛，不得依赖其掩盖丢失。
- 相关停工：`Theme/doc/开发/team/required-default-injection/`（甲/乙/丙）；本切片以用户确认的「未卸载则店面必现」为验收前提，架构会须与 0036 收窄 0012 店面侧一致。

## EARS（验收）

1. WHEN 站点新装且 Cart/Checkout/Payment 模块启用、前台主题已激活，且商家未在当前已发布产品布局版本卸载购买部件，系统 SHALL 在可售零售 PDP 的 `product-purchase-actions` 槽渲染 `product-add-to-cart` 与 `product-buy-now` 部件根（含 `data-testid="product-add-to-cart"` / `product-buy-now"`）。
2. WHEN 同上前置且 Payment 快捷能力对当前站可用，系统 SHALL 在 `product-express-payment` 槽渲染 `product-express-payment` 部件根（含 `data-testid="product-express-payment"`）。
3. WHEN 主题编辑者在当前已发布 `ThemeLayoutVersion` 上对上述某 required 部件执行认定卸载（写入 `user_deleted@{versionId}`），系统 SHALL 允许该槽在店面不再渲染该部件。
4. IF 当前版本 **无** 版本键卸载记录，且发布实体缺少对应 required 节点（或仅模板占位），THEN 系统 SHALL 仍在店面该槽渲染该部件（`REQ-THEME-0036`），且此情形 SHALL 被视为缺陷若最终仍缺失。
5. WHEN 切换前台主题或执行主题/静态相关编译后再次打开可售零售 PDP，系统 SHALL 在未卸载前提下仍满足 EARS-1/2（购买三件套不因切换/编译丢失）。
6. IF 商品为 `quote_only` 或当前货币不可用，THEN 系统 MAY 不渲染加购/立即结账（或仅展示业务提示）；此 SHALL **不**记为「购买部件架构丢失」。
7. WHILE 编辑器草稿被商家清空且未点插槽初始化，系统 SHALL **不**因打开/刷新编辑器静默写回布局（守 `REQ-THEME-0012`）；本条与店面 0036 保证分立。

## 用例

### UC1 新装默认有加购（主成功）

1. 绿field 或等价新装：启用 Product/Cart/Checkout，激活前台主题。
2. 打开一件可售零售商品详情（非询价-only）。
3. **期望**：可见加购按钮（`data-testid="product-add-to-cart"`）；点击可走加购主路径（本 UC 至少断言存在）。

### UC2 新装默认有立即结账

1. 同 UC1 前置。
2. **期望**：可见立即结账（`data-testid="product-buy-now"`）。

### UC3 新装默认有快捷结账（能力可用时）

1. 同 UC1，且站内已配置可用快捷支付方式。
2. **期望**：可见快捷结账区（`data-testid="product-express-payment"`）。

### UC4 人为卸载后可无

1. 在主题编辑器产品布局当前版本卸载 `product-add-to-cart`（或 buy-now / express）。
2. 发布该版本。
3. 打开同一类 PDP。
4. **期望**：被卸载部件可不出现；其余未卸载 required 件仍在。

### UC5 非卸载场景丢失视为缺陷

1. 制造或复现：发布实体缺购买节点，但 **无** `user_deleted@{versionId}`（例如漏发布、漏页型、仅草稿有节点）。
2. 打开可售零售 PDP。
3. **期望**：店面仍出现 required 购买部件；若仍完全缺失，判定 FAIL（架构缺陷），不得用「业务关了」解释。

### UC6 主题切换 / 编译后仍在

1. 切换到另一已激活前台主题（或同主题重新编译/发布产品布局），且未做版本卸载。
2. 打开可售零售 PDP。
3. **期望**：加购 / 立即结账 /（能力可用时）快捷结账仍在。

### UC7 业务开关 ≠ 丢失（对照）

1. 打开 `quote_only` 商品详情。
2. **期望**：可不显示加购/立即结账；规格验收记「业务预期」，不记 UC5 缺陷。

## 方案要点（供架构师，非施工）

- 主机制：Theme `RequiredDefaultInjection*` 店面 overlay，覆盖 **product 布局** 且穿透 `product-info` **嵌套槽**。
- 部件仍由 Cart/Checkout/Payment 拥有；Product 只保留空槽 + 契约，禁止布局内嵌三模块模板为终态。
- 卸载键必须版本隔离；与 `required-default-injection` 立项选项乙+丙对齐用户诉求。
- 验收：契约 UT（声明 + filler/overlay）+ PDP Browser/e2e（UC1–UC6）；UC7 作负例对照。

## 验收绑定

| 类型 | 内容 |
|------|------|
| unit | Theme required overlay / 嵌套槽；Cart/Checkout/Payment `default_injections.required=true` 契约 |
| e2e / WB-OP | 可售 PDP 三 testid 存在；卸载后对应缺失；切主题后仍在 |
| 非目标证据 | 不得以 failsafe 单独算 PASS 若槽内无正式注入部件 |

## 实现状态

- status: `ready-for-plan`（需求分析落盘；待架构师对照 `REQ-THEME-0036` 与嵌套槽覆盖面出方案）
