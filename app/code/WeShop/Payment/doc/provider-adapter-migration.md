# WeShop_Payment Provider adapter migration

## Current adapter slice

- `cash_on_delivery` now has a `Weline\Payment\Interface\ProviderInterface` adapter under `extends/module/Weline_Payment/PaymentProvider/`.
- The adapter keeps `WeShop\Payment\Provider\CashOnDelivery` as the legacy implementation only for static config testing, callbacks, query status, and optional legacy-order execution.
- New Weline Payment calls receive `PaymentResult`, `AvailabilityResult`, `CallbackResult`, `RefundResult`, and `ProviderError` DTOs instead of the old WeShop array contract.

## Config scope boundary

- WeShop_Payment does not add its own scope selector or save UI in this adapter slice.
- Ordinary config fields are exposed only through `extends/module/Weline_SystemConfig/Config/backend/*.phtml`.
- SystemConfig owns scope selection, inheritance, field save, validation, audit, and cache invalidation.
- The old backend save controller has been removed. The WeShop payment management surface is read-only for ordinary key/value config and should link operators to the SystemConfig center.

## Remaining old chain

- `WeShop\Payment\Interface\PaymentProviderInterface` is still used by the existing WeShop management and checkout services.
- Existing providers under `WeShop\Payment\Provider\` still implement the old three-method contract until each method receives its own new-core adapter.
- `WeShop\Payment\Service\PaymentService` still exposes the existing method catalogue while each method is migrated to the new Provider adapter.
- `WeShop\Payment\Service\PaymentManagementService` no longer saves ordinary payment config; `save()` fails fast so new work cannot write scoped method profiles outside SystemConfig.
- The old backend payment management page remains a read-only complex-object adapter surface, not a replacement for SystemConfig scope/save behavior.
