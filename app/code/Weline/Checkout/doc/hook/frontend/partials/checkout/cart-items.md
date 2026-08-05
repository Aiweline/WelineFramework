# 结账商品行列表

服务端渲染结账摘要商品 DOM（P2E-003 / TEST-P2E-09）。

- 首屏默认实现：`CheckoutPageViewModel::currentCart` + `view/frontend/checkout/partials/items.phtml`
- 异步刷新实现：`CheckoutHtmlRenderer::renderItems` 返回 `items_html`
- 扩展模块可通过本 Hook 追加行级说明，但**不得**依赖前端 `createElement`/`innerHTML` 拼装商品结构作为事实源
- 异步刷新时由 `w_query('checkout','getData')` 返回 `items_html`，JS 仅注入服务端 HTML
- Hook 宿主标记为 `data-checkout-items-hook`，服务端商品行标记为 `data-checkout-item`
