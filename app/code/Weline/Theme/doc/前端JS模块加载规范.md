# 前端 JS 模块加载规范（硬约束）

> 权威：前台主题/部件/布局相关 JS **只能**走本规范。MCP `hard-constraints.v1` 规则 id：`theme_js_module_declare_only`。  
> 配套：`Theme.js使用指南.md`、`hook/frontend/partials/head/module-declarations.md`、`部件开发指南.md`。

## 1. 原则

1. **`weline.js` 只做框架加载引擎（强制，MCP `weline_js_loader_framework_only`）**：提供 `Weline.declare` / `Weline.load` / `data-weline-load|declare` 扫描与并发/延后策略、**声明式 UI 挂载编排**（`data-weline-mount` + `Weline.mount.provide/scan/waitFor`）、以及**维护场景下懒加载** Maintenance 模块 JS（如 `maintenanceAsyncWait`）——**禁止**路径启发式 URL 预载；**禁止**在 `weline.js` 内嵌维护 UI 或任何业务逻辑 / **业务挂载面名**。**禁止**在默认配置、`nameMap` 或 `Weline.*` 业务代理里写死业务模块名（`cart` / `account` / `compareShopper` / `wishlist` / `miniCart*` / `storefront*` / `customer*` / `currency` 等）或业务能力。业务一律由归属模块 `weline.modules.js` + 部件 `data-weline-load` / `declare` 完成；挂载面由提供方 `Weline.mount.provide(surface, handler)` 自注册。允许的非业务传输别名仅 `api` / `dom`（及 `welineApi` / `welineDom`）——**明确禁止 `account`**。**核心国际化**由 `Weline_Framework` 登记模组 `i18n`（`Weline_Framework::js/i18n.js`，与 `Phrase` 同属框架能力面；**不**重命名 Phrase，以免与小写 `i18n/` CSV 目录冲突）；主题 head / 语言部件 `declare`/`data-weline-load="i18n"` 加载。外置 `Weline_I18n` 只做增强（语言切换器 UI、国旗、AI 翻译等）。
2. **主题 head 提供加载器底座**（`theme.js` / 前台等价入口 `Weline`）：提供 `Weline.declare` / `Weline.load` / `data-weline-load` / `Weline.mount`。
3. **业务模块用 `weline.modules.js` 注册** JS 模块与别名（含 `paths` / `globalVar`），禁止在部件/布局里手写 `<script src>` / `@static(...js)` / 裸 `<js>` 去拉「模块级」脚本。
4. **部件只声明依赖**：在部件根节点挂 `data-weline-load="cart"` 或 `data-weline-declare="account"`；加载器扫描属性后自动加载。延后策略由 `runtimeConfig.modulesLoad.deferByDefault`（默认 `true` → 空闲延后）控制；需要立刻加载的模块由**站点运行时** `eagerModules` 覆盖，或部件侧显式 `Weline.declare(name, { load: 'eager' })`——**不要**把业务名单写回 `weline.js` 默认值。
5. **布局有 slot 就用 slot**：模块提供部件注入已有 slot；不要为同一能力再造平行挂载点。
6. **PHP 可扫描**：声明必须可被 `Weline\I18n\Helper\JsModuleParser` 识别（`Weline.declare(...)` / `data-weline-load`）。

### 1.0 声明式 UI 挂载（`Weline.mount`）

框架级能力（与 `data-weline-load` 并列），**不是**业务模块自扫：

| 角色 | 职责 |
|------|------|
| **框架** `Weline.mount` | **页面无 `[data-weline-mount]` 则不 boot core、不自动扫描**；有声明才 `ensureMountCore`；按 `data-weline-mount-load` 拉模块；按 `data-weline-mount-after` 等待依赖面 settled；调用已注册 provide；派发 `weline:mount:scan` / `weline:mount:ready` |
| **提供方**（如 Account） | 仅在页上存在本模块宿主时 `Weline.mount.provide(...)`；`provide` 只对已声明 surface 触发 scan |
| **宿主**（如 B2B） | 模板只写特殊属性；抽屉打开后可 `Weline.mount.scan({ root, force: true })`；**禁止**调 `Account.scanMounts` 作编排 |

属性：

| 属性 | 含义 |
|------|------|
| `data-weline-mount` | 主键，`{providerKey}/{surface}` |
| `data-weline-mount-load` | 可选，逗号分隔模块名，确保提供方脚本加载并 `provide` |
| `data-weline-mount-after` | 可选，逗号分隔 surface；依赖面 `ready\|empty\|error` 后才挂本宿主（例：批发面板等 account 挂好） |
| `data-weline-mount-variant` / `-suppress-floating` / `-state` | 提供方约定的呈现与运行时状态 |

