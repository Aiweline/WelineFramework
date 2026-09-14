# 规格：商品详情「找朋友代付」弱入口

slug: `helppay-product-help-pay`  
模块：`Weline_HelpPay`（编排 + Slot widget）+ `Weline_Product`（仅声明购买操作槽）+ `Weline_Checkout`（懒加载地址 HTML）

## work_kind

`feature`（功能现状已标注「PDP 帮我付第三 placement」；本期落地）

## FE / BE 范围

| 面 | 范围 |
|---|---|
| FE | quiet 文字链 CTA、弹层 rules→address-pick→出链双形态、tob/缺货禁用、e2e |
| BE | 复用既有 `createHelpPay`；无新 API；Setup 种子注入 product 布局 |
| Product | 仅将 `product-purchase-actions` max 扩到 6；**禁止**模板写死 HelpPay |

## 需求纠偏

- 入口对齐购物车/结账的 **quiet 文字链**「找朋友代付」，**不要**做成「快捷购买 / 分享给朋友」那种 secondary 按钮。
- PDP 无结账地址 SSR：地址步复用快捷购买的 `address-pick` 懒加载，不得把用户赶到「请先到结账页」。

## EARS

1. When 用户在商品页（零售可售）看到购买区，the system shall 在主购买按钮下方展示弱入口文字链「找朋友代付」（`w-helppay-cta--quiet`），且不得使用主/次按钮样式抢视觉。
2. When 用户点击该入口，the system shall 先展示帮我付规则步（规则链接 + 已阅读勾选），再进入 `address-pick` 懒加载结账同款地址。
3. When 用户确认完整收货地址，the system shall 调用 `helpPay.createHelpPay`（`cart_type=toc`，带 `amount_minor` / `line_summary` / `shipping_address` / `rules_accepted` / `address_confirmed`），并展示链接+二维码双形态。
4. When 售卖模式为批发（tob）或当前规格暂不可售，the system shall 禁用该入口。
5. If 地址组件不可用，the system shall 降级可读错误，不得白屏或空白弹层。
6. The system shall 通过 HelpPay widget `default_injections` 注入 `product-purchase-actions`；Product 模板禁止写死 HelpPay 按钮。

## 用例

### UC1 可售零售 → 出链

1. 打开 PDP，主 CTA 下方见弱文字链「找朋友代付」。
2. 点击 → 规则步 → 下一步 → 地址选择/填写 → 确认并生成代付链接。
3. 弹层展示 `/h/` 链接与二维码，可复制。

### UC2 缺货 / 批发

1. 缺货或切到 tob：入口 disabled，不可打开出链流。

### UC3 与快捷购买区分

1. 「快捷购买」仍为 secondary 按钮、走 `createQuickPay`。
2. 「找朋友代付」为 quiet 链、走 `createHelpPay`（代付人付款，订单归发起人）。

## 非目标

- 不在 PDP SSR `checkout-shipping-address`
- 不改购物车/结账既有 quiet CTA 契约（地址步已与结账同款可编辑，见 `helppay-editable-address-modal.md`）
- 不做真实冻结未付单对接（仍见功能现状后续项）

## 验收

- 契约 UT：widget quiet 样式、Upgrade 种子、JS 含 product placement 流
- e2e：PDP 可见 quiet CTA；点击进规则步；可售时进 address-pick
- Browser WB-OP：禁缓存打开 PDP，目视入口弱于主购买按钮
