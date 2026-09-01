# 前端 JS 模块加载规范（硬约束）

> 权威：前台主题/部件/布局相关 JS **只能**走本规范。MCP `hard-constraints.v1` 规则 id：`theme_js_module_declare_only`。  
> 配套：`Theme.js使用指南.md`、`hook/frontend/partials/head/module-declarations.md`、`部件开发指南.md`。

## 1. 原则

1. **主题 head 提供加载器底座**（`theme.js` / 前台等价入口 `Weline`）：提供 `Weline.declare` / `Weline.load` / `data-weline-load`。
2. **业务模块用 `weline.modules.js` 注册** JS 模块与别名，禁止在部件/布局里手写 `<script src>` / `@static(...js)` / 裸 `<js>` 去拉「模块级」脚本。
3. **部件只声明依赖**：在部件根节点挂 `data-weline-load="cart"`（立即）或 `data-weline-declare="account"`（按需）；加载器扫描属性后自动加载。
4. **布局有 slot 就用 slot**：模块提供部件注入已有 slot；不要为同一能力再造平行挂载点。
5. **PHP 可扫描**：声明必须可被 `Weline\I18n\Helper\JsModuleParser` 识别（`Weline.declare(...)` / `data-weline-load`）。

### 1.1 两套加载通道（勿混淆）

| 通道 | 触发条件 | 典型日志 |
|------|----------|----------|
| **路径启发式预加载**（优先） | URL 含 `/account`、`/cart`、`/checkout` 等 | `路径启发式预加载` / `路径启发式：本页无匹配路由…` |
| **部件属性加载** | DOM 上 `data-weline-load` / `data-weline-declare` | `部件属性模块加载`（列出 cart、miniCartIcon 等） |

**优先级**：路径启发式先认领模块；属性通道对「已认领 / 已加载 / 加载中」的模块**跳过**，开发日志会写「已跳过（路径启发式/已加载）」。同一模块不会双插 script。

首页 `/` **通常不会**走路径启发式（故可能看到「本页无匹配路由」），但加购钮 / 迷你购物车等仍会通过 `data-weline-load` 加载。灰字「路径启发式」≠「本页没有模块」。

## 2. 注册模块（收集门槛）

收集**不是**扫任意 `.js`，只扫各启用模块里**固定文件名、固定目录层级**的登记源文件，再编译合并。实现：`Theme\Config\Reader\WelineModules` + `resource:compile welineModules`。

### 2.1 怎样才会被收集

| 条件 | 要求 |
|------|------|
| 文件名 | 必须正好是 `weline.modules.js` |
| 源路径 | `app/code/{Vendor}/{Module}/view/statics/frontend/weline.modules.js` 或 `.../backend/weline.modules.js` |
| area | `statics` 下一级目录名必须是 `frontend` 或 `backend`（据此分区合并） |
| 模块 | 模块已注册且启用；未启用则扫不到 |
| 产物目录 | `view/statics/base/weline.modules.js` 是**编译产物**，扫描时**跳过**，勿把登记写进 `base/` |

流程：

1. Reader 在启用模块的 `view/` 下找 basename=`weline.modules.js` 的源文件（跳过 `statics/base/`）。
2. 按 path 判定 area，同 area 源文件内容**按扫描结果拼接**。
3. `php bin/w resource:compile welineModules`（或全量 `resource:compile`）写出运行时配置：前台 `Weline_Frontend::view/statics/base/weline.modules.js`，后台对应 `Weline_Backend::.../base/weline.modules.js`。
4. 页面 head 加载该 base 配置后，部件再用 `data-weline-load` / `declare` 才会真正拉脚本。

**只放业务 `.js`、不写本登记表 → 不会被收集。** 只写登记表、不 compile → 运行时 base 不会更新。

### 2.2 源文件怎么写

路径示例（前台）：`app/code/Weline/Cart/view/statics/frontend/weline.modules.js`

必须先初始化全局配置对象，再用 `Object.assign` 合并（参考 Cart / Frontend 现网写法）：

```javascript
window.WelineModulesConfig = window.WelineModulesConfig || {};
window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};
window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};

Object.assign(window.WelineModulesConfig.modules, {
    cart: {
        paths: ["Weline_Cart::js/widgets/product-purchase-actions.js"],
        globalVar: "WelineCartPurchaseActions",
        description: "万能购物车加购/立即结账交互"
    }
});
Object.assign(window.WelineModulesConfig.moduleAliases, {
    // 可选短名 → 真实模块键
});
```

字段约定：

