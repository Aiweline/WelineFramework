# 域名服务适配器统一抽象重构计划

**状态**：🔵 测试中（status: testing）  
**完成度**：90%  
**最后更新**：2026-03-13

---

## 一、背景与目标

### 1.1 现状问题

当前 `DomainRegistrarInterface` 已定义 21 个方法，但实际业务中有大量功能散落在各适配器的非接口方法中，导致：

1. **`modifyDns()` 不在接口中**：GName 独有，调用方通过 `method_exists()` 做运行时检查，其他适配器无法支持
2. **`addZone()` 是 Cloudflare 私有方法**：被 `getProviderNameservers()` 内部调用，但概念（先创建 Zone 再获取 NS）未抽象
3. **`getHostedDomainList()`**：Cloudflare 独有，定义但从未被外部调用
4. **`getTemplates()`、`getTldPrices()`、`getBalance()`**：GName 独有，未抽象
5. **职责混杂**：`DomainRegistrarInterface` 一个接口涵盖了「域名注册」「DNS 管理」「NS 切换」「供应商元数据」四类职责，新供应商实现负担重
6. **`modifyDns` vs `updateNameservers`**：功能重叠但签名不同（string vs array），调用方不统一

### 1.2 目标

将所有适配器实际用到的能力统一抽象，使得新增任何域名商/DNS 服务商时：

- **只需实现标准接口**，无需 `method_exists()` 运行时检查
- **按角色声明能力**（注册商 / DNS 服务商 / CDN 服务商），可选实现
- **统一服务层**屏蔽驱动差异，业务层只调服务不调适配器

---

## 二、决策自审

### 决策：拆分接口为 Base + 能力接口组合

#### 自审分析

