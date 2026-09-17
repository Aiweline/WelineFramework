# 配送弹窗快速添加地址

由 `Weline_Shipping` 实现 Header Checkout 配送弹窗中的「快速添加地址」表单。

未启用 Shipping 模块时，该 Hook 无内容，弹窗仅保留国家选择与已有地址列表。

主题编辑器 / theme-preview 与店面走同一路径：始终渲染本 Hook（`preview_storefront_delivery_parity`）。禁止仅为 `editor_mode` 跳过。

表单要求：

- `captcha="required"`（intent：`checkout.save_delivery_address`）
- 国家由宿主弹窗顶部选择并隐藏同步；快速新增仅渲染省/市/区级联（`<w:theme:address levels="province,city,district">`；勿用 `for`，级别分隔用逗号）
- 地区权威数据为 `Shipping/data/address-catalog/{CC}/*.tsv.gz`（经 `shipping:addresscatalog:import` 入 DB）。未收录国（无 catalog 目录）省市区可手填，不得回落成中国；勿再依赖已删除的 `data/regions/*.json`
- 自动定位成功后由宿主调用 `WelineThemeAddress.applyValues` 自动选中级联项
