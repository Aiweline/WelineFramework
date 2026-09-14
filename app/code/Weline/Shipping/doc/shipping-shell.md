# 万能配送壳（Weline_Shipping）

`Weline_Shipping` 是唯一配送内核（壳）。结账/打单/轨迹通过壳编排；供应商差异通过 Provider 按 `provider_code` 接入。壳拥有匹配、配置托管、幂等与 Provider 发现；Provider **继承** `AbstractShippingProvider` 并实现完整能力面。

相关：[需求.md](需求.md)（SHIP-SHELL-001）、[功能现状.md](功能现状.md)、[provider-development.md](provider-development.md)。

## 1. 边界

### 壳必须拥有

| 职责 | 说明 |
|---|---|
| 身份路由 | `Carrier.provider_code`（空=`local`）→ Provider 实例 |
| 匹配编排 | 禁运 → 可售 → 承运商覆盖 → 航线（发货锚点） |
| 配置托管 | `shipping/carrier/{provider_code}/*`（SystemConfig） |
| 统一入口 | `ShippingFacade`：`listRates` / `createShipment` / `cancelShipment` / `confirmShipment` / `getLabel` / `queryTracking` / `testConnection` |
| Provider 发现 | Extends 扫描 `ShippingProvider/*.php` |
| 结果合并 | 多 Provider 报价合并 + diagnostics；幂等打单键 |

### Provider 必须拥有

| 职责 | 说明 |
|---|---|
| `ShippingProviderInterface` 全方法 | 身份、元数据、可用性、覆盖种子、报价、运单、面单、轨迹、回调、测连、错误归一 |
| 继承 `AbstractShippingProvider` | 未支持能力返回 `unsupported`；有能力的方法 override |
| 专属配置 | schema + `extends/.../Weline_SystemConfig/Config/backend/{code}.phtml` |
| CSP | `cspDirectives()` 自报；禁止壳硬编码供应商域名 |

### 禁止

- 壳 `ShippingServiceManager` / `TrackingService` / Controller **硬编码**某供应商（字面 `yanwen`、开放平台 URL、签名、产品号映射）。
- 壳内 `if (provider === 'local')` 计价分叉；Local 也必须经 `Provider::quote()`。
- Checkout / 业务层直连供应商 SDK。
- 新 Provider 不继承抽象基类、或只实现部分接口方法却省略声明。

## 2. 完整能力面

见 `Interface/ShippingProviderInterface`：`getCode` / `getProviderCode` / `getProviderApiVersion` / `getCapabilities` / `getDisplayMetadata` / `getConfigSchema` / `getDynamicFormSchema` / `cspDirectives` / `checkAvailability` / `defaultCoverageRegions` / `quote` / `createShipment` / `cancelShipment` / `confirmShipment` / `getLabel` / `queryTracking` / `verifyCallback` / `parseCallback` / `testConnection` / `normalizeError`。

## 3. 内置 Provider

| code | 类 | 说明 |
|---|---|---|
| `local` | `LocalRateTemplateProvider` | 费用模板 + FX；覆盖由后台配置（`defaultCoverageRegions` 空） |
| `yanwen` | `YanwenProvider` | 询价/打单/轨迹；**可达覆盖写死为国家级 ISO**（非省市区），见 `YanwenDestinationCoverage` |

供应商可达范围必须在 Provider `defaultCoverageRegions()` 配置死；壳按 `Carrier.provider_code` 种子/启用，禁止混并到 local 默认目录。

## 4. 第三方最小交付

1. `extends/module/Weline_Shipping/ShippingProvider/{X}Provider.php`（`extends AbstractShippingProvider`）
2. `extends/module/Weline_SystemConfig/Config/backend/{provider_code}.phtml`
3. 承运商行 `provider_code` 指向该 code（或 Upgrade 种子）

`getCode()`、配置文件名、`Carrier.provider_code` 三者一致。
