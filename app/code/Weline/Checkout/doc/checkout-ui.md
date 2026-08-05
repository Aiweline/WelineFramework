# Checkout UI（P2E-003）

## 规则

- 商品事实只来自 `w_query('cart', 'getV2Cart')`，由 `CheckoutPageViewModel` 把受信任 minor-unit 行归一化为展示数据；Controller 不接收浏览器商品名、价格或数量
- 首屏商品行 DOM **只**由服务端模板生成：`CheckoutPageViewModel` → `view/frontend/checkout/partials/items.phtml` → Hook `frontend::partials::checkout::cart-items`
- 异步刷新商品行与方式选项仍由 `CheckoutHtmlRenderer` 生成，浏览器只注入 `items_html` / `*_methods_html`
- 浏览器 JS **只**做交互：选配送/支付、提交、`textContent` 更新合计；通过 `items_html` / `*_methods_html` 注入服务端片段
- 业务网络 **只**走 `Weline.Api.resource('checkout')`（禁止 native fetch/XHR/axios）

## 入口

| 表面 | 路径 |
|---|---|
| 商城结账页 | `/checkout` |
| 结账页 | `/weline_checkout/frontend/checkout`（layout=`checkout`） |
| 首屏数据 | `CheckoutPageViewModel::currentCart()` |
| 模板 | `Checkout/view/frontend/checkout/index.phtml` |
| 商品局部模板 | `Checkout/view/frontend/checkout/partials/items.phtml` |
| Theme | `Theme/.../layouts/checkout/default.phtml`、`one-page.phtml` |
| API | `w_query('checkout','getData'|'freezeQuote'|'submitV2')` → 含 `items_html` |

## 验证

```bash
php bin/w phpunit:run --module=Weline_Checkout
php bin/w frontend:check-section-code
php bin/w e2e:run \
  app/code/Weline/Checkout/test/e2e/frontend/plan-p2e003-current-source.spec.js \
  --project=chromium --headless
```

`TEST-P2E-09` 必须证明 `/checkout` 首屏响应和可见 DOM 都含服务端
`data-checkout-item`；`TEST-BROWSER-01` 必须从受信任 `cart.addV2` 走到
`checkout.freezeQuote` / `checkout.submitV2`，不得因接口拒绝而跳过。

模块：`Weline_Checkout` `1.4.0`；Theme 布局增量无需升版强制。
