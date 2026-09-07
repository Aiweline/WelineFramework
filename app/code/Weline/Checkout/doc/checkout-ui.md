# Checkout UI（P2E-003）

## 规则

- 商品事实只来自 `w_query('cart', 'getCart')`，由 `CheckoutPageViewModel` 把受信任 minor-unit 行归一化为展示数据；Controller 不接收浏览器商品名、价格或数量
- 首屏商品行 DOM **只**由服务端模板生成：`CheckoutPageViewModel` → `view/frontend/checkout/partials/items.phtml` → Hook `frontend::partials::checkout::cart-items`
- 商品行左侧缩略图由 `CheckoutHtmlRenderer::renderItems` 输出（`image`/`image_src`，无图走 `StorefrontImagePlaceholder`）；异步 `items_html` 同源
- 规格行以横向芯片展示 `swatch_image`/`swatch_color` + 标签·值（窄屏 wrap）
- 异步刷新商品行与方式选项仍由 `CheckoutHtmlRenderer` 生成，浏览器只注入 `items_html` / `*_methods_html`
- 浏览器 JS **只**做交互：选配送/支付、提交、`textContent` 更新合计；通过 `items_html` / `*_methods_html` 注入服务端片段
- 业务网络 **只**走 `Weline.Api.resource('checkout')`（禁止 native fetch/XHR/axios）
- `submitV2` 的 `payment.outcome` 是支付 UI 的唯一状态事实：`paid` 进入成功页，
  `pending` 执行受控 Provider 跳转或显示待处理，`failed` 显示同订单恢复操作
- 恢复状态保存在 URL fragment/浏览器会话；支付重试调用
  `Weline.Api.resource('checkout').resumePaymentV2(...)`，不得重新 submit 订单
- 成功页 URL 始终附带 `checkout_token`；服务端校验已提交 Session、订单归属和
  短期能力凭证。WLS 跨 Worker 跳转尚未恢复身份时允许凭证完成成功页首屏，
  已存在登录身份时仍拒绝其他 customer 的订单

## 视觉（布局 Shopify / 色板 Amazon）

- 布局：保留 Shopify 风格两栏（主表单 + sticky 订单摘要）、panel 卡片与 860px 以下单列响应式
- 色板：`.weline-checkout` 作用域内定义 `--checkout-text` `#0f1111`、`--checkout-text-secondary` `#565959`、`--checkout-link` `#007185`、`--checkout-border` `#ddd`、`--checkout-cta-bg` `#ffd814`（深色字）
- 优惠券：`checkout-summary-discount` 槽 + `Weline_Marketing::checkout-coupon` 部件（默认 Amazon 灰底应用按钮）；禁止 Hook 直出模板
- 订单留言：`checkout-summary-note` 槽 + `Weline_Order::order-notice` 部件；与迷你购物车/购物车页共享留言会话
- 优惠与留言：摘要侧以 `mini-cart-drawer__extras` + `miniCartExtras` Tab 切换（对齐迷你购物车）；应付合计 = 商品小计 + 运费 − `discount_preview`
- 收货地址：`checkout-shipping-address` 槽 + `Weline_Shipping::checkout-shipping-address` 部件（`<w:theme:address>` 级联 + 已存地址选择）；Checkout 禁止裸拼国家/省/市 input
- 收货交互：有已存地址默认收起；radio 单选地址卡；可编辑 / 使用新地址；账单默认与收货相同，可展开修改（Shopify 分段 + Amazon 卡片）
- 模块页槽发现：`checkout-storefront-slots` 容器声明结账模块页嵌套槽，供 Theme `findSlot` / required `default_injections` 安装（对齐 Product `product-info`）
- 快捷支付：`checkout-express-payment` 槽（左侧主栏、收货信息上方）+ `Weline_Payment::checkout-express-payment` 部件（`enabled` 开关；Shopify Express 布局 + Amazon 色板）；禁止 Checkout 内嵌 PayPal
- 优先复用 Theme Amazon token（`--color-text-*` / `--color-link`）；CTA 黄按钮写死 Amazon 黄，避免站点品牌主色（如品红）污染结账主按钮
- 入口页：`/checkout`
- 页头副标题：控制器 `checkout_page_subtitle`（默认面向顾客的引导文案；赋空字符串可隐藏）

## 入口

| 表面 | 路径 |
|---|---|
| 商城结账页 | `/checkout` |
| 结账页 | `/weline_checkout/frontend/checkout`（layout=`checkout`） |
| 首屏数据 | `CheckoutPageViewModel::currentCart()` |
| 模板 | `Checkout/view/frontend/checkout/index.phtml` |
| 商品局部模板 | `Checkout/view/frontend/checkout/partials/items.phtml` |
| Theme | `Theme/.../layouts/checkout/default.phtml`、`one-page.phtml` |
| API | `w_query('checkout','getData'|'freezeQuote'|'submitV2'|'resumePaymentV2')` → 含 `items_html` 与结构化 `payment` |

## 验证

```bash
php bin/w phpunit:run --module=Weline_Checkout
php bin/w frontend:check-section-code
php bin/w e2e:run \
  app/code/Weline/Checkout/test/e2e/frontend/plan-p2e003-current-source.spec.js \
  --project=chromium --headless
```

`TEST-P2E-09` 必须证明 `/checkout` 首屏响应和可见 DOM 都含服务端
`data-checkout-item`；`TEST-BROWSER-01` 必须从受信任 `cart.add` 走到
`checkout.freezeQuote` / `checkout.submitV2`，不得因接口拒绝而跳过。

模块：`Weline_Checkout` `1.4.4`；Theme 布局增量无需升版强制。
