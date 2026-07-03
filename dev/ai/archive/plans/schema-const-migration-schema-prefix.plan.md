# Model DDL 常量迁移：全部改为 schema_* 且 Diff 兼容

**状态**：🟡 进行中（50 子智能体 Model 迁移已跑完；Setup 迁出待实施）  
**性质**：重构，不做兼容；旧写法全部删除  
**最后更新**：2026-03-20

## 约束与兼容说明

- **不做兼容**：框架只读 `schema_table`、`schema_primary_key`、`schema_primary_keys`、`schema_fields_*`；旧常量 `table`、`primary_key`、`fields_*` 及**属性上的 #[Col]** 已废弃，SchemaParser 仅解析**常量上的 #[Col]**。
- **Diff 版本兼容**：表名来自 getTable()（迁移后仅 schema_table）；列定义来自带 #[Col] 的 schema_fields_* 常量；迁移表存表名字符串，历史迁移记录可继续使用。

### 列定义约定（硬性，唯一合法写法）

- **#[Col] 写在 schema_fields_* 常量上**：每个字段仅保留两行，无 `protected mixed $xxx = null;` 属性。示例：
  ```php
  #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '域名ID')]
  public const schema_fields_ID = 'domain_id';
  #[Col('datetime', nullable: true, comment: '最后同步时间')]
  public const schema_fields_SYNCED_AT = 'synced_at';
  ```
- **列名**：由常量值提供（如 `schema_fields_ID = 'domain_id'`）；**注释**写在 `#[Col(..., comment: '...')]` 中。
- **DbSchemaReader 禁止方言**：表结构读取须通过 ConnectorInterface 的 getTableComment / getTableColumns / getTableIndexes / getTableForeignKeys，由各适配器实现方言。

### 阶段：50 子智能体迁移全量 Model（仅新格式）

- **任务**：全仓库所有 Model 文件改为唯一合法写法：删除 `protected mixed $xxx = null;` 及属性上的 #[Col]；每个字段仅保留 `#[Col(..., comment: '注释')]` + `public const schema_fields_XXX = '列名';`。
- **不兼容**：旧写法（属性+Col）全部删除，SchemaParser 已只解析常量上的 #[Col]。
- **分配**：50 个子智能体并发，按 `crc32(normalized_path) % 50` 分桶，每桶处理对应 Model 文件。

### 待实施：Model 内 setup/upgrade/install 迁出

- **目标**：Model 类中不再包含 `setup(ModelSetup, Context)`、`upgrade(...)`、`install(...)` 等表结构/安装逻辑；上述逻辑迁移到各模块 **Setup 目录**（如 `Setup/Install.php`、`Setup/Upgrade.php` 或统一入口），由框架在升级时调用 Setup 而非 Model。
- **说明**：在 50 子智能体完成列定义迁移后，单独批次实施。

#### 需迁出的 Model 清单（项目下全部，含 setup/upgrade/install 且签名为 ModelSetup + Context）

以下路径统一为 `/`，去重后共 **23 个文件**（不含测试、接口、注释掉的代码）。

