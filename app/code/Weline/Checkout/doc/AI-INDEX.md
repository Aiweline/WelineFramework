<!-- weline:module-ai-index:auto-generated -->
# Weline_Checkout AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-docs.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. 全局硬规则与任务路由：`app/code/Weline/Ai/doc/AI硬规则索引.md`
3. 本文件：`app/code/Weline/Checkout/doc/AI-INDEX.md`
4. 模块说明：`app/code/Weline/Checkout/doc/README.md`
5. `app/code/Weline/Theme/doc/AI-INDEX.md`
6. `app/code/Weline/Frontend/doc/AI-INDEX.md`
7. `app/code/Weline/Taglib/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Checkout`
- 目录：`app/code/Weline/Checkout`
- Vendor：`Weline`
- Module：`Checkout`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Checkout/composer.json`
- `app/code/Weline/Checkout/etc/backend/menu.xml`
- `app/code/Weline/Checkout/etc/module.xml`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：5
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后优先跑完整 `setup:upgrade`；仅需重建路由图时可用 `setup:upgrade --route`（选填）。 文件数：10
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：4
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：4
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：37
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器发现，完整 `setup:upgrade` 会同步；仅路由图变更时可用 `--route`（选填）。 文件数：5
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：3
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：8
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：7
- `view/theme`：主题资源贡献层。读 Weline_Theme/doc/AI-INDEX.md 后按 layout/partial/component/widget 规则开发。 文件数：5

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `view/theme`，说明该模块向主题资源 catalog 贡献 layout/partial/component/widget/asset。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 入口：`extends/module/Weline_Framework/Query/CheckoutQueryProvider.php`、`extends/module/Weline_Framework/Query/CheckoutSignalsQueryProvider.php`；前端/跨模块读数据先查 `php bin/w query:help`。

## doc 目录

- `app/code/Weline/Checkout/doc/API文档.md`
- `app/code/Weline/Checkout/doc/README.md`
- `app/code/Weline/Checkout/doc/checkout-ui.md`
- `app/code/Weline/Checkout/doc/checkout-v2.md`
- `app/code/Weline/Checkout/doc/event/checkout/asset-discount-apply.md`
- `app/code/Weline/Checkout/doc/event/checkout/freeze-quote-enrich.md`
- `app/code/Weline/Checkout/doc/event/checkout/guest-validate-after.md`
- `app/code/Weline/Checkout/doc/event/checkout/guest-validate-before.md`
- `app/code/Weline/Checkout/doc/event/checkout/identity-resolve-after.md`
- `app/code/Weline/Checkout/doc/event/checkout/identity-resolve-before.md`
- `app/code/Weline/Checkout/doc/event/checkout/legacy-writer-assert.md`
- `app/code/Weline/Checkout/doc/event/checkout/shipping-methods-enrich.md`
- `app/code/Weline/Checkout/doc/event/checkout/shipping-quote-overlay.md`
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
- `app/code/Weline/Checkout/doc/hook/frontend/partials/checkout/cart-items.md`
- `app/code/Weline/Checkout/doc/hook/frontend/partials/checkout/payment-details.md`
- `app/code/Weline/Checkout/doc/hook/frontend/partials/checkout/payment-methods.md`
- `app/code/Weline/Checkout/doc/hook/frontend/partials/checkout/shipping-methods.md`
- `app/code/Weline/Checkout/doc/hook/frontend/widgets/checkout-delivery-context/quick-add.md`
- `app/code/Weline/Checkout/doc/事件使用指南.md`
- `app/code/Weline/Checkout/doc/使用指南.md`
- `app/code/Weline/Checkout/doc/功能现状.md`
- `app/code/Weline/Checkout/doc/开发/spec/checkout-abandon-signals.md`
- `app/code/Weline/Checkout/doc/开发/spec/checkout-entry-attribution.md`
- `app/code/Weline/Checkout/doc/开发/spec/checkout-fault-snapshot.md`
- `app/code/Weline/Checkout/doc/开发/spec/express-pay-event-chain.md`
- `app/code/Weline/Checkout/doc/开发/spec/payment-cancel-continue-pay.md`
- `app/code/Weline/Checkout/doc/开发/team/checkout-shipping-soft-fallback-sanqing/meetings/汇审.md`
- `app/code/Weline/Checkout/doc/开发/team/payment-cancel-continue-pay/meetings/技术方案会-继续支付复用结账页-20260921.md`
- `app/code/Weline/Checkout/doc/开发/team/payment-cancel-continue-pay/meetings/汇审.md`
- `app/code/Weline/Checkout/doc/开发日志.md`
- `app/code/Weline/Checkout/doc/需求.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Checkout> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
