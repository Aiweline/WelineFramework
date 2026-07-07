<!-- weline:module-ai-index:auto-generated -->
# Weline_Payment AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-ai-indexes.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. `dev/ai/global-constraints.md`
3. `dev/ai/diagrams/08-module-docs-index.txt`
4. 本文件：`app/code/Weline/Payment/doc/AI-INDEX.md`
5. 模块说明：`app/code/Weline/Payment/doc/README.md`
6. `app/code/Weline/Theme/doc/AI-INDEX.md`
7. `app/code/Weline/Frontend/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Payment`
- 目录：`app/code/Weline/Payment`
- Vendor：`Weline`
- Module：`Payment`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Payment/etc/module.xml`
- `app/code/Weline/Payment/etc/backend/menu.xml`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：22
- `Block`：视图数据块。配合模板输出页面数据，变更前要读对应模板和 layout。 文件数：2
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后运行 setup:upgrade --route，同步路由。 文件数：6
- `Helper`：模块内辅助能力。跨模块不要直接调用未发布 Helper。 文件数：1
- `Interface`：模块发布的接口契约。跨模块依赖优先使用这里的稳定契约。 文件数：3
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：13
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：15
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：2
- `etc`：模块配置。禁止 routes.xml；路由由控制器和 setup:upgrade --route 生成。 文件数：3
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：5
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：8
- `view/tpl`：模板编译/生成产物。禁止直接修改。 文件数：0

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 相关 PHP 文件：extends/module/Weline_Framework/Query/PaymentQueryProvider.php；前端/跨模块读数据先查 query 帮助。

## doc 目录

- `app/code/Weline/Payment/doc/README.md`
- `app/code/Weline/Payment/doc/extends.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-form-after.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-form-before.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-methods-after.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-methods-before.md`
- `app/code/Weline/Payment/doc/hook/frontend/checkout/payment-result.md`
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
- `app/code/Weline/Payment/doc/provider-development.md`
- `app/code/Weline/Payment/doc/需求.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Payment> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