| # | 路径 | 说明 |
|---|------|------|
| 1 | app/code/Weline/Framework/Database/Model.php | 基类空实现，迁出后接口/基类不再声明这三方法 |
| 2 | app/code/Weline/Framework/Database/Model/Migration.php | setup, install |
| 3 | app/code/Weline/Framework/Database/test/Model/WelineModel.php | 测试用 Model，可保留或迁到测试 Setup |
| 4 | app/code/Weline/Framework/Setup/Db/Model/FieldBackup.php | install, setup（有建表/备份逻辑） |
| 5 | app/code/Weline/Framework/Setup/Db/Model/FieldBackupConflict.php | install, setup（有逻辑） |
| 6 | app/code/Weline/Framework/Setup/Db/Model/FieldDefinitionBackup.php | install, setup（有逻辑） |
| 7 | app/code/Weline/Database/Model/MigrationBackup.php | setup, install |
| 8 | app/code/Weline/Database/Model/Migration.php | setup, install |
| 9 | app/code/Weline/Eav/Model/EavAttribute.php | setup, upgrade, install（空） |
| 10 | app/code/Weline/Eav/Model/EavAttribute/Group.php | setup, upgrade, install（空） |
| 11 | app/code/Weline/Eav/Model/EavAttribute/Option.php | setup, upgrade, install（空） |
| 12 | app/code/Weline/Eav/Model/EavAttribute/Set.php | setup, upgrade, install（空） |
| 13 | app/code/Weline/Eav/Model/EavAttribute/Type.php | setup, upgrade, install（空） |
| 14 | app/code/Weline/Eav/Model/EavAttribute/Type/Value.php | setup, upgrade, install（有逻辑） |
| 15 | app/code/Weline/Eav/Model/EavEntity.php | setup, upgrade, install（部分有逻辑） |
| 16 | app/code/Weline/Eav/Model/Test.php | setup, upgrade, install（有逻辑） |
| 17 | app/code/Weline/Frontend/Model/System/FrontendNotification.php | setup, upgrade, install（空） |
| 18 | app/code/Weline/I18n/Model/Locale/Name.php | setup, upgrade, install（有逻辑） |
| 19 | app/code/Weline/Index/Model/Backend/Setting.php | setup, upgrade, install（install 有逻辑） |
| 20 | app/code/Weline/Theme/Model/ThemeLayout.php | ~~setup（空）~~ ✅ 已完成（ThemeLayout 已无 setup 方法；Setup/Install.php 和 Upgrade.php 已清理为空 stub，旧式建表/裸 SQL ALTER 均已移除） |
| 21 | app/code/WeShop/Product/Model/Product/OptionId.php | setup, upgrade, install（空） |
| 22 | app/code/WeShop/Cms/Model/Page.php | install, upgrade, setup（有逻辑） |
| 23 | app/code/WeShop/Store/Model/Store/Currency.php | setup, upgrade, install（空） |

**排除说明**：已排除 `Setup/Install.php`、`Setup/Upgrade.php`（本身就在 Setup）、接口定义、Controller 的 install、Migration 脚本的 install、测试 Mock 的 install。`EavAttribute/LocalDescription.php` 中对应方法已注释，未列入。

---

## Diff 时注释必须参与比较（硬性）

**真实意图**：Diff 用于判断 **schema 结构是否发生变更**，而不是“Model 文件是否变化”。注释的调整意味着字段含义/属性在调整，应视为 schema 变更。

- **列注释**：`SchemaDiffEngine::columnEquals()` 必须参与比较 **comment**（以及建议 **default**）。仅当声明与库表在类型、长度、可空、主键、自增、唯一、**注释**等均一致时才视为“无变更”；否则生成 MODIFY_COLUMN，执行 ALTER 同步注释到库。
- **表注释**：`DbSchemaReader::readTable()` 需从库中读取表注释（如 information_schema.TABLES.TABLE_COMMENT）；`SchemaDiffEngine::diff()` 需比较 `$declared->comment` 与 `$actual->comment`，若不同则生成“改表注释”的 op 并执行，使表注释变更被持久化。
- **不依赖文件 hash**：是否执行 DDL 仅由“声明 schema vs 实际 DB schema”的 diff 结果决定，不由 Model 文件 MD5/hash 触发；未改动的声明不会产生多余 DDL。

涉及文件：

- [SchemaDiffEngine.php](app/code/Weline/Framework/Database/Schema/SchemaDiffEngine.php)：`columnEquals()` 增加 `comment`（及可选 `default`）比较；`diff()` 增加表 comment 比较并产出对应 op。
- [DbSchemaReader.php](app/code/Weline/Framework/Database/Schema/DbSchemaReader.php)：`readTable()` 读取表 comment 并写入 TableSchema。
- SchemaMigrationExecutor：若新增“仅改表注释”的 op 类型，需支持生成并执行对应 ALTER TABLE ... COMMENT。

---

## 阶段一：框架仅使用 schema_*

### 1.1 AbstractModel

- 删除类常量：`table`、`primary_key`、`fields_ID`、`fields_CREATE_TIME`、`fields_UPDATE_TIME`。
- 新增类常量：`schema_table`、`schema_primary_key`、`schema_primary_keys`、`schema_fields_ID`、`schema_fields_CREATE_TIME`、`schema_fields_UPDATE_TIME`（含默认值）。
- 表名、主键、getModelFields()、getId/setId/getCreateTime/setCreateTime/getUpdateTime/setUpdateTime 仅从 schema_* 读取。

