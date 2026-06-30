---
name: payment-provider-development
description: Use when developing or reviewing a third-party payment provider module for Weline_Payment, including ProviderInterface implementation, checkout phtml, SystemConfig config phtml, PayableResolver boundaries, payment/refund idempotency, scope-aware configuration, currency/country support, and fake-mode validation.
---

# Payment Provider Development

Use this skill for any WelineFramework task that creates, migrates, reviews, or documents a payment method module connected to `Weline_Payment`.

## Primary References

Load these files only as needed:

- `app/code/Weline/Payment/doc/provider-development.md`: third-party module workflow and production checklist.
- `app/code/Weline/Payment/doc/extends.md`: compact extension examples.
- `app/code/Weline/Payment/doc/需求.md`: full payment architecture requirements and open gaps.
- `app/code/Weline/Payment/Interface/ProviderInterface.php`: required Provider contract.
- `app/code/Weline/Payment/Interface/PayableResolverInterface.php`: Payable object contract.
- `app/code/Weline/Payment/Helper/PaymentMethodAttributeHelper.php`: helper for declaring and reading ordinary payment-method EAV attributes by method code.
- `app/code/Weline/Payment/extends/module/Weline_Payment/PaymentProvider/FakeProvider.php`: local fake Provider example.
- `app/code/Weline/Payment/extends/module/Weline_SystemConfig/Config/backend/fake_card.phtml`: SystemConfig template example.

## Workflow

1. Confirm the payment method code and keep it identical across Provider `getCode()`, checkout template code, config template file, SystemConfig keys, and payment method EAV ownership.
2. Implement exactly one Provider class under `extends/module/Weline_Payment/PaymentProvider/` and make it implement `Weline\Payment\Interface\ProviderInterface`. Do not introduce a Provider abstract base class for third parties.
3. Implement every ProviderInterface method: availability, create/resume payment, authorize, capture, void, refund, query, callback verify/parse, connection test, and error normalization.
4. Declare Provider capabilities as the hard upper bound: supported currencies, default currency, supported countries, refund types, authorization/capture, saved instruments, offline confirmation, language support, amount limits, and dynamic fields.
5. Add the backend config phtml under `extends/module/Weline_SystemConfig/Config/backend/{method_code}.phtml`. Ordinary fields must use `payment/method/{method_code}/{field}` keys and be managed by `Weline_SystemConfig`, not a payment-module save controller.
6. Add or document the checkout phtml under `view/templates/Frontend/checkout/{method_code}.phtml`. The template displays method-specific UI only; payment creation still goes through `Weline_Payment`.
7. If the payable object is not a simple one-time payment, implement or route to a `PayableResolverInterface` resolver. Payment must not directly mutate order, app-market, A2A, escrow, fulfillment, or entitlement state.
8. Treat payment/refund operations as ledgered, locked, and idempotent. Require stable codes for intent, attempt, transaction, refund, allocation, and provider reference.
9. For asset-like methods or payment discounts, enforce the configured role per payable. Credit, points, and W Coin are disabled by default and require exchange ratio plus explicit payment or discount enablement.
10. For ordinary provider-specific method attributes, use `PaymentMethodAttributeHelper` and existing EAV type codes such as `input_string`; keep secrets in encrypted SystemConfig fields.
11. Verify with the nearest safe commands, then run route sync and Browser fake-mode smoke when the task affects visible checkout or payment flow.

## Guardrails

- The payment route prefix is `payment`, not `weline_payment`.
- No old payment interface compatibility is assumed.
- Provider configuration is scope-aware through `Weline_SystemConfig`; Provider modules only provide phtml templates and special controllers when needed.
- A payment method unsupported for the payable currency or user country must not be selectable in checkout. It may appear only in the disabled "more methods" view with a reason.
- Payment method EAV attributes are for ordinary provider-specific metadata. Attribute code must be provider-prefixed and owned by the same method code used by Provider/config/checkout.
- Do not store secrets in docs, logs, EAV, screenshots, or visible templates. Use encrypted SystemConfig fields for secrets.
- Do not claim global payment readiness unless fake-mode Browser validation and the specific Provider payment/refund paths were actually executed.

## Validation Commands

Use focused validation first:

```powershell
php -l app/code/Vendor/Module/extends/module/Weline_Payment/PaymentProvider/{Provider}.php
php -l app/code/Vendor/Module/extends/module/Weline_SystemConfig/Config/backend/{method_code}.phtml
php bin/w setup:upgrade --route
php bin/w route:list
php bin/w http:request /payment/frontend/checkout/fake
```

For browser-visible checkout changes, start a non-9501 WLS instance and stop it after verification:

```powershell
php bin/w server:start -p 9506 -n ai-test-payment-fake
php bin/w server:stop -n ai-test-payment-fake
```
