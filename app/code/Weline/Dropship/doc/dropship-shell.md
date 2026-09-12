# 万能货源壳（Weline_Dropship）

`Weline_Dropship` 是唯一货源代发内核（壳）。供应商能力通过 Extends `DropshipProvider` 按 `code`（=`dropship_source`）接入。壳拥有统一菜单/表/Inbox/编排与配置托管；Provider 只实现供应商差异。

对齐 [Payment payment-shell.md](../../Payment/doc/payment-shell.md)。MCP 硬约束：`shell_provider_business_isomorph`。

相关：[README.md](README.md)、[provider-development.md](provider-development.md)、[extends.md](extends.md)。

## 1. 边界

### 壳必须拥有

| 职责 | 说明 |
|---|---|
| 身份路由 | Provider `code` / `endpoint_code` |
| 统一 URL | Webhook `dropship/frontend/callback/notify?endpoint_code={code}.{env}.default` |
| 编排与状态 | Listing 投影、推单 Outbox、履约、Inbox 幂等 |
| 配置托管 | 壳级 `dropship/platforms/*`、`dropship/pricing/*`、`dropship/ops/*`；渠道凭证键由 Provider 声明 |
| 后台壳页 | 货源平台 / 仓映射 / 货源商品（选品器） / 货源配置等 Controller **只编排** |
| 选品器 | 左轨已启用 Provider；中栏/国家按 `capabilities.browse` + `browse_country_filter` 门控；BrowseQuery 规范化；DraftAdmit 校验同 `provider_code` |

### Provider 必须拥有

| 职责 | 说明 |
|---|---|
| `DropshipProviderInterface` | code / capabilities / metadata / config schema / probe |
| 能力子接口 | Catalog / Fulfillment / Freight / Webhook / **Warehouse**（按需） |
| 专属配置 | SystemConfig 模板 + `config_center` |
| 供应商差异 | API、凭证解析、载荷归一化、**远程仓表与拉取**——**禁止**写回壳 Controller |

### 禁止

- 壳 Controller / 壳 Service 硬编码某供应商网关（如直接依赖 `CjApiClient` 业务路径）。
- 为每个供应商新写一套业务控制器或在壳内重写探活/目录/履约/回调解析。
- 壳表/Service 使用供应商命名字段（如 `cj_*`）或消费供应商私有 Webhook 键；仓映射用 `remote_country_code` / `remote_storage_id`。
- Catalog Provider 直接写 listing/Product/Inventory（只返回 `DropshipCatalogSnapshot`）。
- 对接新供应商时用「改壳」代替 Extends Provider。
- Listing/Settings 默认写死某一供应商 code。

## 2. URL 切换（对齐 Payment）

| 入口 | 参数 |
|------|------|
| 后台探活 / 列表 | `provider_code` |
| 搜索货源 | `provider_code`（兼容别名 `provider`；默认=第一个已注册 Provider，不写死） |
| Webhook | `endpoint_code={provider}.{env}.default` |
| Provider 扩展控制器 | `dropship/frontend|backend/provider-gateway/dispatch?provider_code={code}&action={kebab}` → Provider `controller{Action}` |

### Provider `controller*`（特殊入口）

在 Extends Provider 上声明 `public function controllerXxx(array $params)`，壳 Gateway 按 URL `action` 调度。  
**禁止**占用 `notify` / `callback` / `webhook`（壳 Webhook 专用）。  
**禁止**在供应商模块为货源业务新建 MVC Controller；扩展 HTTP 只走 Gateway。

仓映射字段只有 `remote_country_code` / `remote_storage_id`；**无** `cj_*` 读兜底。升级脚本一次性迁旧列后删除。

## 3. 第三方最小交付

1. `extends/module/Weline_Dropship/DropshipProvider/{X}Provider.php`
2. `extends/module/Weline_SystemConfig/Config/backend/{code}.phtml`（有凭证时）

`getCode()`、配置模板、商品 `dropship_source` 三者一致。细节见 [provider-development.md](provider-development.md)。
