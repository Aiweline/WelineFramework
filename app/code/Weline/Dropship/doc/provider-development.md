# 如何新增货源供应商

对齐 [Payment provider-development](../../Payment/doc/provider-development.md) 的壳+Provider 模式。
硬边界见 [dropship-shell.md](dropship-shell.md)。MCP 硬约束：`shell_provider_business_isomorph`。

## 0. 硬性同构（禁止业务重写）

| 允许 | 禁止 |
|------|------|
| **一个** Extends Provider 类承载该供应商业务（目录/履约/运费/Webhook/探活/配置 schema），按需实现子接口 | 在 `Weline_Dropship` Controller / 壳 Service 里按供应商重写 API、凭证解析、履约推进、目录抓取 |
| Provider 调用本模块私有 Client/Helper（如 `CjApiClient`） | 壳硬编码 `cj`/`fake` 网关细节或为每家新开业务控制器 |
| 壳 Controller 编排：扫 Provider、ACL、Inbox、统一回调 URL、表、范围工具；用 **`provider_code` / `endpoint_code`** URL 参数切换 | 对接新供应商时改壳业务逻辑代替 Provider |

对接标准交付物：**Provider + SystemConfig 凭证模板**（与万能支付同构）。

壳 HTTP 在 `Weline_Dropship`（万能）；供应商模块**不要**为货源业务再写一套 Controller。  
特殊扩展入口：在 Provider 上写 `controllerXxx`，经壳  
`dropship/frontend|backend/provider-gateway/dispatch?provider_code={code}&action={kebab-from-Xxx}` 调度。

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
| `getCapabilities()` | catalog/**browse**/**browse_country_filter**/fulfillment/freight/webhook/**warehouse** |
| `getDisplayMetadata()` | title/module/sort_order |
| `getConfigSchema()` | 字段提示 |
| `probeConnection()` | 探活 |

按能力再实现 Catalog / Fulfillment / Freight / Webhook / Warehouse 子接口。Catalog 只返回 `DropshipCatalogSnapshot`，**禁止**写 listing/Product/Inventory。

### 选品器（壳兼容）

- 选品 UI 只消费 SPI：实现 `DropshipCatalogProviderInterface` 即可进选品器；可选 `DropshipCatalogBrowseProviderInterface::listCategories()`。
- `getCapabilities()` 声明：`browse=true`（且实现 Browse 接口）才渲染分类栏；`browse_country_filter=false` 时壳隐藏国家控件。
- 壳规范化 BrowseQuery 仅含：`country_code?` / `category_id?` / `keyword?` / `page` / `size`。**禁止**壳下发供应商私有键（如 `lv3`/`cj_*`）。
- `listCategories` 节点：`id` / `name` / `parent_id` / `level` / `path`；壳按父子渲染树或扁平列表。
- 壳层会用 `DropshipCatalogCategoryCacheService` 按 provider+locale+country 缓存 `listCategories`（默认 TTL 24h）；Provider 无需自建缓存。`refresh_categories=1` 可强制刷新。
- Snapshot 一等字段：`category_id` / `category_path`（勿用壳可读的供应商私有 EAV 键走私分类）。
- **语言**：BrowseQuery 可选 `locale`（壳注入 `State::getLangLocal()`）。Provider 按 locale 选择商品/分类展示名；无该语种时回退另一语种。CJ 分类接口若仅返回单语名称则原样展示。

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
壳流程：`verifyWebhook`（纯校验）→ `parseWebhook`（纯解析）→ Inbox → 履约投影。

`parseWebhook` **必须**返回壳标准 `fulfillment`：

| 键 | 说明 |
|----|------|
| `external_order_id` | 远端订单号 |
| `order_uuid` | 本站订单 UUID（若有） |
| `tracking_number` | 运单号 |
| `carrier` | 承运商 |
| `status` | 履约状态 |

供应商私有键（如 CJ 的 `orderId`/`trackNumber`）只在 Provider 内映射，**禁止**写回壳 Inbox 消费逻辑。

- Fake / CJ 均实现 `DropshipWebhookProviderInterface`；货源平台「回调地址」可复制 Hook URL。

## 4.1 远程仓（可选）

实现 `DropshipWarehouseProviderInterface`，`getCapabilities()['warehouse']=true`：

| 方法 | 说明 |
|------|------|
| `listWarehouses($context)` | 读 **本 Provider 私有仓表**，返回 `[{value,label,country_code?}]` |
| `pullWarehouses($context)` | 调供应商 API 写入私有仓表后返回同形列表 |

仓主数据归属各 Provider（如 Fake `weline_dropship_fake_warehouse`、CJ `weline_cj_warehouse`）。壳仅提供 `dropship/backend/warehouse/remoteSearch|remotePull` 编排与 `<w:dropship:remote-warehouse:select>`。

`listWarehouses` 可选 `context.country_code` 过滤。`pullWarehouses` 失败应抛可读异常（壳 `postRemotePull` 透出 toast），勿空吞。CJ 官方 `globalWarehouseList` 以 `areaId` 为仓标识（有 `storageId` 时优先）；`createOrderV3` 主字段 `fromCountryCode`，`storageId` 可选。

## 5. 样板

- 壳内置：`FakeDropshipProvider`（dev/test）
- 默认：`Weline_CjDropshipping` → `CjProvider`
