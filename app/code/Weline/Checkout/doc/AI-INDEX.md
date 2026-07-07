<!-- weline:module-ai-index:auto-generated -->
# Weline_Checkout AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Checkout/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Checkout/doc/README.md`
6. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Checkout`
- 目录：`app/code/Weline/Checkout`
- Vendor：`Weline`
- Module：`Checkout`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Checkout/etc/module.xml`
- `app/code/Weline/Checkout/etc/backend/menu.xml`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：2
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。 文件数：3
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：3
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：2
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：4
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。 文件数：3
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：0

## 从源码识别到的开发提示

- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。

## doc 目录

- `app/code/Weline/Checkout/doc/API文档.md`
- `app/code/Weline/Checkout/doc/README.md`
- `app/code/Weline/Checkout/doc/event/checkout/guest-validate-after.md`
- `app/code/Weline/Checkout/doc/event/checkout/guest-validate-before.md`
- `app/code/Weline/Checkout/doc/event/checkout/identity-resolve-after.md`
- `app/code/Weline/Checkout/doc/event/checkout/identity-resolve-before.md`
- `app/code/Weline/Checkout/doc/event/checkout/创建订单前.md`
- `app/code/Weline/Checkout/doc/event/checkout/创建订单后.md`
- `app/code/Weline/Checkout/doc/event/checkout/结账数据验证前.md`
- `app/code/Weline/Checkout/doc/event/checkout/结账数据验证后.md`
- `app/code/Weline/Checkout/doc/event/checkout/计算订单总额前.md`
- `app/code/Weline/Checkout/doc/event/checkout/计算订单总额后.md`
- `app/code/Weline/Checkout/doc/event/order/订单加载前.md`
- `app/code/Weline/Checkout/doc/event/order/订单加载后.md`
- `app/code/Weline/Checkout/doc/event/order/订单取消.md`
- `app/code/Weline/Checkout/doc/event/order/订单取消前.md`
- `app/code/Weline/Checkout/doc/event/order/订单取消后.md`
- `app/code/Weline/Checkout/doc/event/order/订单完成.md`
- `app/code/Weline/Checkout/doc/event/order/订单状态变更前.md`
- `app/code/Weline/Checkout/doc/event/order/订单状态变更后.md`
- `app/code/Weline/Checkout/doc/event/order/订单退款.md`
- `app/code/Weline/Checkout/doc/event/payment/支付回调前.md`
- `app/code/Weline/Checkout/doc/event/payment/支付回调后.md`
- `app/code/Weline/Checkout/doc/event/payment/支付处理前.md`
- `app/code/Weline/Checkout/doc/event/payment/支付处理后.md`
- `app/code/Weline/Checkout/doc/event/payment/支付失败.md`
- `app/code/Weline/Checkout/doc/event/payment/支付成功.md`
- `app/code/Weline/Checkout/doc/event/payment/支付验证前.md`
- `app/code/Weline/Checkout/doc/event/payment/支付验证后.md`
- `app/code/Weline/Checkout/doc/hook/backend/order/list/filters.md`
- `app/code/Weline/Checkout/doc/hook/backend/order/view/after.md`
- `app/code/Weline/Checkout/doc/hook/backend/order/view/before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/content-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/content-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/form-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/form-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/head-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/head-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/identity-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/identity-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/identity-options-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/identity-options-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/notification-preferences.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/payment-methods-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/payment-methods-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/place-order-button-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/place-order-button-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/review-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/review-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/shipping-methods-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/shipping-methods-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-discount-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-discount-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-grand-total-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-grand-total-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-rows-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-rows-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-shipping-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-shipping-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-subtotal-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-subtotal-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-tax-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/checkout/summary-tax-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/order/list/content-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/order/list/content-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/order/view/content-after.md`
- `app/code/Weline/Checkout/doc/hook/frontend/layouts/order/view/content-before.md`
- `app/code/Weline/Checkout/doc/hook/frontend/partials/checkout/payment-details.md`
- `app/code/Weline/Checkout/doc/hook/frontend/partials/checkout/payment-methods.md`
- `app/code/Weline/Checkout/doc/hook/frontend/partials/checkout/shipping-methods.md`
- `app/code/Weline/Checkout/doc/事件使用指南.md`
- `app/code/Weline/Checkout/doc/使用指南.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Checkout> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
