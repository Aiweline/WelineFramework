# 规格：快捷购买弹窗内复用结账地址（懒加载）

slug: `helppay-quick-pay-address-modal`  
模块：`Weline_HelpPay`（编排）+ `Weline_Checkout`（懒加载 HTML）+ `Weline_Shipping`（地址部件）

## EARS

1. When 用户在商品页点击「快捷购买」且可售零售态，the system shall 打开 HelpPay 弹窗的 `address-pick` 步，而不是仅展示「无法完成操作 + 关闭」。
2. When `address-pick` 首次展示，the system shall 通过 `checkout.renderDeliveryAddressWidget` 按需拉取结账同款地址部件 HTML，并不得在商品页 SSR 该部件。
3. When 地址片段注入后，the system shall 调用 `shippingCheckoutAddress.mount` 绑定交互（选择 / 更换 / 新地址 / theme:address）。
4. When 用户点击「下一步」且地址完整且非禁运，the system shall 进入物流步（见 `helppay-quick-pay-popup-checkout.md`），**不得**立刻 `location.assign(/q/)`，**不得**展示分享链接/二维码主界面。
5. When 地址不完整或禁运，the system shall 留在 `address-pick` 就地提示，不得把用户赶到「请先到结账页完善」死胡同。
6. If Shipping 部件不可用，the system shall 降级为可读错误，不得白屏。
7. When 弹窗挂载地址部件，the system shall 使用 `session_isolation`，选择/编辑地址不得改写万能结账配送上下文。

## 用例

### UC1 无地址首次快捷购买

1. 用户打开 PDP，点击快捷购买。
2. 弹窗展示加载态 → 结账地址表单（new）。
3. 用户填写并确认 → 进入 `/q/` 付款页。

### UC2 已有会话地址

1. 用户已在结账/顶栏选过地址。
2. 点击快捷购买 → collapsed 选中卡，可「更换地址」或「使用新地址」。
3. 确认后进入物流步；弹窗 `session_isolation`，不得改写万能结账配送会话。

### UC3 更换确认

1. 弹窗内点「更换地址」展开列表，选另一张卡或新地址。
2. 确认进入付款；配送会话与 Header/结账同源。

## 非目标

- 不在 PDP 文档树 SSR `checkout-shipping-address`
- 不改 `createQuickPay` 入参契约字段名

## 关联

- 结账主路径纠偏：`helppay-quick-pay-inline-checkout.md`
- 帮我付（结账/购物车/详情）地址步已统一为可编辑 `address-pick`，见 `helppay-editable-address-modal.md`
