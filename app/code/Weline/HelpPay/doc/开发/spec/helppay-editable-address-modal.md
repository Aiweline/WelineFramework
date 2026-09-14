# 规格：帮我付弹层完整地址编辑

slug: `helppay-editable-address-modal`  
模块：`Weline_HelpPay`（编排）+ `Weline_Checkout`（懒加载 HTML）+ `Weline_Shipping`（地址部件）

## work_kind

`feature`（结账/购物车/详情「找朋友代付」地址步：只读预览 → 结账同款选/改/增）

## FE / BE 范围

| 面 | 范围 |
|---|---|
| FE | 规则步后统一 `address-pick`；懒加载结账地址；确认 `resolveQuoteAddress` → `createHelpPay`；结账页 API 备份/恢复 |
| BE | 无新 API；复用 `renderDeliveryAddressWidget` / `createHelpPay` |

## EARS

1. When 用户在结账、购物车或商品详情点击「找朋友代付」并同意规则，the system shall 进入 `address-pick`，展示结账同款地址部件（选择 / 更换 / 新地址 / 编辑），不得仅展示只读文本预览。
2. When `address-pick` 首次展示且页内无弹层内地址宿主，the system shall 通过 `checkout.renderDeliveryAddressWidget` 懒加载 HTML，并调用 `shippingCheckoutAddress.mount`。
3. When 用户确认地址完整，the system shall 用 `resolveQuoteAddress`（优先弹层挂载根）映射 `shipping_address`，调用 `helpPay.createHelpPay`，再展示链接+二维码。
4. When 结账页已存在页内地址部件且弹层再次 mount，the system shall 在打开前备份 `WelineShippingCheckoutAddress`，关闭后恢复，避免结账提交读错根。
5. When 地址不完整或禁运，the system shall 留在 `address-pick` 就地提示，不得把用户赶到「请先到结账页完善」死胡同。
6. The system shall 不在商品详情页 SSR `checkout-shipping-address`。

## 用例

### UC1 结账已有选中地址

1. 结账页已选地址卡 → 找朋友代付 → 规则 → 地址步见可编辑地址卡（可更换/新地址）。
2. 确认 → `/h/` 双形态。

### UC2 购物车无页内地址

1. 购物车点找朋友代付 → 懒加载地址部件 → 填写或选择 → 出链。

### UC3 详情代付

1. PDP quiet「找朋友代付」→ 规则 → address-pick → `createHelpPay`（amount + line_summary）。

## 非目标

- 不改 Shipping 地址部件内核
- 不改 `createHelpPay` 入参字段名
- selection_share 仍无地址步

## 验收

- 契约 UT：JS 含 `ensureHelpPayAddressMounted` / `confirmHelpPayFromAddress`；无只读 `help-pay-address-preview` 主路径
- e2e：结账/PDP 代付进 address-pick 且可见地址宿主
- Browser：禁缓存见选/改/增控件
