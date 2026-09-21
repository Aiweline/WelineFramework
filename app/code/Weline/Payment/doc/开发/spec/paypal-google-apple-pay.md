---
status: ready-for-plan
work_kind: feature
feature_slug: paypal-google-apple-pay
module: Weline_Payment
fe_be_scope: both
plan_complexity: complex
updated: 2026-09-20
---

# 规格：PayPal 后台默认开启且可关闭 Google Pay / Apple Pay

## 元信息

| 字段 | 值 |
|------|-----|
| `work_kind` | `feature` |
| `fe_be_scope` | `both`（前后端都有） |
| 归属模块 | `Weline_Payment` |
| 相邻模块 | `Weline_Checkout`（只消费壳能力/事件，禁止内嵌 SDK） |
| 团队目录 | `doc/开发/team/paypal-google-apple-pay/` |

### 前后端范围（`requirement_fe_be_scope_analysis`）

| 侧 | 是否在范围 | 要点 |
|----|------------|------|
| **backend** | 是 | PayPal 方式配置页新增 Google Pay / Apple Pay 开关；默认 `true`；受 PayPal 总开关约束；配置读写与契约断言 |
| **frontend** | 是 | 前台按开关决定钱包按钮渲染或 `disable-funding`；PayPal 总关或子开关关时二者不可用；Checkout 不内嵌 SDK |

## 背景与现状（领域探查结论）

- 当前 PayPal 主链为 **Orders v2 + approve redirect**，**无 JS SDK**。
- Checkout 文档硬约束：**禁止在 Checkout 模块内嵌 PayPal / 支付商 JS SDK**（槽位 + Payment 部件承接呈现）。
- 官方 Google Pay / Apple Pay（PayPal Expanded Checkout / Buttons funding）通常依赖 **PayPal JS SDK**（或等价 Expanded Checkout 前端集成），与纯 redirect 主链不同。
- 既有方式级开关先例：`payment/method/paypal/enabled`、`payment/method/paypal/express_enabled`（默认开、可关）。

## 需求纠偏（`requirement_framework_scrutiny`）

| 字面/易偏理解 | 纠偏结论 |
|---------------|----------|
| 在 Checkout 里加 Google/Apple Pay 按钮或挂 SDK | **否**。开关、配置、SDK/按钮渲染与 funding 控制均归 **`Weline_Payment`**；Checkout 只声明槽/消费壳 `list*` 能力与支付事件，**不得**内嵌 SDK |
| 仅改 redirect 主链即可「默认开 Google/Apple Pay」 | **否**。官方钱包按钮能力需 JS SDK / Expanded Checkout 路径；架构须在 Payment 内引入可控前端集成，且与现有 Orders v2 闭环兼容 |
| 默认关、运营再开 | **否**。**默认 `true`（开启）**，后台可关闭 |
| Checkout 与 Payment 各维护一套开关 | **否**。单一配置源在 Payment；Checkout 不平行造配置 |

## 澄清记录

| # | 问题 | 结论（本回合已确认） |
|---|------|----------------------|
| 1 | Google/Apple Pay 开关放哪？ | Payment 模块 PayPal 配置；Checkout 不持有开关 |
| 2 | 默认值？ | 默认开启（`true`），可关闭 |
| 3 | PayPal 总开关关时？ | Google Pay 与 Apple Pay **均不可用**（子开关无效） |
| 4 | 关子开关后前台表现？ | `disable-funding` 对应 funding，或按钮不渲染；二者等价于「买家不可用」 |
| 5 | Checkout 是否嵌 SDK？ | **禁止**；由 Payment 壳/部件提供 |
| 6 | 本期是否重做整站支付协议？ | **否**；在现有壳 + Orders v2 能力上扩展钱包 funding |

## 用户故事

1. **作为** 站点运营，**我希望** 在后台 PayPal 配置中看到 Google Pay / Apple Pay 开关且默认为开，**以便** 开箱即用并按合规/地区需要随时关闭。
2. **作为** 买家，**我希望** 仅在运营开启且 PayPal 可用时看到/使用对应钱包支付，**以便** 不出现关了仍可点的死按钮。
3. **作为** 框架维护者，**我希望** Checkout 继续只消费 Payment 壳能力，**以便** 不违反「Checkout 禁止内嵌 SDK」解耦边界。

## 隐形需求（`implicit_requirements`）

- 复用现有 PayPal 后台配置模板与 SystemConfig 字段模式（与 `express_enabled` 同类）。
- 与壳级快捷支付 / `listExpressMethods` / express 部件能力收窄模式对齐（关则能力列表不含对应项）。
- i18n：新增后台 label/description 须进模块 `zh_Hans_CN` + `en_US` CSV（实现阶段）。
- 设备/浏览器不支持某钱包时：前端不渲染或官方 SDK 自然隐藏，不得伪装「已开启却永远失败」的假按钮（实现阶段由架构细化）。
- Sandbox：能测的路径用 sandbox 验；设备受限（真机 Apple Pay 等）以契约 UT + 可模拟路径为主，并在验收记录注明限制。

## 非目标