```html
<div data-weline-mount="customer/login-panel"
     data-weline-mount-load="account"
     data-weline-mount-suppress-floating="1"></div>
<!-- 仅快捷：data-weline-mount="customer/social-quick" -->
<!-- 依赖等待示例：本面等登录面板 settled 后再挂 -->
<div data-weline-mount="b2b/apply-panel"
     data-weline-mount-load="b2bSellingMode"
     data-weline-mount-after="customer/login-panel"></div>
```

提供方清单写在各模块 `doc/挂载面.md`（如 Customer）；协议正文以本节为准。

### 1.1 两套加载通道（仅此两种）

| 通道 | 触发条件 | 典型日志 |
|------|----------|----------|
| **申明加载** | `Weline.declare(...)` / head `module-declarations` | 声明延后 / 立即加载 |
| **属性申明加载** | DOM 上 `data-weline-load` / `data-weline-declare` | `部件属性模块加载` |

**禁止路径启发式**：不再按 URL 路径自动预载模块（易与模板声明重复插 script）。需要模块时在模板 / 部件 / head 自行声明。

属性通道对「已加载 / 加载中」的模块跳过；开发日志写「已跳过（已加载/加载中）」。同一模块不会双插 script。

默认 `modulesLoad.deferByDefault=true`：部件 `data-weline-load` 走空闲延后；`data-weline-declare` 在 `loadDeclaredDeferred=true` 时同样调度延后加载。

### 1.2 开发环境：MutationObserver 反馈环防护

`weline.js` 在 **DEV / `runtimeConfig.debug` / `?debug=1`** 下包装原生 `MutationObserver`：

- **同步重入**：回调内改 DOM 导致同一观察者嵌套投递 → `disconnect` + `console.error`
- **短窗风暴**：约 250ms 内同一观察者投递 > 40 次 → 同上（覆盖微任务连发）

控制台关键字：`[Weline:DEV] MutationObserver 反馈环`；事件：`weline:dev:mutation-loop`；详情含 `observeStack`。生产不包装。业务侧正确做法：回调加重入/`__painting` 守卫，或只发现新节点、禁止每次全量重绘。
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

**Agent / 主题开发硬门槛（与 Theme开发总指南一致）**：凡新增、修改、迁移、删除 `weline.modules.js` 条目或改动其 `paths` 指向的模块级 JS，**本回合收口前必须**执行：

```bash
php bin/w resource:compile welineModules
```

禁止假设「保存源文件就会进店面」；禁止手改 `statics/base/weline.modules.js` 冒充收集。

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
- `load`（可选）：`"defer"` 空闲延后 / `"eager"` 立即；由加载器 `shouldDeferAttributeModule` 读取。**写在归属模块登记里**，禁止写进 `weline.js` 默认名单。
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
| `data-weline-load="a,b"` | DOM 就绪后按 `modulesLoad` / 模块 `load` 策略加载（默认空闲延后） |
| `data-weline-declare="a,b"` | 声明并在 `loadDeclaredDeferred` 下调度延后加载；也可按需 `Weline.use` |
| 模块登记 `load: "defer"\|"eager"` | 写在归属模块的 `weline.modules.js`（如 Cart `cart`、Compare `compareShopper`）；**禁止**写进 `weline.js` 默认名单 |
| `Weline.declare('cart', true)` | 脚本内声明并立即加载（优先放 head `module-declarations` hook） |
| `Weline.declare('cart', { load: 'eager' })` | 显式立即；`{ load: 'defer' }` 空闲延后 |

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
- 在 `Weline_Frontend::js/weline.js` 默认配置 / `getGlobalVarName` 回退表 / **路径启发式** / `Weline.Account` 等业务代理中**写死业务模块名或业务逻辑**（路径启发式已移除；含 `account`/`cart` 必须放归属模块的 `weline.modules.js` + 部件声明）
- 在 `weline.js` **内嵌**维护弹层/兑礼 UI（只允许懒加载 Maintenance 模块 JS）
- 依赖 URL 路径自动预载模块（必须模板内 `declare` / `data-weline-load`）

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
| Theme 页头账户 | `api` + `account` | **账户部件根** `widgets/header/account`：`data-weline-load="api,account"`（`account` 由 **Customer** 登记 `account-session.js`；禁止写到 header 布局壳） |
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