| 问题 | 分析 |
|------|------|
| **为什么这么做？** | 当前单一接口 21 个方法太重，DNS-only 供应商（如 Cloudflare）要实现一堆空方法；按能力拆分后，每个适配器只实现自己支持的接口 |
| **收益** | 1. 新增供应商门槛降低 2. 消除 `method_exists()` 运行时检查 3. 类型安全的能力检查（`instanceof`） 4. 各能力独立演进 |
| **缺陷/风险** | 1. 现有 5 个适配器需要调整 `implements` 声明 2. 调用方需从 `method_exists` 改为 `instanceof` 3. 接口拆分后不向后兼容（但现有适配器都在内部，影响可控） |
| **影响范围** | Adapter/*.php（5 个）、DomainSyncService、WebsitesQueryProvider、DnsCdnAutoSwitch、DomainProvisioningService、DomainResolveService、Controller/Admin/Domain |
| **关联模块** | Weline_Websites、GuoLaiRen_PageBuilder |
| **应对方案** | 保留 `DomainRegistrarInterface` 继承组合新接口，旧代码可平滑迁移；先加接口、后改调用方 |
| **安全隐患** | 无新增安全风险，纯重构 |
| **命中技能** | code-generation-standards、module-development |

#### 结论

采用「基础接口 + 能力接口」组合模式，`DomainRegistrarInterface` 作为聚合接口保持不变（向后兼容），新增独立能力接口供类型检查。

---

## 三、架构设计

### 3.1 接口拆分

```
Api/
├── ProviderInfoInterface.php        ← 供应商元数据（code、name、config、version）
├── DomainPurchaseInterface.php      ← 域名购买能力（check、purchase、list、detail）
├── DnsManagementInterface.php       ← DNS 记录管理（CRUD、批量）
├── NameserverSwitchInterface.php    ← NS 切换能力（update NS、get provider NS）
├── AccountInfoInterface.php         ← 账户信息（余额、模板、TLD 价格）
├── ZoneManagementInterface.php      ← Zone 管理（Cloudflare 类供应商需要先创建 Zone）
└── DomainRegistrarInterface.php     ← 聚合接口（extends 以上所有，向后兼容）
```

### 3.2 各接口定义

#### ProviderInfoInterface（必须实现）

```php
interface ProviderInfoInterface
{
    public function getRegistrarCode(): string;
    public function getRegistrarName(): string;
    public function getDescription(): string;
    public function getVersion(): string;
    public function getConfigFields(): array;
    public function getConfigHelp(): array;
    public function testConnection(array $credentials): bool;
    public function isDomainRegistrar(): bool;
}
```

#### DomainPurchaseInterface（域名注册商实现）

```php
interface DomainPurchaseInterface
{
    public function checkAvailability(string $domain, array $credentials): array;
    public function batchCheckAvailability(array $domains, array $credentials): array;
    public function purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array;
    public function getDomainList(array $credentials): array;
    public function getDomainDetail(string $domain, array $credentials): array;
}
```

#### DnsManagementInterface（支持 DNS 管理的供应商实现）

```php
interface DnsManagementInterface
{
    public function supportsDnsManagement(): bool;
    public function getDnsRecords(string $domain, array $credentials): array;
    public function addDnsRecord(string $domain, array $record, array $credentials): array;
    public function updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array;
    public function deleteDnsRecord(string $domain, string $recordId, array $credentials): array;
    public function batchAddDnsRecords(string $domain, array $records, array $credentials): array;
}
```

#### NameserverSwitchInterface（NS 切换能力）

```php
interface NameserverSwitchInterface
{
    /**
     * 在注册商处修改域名的 Nameserver
     * 统一入口，替代原 modifyDns()
     */
    public function updateNameservers(string $domain, array $nameservers, array $credentials): array;

    /**
     * 获取该供应商分配的 Nameserver
     * Cloudflare 类供应商需要传 domain 参数（会自动 addZone）
     */
    public function getProviderNameservers(array $credentials, string $domain = ''): array;
}
```

#### AccountInfoInterface（可选，账户信息）

```php
interface AccountInfoInterface
{
    public function getAccountBalance(array $credentials): array;
    public function getTldPrices(array $credentials): array;
    public function getContactTemplates(array $credentials): array;
}
```

#### ZoneManagementInterface（可选，Zone 管理 — Cloudflare 等需先创建 Zone）

```php
interface ZoneManagementInterface
{
    public function addZone(string $domain, array $credentials): array;
    public function getHostedDomainList(array $credentials): array;
}
```

#### DomainRegistrarInterface（聚合，向后兼容）

```php
interface DomainRegistrarInterface extends
    ProviderInfoInterface,
    DomainPurchaseInterface,
    DnsManagementInterface,
    NameserverSwitchInterface
{
    // 保持空 body，聚合继承即可
    // 现有所有适配器仍 implements DomainRegistrarInterface，无需改动
}
```

### 3.3 各适配器实现矩阵

| 适配器 | ProviderInfo | DomainPurchase | DnsManagement | NameserverSwitch | AccountInfo | ZoneManagement |
|--------|:---:|:---:|:---:|:---:|:---:|:---:|
| GnameRegistrar | ✅ | ✅ | ✅ | ✅ | ✅ 新增 | ❌ |
| CloudflareRegistrar | ✅ | ✅(空壳) | ✅ | ✅ | ❌ | ✅ 新增 |
| AliyunDomainRegistrar | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| AwsRoute53Registrar | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| AzureDnsRegistrar | ✅ | ✅(空壳) | ✅ | ✅ | ❌ | ❌ |

### 3.4 调用方迁移

| 调用方 | 当前写法 | 迁移后写法 |
|--------|----------|-----------|
| `DomainSyncService::batchChangeDns()` | `method_exists($adapter, 'modifyDns')` → `$adapter->modifyDns(domain, commaString, creds)` | `$adapter instanceof NameserverSwitchInterface` → `$adapter->updateNameservers(domain, array, creds)` |
| `WebsitesQueryProvider::modifyDns()` | `method_exists($adapter, 'modifyDns')` → `call_user_func(...)` | `$adapter instanceof NameserverSwitchInterface` → `$adapter->updateNameservers(...)` |
| `DnsCdnAutoSwitch::processDomain()` | 直接调 `$targetAdapter->getProviderNameservers()` / `$sourceAdapter->updateNameservers()` | 不变（已用接口方法） |
| `DomainResolveService` | 直接调接口方法 | 不变 |

### 3.5 消除 modifyDns

`modifyDns(string $domain, string $dnsServers, array $credentials)` 与 `updateNameservers(string $domain, array $nameservers, array $credentials)` 功能完全重叠，区别仅在 NS 是逗号字符串 vs 数组。

**方案**：
1. `GnameRegistrar::updateNameservers()` 已内部调用 `modifyDns()`，保持不变
2. `modifyDns()` 降级为 GName 适配器的 **private** 内部方法
3. 所有外部调用方统一使用 `updateNameservers()`
4. `WebsitesQueryProvider::modifyDns()` 查询操作内部转为调 `updateNameservers()`

---

## 四、命中技能（开发时必须参考）

| 技能 | 路径 | 说明 |
|------|------|------|
| code-generation-standards | `dev/ai/skills/code-generation-standards/SKILL.md` | 架构分层、框架边界 |
| module-development | `dev/ai/skills/module-development/SKILL.md` | 模块开发规范、setup:upgrade |
| create-extends | `dev/ai/skills/create-extends/SKILL.md` | 扩展点定义 |
| unified-query-provider | `dev/ai/skills/unified-query-provider/SKILL.md` | QueryProvider 修改 |
| php-unit-testing | `dev/ai/skills/php-unit-testing/SKILL.md` | 单元测试 |
| i18n-internationalization | `dev/ai/skills/i18n-internationalization/SKILL.md` | 翻译文案 |

---

## 五、开发阶段

### 阶段 1：接口拆分（🟢 已完成）

- 创建 6 个能力接口文件 ✅
- 修改 `DomainRegistrarInterface` 为聚合接口（extends 组合）✅
- 所有现有适配器无需改动（仍 implements DomainRegistrarInterface）✅

### 阶段 2：新增能力接口实现（🟢 已完成）

- GnameRegistrar 新增 `implements AccountInfoInterface` ✅
- CloudflareRegistrar 新增 `implements ZoneManagementInterface` ✅
- GnameRegistrar 将 `modifyDns()` 改为 private ✅

### 阶段 3：调用方迁移（🟢 已完成）

- DomainSyncService：`method_exists` → `instanceof NameserverSwitchInterface` ✅
- WebsitesQueryProvider：`method_exists` → `instanceof` + 统一调 `updateNameservers()` ✅
- 验证 DnsCdnAutoSwitch、DomainProvisioningService 无需改动 ✅

### 阶段 4：测试验证（🔵 测试中）

- PHP Reflection 验证所有适配器接口实现正确 ✅
- GnameRegistrarIntegrationTest 已迁移到 updateNameservers ✅
- setup:upgrade 通过 ✅
- NS 切换端到端验证（待线上验证）

---

## 六、进度记录

| 日期 | 进度 | 说明 |
|------|------|------|
| 2026-03-13 | 计划创建 | 完成现状分析、接口设计、迁移方案 |
| 2026-03-13 | 开发完成 | 阶段1-3 全部完成，接口拆分、适配器调整、调用方迁移 |