- 不在 `Weline_Checkout` 内嵌 PayPal JS SDK 或平行支付协议。
- 不本期重做 Stripe / Braintree 等其它网关的 Google/Apple Pay。
- 不强制所有地区/设备都必须展示按钮（受官方 SDK、浏览器、商户账号能力约束）。
- 不改变 PayPal 总开关语义以外的其它支付方式配置。

## EARS 验收标准

1. **WHEN** 运营打开后台 PayPal 支付方式配置页，**THEN** 系统 **SHALL** 展示 Google Pay 与 Apple Pay 独立开关，且二者默认值为开启（`true` / 等价布尔真）。
2. **WHEN** Google Pay（或 Apple Pay）开关被设为关闭并保存，**THEN** 系统 **SHALL** 使前台对应钱包不可用：通过 PayPal JS SDK `disable-funding`（或等价）屏蔽对应 funding，**或** 不渲染该按钮；买家无法发起该钱包支付。
3. **IF** PayPal 总开关（`payment/method/paypal/enabled` 或等价）为关，**THEN** 系统 **SHALL** 使 Google Pay 与 Apple Pay **均不可用**，即使其子开关仍为开。
4. **WHILE** 渲染结账/快捷支付中的 PayPal 钱包能力，**THEN** 系统 **SHALL** 由 `Weline_Payment` 提供配置解析与前端呈现；Checkout **SHALL NOT** 内嵌 SDK 或私有开关。
5. **WHEN** 两子开关均为默认开且 PayPal 总开关开、凭据可用，**THEN** 系统 **SHALL** 在支持的集成面上允许对应 funding/按钮出现（受设备与商户能力限制时允许官方自然隐藏，但不得因本站默认关而隐藏）。

## 用例（UC）

### UC-1 默认开启可见可用（主成功）

| 字段 | 内容 |
|------|------|
| 角色 | 运营 + 买家 |
| 前置 | PayPal 总开关开；凭据可用；Google/Apple Pay 保持默认（未关） |
| 步骤 | 1. 后台打开 PayPal 配置 → 见两开关默认真<br>2. 前台进入可用结账/快捷支付面（sandbox）<br>3. 在支持环境下观察 Google Pay / Apple Pay funding 或按钮 |
| 期望 | 后台默认真；前台未因本站配置而禁用二者 |
| 映射验收 | Browser WB-OP（后台）；契约 UT（default）；sandbox 能测路径 |

### UC-2 关子开关后不可用

| 字段 | 内容 |
|------|------|
| 角色 | 运营 + 买家 |
| 前置 | PayPal 总开关开 |
| 步骤 | 1. 后台关闭 Google Pay（或 Apple Pay）并保存<br>2. 前台刷新结账/快捷支付面（禁缓存） |
| 期望 | 对应钱包：`disable-funding` 生效或按钮不渲染；不可发起该钱包支付 |
| 备选 | 只关其一：另一钱包仍可按默认/配置可用 |
| 映射验收 | 契约 UT（config→funding 映射）；Browser/sandbox 能测路径 |

### UC-3 PayPal 总开关关时二者不可用

| 字段 | 内容 |
|------|------|
| 角色 | 运营 + 买家 |
| 前置 | 子开关可为开 |
| 步骤 | 1. 关闭 PayPal 总开关并保存<br>2. 前台进入结账/支付方式区 |
| 期望 | Google Pay 与 Apple Pay 均不可用；不得因「子开关仍开」而露出 |
| 映射验收 | 契约 UT（总开关门禁）；Browser 断言无对应按钮/funding |

### UC-4 模块边界（回归）

| 字段 | 内容 |
|------|------|
| 角色 | 开发/测试 |
| 步骤 | 静态/契约检查 Checkout 模板与结账页脚本 |
| 期望 | Checkout 无 PayPal JS SDK 脚本标签/内嵌初始化；Payment 部件或壳资源承载 |
| 映射验收 | 契约 UT / grep 契约 |

## 验收计划（本规格级意图）

| 类型 | 意图 |
|------|------|
| 后台 WB-OP | 配置页可见两开关，默认真；可关可开并保存 |
| 前台 | 关后 `disable-funding` 或按钮不渲染；总开关关时二者皆无 |
| 契约 UT | 默认值、总开关门禁、Checkout 无 SDK、配置键→funding 映射 |
| Sandbox | 能测的 sandbox 路径测；真机/地区受限注明 N/A 理由（≥24 字） |
| e2e | feature 非简单：计划阶段纳入 chapter + plan-suite（架构定路径后） |

## 就绪检查

- [x] `status` = `ready-for-plan`
- [x] ≥1 用户故事 + ≥2 条 EARS
- [x] ≥1 用例（含 UC-1/2/3 必测边界）
- [x] 非目标明确
- [x] 已点名 e2e / WB-OP / 契约 UT 验收意图
- [x] 未把具体类名/补丁步骤写成强制 how（留给架构）

## 下一步（给项目经理）

1. 启用宿主 **Plan Mode**，请 **架构师** 做扩展点选型与方案（Payment 内 JS SDK / Expanded Checkout 落点、与 Orders v2 redirect 并存策略、配置键命名）。
2. 本规格阶段 **不改业务 PHP/JS**。
