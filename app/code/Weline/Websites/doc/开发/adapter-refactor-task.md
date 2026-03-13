# 域名服务适配器统一抽象重构 - 任务清单

> 最后更新：2026-03-13  
> 关联计划：[adapter-refactor-plan.md](./adapter-refactor-plan.md)

## 任务状态说明

- `[ ]` 待办 / pending
- `[-]` 进行中 / in_progress
- `[x]` 已完成 / done

---

## 阶段 1：接口拆分

### 1.1 创建能力接口

- [x] **ProviderInfoInterface** `Api/ProviderInfoInterface.php`
  - [ ] `getRegistrarCode(): string`
  - [ ] `getRegistrarName(): string`
  - [ ] `getDescription(): string`
  - [ ] `getVersion(): string`
  - [ ] `getConfigFields(): array`
  - [ ] `getConfigHelp(): array`
  - [ ] `testConnection(array $credentials): bool`
  - [ ] `isDomainRegistrar(): bool`

- [x] **DomainPurchaseInterface** `Api/DomainPurchaseInterface.php`
  - [x] `checkAvailability(string $domain, array $credentials): array`
  - [x] `batchCheckAvailability(array $domains, array $credentials): array`
  - [x] `purchaseDomain(string $domain, int $years, array $credentials, array $contactInfo = []): array`
  - [x] `getDomainList(array $credentials): array`
  - [x] `getDomainDetail(string $domain, array $credentials): array`

- [x] **DnsManagementInterface** `Api/DnsManagementInterface.php`
  - [x] `supportsDnsManagement(): bool`
  - [x] `getDnsRecords(string $domain, array $credentials): array`
  - [x] `addDnsRecord(string $domain, array $record, array $credentials): array`
  - [x] `updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array`
  - [x] `deleteDnsRecord(string $domain, string $recordId, array $credentials): array`
  - [x] `batchAddDnsRecords(string $domain, array $records, array $credentials): array`

- [x] **NameserverSwitchInterface** `Api/NameserverSwitchInterface.php`
  - [x] `updateNameservers(string $domain, array $nameservers, array $credentials): array`
  - [x] `getProviderNameservers(array $credentials, string $domain = ''): array`

- [x] **AccountInfoInterface** `Api/AccountInfoInterface.php`
  - [x] `getAccountBalance(array $credentials): array`
  - [x] `getTldPrices(array $credentials): array`
  - [x] `getContactTemplates(array $credentials): array`

- [x] **ZoneManagementInterface** `Api/ZoneManagementInterface.php`
  - [x] `addZone(string $domain, array $credentials): array`
  - [x] `getHostedDomainList(array $credentials): array`

### 1.2 修改聚合接口

- [x] **DomainRegistrarInterface** `Api/DomainRegistrarInterface.php`
  - [x] 改为 extends ProviderInfoInterface, DomainPurchaseInterface, DnsManagementInterface, NameserverSwitchInterface
  - [x] 移除方法声明（由父接口提供）
  - [x] 保留 PHPDoc 说明这是聚合接口

---

## 阶段 2：适配器调整

### 2.1 GnameRegistrar

- [x] **新增 AccountInfoInterface 实现** `Adapter/GnameRegistrar.php`
  - [x] 类声明新增 `implements AccountInfoInterface`
  - [x] `getAccountBalance()` → 包装现有 `getBalance()`
  - [x] `getContactTemplates()` → 包装现有 `getTemplates()`
  - [x] `getTldPrices()` → 已存在，签名兼容
  - [x] 将 `modifyDns()` 改为 `private`（`updateNameservers()` 已调用它）
  - [x] 将 `getBalance()` 改为 `private`
  - [x] 将 `getTemplates()` 改为 `private`

### 2.2 CloudflareRegistrar

- [x] **新增 ZoneManagementInterface 实现** `Adapter/CloudflareRegistrar.php`
  - [x] 类声明新增 `implements ZoneManagementInterface`
  - [x] `addZone()` → 已存在，签名兼容
  - [x] `getHostedDomainList()` → 已存在，签名兼容

### 2.3 其他适配器（无需改动，确认即可）

- [x] **AliyunDomainRegistrar** — 仍 `implements DomainRegistrarInterface`，无非接口方法 ✅ 验证通过
- [x] **AwsRoute53Registrar** — 仍 `implements DomainRegistrarInterface`，无非接口方法 ✅ 验证通过
- [x] **AzureDnsRegistrar** — 仍 `implements DomainRegistrarInterface`，无非接口方法 ✅ 验证通过