### 1.2 Model、SchemaParser、MigrateModel、DataTable Form、文档

- Model 基类与所有引用处改为 schema_fields_* / schema_table / schema_primary_key。
- MigrateModel 只解析与生成 schema_*；DataTable Form 只收集 schema_fields_*；文档与注解改为 schema_* 说明。

---

## 阶段二：迁移框架内 Model

按依赖顺序：AbstractModel → Migration → MigrationBackup → WelineModel → FieldBackup / FieldBackupConflict / FieldDefinitionBackup；每类增加 schema_*、删除 table/primary_key/fields_*，类内引用同步改。

---

## 待迁移 Model 清单（按模块，后续按此列表逐个迁移）

以下为含 `table` / `primary_key` / `fields_*` 的 Model 文件，需改为 schema_* 并删除旧常量。路径已去重、统一为 `/`。

### Framework（阶段二优先）

- app/code/Weline/Framework/Database/AbstractModel.php
- app/code/Weline/Framework/Database/Model/Migration.php
- app/code/Weline/Framework/Database/Model/MigrationBackup.php
- app/code/Weline/Framework/Database/test/Model/WelineModel.php
- app/code/Weline/Framework/Setup/Db/Model/FieldBackup.php
- app/code/Weline/Framework/Setup/Db/Model/FieldBackupConflict.php
- app/code/Weline/Framework/Setup/Db/Model/FieldDefinitionBackup.php

### Weline 模块

