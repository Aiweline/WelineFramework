# Extends · DropshipProvider

见模块根 `extends.php`：

- path: `extends/module/Weline_Dropship/DropshipProvider`
- interface: `Weline\Dropship\Interface\DropshipProviderInterface`
- multiple: true

能力子接口（按需）：

- `DropshipCatalogProviderInterface`
- `DropshipFulfillmentProviderInterface`
- `DropshipFreightProviderInterface`
- `DropshipWebhookProviderInterface`

Scanner 要求：声明的 capabilities ⊆ 已实现子接口；`getCode()` 即 `dropship_source` 标记。