---

## 阶段 3：调用方迁移

### 3.1 DomainSyncService

- [x] **消除 method_exists** `Service/DomainSyncService.php`
  - [x] `batchChangeDns()` 中将 `method_exists($cached['adapter'], 'modifyDns')` 改为 `$cached['adapter'] instanceof NameserverSwitchInterface`
  - [x] 将 `$adapter->modifyDns(...)` 改为 `$adapter->updateNameservers($domainName, explode(',', $dnsServers), $credentials)`
  - [x] 新增 `use Weline\Websites\Api\NameserverSwitchInterface`

### 3.2 WebsitesQueryProvider

- [x] **消除 method_exists** `extends/module/Weline_Framework/Query/WebsitesQueryProvider.php`
  - [x] `modifyDns()` 方法中将 `method_exists($adapter, 'modifyDns')` 改为 `$adapter instanceof NameserverSwitchInterface`
  - [x] 将 `call_user_func([$adapter, 'modifyDns'], ...)` 改为 `$adapter->updateNameservers($domain, $nsList, $credentials)`
  - [x] 新增 `use Weline\Websites\Api\NameserverSwitchInterface`

### 3.3 DomainRegistrarResolverService

- [x] **适配器发现逻辑** `Service/DomainRegistrarResolverService.php`
  - [x] 确认仍按 `DomainRegistrarInterface` 扫描（不改）

### 3.4 验证无需改动的调用方

- [x] **DnsCdnAutoSwitch** — 已使用 `updateNameservers()` 和 `getProviderNameservers()`，无需改动 ✅
- [x] **DomainProvisioningService** — 通过 `w_query('websites', 'modifyDns')` 调用，改动在 WebsitesQueryProvider 即可 ✅
- [x] **DomainResolveService** — 已使用接口方法，无需改动 ✅
- [x] **Controller/Admin/Domain** — 无 modifyDns 调用 ✅
- [x] **GuoLaiRen/PageBuilder/Controller/Backend/DomainManagement** — 无 modifyDns 调用 ✅

---

## 阶段 4：测试验证

### 4.1 现有测试

- [x] **GnameRegistrarIntegrationTest** — `modifyDns` 测试改为 `updateNameservers` ✅
- [ ] **GnameRegistrarTest** 通过（待运行）
- [ ] **CloudflareTest** 通过（待运行）

### 4.2 接口验证（PHP Reflection）

- [x] GnameRegistrar implements AccountInfoInterface = YES
- [x] CloudflareRegistrar implements ZoneManagementInterface = YES
- [x] GnameRegistrar implements NameserverSwitchInterface = YES
- [x] AliyunDomainRegistrar implements DomainRegistrarInterface = YES
- [x] AwsRoute53Registrar implements DomainRegistrarInterface = YES
- [x] AzureDnsRegistrar implements DomainRegistrarInterface = YES

### 4.3 回归验证

- [ ] DNS 切换流程端到端：GName → Cloudflare（定时任务 DnsCdnAutoSwitch）
- [ ] DNS 切换流程端到端：手动切换（Controller postBatchSwitchDns）
- [ ] modifyDns QueryProvider 查询操作正常
- [ ] 域名同步流程正常（DomainSyncService）

---

## 缺陷修复（自审发现）

- [x] **`modifyDns` 遗留清理**：GnameRegistrarIntegrationTest 已改为 `$adapter->updateNameservers(...)`
- [ ] **`getCloudflareNameservers` 清理**：CloudflareRegistrar 中的兼容方法，确认无外部调用后标记 `@deprecated` 或删除

---

## 关联更新

- [ ] **i18n 翻译**：如有新增用户可见文案，更新 `i18n/en_US.csv` 和 `i18n/zh_Hans_CN.csv`
- [ ] **文档更新**：更新 `doc/域名管理架构.md` 中接口说明
- [ ] **技能更新**：如有架构变更需要记录到技能文档

---

## 备注

- 所有新接口文件放在 `Api/` 目录下
- `DomainRegistrarInterface` 保持向后兼容，现有适配器不需要修改 `implements` 声明
- 新增能力接口是可选的，适配器按需额外 `implements`
- 调用方通过 `instanceof` 做能力检查，替代 `method_exists()`