- app/code/Weline/Acl/Model/IpWhitelist.php
- app/code/Weline/Acl/Model/Role.php
- app/code/Weline/Acl/Model/RoleAccess.php
- app/code/Weline/Acl/Model/SecurityLog.php
- app/code/Weline/Acl/Model/WhiteAclSource.php
- app/code/Weline/Admin/Model/MenuAccessLog.php
- app/code/Weline/Api/Model/ApiUserRole.php
- app/code/Weline/Api/Model/ApiUserToken.php
- app/code/Weline/Api/Model/SandboxTest.php
- app/code/Weline/Async/Model/SyncHost.php
- app/code/Weline/Async/Model/SyncMapping.php
- app/code/Weline/AutoLeadAgent/Model/AgentConfig.php
- app/code/Weline/AutoLeadAgent/Model/AgentToken.php
- app/code/Weline/AutoLeadAgent/Model/LeadCandidate.php
- app/code/Weline/AutoLeadAgent/Model/SearchEngineMapping.php
- app/code/Weline/AutoLeadAgent/Model/SearchTask.php
- app/code/Weline/AutoLeadAgent/Model/TargetWebsite.php
- app/code/Weline/AutoLeadAgent/Model/WasmHash.php
- app/code/Weline/Backend/Model/BackendUser.php
- app/code/Weline/Backend/Model/BackendUserConfig.php
- app/code/Weline/Backend/Model/BackendUserData.php
- app/code/Weline/Backend/Model/BackendUserToken.php
- app/code/Weline/Backend/Model/Menu.php
- app/code/Weline/Backend/Model/NotificationChannel.php
- app/code/Weline/Backend/Model/NotificationTopic.php
- app/code/Weline/Backend/Model/SystemNotification.php
- app/code/Weline/Backend/Model/UserContact.php
- app/code/Weline/Backend/Model/UserNotificationStatus.php
- app/code/Weline/Backend/Model/UserNotificationSubscription.php
- app/code/Weline/Bt_Center/Model/BtServer.php
- app/code/Weline/CacheManager/Model/Cache.php
- app/code/Weline/Captcha/Model/CaptchaResult.php
- app/code/Weline/Captcha/Model/Config.php
- app/code/Weline/Captcha/Model/FailedAttempt.php
- app/code/Weline/Checkout/Model/Order.php
- app/code/Weline/Checkout/Model/OrderItem.php
- app/code/Weline/Checkout/Model/PaymentTransaction.php
- app/code/Weline/Cms/Model/FormSubmission.php
- app/code/Weline/Cms/Model/Page.php
- app/code/Weline/Cms/Model/Page/LocalDescription.php
- app/code/Weline/Cms/Model/Style.php
- app/code/Weline/Cdn/Model/Account.php
- app/code/Weline/Cdn/Model/ApiRule.php
- app/code/Weline/Cdn/Model/AttackLog.php
- app/code/Weline/Cdn/Model/Domain.php
- app/code/Weline/Cdn/Model/WarmupUrl.php
- app/code/Weline/Component/Model/Component.php
- app/code/Weline/Customer/Model/Customer.php
- app/code/Weline/Customer/Model/CustomerToken.php
- app/code/Weline/CustomerService/Model/ChatMessage.php
- app/code/Weline/CustomerService/Model/ChatSession.php
- app/code/Weline/CustomerService/Model/CustomerLanguage.php
- app/code/Weline/CustomerService/Model/CustomerServiceConfig.php
- app/code/Weline/CustomerService/Model/ServiceAgent.php
- app/code/Weline/Database/Model/Migration.php
- app/code/Weline/Database/Model/MigrationBackup.php
- app/code/Weline/Database/Model/ModuleVersionHistory.php
- app/code/Weline/DataTable/Model/TestOrder.php
- app/code/Weline/DataTable/Model/TestProduct.php
- app/code/Weline/DataTable/Model/TestUser.php
- app/code/Weline/DataTable/Model/TestUserProfile.php
- app/code/Weline/DataTable/Model/TestUserAddress.php
- app/code/Weline/DeveloperWorkspace/Model/Document.php
- app/code/Weline/DeveloperWorkspace/Model/Document/Catalog.php
- app/code/Weline/Eav/Model/EavAttribute.php
- app/code/Weline/Eav/Model/EavAttribute/Set.php
- app/code/Weline/Eav/Model/EavAttribute/Set/LocalDescription.php
- app/code/Weline/Eav/Model/EavAttribute/Type/LocalDescription.php
- app/code/Weline/Eav/Model/EavAttribute/Type/Value.php
- app/code/Weline/Eav/Model/EavEntity.php
- app/code/Weline/Frontend/Model/FrontendUser.php
- app/code/Weline/Frontend/Model/FrontendUserConfig.php
- app/code/Weline/Frontend/Model/FrontendUserToken.php
- app/code/Weline/GenerativeEngineOptimization/Model/Feed.php
- app/code/Weline/GenerativeEngineOptimization/Model/FeedItem.php
- app/code/Weline/GenerativeEngineOptimization/Model/Platform.php
- app/code/Weline/GenerativeEngineOptimization/Model/PlatformAccount.php
- app/code/Weline/GenerativeEngineOptimization/Model/PushLog.php
- app/code/Weline/I18n/Model/Countries.php
- app/code/Weline/I18n/Model/Countries/Locale/Name.php
- app/code/Weline/I18n/Model/Dictionary.php
- app/code/Weline/I18n/Model/Locale.php
- app/code/Weline/I18n/Model/Locale/Dictionary.php
- app/code/Weline/I18n/Model/Locale/Name.php
- app/code/Weline/I18n/Model/Locals.php
- app/code/Weline/Indexer/Model/Indexer.php
- app/code/Weline/Layout/Model/Layout.php
- app/code/Weline/Layout/Model/LayoutSchedule.php
- app/code/Weline/Maintenance/Model/Backup.php
- app/code/Weline/Marketing/Model/Coupon/Coupon.php
- app/code/Weline/Marketing/Model/Rule/LocalDescription.php
- app/code/Weline/Marketing/Model/Rule/Rule.php
- app/code/Weline/Marketing/Model/Campaign/Campaign.php
- app/code/Weline/Meta/Model/Meta.php
- app/code/Weline/Meta/Model/MetaConfig.php
- app/code/Weline/Meta/Model/MetaLocal.php
- app/code/Weline/ModuleManager/Model/Module.php
- app/code/Weline/Multipass/Model/MultipassSite.php
- app/code/Weline/Order/Model/Order.php
- app/code/Weline/Order/Model/OrderHistory.php
- app/code/Weline/Order/Model/OrderInvoice.php
- app/code/Weline/Order/Model/OrderItem.php
- app/code/Weline/Order/Model/OrderPayment.php
- app/code/Weline/Order/Model/OrderRefund.php
- app/code/Weline/Order/Model/OrderShipment.php
- app/code/Weline/Order/Model/OrderStatus.php
- app/code/Weline/Order/Model/OrderStatusTranslation.php
- app/code/Weline/Payment/Model/PaymentMethod.php
- app/code/Weline/Payment/Model/PaymentTransaction.php
- app/code/Weline/Queue/Model/Queue.php
- app/code/Weline/Queue/Model/Queue/Type.php
- app/code/Weline/Queue/Model/Queue/Type/Attributes.php
- app/code/Weline/RdpWrapper/Model/RdpUser.php
- app/code/Weline/Seo/Model/SeoAccount.php
- app/code/Weline/Seo/Model/SeoKeyword.php
- app/code/Weline/Seo/Model/SeoKeywordTrend.php
- app/code/Weline/Seo/Model/SeoSubject.php
- app/code/Weline/Seo/Model/SeoSuggestion.php
- app/code/Weline/Seo/Model/SeoTask.php
- app/code/Weline/Seo/Model/SeoWebsiteAccount.php
- app/code/Weline/Seo/Model/SeoWebsiteStats.php
- app/code/Weline/Seo/Model/SitemapUrl.php
- app/code/Weline/Server/Model/AttackLog.php
- app/code/Weline/Server/Model/ServerStatusLog.php
- app/code/Weline/Server/Model/SslCertificate.php
- app/code/Weline/SessionManager/Model/Session.php
- app/code/Weline/Saas/Model/ProvisioningOrder.php
- app/code/Weline/Saas/Model/ProvisioningStep.php
- app/code/Weline/Shipping/Model/Carrier.php
- app/code/Weline/Shipping/Model/DeliveryAddress.php
- app/code/Weline/Shipping/Model/FreeShippingRule.php
- app/code/Weline/Shipping/Model/RateTemplate.php
- app/code/Weline/Shipping/Model/Region.php
- app/code/Weline/Shipping/Model/ShippingAddress.php
- app/code/Weline/Shipping/Model/ShippingService.php
- app/code/Weline/Shipping/Model/Tracking.php
- app/code/Weline/Shipping/Model/TrackingNode.php
- app/code/Weline/Shipping/Model/Zone.php
- app/code/Weline/Shipping/Model/ZoneRegion.php
- app/code/Weline/Storage/Model/StorageConfig.php
- app/code/Weline/Sticker/Model/StickerLog.php
- app/code/Weline/SystemConfig/Model/SystemConfig.php
- app/code/Weline/Taglib/Model/Taglib.php
- app/code/Weline/Taglib/Model/UserScope.php
- app/code/Weline/Terraform/Model/Batch.php
- app/code/Weline/Terraform/Model/BatchItem.php
- app/code/Weline/Theme/Model/ThemeLayout.php
- app/code/Weline/Theme/Model/ThemeLayoutVersion.php
- app/code/Weline/Theme/Model/WelineTheme.php
- app/code/Weline/TranslationService/Model/TranslationProvider.php
- app/code/Weline/TranslationService/Model/TranslationRecord.php
- app/code/Weline/TwoFactorAuth/Model/TotpAccount.php
- app/code/Weline/TwoFactorAuth/Model/TwoFactorConfig.php
- app/code/Weline/TwoFactorAuth/Model/UserTwoFactor.php
- app/code/Weline/UrlManager/Model/UrlManager.php
- app/code/Weline/UrlManager/Model/UrlRewrite.php
- app/code/Weline/Visitor/Model/AbTest.php
- app/code/Weline/Visitor/Model/Pixel.php
- app/code/Weline/Visitor/Model/PixelAdditional.php
- app/code/Weline/Visitor/Model/PixelEncryptionToken.php
- app/code/Weline/Visitor/Model/PixelSource.php
- app/code/Weline/Websites/Model/Domain.php
- app/code/Weline/Websites/Model/DomainConfig.php
- app/code/Weline/Websites/Model/DomainPool.php
- app/code/Weline/Websites/Model/DomainPurchaseItem.php
- app/code/Weline/Websites/Model/DomainPurchaseOrder.php
- app/code/Weline/Websites/Model/DomainRegistrar.php
- app/code/Weline/Websites/Model/DomainRegistrarAccount.php
- app/code/Weline/Websites/Model/DomainAutoResolveTask.php
- app/code/Weline/Websites/Model/DomainDnsRecord.php
- app/code/Weline/Websites/Model/Website.php
- app/code/Weline/Websites/Model/WebsiteCurrency.php
- app/code/Weline/Websites/Model/WebsiteDomain.php
- app/code/Weline/Websites/Model/WebsiteLanguage.php
- app/code/Weline/Widget/Model/Page.php
- app/code/Weline/Widget/Model/Widget/LocalDescription.php
- app/code/Weline/Ai/Model/AiAbTest.php
- app/code/Weline/Ai/Model/AiAgent.php
- app/code/Weline/Ai/Model/AiApiCallLog.php
- app/code/Weline/Ai/Model/AiApiKey.php
- app/code/Weline/Ai/Model/AiApiQuota.php
- app/code/Weline/Ai/Model/AiAssistant.php
- app/code/Weline/Ai/Model/AiAssistantConversation.php
- app/code/Weline/Ai/Model/AiAssistantPromptTemplate.php
- app/code/Weline/Ai/Model/AiAssistantRating.php
- app/code/Weline/Ai/Model/AiAssistantRental.php
- app/code/Weline/Ai/Model/AiAssistantRevenue.php
- app/code/Weline/Ai/Model/AiAuditLogDetail.php
- app/code/Weline/Ai/Model/AiBillingInvoice.php
- app/code/Weline/Ai/Model/AiBillingPlan.php
- app/code/Weline/Ai/Model/AiBillingRecordDetail.php
- app/code/Weline/Ai/Model/AiContentSafety.php
- app/code/Weline/Ai/Model/AiDefaultModel.php
- app/code/Weline/Ai/Model/AiDeveloperTool.php
- app/code/Weline/Ai/Model/AiI18nContent.php
- app/code/Weline/Ai/Model/AiMarketingCampaign.php
- app/code/Weline/Ai/Model/AiMobileDevice.php
- app/code/Weline/Ai/Model/AiModel.php
- app/code/Weline/Ai/Model/AiModelBenchmark.php
- app/code/Weline/Ai/Model/AiModelDeployment.php
- app/code/Weline/Ai/Model/AiModelVersion.php
- app/code/Weline/Ai/Model/AiPerformanceMetricDetail.php
- app/code/Weline/Ai/Model/AiScenarioAdapter.php
- app/code/Weline/Ai/Model/AiScenarioAdapterConfig.php
- app/code/Weline/Ai/Model/AiSecurityScan.php
- app/code/Weline/Ai/Model/AiSupportTicket.php
- app/code/Weline/Ai/Model/AiTenant.php
- app/code/Weline/Ai/Model/AiTenantConfig.php
- app/code/Weline/Ai/Model/AiTenantUser.php
- app/code/Weline/Ai/Model/AiThirdPartyIntegration.php
- app/code/Weline/Ai/Model/AiTrainingData.php
- app/code/Weline/Ai/Model/AiUsageLog.php
- app/code/Weline/Ai/Model/AiUserBill.php
- app/code/Weline/Ai/Model/AiUserRecharge.php
- app/code/Weline/Ai/Model/Provider/Account.php
- app/code/Weline/Ai/Model/Provider/UsageRecord.php
- app/code/Weline/AiKnowledge/Model/CallHistory.php
- app/code/Weline/AliDdnsServer/Model/DdnsDomains.php
- app/code/Weline/Cron/Model/CronTask.php

