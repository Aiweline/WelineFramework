# Weline_Websites 模块扩展点

## 域名商适配器 (Registrar)

### 概述

Weline_Websites 模块提供域名商适配器扩展点，允许第三方模块接入新的域名注册商 API。

### 接口

所有域名商适配器必须实现 `Weline\Websites\Api\DomainRegistrarInterface` 接口（含 **`checkFqdnOriginPointsToServer`**：按该供应商权威解析记录判断 FQDN 的 A/AAAA 是否指向本机源站）。多数供应商可在适配器里 `use Weline\Websites\Adapter\Concern\DefaultDnsZoneOriginMatchTrait`；若 API 返回的 host 形态特殊，请自行实现该方法（参考 `GnameRegistrar`）。

### 快速开始

1. 在你的模块中创建适配器文件：

```
your_module/extends/module/Weline_Websites/Registrar/YourRegistrar.php
```

2. 实现 `DomainRegistrarInterface` 接口：

```php
<?php
namespace YourVendor\YourModule\Extends\Module\Weline_Websites\Registrar;

use Weline\Websites\Api\DomainRegistrarInterface;

class YourRegistrar implements DomainRegistrarInterface
{
    public function getRegistrarCode(): string { return 'your_registrar'; }
    public function getRegistrarName(): string { return 'Your Registrar'; }
    // ... 实现其他方法
}
```

3. 运行 `php bin/m s:up` 注册扩展。

### 内置适配器

| 适配器 | 代码 | 说明 |
|--------|------|------|
| AWS Route53 | `aws_route53` | Amazon Web Services 域名服务 |
| 阿里云域名 | `aliyun_domain` | 阿里云域名注册服务 |
| Azure DNS | `azure_dns` | Microsoft Azure 域名服务 |

---

## 域名购买与后台 / WLS / 生命周期（对齐说明）

以下链路在现行代码下**仍按原设计衔接**，购买侧改动未改表结构或 Cron 入口名。

### 1. 后台管理（Admin Domain）

- **购买**：`DomainPurchaseService::createAndProcessOrder` → 适配器 `purchaseDomain` → 成功则入 **Domain** / **DomainPool**、可选 **WebsiteDomain**、可选 **DomainAutoResolveTask**。
- **列表 / 同步 / DNS**：仍走 `DomainSyncService`、`DomainResolveService`、`WebsitesQueryProvider`，依赖 **`DomainRegistrarInterface`**（单一接口），与删除的旧拆分接口无关。

### 2. WLS（Worker / 证书）

- **HTTPS / ACME**：`SslCertificateService` 仍按 **DomainPool**（含 `root_domain`）拉子域参与证书；与购买后 `addToDomainPoolWithSubdomains` 一致。
- **站点域名同步**：`Server` 侧 `WebsiteDomain` 同步与购买里 **bindToWebsite** 写入的站点域名一致。

### 3. 生命周期编排（可选）

- 购买成功且勾选 **启动全流程**（或 API `start_lifecycle`）：`DomainPurchaseService` 内先调 **`DomainLifecycleOrchestrationService::startPurchasedLifecycle`**。
- 事件 **`Weline_Websites::domain::purchase_success`** → **`DomainPurchaseSuccess` Observer**：
  - **仅当** `start_lifecycle` 为真，且服务内尚未成功建单时，才再次调用 `startPurchasedLifecycle`（作补救）；已建单则跳过，避免重复 `processOrder`。
  - **未勾选**生命周期时 Observer **不再**强行创建配置订单（`ProvisioningOrder`），与后台弹窗一致。
- 编排后续步骤：**DNS → 解析校验 → 验证 → SSL** 等仍由 **`DomainLifecycleOrchestration` Cron** 与 `processPendingOrders` 推进。

### 4. 阿里云待支付

- 购买接口返回 **未成功入池**（`success=false` + 任务号）时，不会走完整「已注册」后续；需在控制台付款后再 **同步域名 / 手动入池** 或走导入流程。

### 5. 集成方注意

- Query **`purchaseDomain`** 若需 GoDaddy/AWS/阿里云联系人：传 **`purchase_contact`** 或配置 **`Weline_Websites/etc/env.php`** 的 **`domain_purchase_default_contact`**；阿里云建议传 **`client_ip`**。

---

## 定时任务 / SSE / 聚合器 与新版域名抽象对接

| 入口 | 购买 | DNS/NS 操作 | 说明 |
|------|------|-------------|------|
| **Cron `DomainAutoResolve`** | 否 | **`getAdapter` → `addDnsRecord`** | 仅解析任务，走统一适配器。 |
| **Cron `DnsCdnAutoSwitch` + `DnsSwitchService`** | 否 | **`executeDnsSwitchWithStandardOptions` → `executeDnsSwitch`** | 与 Admin `postSwitchDnsAccount`/SSE 同默认 options；PageBuilder SSE 在 `buildStandardSwitchOptions` 上 merge。 |
| **DnsSwitchService 记录搬迁** | — | **Step1** 优先从 **`dns_account_id`≠目标** 的托管账户 `getDnsRecords`，否则注册商；**Step4** `pushRecordsToProvider` 以 Step1 后本地库为准（`records_to_push` 仅兜底）。 |
| **`dns_account_id` 补全** | **`DomainPurchaseService` 落库前** | **`DomainResolveService::ensureDnsAccountIdPersisted`** | 另：**注册商 `syncAccount` 拉域后**逐条补全；**`executeDnsSwitch` Step1 前**再补；**`getDnsManagementAccount`** 成功且仍为 0 时写入当前所用账户。 |
| **铁律：委派 NS** | — | **`Domain.account_id`** 对应注册商 → `sourceAdapter->updateNameservers` | 改注册局 NS 只用注册商账户；**`dns_account_id`** 仅为托管目标（Zone/记录），`CloudflareRegistrar::updateNameservers` 固定失败并提示去注册商。 |
| **Cron `DomainLifecycleOrchestration` 等** | 否 | 经 **`DomainResolveService` / Pool** | 不直接 purchase；依赖已入池域名。 |
| **Admin `Domain` 控制器** | **`DomainPurchaseService`** | **`DomainRegistrarResolverService` / `w_query`** | 与抽象一致。 |
| **SSE `SiteBuilderAgent::getTriggerSse`** | **`WebsiteAgentService` → `createAndProcessOrder`** | 后续解析/证书同全局链路 | 已透传 **`user_client_ip`**；联系人仍靠 **env 默认** 或账号（如 Gname）。 |
| **AI 工具 `PurchaseDomainAndBuildSiteTool`** | 同上 | 同上 | 无 Request 时无 client_ip；请配 **env `domain_purchase_default_contact`**。 |
| **`QuickBuildAggregator::purchaseDomain`** | **`w_query('websites','purchaseDomain')`** | 同 Query | 支持 **`options.client_ip`、`options.purchase_contact`**。 |
| **PageBuilder `DomainManagement` 购买 AJAX** | 经聚合器 | 同上 | 自动附带 **客户端 IP**；可选 POST **`purchase_contact` JSON**。 |

**结论**：定时 DNS 类任务与 **SSE/QuickBuild/后台** 购买路径均已落到 **`DomainRegistrarInterface` + `WebsitesQueryProvider`**（或 `DomainPurchaseService`），与新版单一抽象一致；未再走已移除的拆分接口。
