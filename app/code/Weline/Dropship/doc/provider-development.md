# 如何新增货源供应商

对齐 [Payment provider-development](../../Payment/doc/provider-development.md) 的壳+Provider 模式。

## 1. 最小模块

```text
app/code/Vendor/YourSource/
├─ register.php
├─ etc/module.php          # requires: Weline_Dropship
├─ extends/module/Weline_Dropship/DropshipProvider/YourSourceProvider.php
├─ extends/module/Weline_SystemConfig/Config/backend/your_source.phtml
└─ doc/{README,需求,功能现状,开发日志}.md
```

## 2. Provider

命名空间：`Vendor\YourSource\Extends\Module\Weline_Dropship\DropshipProvider`

必选实现 `DropshipProviderInterface`：

| 方法 | 说明 |
|------|------|
| `getCode()` | 稳定 code，且等于商品 `dropship_source` |
| `getCapabilities()` | catalog/fulfillment/freight/webhook |
| `getDisplayMetadata()` | title/module/sort_order |
| `getConfigSchema()` | 字段提示 |
| `probeConnection()` | 探活 |

按能力再实现 Catalog / Fulfillment / Freight / Webhook 子接口。Catalog 只返回 `DropshipCatalogSnapshot`，**禁止**写 listing/Product/Inventory。

## 3. 配置

凭证键建议：`dropship/channel/{code}/*`。范围启用由壳 `dropship/platforms/enabled` 多选控制。

`getConfigSchema()` 可声明 `config_center`，供货源平台列表「配置凭证」：**优先**页内 `w-dialog`（含 `<w:scope>` 作用范围）+ `<w:config:embed group>` 编辑；深链统一配置中心作次要入口（**禁止**链到壳 `dropship/backend/config`）。

- `module` / `area` / **`group`**（配置模板里 `<w:config:group code="...">`）→ 弹窗 embed 必填
- `guide_key` / `guide_title` → 深链定位与弹窗标题
- 弹窗作用范围走 URL `target_scope`（与 Affiliate/货源配置壳一致）；供应商凭证通常 `scope="global"` 全局一套
- 凭证字段若需在 embed 内可编辑，**勿**用 `type="password"`（embed 会变 `sensitive_readonly`），用 `text` 即可

```php
return [
    'fields' => ['email', 'api_key'],
    'config_center' => [
        'module' => 'Vendor_YourSource',
        'area' => 'backend',
        'group' => 'dropship_channel_your',
        'guide_key' => 'dropship/channel/your/email',
        'guide_title' => 'YourSource 凭证',
        'guide_summary' => '填写 API 凭证后可探活。',
    ],
];
```

## 4. Webhook

统一：`dropship/frontend/callback/notify?endpoint_code={code}.{env}.default`  
Provider `parseWebhook` 纯函数；壳写 Inbox 并推进履约。

- Fake / CJ 均实现 `DropshipWebhookProviderInterface`；货源平台「回调地址」可复制 Hook URL。
- 履约投影键建议：`orderId` / `trackNumber` / `logisticName` / `orderStatus`（与 CJ 载荷同形；Fake 也会把 `external_order_id`/`tracking`/`carrier`/`status` 归一化）。

## 5. 样板

- 壳内置：`FakeDropshipProvider`（dev/test）
- 默认：`Weline_CjDropshipping` → `CjProvider`
