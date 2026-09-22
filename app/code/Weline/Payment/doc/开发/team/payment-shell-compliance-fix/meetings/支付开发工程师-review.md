# 支付开发工程师 — 施工完成审阅

slug: `payment-shell-compliance-fix`  
席位: Team:支付开发工程师:  
日期: 2026-09-22  
版本: `Weline_Payment` **1.9.99** / `Weline_Checkout` **1.5.32**

## 完成清单

| ID | 状态 | 证据 |
|----|------|------|
| FIX-P0-VERIFY | ✅ | `PayPalProvider::verifyCallback` 本地 openssl 验签 only；无 `webhook_cert_pem`+transmission → fail-closed；显式 `allow_unsigned_webhook` 才放宽。源码断言无出站。 |
| FIX-P0-SECRET | ✅ | `paypal.phtml` sandbox/live `*_client_secret`；`stripe.phtml` sandbox/live `*_secret_key` / `*_webhook_secret` → `type="secret"` + `value-type="encrypted"`。 |
| FIX-P1-SOFT | ✅ | 删除「无签仅凭 event id verified」；与 VERIFY 同测。 |
| FIX-P1-EXPRESS | ✅ | `PaymentExpressFacadeInterface::abandonExpressPayment` + `ExpressCheckoutOrchestrator` 实现；`ExpressCheckoutFlowService::abandon` 只调 Facade，订单取消仍在 Checkout。 |
| FIX-P1-REFUND | ✅ | `PaymentService::refund(..., int $amountMinor, ...)`；`refundWithMajorAmount` deprecated float 包装。仓内无旧 float 调用方。 |

## MUST NOT（本波遵守）

- 未开启 `PaymentFacadeV2` entryEnabled  
- 未新建渠道 Controller  
- 未实现壳侧远程核验 B  
- 未大迁 ApiClient 命名空间  

## 残留（汇审可知）

- `ExpressCheckoutFlowService::markConfirmCapture` 仍写 `PaymentTransaction`（已标 TODO）；非 abandon 路径，建议下波迁入 Express Facade。

## 跑测

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Payment/Test/Unit/bootstrap.php \
  app/code/Weline/Payment/Test/Unit/Service/PayPalWebhookVerifyContractTest.php \
  app/code/Weline/Payment/Test/Unit/Service/ExpressCheckoutOrchestratorContractTest.php \
  app/code/Weline/Payment/Test/Unit/Service/PaymentServiceRefundMinorContractTest.php \
  app/code/Weline/Payment/Test/Unit/View/PayPalConfigTemplateContractTest.php \
  app/code/Weline/Payment/Test/Unit/View/StripeConfigSecretEncryptedContractTest.php
```

结果: **OK (20 tests, 144 assertions)**

## Paths 变更摘要

- `PaymentProvider/PayPalProvider.php` — verifyCallback 机制 A  
- `Config/backend/paypal.phtml` / `stripe.phtml` — encrypted secrets  
- `Api/PaymentExpressFacadeInterface.php` + `Service/ExpressCheckoutOrchestrator.php` — abandonExpressPayment  
- `Checkout/Service/ExpressCheckoutFlowService.php` — abandon 经 Facade  
- `Service/PaymentService.php` — refund amount_minor  
- 契约单测若干；`etc/module.php` 版本 bump  

## 失败项

无。
