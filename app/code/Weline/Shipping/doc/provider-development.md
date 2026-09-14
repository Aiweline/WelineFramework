# Shipping Provider 开发摘要

1. 继承 `Weline\Shipping\Service\Provider\AbstractShippingProvider`。
2. 放在 `extends/module/Weline_Shipping/ShippingProvider/{Name}Provider.php`。
3. `getCode()` 与 SystemConfig 模板文件名、`Carrier.provider_code` 一致。
4. 有能力的方法必须 override；其余留给基类 `unsupported`。
5. 禁止在壳 Service/Controller 写供应商专用逻辑；详见 [shipping-shell.md](shipping-shell.md)。