- `paths`：`Vendor_Module::相对 view/statics 的路径`（如 `js/widgets/foo.js` → 真实文件在 `view/statics/js/widgets/foo.js`）。
- `globalVar`：脚本加载后必须存在的全局名（与 Theme `ModuleLoader` 校验一致；Worker 等可 `null`）。
- **模块名必须是裸 JS 标识符**（如 `customerAccount`），禁止 `"customer-account"` 这类引号+连字符键——`Frontend\Observer\Compiler` 合并时只识别裸标识符。
- 编译：`php bin/w resource:compile welineModules`（或全量 resource:compile）。

## 3. 部件声明（推荐）

```html
<div class="weline-cart-product-add-to-cart"
     data-weline-load="cart"
     data-widget-module="Weline_Cart"
     data-widget-code="product-add-to-cart">
  ...
</div>
```

| 属性 | 行为 |
|------|------|
| `data-weline-load="a,b"` | DOM 就绪后**立即**加载模块 |
| `data-weline-declare="a,b"` | 仅声明，**按需**再 `Weline.use` / 首次调用时加载 |
| `Weline.declare('cart', true)` | 脚本内声明并立即加载（优先放 head `module-declarations` hook） |
| `Weline.declare('cart', true, path, null, { loadOrder: 'last' })` | 延后到 DOMContentLoaded 后再拉脚本 |

多部件同页声明同一模块：加载器去重，只拉一次。

## 4. Head 统一声明（可选）

Hook：`Weline_Theme::frontend::partials::head::module-declarations`  
文件：`view/hooks/Weline_Theme/frontend/partials/head/module-declarations.phtml`

用于全站/区域级模块（搜索、定位等），仍须 `Weline.declare`，禁止 `@static` 直引。

## 5. 禁止

- 部件/布局/body-end **直接** `<script src="@static(Module::js/....js)">` 加载已登记或应登记的模块脚本
- 用裸 `<js>Module::js/....js</js>` 替代 `data-weline-load` / `declare`（模块级能力）
- 在部件模板内写带 `<?=` 的内联业务 `<script>`（布局提取会泄漏文本）
- 业务请求绕过 `Weline.Api.*`（仍遵守 Frontend Api 规范）

## 6. 与「部件 UI 脚本」的边界

- **模块级能力**（购物车、结账加购、收藏、账户 API、用户中心页逻辑）：必须本规范。
- 极小的、无共享、无翻译扫描需求的展示脚本：仍禁止 `<?=` 内联；若升级为跨页/跨部件复用，必须升格为 `weline.modules.js` 模块。

## 7. 已知应对模块

| 模块 | 建议模块名 | 典型部件属性 |
|------|------------|--------------|
| Cart 万能购物车 | `cart` | `data-weline-load="cart"` |
| Checkout | 复用 `cart`（加购/立即结账同脚本）或自有 `checkout` | `data-weline-load="cart"` |
| Wishlist 收藏 | `wishlist` | `data-weline-load="wishlist,api"` |
| Compare 对比 | `comparePage` / `compareShopper` | 对比页 / body-end 对比栏 |
| Theme 迷你购物车 | `miniCartExtras` / `miniCartIcon` | 布局/body-end `data-weline-load` |
| Theme 页头搜索 / 店面兜底 | `headerSearch` / `storefrontImageFallback` / `storefrontShopperToast` | 搜索部件或 base body-end |
| Theme 页头账户 | `api` + `account` | **账户部件根** `widgets/header/account`：`data-weline-load="api,account"`（禁止写到 header 布局壳） |
| Customer 用户中心 | `api` + `account`（API）；页 UI：`customerAccount` / `customerLogout` | 认证表单 `data-weline-load="api,account"`；账户页加 `customerAccount` |
| Shipping | `shippingCheckoutAddress` / `shippingAccountAddress` | 结账地址部件 / 账户地址区 |
| Review / Blog 评论 | `productReviews` | 商品/博客评论部件根 |
| Marketing 优惠券 | `checkoutCoupon` | 结账/迷你车优惠券部件 |
| Product 相关推荐 FBT | `relatedProducts` / `recommendedProducts` / `crossSell` | 对应部件根 |
| Order 留言 | `orderNotice` | 迷你车订单留言 |
| CustomerService | `customerService` | `Weline.load('customerService')`（空闲预载） |
| TwoFactorAuth | `accountTwoFactor` | 账户两步验证面板 |
| Currency | `currency` | 语币选择资源区 `data-weline-load="currency"` |
| Frontend Dom 微核 | `dom`（`welineDom`） | **按需**：`data-weline-when` / `data-weline-on` 自动探测加载；或显式 `data-weline-load="dom"`。静态首屏绑定不要用。 |

## 8. 验收

- 页面 Network 中模块脚本由加载器发起，部件 DOM 上可见 `data-weline-load` / `data-weline-declare`
- 无重复的「同路径手写 script + 属性双加载」残留（手写 script 必须删除）
- `JsModuleParser` / 主题相关契约测试通过
