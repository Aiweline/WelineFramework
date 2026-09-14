# 规格：代付页付款账单地址（结账同款）

slug: `helppay-payer-billing-address`  
模块：`Weline_HelpPay`（代付页）+ `Weline_Shipping`（结账地址部件）+ `Weline_Checkout`（可选上下文）

## work_kind

`feature`（代付人卡支付账单地址：单行输入 → 结账同款选/改/增）

## plan_skip

复用已落地的 `checkout-shipping-address` + HelpPay 弹层地址挂载模式；无新架构面，跳过宿主 Plan Mode。

## FE / BE 范围

| 面 | 范围 |
|---|---|
| FE | `/h/` 支付区 SSR/挂载结账地址部件；标题「付款账单地址」；隐藏「账单同收货」嵌套区；`session-isolation`；确认付款前 `resolveQuoteAddress` 校验 |
| BE | Payer 控制器渲染部件 HTML；**不**写回 payment_link shipping；billing 仅前端收集（支付网关闭环后续） |

## EARS

1. When 代付人打开有效 `/h/{token}`，the system shall 在「付款账单地址」展示结账同款地址部件（选择 / 更换 / 新地址 / 编辑），不得仅展示单行 `billing_line1`。
2. When 部件挂载，the system shall 设置 `data-session-isolation=1`，选择/保存仅作用于本页账单，不得改写发起人收货或万能结账配送会话。
3. When 部件渲染，the system shall 隐藏 `data-billing-section`（结账「账单同收货」），避免与代付账单语义冲突。
4. When 代付人点击「确认付款」且账单地址不完整，the system shall 就地提示并阻止进入支付；完整时将结构化账单写入本页状态（不写回 shipping）。
5. The system shall 继续 `shipping_redacted`：本页不展示发起人收货详情。

## 用例

### UC1 访客代付人填新账单地址

1. 打开 `/h/` → 支付区见地址表单（new）→ 填写 → 保存此地址 → 确认付款通过校验。

### UC2 登录代付人选用已存地址

1. 登录 B 打开 `/h/` → 见 B 的已存地址卡 → 选择/更换/新地址 → 确认付款。

## 非目标

- 本期不做真实支付网关代付闭环
- 不改 Shipping 地址部件内核校验逻辑（仅消费 `title` / `saved_heading`）
- 不展示发起人 shipping

## 验收

- 契约 UT：`payer.phtml` 含 `data-shipping-checkout-address` / `data-session-isolation`；无 `billing_line1` 主路径
- e2e 或 Browser：`/h/` 可见选/改/增控件；`data-billing-section` 不可见
- 汇审 + 交付地址