### WeShop 模块

- app/code/WeShop/Address/Model/Address.php
- app/code/WeShop/Affiliate/Model/Affiliate.php
- app/code/WeShop/B2B/Model/Company.php
- app/code/WeShop/Cart/Model/Cart.php
- app/code/WeShop/Catalog/Model/Category.php
- app/code/WeShop/Catalog/Model/Category/LocalDescription.php
- app/code/WeShop/Cms/Model/Page.php
- app/code/WeShop/Cms/Model/Page/LocalDescription.php
- app/code/WeShop/Compare/Model/Compare.php
- app/code/WeShop/Compliance/Model/CookieConsent.php
- app/code/WeShop/Customer/Model/Customer.php
- app/code/WeShop/Filters/Model/CategoryFilterConfig.php
- app/code/WeShop/Filters/Model/FilterCache.php
- app/code/WeShop/Filters/Model/PriceRange.php
- app/code/WeShop/GiftCard/Model/GiftCard.php
- app/code/WeShop/Invoice/Model/Invoice.php
- app/code/WeShop/Inventory/Model/Source.php
- app/code/WeShop/Inventory/Model/SourceItem.php
- app/code/WeShop/Logistics/Model/Tracking.php
- app/code/WeShop/Membership/Model/Membership.php
- app/code/WeShop/Notification/Model/Notification.php
- app/code/WeShop/Order/Model/Order.php
- app/code/WeShop/Order/Model/OrderItem.php
- app/code/WeShop/Product/Model/Product.php
- app/code/WeShop/Product/Model/Product/OptionId.php
- app/code/WeShop/Product/Model/ProductCategory.php
- app/code/WeShop/Product/Model/ProductLayout.php
- app/code/WeShop/Product/Model/ProductLayoutSchedule.php
- app/code/WeShop/Product/Model/ProductWebsite.php
- app/code/WeShop/Product/Model/Test.php
- app/code/WeShop/Promotion/Model/Coupon.php
- app/code/WeShop/QA/Model/Question.php
- app/code/WeShop/RecentlyViewed/Model/RecentlyViewed.php
- app/code/WeShop/Review/Model/Review.php
- app/code/WeShop/RMA/Model/Rma.php
- app/code/WeShop/Search/Model/SearchEngineConfig.php
- app/code/WeShop/Search/Model/SearchHistory.php
- app/code/WeShop/Social/Model/SocialShare.php
- app/code/WeShop/Store/Model/Store.php
- app/code/WeShop/Store/Model/Store/Currency.php
- app/code/WeShop/Subscription/Model/Subscription.php
- app/code/WeShop/Subscription/Model/SubscriptionHistory.php
- app/code/WeShop/Subscription/Model/SubscriptionPlan.php
- app/code/WeShop/Wishlist/Model/Wishlist.php

