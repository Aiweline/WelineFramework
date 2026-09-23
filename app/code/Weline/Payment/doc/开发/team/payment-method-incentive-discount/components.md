# components.md · payment-method-incentive-discount（结账「减 X」）

> Team:原型: · 对齐冻结输入。**不改生产码**；禁脱离现有结账壳另画通用 SaaS 稿。

## 1. 复用（禁止重做）

| 组件 / 面 | 归属 | 用途 |
|-----------|------|------|
| `.payment-methods` / `.payment-methods__grid` / `.payment-method-card*` | `Weline_Payment` `view/templates/Frontend/checkout/payment-methods.phtml` | 支付方式列表壳；激励提示挂在**既有卡片**内，不另开第二套选卡 UI |
| `.payment-method-card__badge` / `__reason` | 同上 | Badge 槽复用为「可选减 / 减 X」；不可用态仍走 `__reason`，禁灰卡仍显示可点「可减」 |
| `Weline_Payment::frontend::checkout::payment-methods-*` hooks | Payment | 列表前后扩展点；优先槽内增量，不平行新区块 |
| `.weline-checkout__totals` + `role="listitem"` 行模式 | `Weline_Checkout` `checkout/index.phtml` | 摘要金额表；激励行**平行**现有 `data-checkout-discount-row` / `data-checkout-cod-fee-row` |
| `data-checkout-cod-fee-row` 交互范式 | Checkout FE | **反向参考**：选方式 → 摘要行显隐 + 应付重算；激励=减免，COD=加价，**不得**用 surcharge 文案 |
| `data-checkout-discount-row` | Checkout FE | 保留给券/购物车等业务优惠聚合（或既有「优惠」语义）；**禁止**把支付方式激励并进同一模糊「优惠」行 |
| 结账币种 / Money 展示 | Checkout / I18n | 「减 ¥X」跟结账币格式化；禁硬编码货币符号组件 |

## 2. 本 feature 增量（施工期由前端/主题落地；本席仅钉契约）

| 增量 | 建议 data / BEM | 说明 |
|------|-----------------|------|
| 卡片激励提示 | `payment-method-card__incentive`（或复用 badge class + `data-incentive-savings`） | 右侧/标题行尾：**未选**=「可选减 ¥X」；**已选**=加粗「减 ¥X」；百分比=「减 X%（约 ¥Y）」 |
| 摘要激励行 | `data-checkout-payment-incentive-row` + `data-payment-incentive-amount` | 独立一行标签「支付方式优惠」；金额负向展示；无激励时 `hidden` |
| 列表 payload | 正式名对齐冻结（候选 `savings_minor` + `incentive_label`） | 与可兑现扣减额恒等；禁「最高减」字段 |

## 3. 禁止

- 新造「支付优惠中心」卡片墙、紫白 SaaS dashboard、脱离 `payment-method-card` 的第二列表。
- 把其它方式标成「原价/多付」制造实质 surcharge 对比。
- 购物车/PDP 首期强制挂「再减」条（顾问：结账列表为主；后续可选）。
- 合并摘要「优惠」行掩盖券 vs 支付方式激励。

## 4. 主题 / Token

视觉跟现有结账与 Payment 卡片（边框/选中绿系已存在于模板内联样式）；色板与 token 收口交 **Team:主题开发工程师:** / **Team:UI:**，本席不定色板。
