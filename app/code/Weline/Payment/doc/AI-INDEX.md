<!-- weline:module-ai-index:auto-generated -->
# Weline_Payment AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-docs.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. 全局硬规则与任务路由：`app/code/Weline/Ai/doc/AI硬规则索引.md`
3. 本文件：`app/code/Weline/Payment/doc/AI-INDEX.md`
4. 模块说明：`app/code/Weline/Payment/doc/README.md`
5. `app/code/Weline/Theme/doc/AI-INDEX.md`
6. `app/code/Weline/Frontend/doc/AI-INDEX.md`
7. `app/code/Weline/Taglib/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Payment`
- 目录：`app/code/Weline/Payment`
- Vendor：`Weline`
- Module：`Payment`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Payment/composer.json`
- `app/code/Weline/Payment/etc/backend/menu.xml`
- `app/code/Weline/Payment/etc/module.xml`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：46
- `Block`：视图数据块。配合模板输出页面数据，变更前要读对应模板和 layout。 文件数：2
- `Console`：php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。 文件数：12
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后优先跑完整 `setup:upgrade`；仅需重建路由图时可用 `setup:upgrade --route`（选填）。 文件数：16
- `Controller/Router.php`：ModuleRouter 自定义 URL 匹配入口。只有自定义公网路径/动态路由匹配才改这里。 文件数：1
- `Helper`：模块内辅助能力。跨模块不要直接调用未发布 Helper。 文件数：1
- `Interface`：模块发布的接口契约。跨模块依赖优先使用这里的稳定契约。 文件数：8
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：23
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：9
- `Queue`：队列生产/消费入口。读 Queue 技能和模块文档后再改。 文件数：3
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：90
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：2
- `etc`：模块配置。禁止 routes.xml；路由由控制器发现，完整 `setup:upgrade` 会同步；仅路由图变更时可用 `--route`（选填）。 文件数：7
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：15
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：14
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：38
- `view/theme`：主题资源贡献层。读 Weline_Theme/doc/AI-INDEX.md 后按 layout/partial/component/widget 规则开发。 文件数：1

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `view/theme`，说明该模块向主题资源 catalog 贡献 layout/partial/component/widget/asset。
- 存在 `Controller/Router.php`，说明模块可能发布自定义 URL 匹配；不要用 `routes.xml` 代替。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 入口：`extends/module/Weline_Framework/Query/PaymentQueryProvider.php`；前端/跨模块读数据先查 `php bin/w query:help`。

## doc 目录

- `app/code/Weline/Payment/doc/README.md`
- `app/code/Weline/Payment/doc/dev-webhook-relay.md`
- `app/code/Weline/Payment/doc/event/checkout-available-methods-enrich.md`
- `app/code/Weline/Payment/doc/extends.md`
- `app/code/Weline/Payment/doc/facade-v2.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-form-after.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-form-before.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-methods-after.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-methods-before.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-result.md`
- `app/code/Weline/Payment/doc/migrate-p2-payment.md`
- `app/code/Weline/Payment/doc/payment-customer-guide-i18n.md`
- `app/code/Weline/Payment/doc/payment-methods/adyen/adyen_checkout.md`
- `app/code/Weline/Payment/doc/payment-methods/adyen/ideal.md`
- `app/code/Weline/Payment/doc/payment-methods/adyen/open_banking_pay_by_bank.md`
- `app/code/Weline/Payment/doc/payment-methods/adyen/sepa_bank_transfer.md`
- `app/code/Weline/Payment/doc/payment-methods/airwallex/airwallex_payments.md`
- `app/code/Weline/Payment/doc/payment-methods/airwallex/local_virtual_account.md`
- `app/code/Weline/Payment/doc/payment-methods/alipay/alipay.md`
- `app/code/Weline/Payment/doc/payment-methods/braintree/braintree_dropin.md`
- `app/code/Weline/Payment/doc/payment-methods/checkout_com/checkout_com_hosted.md`
- `app/code/Weline/Payment/doc/payment-methods/dlocal/dlocal_payins.md`
- `app/code/Weline/Payment/doc/payment-methods/dlocal/pse.md`
- `app/code/Weline/Payment/doc/payment-methods/dlocal/spei.md`
- `app/code/Weline/Payment/doc/payment-methods/ebanx/ebanx_payins.md`
- `app/code/Weline/Payment/doc/payment-methods/ebanx/pix.md`
- `app/code/Weline/Payment/doc/payment-methods/flutterwave/flutterwave.md`
- `app/code/Weline/Payment/doc/payment-methods/flutterwave/mobile_money_mpesa.md`
- `app/code/Weline/Payment/doc/payment-methods/hyperpay/hyperpay.md`
- `app/code/Weline/Payment/doc/payment-methods/klarna/klarna.md`
- `app/code/Weline/Payment/doc/payment-methods/lianlian/lianlian_payin.md`
- `app/code/Weline/Payment/doc/payment-methods/manual/b2b_credit_account.md`
- `app/code/Weline/Payment/doc/payment-methods/manual/cash_on_delivery.md`
- `app/code/Weline/Payment/doc/payment-methods/manual/manual_transfer.md`
- `app/code/Weline/Payment/doc/payment-methods/mercado_pago/mercado_pago.md`
- `app/code/Weline/Payment/doc/payment-methods/midtrans/midtrans.md`
- `app/code/Weline/Payment/doc/payment-methods/nuvei/nuvei_checkout.md`
- `app/code/Weline/Payment/doc/payment-methods/payoneer/payoneer_checkout.md`
- `app/code/Weline/Payment/doc/payment-methods/paypal/paypal.md`
- `app/code/Weline/Payment/doc/payment-methods/paystack/paystack.md`
- `app/code/Weline/Payment/doc/payment-methods/paytabs/paytabs.md`
- `app/code/Weline/Payment/doc/payment-methods/pingpong/pingpong_payin.md`
- `app/code/Weline/Payment/doc/payment-methods/rapyd/rapyd_collect.md`
- `app/code/Weline/Payment/doc/payment-methods/razorpay/razorpay.md`
- `app/code/Weline/Payment/doc/payment-methods/razorpay/upi.md`
- `app/code/Weline/Payment/doc/payment-methods/stripe/ach_debit.md`
- `app/code/Weline/Payment/doc/payment-methods/stripe/acss_debit.md`
- `app/code/Weline/Payment/doc/payment-methods/stripe/bacs_debit.md`
- `app/code/Weline/Payment/doc/payment-methods/stripe/becs_debit.md`
- `app/code/Weline/Payment/doc/payment-methods/stripe/paynow.md`
- `app/code/Weline/Payment/doc/payment-methods/stripe/payto.md`
- `app/code/Weline/Payment/doc/payment-methods/stripe/stripe_checkout.md`
- `app/code/Weline/Payment/doc/payment-methods/unionpay/unionpay.md`
- `app/code/Weline/Payment/doc/payment-methods/wechatpay/wechatpay.md`
- `app/code/Weline/Payment/doc/payment-methods/wise/wise_business_transfer.md`
- `app/code/Weline/Payment/doc/payment-methods/worldpay/worldpay_hosted.md`
- `app/code/Weline/Payment/doc/payment-methods/xendit/xendit.md`
- `app/code/Weline/Payment/doc/payment-reconcile.md`
- `app/code/Weline/Payment/doc/payment-shell.md`
- `app/code/Weline/Payment/doc/payment-state.md`
- `app/code/Weline/Payment/doc/provider-development.md`
- `app/code/Weline/Payment/doc/webhook.md`
- `app/code/Weline/Payment/doc/功能现状.md`
- `app/code/Weline/Payment/doc/开发/session/payment-method-incentive-discount.md`
- `app/code/Weline/Payment/doc/开发/spec/backend-order-payment-records-chrome.md`
- `app/code/Weline/Payment/doc/开发/spec/payment-method-incentive-discount.md`
- `app/code/Weline/Payment/doc/开发/spec/paypal-google-apple-pay.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/align-freeze.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/construction-retest.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/construction.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/channel/kickoff-clarify.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/components.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/contracts.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/deps.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/UI-align.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/acceptance-prototype.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/acceptance-ui.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/align-freeze.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/主题开发工程师-align.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/前端-align.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/前端-construction.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/原型-align.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/扩展点.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/支付开发工程师-align.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/支付开发工程师-amount-parity.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/支付开发工程师-construction.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/支付开发工程师-review.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/支付开发工程师-rework-base-minor.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/支付开发工程师-rework-event.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/架构师.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/汇审.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/测试-browser.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/电商顾问.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/翻译-align.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/翻译-review.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/需求分析.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/meetings/领域探查.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/roster.md`
- `app/code/Weline/Payment/doc/开发/team/payment-method-incentive-discount/surfaces.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/channel/payment-browser-verify.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/contracts.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/meetings/align-freeze.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/meetings/支付开发工程师-browser-review.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/meetings/支付开发工程师-review.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/meetings/架构师-review.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/meetings/汇审.md`
- `app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/roster.md`
- `app/code/Weline/Payment/doc/开发/team/paypal-google-apple-pay/meetings/技术方案会.md`
- `app/code/Weline/Payment/doc/开发/team/paypal-google-apple-pay/meetings/汇审.md`
- `app/code/Weline/Payment/doc/开发/team/paypal-google-apple-pay/roster.md`
- `app/code/Weline/Payment/doc/开发/team/stripe-guide-en-us-i18n/meetings/汇审.md`
- `app/code/Weline/Payment/doc/开发日志.md`
- `app/code/Weline/Payment/doc/需求.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Payment> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