### GuoLaiRen 模块

- app/code/GuoLaiRen/Blog/Model/Category.php
- app/code/GuoLaiRen/Blog/Model/Post.php
- app/code/GuoLaiRen/Blog/Model/TrendProfile.php
- app/code/GuoLaiRen/Blog/Model/TrendSiteQuota.php
- app/code/GuoLaiRen/Blog/Model/TrendingKeywordLog.php
- app/code/GuoLaiRen/Desensitization/Model/DesensitizationLog.php
- app/code/GuoLaiRen/Desensitization/Model/DesensitizationRule.php










### Aws 模块

- app/code/Aws/Domains/Model/AwsConfig.php
- app/code/Aws/Domains/Model/DomainOperation.php

### Agent 模块

- app/code/Agent/WeeklyReport/Model/WeeklyReport.php
- app/code/Agent/WeeklyReport/Model/WeeklyTask.php

### WelineTools 模块

- app/code/WelineTools/FontSubLetter/Model/CharMap.php
- app/code/WelineTools/FontSubLetter/Model/FontRecord.php

**说明**：迁移时每个文件需（1）增加 schema_table / schema_primary_key 或 schema_primary_keys / schema_fields_*；（2）删除 table / primary_key / fields_*；（3）类内 self::fields_*、self::table、self::primary_key 改为 self::schema_*。业务代码中引用该类常量的地方同步改为 ::schema_*。

