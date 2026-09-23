# QA-05 / QA-09 · Cart/Checkout 模块侧状态机修复

日期：2026-09-23  
席位：Team:Cart/Checkout  
仓库：`/Users/weline/Project/Official/框架`

## 根因确认

### QA-05 购物车三态并存
1. **主因（模块）**：页面壳仅靠 `node.hidden` 切换 loading/empty/error/ready；CSS `[hidden]{display:none}` 无 `!important`，且与 `.weline-cart-shell__state{display:flex}` / `__grid{display:grid}` 级联并存时，易出现「视觉或 a11y 树」同时读到多态文案。
2. **串味**：页内摘要区复用了 `mini-cart-drawer__extras` 类名；mini-cart 也有「购物车是空的」等 i18n 属性，走查/a11y 扫全文时易与页壳态文案混读。真页壳态文案（「暂时无法加载购物车」）仅存在于 cart page error 壳。
3. **非根因**：mini-cart 抽屉本身未写入 cart page 的 `data-cart-state` 节点。

### QA-09 结算加载中+空车+有表单
1. **主因（框架+模块）**：`<w:form … hidden>` 曾被 FormRenderer 丢弃（BOOLEAN 仅 `novalidate`）。截图首屏：notice「正在加载…」+ 表单收货区 + 摘要内 SSR「购物车为空」同屏。
2. **dirty-load 复核**：施工中途再读 `FormRenderer.php`，`BOOLEAN_ATTRIBUTES` **已含** `['novalidate','hidden']`（框架席已进）。模块侧仍加 **form-host 包装** 作防御，不单绑 Form 属性。
3. **身份**：cookie 为 guest 权威；client token 未对齐前若 paint 空车，会出现「购物车有货、结算空车」无提示。

## 改动列表

| 文件 | 变更 |
|------|------|
| `Cart/.../cart/index.phtml` | `data-cart-view`；`showState` 互斥 + `aria-hidden`/`inert`；`data-cart-page-*` 命名空间；启动即 `showState('loading')`；CSS 版本戳 |
| `Cart/.../css/cart-page-amazon.css` | `[data-cart-view]` 独占显示 + `display:none !important` |
| `Checkout/.../checkout/index.phtml` | `data-checkout-view="loading"`；`data-checkout-form-host` 包装；`showCheckoutShell` / `setFormVisible`；cookie 对齐前禁止 ready/empty paint；本地有货+服务端空车 → `mismatch` 提示；CSS 互斥壳 |
| `Checkout/i18n/{zh_Hans_CN,en_US}.csv` | `guest_cart_mismatch` 中英 |
| `Cart/Test/.../CartPageStateMutexContractTest.php` | 新增契约 UT |
| `Checkout/Test/.../CheckoutShellMutexContractTest.php` | 新增契约 UT |
| `CartStorefrontQueryBinContractTest` / `StorefrontCheckoutTemplateContractTest` | 对齐新壳断言 |

## 测试结果

| 测试 | 结果 |
|------|------|
| `CartPageStateMutexContractTest` | ✅ PASS（15 assertions） |
| `CheckoutShellMutexContractTest` | ✅ PASS（16 assertions） |
| `StorefrontCheckoutTemplateContractTest` | ✅ PASS（18 tests / 313 assertions） |
| `CartStorefrontQueryBinContractTest` | ⚠️ 含既有失败（`await cartIdentity()` / `data-cart-swatch-trigger` 与本席无关的脏仓断言）；本席新增的 `data-cart-view` / `inert` 断言未单独红 |
| `php bin/w i18n:collect --module=Weline_Checkout` | ✅ |

未跑 Browser 深点（依赖 Wave0 WLS 稳定 + 走查席复验）。

## 仍依赖框架侧的点

1. **FormRenderer `hidden`**：已进 `BOOLEAN_ATTRIBUTES`（dirty-load 确认）。模块 host 保留，防止回退。
2. **QA-01 Worker/Fiber**：Browser 复验 `/cart` `/checkout` 需 WLS 稳后再做。
3. **单一购物车 revision 跨 Cart/mini-cart/Checkout**：本席完成页壳互斥 + guest 对齐门禁；跨表面同一 snapshot revision 的更深契约若架构席另立，可再跟。

## 验收契约（本席交付）

- `/cart`：任意时刻用户可见仅一种态 loading|empty|error|ready（根 `data-cart-view`）。
- `/checkout`：初始只暴露加载 notice；表单在 form-host 内，getData 成功且非空才 ready；空车只走 empty 壳；本地有货服务端空 → mismatch 提示，禁止无提示空车。
