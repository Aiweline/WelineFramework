# 迷你购物车 API 按需加载

## 背景

迷你购物车挂在 header hook 中，容易受到页面、hook 或 widget HTML 缓存影响。如果在 `drawer.phtml` 中直接读取 session/cart 并输出商品行，缓存命中时会把空购物车或旧数量固化到后续页面。

## 约定

- `drawer.phtml` 只输出抽屉结构、静态空态、按钮和配置，不读取购物车商品数据。
- 用户点击打开迷你购物车后，`mini-cart.js` 通过 `Weline.Api.resource('cart').miniItems()` 获取商品、数量和金额。
- 初始页面加载不自动请求 `cart.count`；其它脚本需要数量同步时显式调用 `window.WeShopCartHydrate.scheduleHydrate()` 或 `hydrateCartFromApi()`。
- `cart.miniItems` 和 `cart.count` 属于用户态动态数据，QueryProvider 描述中不声明缓存 TTL。

## 验证入口

1. 清理页面/模板缓存后打开任意带 header cart hook 的前台页面。
2. 首次不点击购物车时，Network 中不应出现 `cart.count` 或 `cart.miniItems`。
3. 点击购物车图标后，应出现一次 `cart.miniItems` Worker API 调用，抽屉数量、商品行和小计以 API 响应为准。