---

## 阶段三：迁移业务 Model 与引用

- **已完成**：按「待迁移 Model 清单」由 10 个子智能体并发迁移（约 275 个 Model 文件）；另补迁 WeShop Subscription（3 个）+ Wishlist（1 个）共 4 个文件。
- 业务/测试中 `::fields_`、`::table`、`::primary_key` 已由各批次或批次 10 关联修改处改为 `::schema_*`。

---

## 阶段四：清理与校验

- **已完成**：Shipping Model 内 `self::table`→`self::schema_table`；单元测试与 Framework Table 注解改为 `schema_table`；业务/服务中大量 `Model::fields_*`→`Model::schema_fields_*`（Shipping、Cdn、AutoLeadAgent、Ai、Seo、Websites、DeveloperWorkspace、Checkout、Api、UrlManager、Theme、Eav Group 等）；Eav `Group` 模型补迁 schema_*；`Module\Backup::$module_name` 与 AbstractModel 可见性/类型对齐。`php bin/w setup:upgrade` 已通过（exit 0）。
- **可选后续**：全仓库仍有个别 `::fields_` 引用（约百处，分散在 Controller/Service/Test 等），可按需逐步改为 `::schema_fields_*`；可选单元测试断言 getTable/getModelFields 来源于 schema_*。
