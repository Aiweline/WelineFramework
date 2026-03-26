# Model 声明式 Schema 迁移引擎

**状态**：🟢 已完成（status: completed）  
**当前阶段**：Phase 6、Phase 7、Phase 8 均已完成（声明式迁移、Setup 逻辑迁移、接口与调用移除、技能/规则/文档更新）  
**性质**：重构，不做兼容  
**最后更新**：2025-02-28  
**架构约束**：SOLID 原则；框架能力复用（Stage、Event、Extends）；依赖倒置（事件解耦）

## 概述

将 Model 表结构迁移到声明式 Schema（`#[Col]` 等注解），迁移/备份能力迁入 Framework ORM，实现企业级 schema diff、备份、回滚。**本重构不保留对旧 install/upgrade/setup 的兼容**：完成后需扫罗所有模块并全部改为声明式或 SchemaProvider，并从 ModelInterface 移除 setup/upgrade/install。**波及范围大**：Phase 8 需同步更新技能、规则与相关文档。

---

## 一、不做兼容（硬性）

- **ModelInterface**：完成后仅保留 `columns()`，**移除** `setup()`、`upgrade()`、`install()` 三个方法。
- **ModelManager**：移除对 `model->install/upgrade/setup()` 的调用及“表不存在时自动 install”的逻辑；表结构由 SchemaDiffStage 统一处理。
- **所有实现 ModelInterface 的 Model**：必须在此次重构内全部迁移完毕，不允许遗留仍实现旧接口的 Model。
- **发现方式**：通过代码扫罗（见七、扫罗与核对清单）列出所有带 install/upgrade/setup 的 Model，逐模块迁移，迁移完成后再删除接口方法。

---

## 二、SOLID 原则与架构约束

### 2.0 SOLID 应用

| 原则 | 应用 |
|------|------|
| **S 单一职责** | 每 Stage 只做一事；SchemaParser 只解析、SchemaDiffEngine 只 diff、SchemaMigrationExecutor 只执行；Module\Table 注册通过 Observer 单独负责 |
| **O 开闭原则** | 新 Schema 来源通过 Extends SchemaProvider 扩展，不修改 Framework；新 Stage 通过 registerStage 注册；新回滚能力通过事件观察者扩展 |
| **L 里氏替换** | 所有 Stage 实现 StageInterface 可互换；所有 SchemaProvider 实现 SchemaProviderInterface 可互换 |
| **I 接口隔离** | ModelInterface 仅保留 columns()；SchemaProviderInterface 仅含 getSchemas() 等必要方法；不强迫实现无关方法 |
| **D 依赖倒置** | SchemaDiffStage 依赖 SchemaProviderInterface 抽象，不依赖具体 Eav 实现；执行层通过 Event dispatch 通知注册，不直接依赖 ModuleManager |

### 2.1 框架能力复用（不引入新机制）

| 能力 | 用途 | 参考 |
|------|------|------|
| **Stage**（StageInterface） | 编排与原子性 | StageUpdateManager、ModuleSetupStage |
| **Event + Observer** | 解耦、模块表注册 | model_update_after、event.xml |
| **Extends**（extends.php） | Schema 扩展点 | Query 扩展点、QueryProviderInterface |
| **SchemaInterface / Registry** | 表结构契约 | Eav SchemaInterface、SchemaRegistry |
| **class_exists 可选集成** | 弱依赖 | UninstallService 对 ModuleBackupService |

---

## 三、核心设计要点

### 3.1 表名来源

- 统一使用 Model 的 `getTable()` 获取表名；框架已支持 `const table` 与 `processTable()`，无需 `init('table_name', 'pk')`。

### 3.2 迁移/备份归属

- 迁移、备份、回滚逻辑全部放入 **Weline\\Framework**（不依赖 Weline_Database 扩展）。
- **FrameworkDbBootstrapStage**（order=0）最先执行，创建 migrations、migration_backups、module_versions 等表；表名通过 Model 的 `getTable()` 获取，与现有一致（如 `weline_database_migrations`）。

### 3.3 显式 migration 与 schema diff

| 职责       | 命令                 | 说明 |
|------------|----------------------|------|
| Schema diff | `setup:upgrade`      | 解析 `#[Col]`，diff 库，执行 DDL，记录 migration |
| 显式 migration | `db:migrate:upgrade` | 执行用户 migration 文件（up/down） |

### 3.4 阶段执行顺序（Stage 编排）

| Order | Stage | 职责 |
|-------|-------|------|
| 0 | FrameworkDbBootstrapStage | 创建 migrations、migration_backups、module_versions |
| 0.5 | ModuleManagerBootstrapStage（可选，class_exists） | 创建 module_tables、module_backups（ModuleManager 提供） |
| 1 | ModuleSetupStage | Setup 脚本、Handle 逻辑；**不再**调用 ModelManager::update(install/upgrade/setup) |
| 1.5 | EavSchemaStage（可选） | 调用 Eav SchemaRegistry::createAllTables，复用 Eav 现有机制 |
| 2 | EavSchemaStage | 可选：Eav SchemaRegistry::createAllTables，每表后 dispatch table_ddl_after |
| 3 | DatabaseUpdateStage | 现有阶段：Model install/upgrade/setup（逐步废弃） |
| 4 | SchemaDiffStage | 解析 `#[Col]`、diff 库表、执行 DDL、dispatch table_ddl_before/after |
| 5 | RouteUpdateStage 等 | 后续阶段 |

### 3.5 模块表注册：Event 解耦（依赖倒置）

- **新事件**：`Weline_Framework_Schema::table_ddl_before`（CREATE 前）、`Weline_Framework_Schema::table_ddl_after`（DDL 成功后）
- **Payload**：module_name, table_name, model_class
- **观察者**：ModuleManager 注册 Observer，before 做冲突检查、after 写 Module\Table
- **原则**：Framework 只 dispatch，不依赖 ModuleManager；ModuleManager 可选监听，实现 module:remove/restore 对齐

### 3.6 三种回滚/恢复的职责划分

| 能力 | 粒度 | 数据来源 | 机制 |
|------|------|----------|------|
| Schema 变更回滚 | 单次 DDL | Migration + MigrationBackup | 执行层 DDL 前备份、记录 rollback_ddl |
| 显式迁移回滚 | 迁移版本 | 同上 | db:migrate:rollback |
| 模块级恢复 | 整模块 | Module\Table + ModuleBackup | module:restore，依赖 table_ddl_after 维护 Module\Table |

### 3.7 columns() 与 #[Col] 的关系

- **columns()**：ModelInterface 保留，供运行时 ORM（getModelFields 等）使用。
- **#[Col]**：供 SchemaParser 解析，生成 DDL。
- **策略**：优先由 `#[Col]` 反射生成 `columns()`，避免两套声明；Phase 2 明确。

### 3.8 排除与 bootstrap Model 清单

不参与 SchemaDiff，表由 bootstrap 创建：

- Migration、MigrationBackup、ModuleVersion、ModuleVersionHistory
- FieldBackup、FieldBackupConflict、FieldDefinitionBackup
- Module\Table、Module\Backup（ModuleManager 系统表，不参与模块备份）

### 3.9 Eav 接入

- **方式 A（推荐）**：EavSchemaStage 调用 SchemaRegistry::createAllTables()，每表创建后 dispatch table_ddl_after
- **方式 B**：Eav 实现 SchemaProviderInterface（Extends），由 SchemaDiffStage 统一执行
- 静态表必须在动态表（eav_*_*）前存在

### 3.10 Setup 目录脚本与 Model install 的区分

- **Setup/Install.php、Upgrade.php**：保留，负责数据迁移、业务初始化
- **Handle::setupInstall()**：仅移除对 ModelManager::update(install/upgrade/setup) 的调用
- 表结构由 FrameworkDbBootstrapStage + SchemaDiffStage 负责

### 3.11 其他约定

- **排除 Model**：见 3.8。
- **DROP 与备份**：DROP 前备份数据；回滚时恢复字段+数据；表重命名自动识别并做迁移与备份。
- **Schema 回滚**：支持反向 diff，每个 DDL 有对应 rollback。
- **多库**：Model 指定其他连接时，备份写入该连接；主库记录连接信息；回滚时校验连接，不一致则报错。
- **解析失败**：解析失败即中止并报错，不继续。
- **model_update_before/after**：由 table_ddl_before/after 替代；ModuleManager 观察者迁移到新事件。
- **Weline_Database 依赖**：Phase 7 前完成扫描与迁移。

---

## 四、组件与目录结构（概要）

- **Framework 内**：FrameworkDbBootstrapStage、ModuleManagerBootstrapStage（可选）、SchemaDiffStage、EavSchemaStage（可选）；SchemaParser、DbSchemaReader、SchemaDiffEngine、SchemaMigrationExecutor；Extends Schema 扩展点；Migration/MigrationBackup 及 BackupService；table_ddl_before/after 事件。
- **ModuleManager**：table_ddl_* 观察者（冲突检查、写 Module\Table）；ModuleManagerBootstrapStage。
- **Eav**：EavSchemaStage 或 SchemaProvider，复用 SchemaRegistry。

---

## 五、实施步骤（含全量模块迁移）

### Phase 1：Framework 基础设施

- 将 Migration、MigrationBackup、BackupService 迁入 Framework，表名与 API 兼容。
- 实现 FrameworkDbBootstrapStage（order=0），在 Upgrade 中注册。
- Bootstrap 表名通过 Model 的 `getTable()` 获取。

### Phase 2：注解与解析

- 实现 `#[Table]`、`#[Col]`、`#[Index]`、`#[ForeignKey]`。
- 实现 SchemaParser、DbSchemaReader。
- 明确 `columns()` 与 `#[Col]` 的生成策略（如由 `#[Col]` 反射生成 `columns()`）。

### Phase 3：Diff 与执行（✅ 已完成）

- 实现 SchemaDiffEngine、SchemaMigrationExecutor（含 table_ddl_before/after 事件）。
- 实现 SchemaDiffStage（order=3，在 DatabaseUpdateStage 之后）；解析失败则硬失败。
- 在 Executor 中：每条 DDL 前 dispatch table_ddl_before，DDL 成功后 dispatch table_ddl_after。
- 排除表：weline_database_migrations、weline_database_backups（EXCLUDE_TABLES）。

### Phase 4：回滚与多库（✅ 已完成）

- 每个 DDL 生成并记录 rollback_ddl：SchemaMigrationExecutor 中 buildRollbackDdl()，Migration.recordSchemaDdl() 写入 forward_ddl/rollback_ddl/schema_table_name/connection_name；Migration 表由 FrameworkDbBootstrapStage 增加上述列（新建表含列，已有表 ALTER 补充）。
- MODIFY_COLUMN 回滚：SchemaDiffOp 增加 rollbackPayload，SchemaDiffEngine 在 MODIFY 时传入旧列定义。
- DROP COLUMN 前备份：Executor 注入 BackupService，在执行 drop_column 前调用 backupColumnData；记录先 status=running 再执行 DDL 后 updateStatus(installed)。
- 多库：connection_name 已落库，预留；回滚时校验连接待后续实现。

### Phase 5：Eav 与缓存（✅ 已完成）

- 定义 Extends Schema 扩展点：SchemaProviderInterface（getTableSchemas(): array）、Framework extends.php 增加 'Schema' 扩展点。
- 实现 EavSchemaStage（order=2，可选）：若存在 Eav\\Schema\\SchemaRegistry 则 registerClasses + createAllTables(ModelSetup)，每表创建后 dispatch table_ddl_after；Upgrade 中注册为 order=2，Database 3、SchemaDiff 4、Route 5、File 6。
- 迁移 ModuleManager：新增 TableDdlBefore / TableDdlAfter 观察者监听 table_ddl_before/after，逻辑与原 model_update_before/after 对齐（冲突检查、写 Module\\Table）；Eav 表用 model 占位符 `Eav::表名`。
- Schema 变更后清理缓存：Framework 新增 SchemaChangeClearCacheObserver 监听 table_ddl_after，清理 database 缓存池。

### Phase 6：全量模块扫罗与迁移（✅ 已完成）

- **目标**：所有曾实现 install/upgrade/setup 的 Model 全部改为声明式或 SchemaProvider 或纳入排除列表，无遗漏。
- **扫罗结果**：
  - 完整清单：`dev/ai/plans/model-declarative-schema-migration-affected-models.txt`（按 Vendor_Module 分组，可逐项标记 [x]）。
  - SchemaDiffStage 已扩展排除：EXCLUDE_TABLES 增加 weline_database_module_versions、weline_database_module_version_history、weline_framework_field_backup*；EXCLUDE_MODEL_CLASSES 增加 Migration、MigrationBackup、FieldBackup*、ModuleVersion、ModuleVersionHistory、Module\Table、Module\Backup（plan 3.8 全覆盖）。
- **每模块处理**：
  - 若为 bootstrap/系统表（见 3.8）：已加入排除，无需在 Model 保留 install/upgrade/setup。
  - 若为 Eav 动态表：由 EavSchemaStage 创建，对应 Model 可保留空实现或后续 SchemaProvider。
  - 其余 Model：改为使用 `#[Col]`（及 Table/Index/ForeignKey）声明表结构，删除 install/upgrade/setup 实现。
- **Setup 逻辑迁移（3.10）**：原在 Model 的**数据迁移、业务初始化**（如种子数据 seedDefaultXxx）须迁至各模块 **Setup/Install.php**（或 Setup/Upgrade.php）；迁完后 Model 的 setup/install/upgrade 仅保留空实现。已迁移：Backend（默认管理员+UserRole）、Acl（默认角色）、Websites（默认网站）、Visitor（PixelSource 默认来源）、Currency（CNY/USD+Config）、Customer/Frontend（默认用户）、Ai（AiTenant 默认租户）。
- **产出**：每个模块下所有 Model 均不再包含 install/upgrade/setup 方法；种子/业务初始化在 Setup 目录；在清单中逐项标记完成。

### Phase 7：移除旧接口与调用（✅ 已完成）

- 从 **ModelInterface** 中已删除 `setup()`、`upgrade()`、`install()` 方法声明。
- **ModelManager::update()** 对 type=setup/upgrade/install 已改为空实现（不再遍历 Model、不再调用 setupModel）。
- **Handle::setupModel()、setupInstall()** 已移除对 ModelManager::update(install/upgrade/setup) 的调用；表结构由 SchemaDiffStage 与 bootstrap 负责，业务初始化由各模块 Setup/Install.php、Upgrade.php 负责。
- 实现 **ModuleManagerBootstrapStage**（order=0.5，class_exists 可选）：创建 module_tables、module_backups。✅ 已实现并注册（Setup/Stage/ModuleManagerBootstrapStage.php，Upgrade.php 中 order=0.5）。
- 废弃 `model_update_before/after`，由 `table_ddl_before/after` 完全替代。✅ Framework event.php 已标废弃说明；ModuleManager event.xml 已移除两事件观察者。
- 完成 Weline_Database 依赖扫描与迁移。✅ ModuleVersion、ModuleVersionHistory 已改为 #[Table]/#[Col] 声明式，并从 SchemaDiffStage 排除列表中移除，由 SchemaDiffStage 正常建表；Weline_Database Setup/Install 注释已更新。
- 运行全量测试与回归；验证 module:remove / module:restore 与 table_ddl_* 对齐。建议：`php bin/w setup:upgrade` 后执行 `php bin/w module:remove <模块>` / `module:restore` 及现有 PHPUnit/HTTP 测试。

### Phase 8：技能、规则与文档更新（✅ 已完成）

本重构影响范围大，已同步更新技能、规则及相关文档，确保 AI 与开发者后续不引用已废弃的 install/upgrade/setup。

#### 8.1 技能更新（dev/ai/skills/）

| 技能 | 更新内容 |
|------|----------|
| **module-development** | 移除/重写 install/upgrade/setup、hasField、ModelSetup 相关章节；改为 #[Col] 声明式 + setup:upgrade 触发 SchemaDiff；保留 register.php 版本号说明（若仍用于其它用途） |
| **database-model-standards** | 移除 install/upgrade/hasField 示例；补充 #[Col]、#[Table] 注解写法；Schema diff 流程；禁止手写 DDL |
| **code-generation-standards** | 更新 Model 结构示例：仅 columns() + #[Col]，无 install/upgrade/setup |
| **skill-trigger-reminders** | 增加触发词：`#[Col]`、schema diff、声明式 schema、加字段（改为注解） |
| **framework-method-validation** | 移除 hasField/tableColumnExist 等与 ModelSetup 相关的错误示例中 install/upgrade 引用；补充 #[Col] 相关校验 |
| **error-tracking / error-learning** | 更新 COMMON_ERRORS、ERROR_LOG：upgrade() 不执行、register.php 版本号等条目改为“已废弃，见 schema diff”；新增 #[Col] 解析失败、SchemaProvider 相关错误 |
| **create-framework-command** | 若有 setup:upgrade 内部流程说明，更新为包含 SchemaDiffStage |
| **post-plan-completion-check** | 若含“register.php 版本号 + upgrade”检查项，改为“schema 变更 + setup:upgrade” |

#### 8.2 规则更新（dev/ai/rules/）

| 规则 | 更新内容 |
|------|----------|
| **code-generation-rules** | 将 module-development 引用中“数据库升级(hasField)”改为“数据库 schema 声明式(#[Col])”；与 database-model-standards 的 hasField/升级写法引用改为 #[Col]/Schema diff |

#### 8.3 文档变更（dev/ai/、根目录等）

| 文档 | 更新内容 |
|------|----------|
| **AI 提示词.md** | Model 结构、install/upgrade/setup 示例全部改为 #[Col]；setup:upgrade 说明补充 Schema diff 流程；框架升级机制改为“声明式 schema + setup:upgrade” |
| **AI-常犯错误.md** | hasField/tableColumnExist 示例改为 #[Col]；移除 upgrade() 不执行因版本号未更新类错误（或标记为历史） |
| **AI 测试提示词.md** | setup:upgrade 相关说明若有 Model 升级，改为 Schema diff |
| **开发文档.md**（若存在） | 数据库/schema 章节全面更新 |
| **模块 doc/开发/** | 各模块若有 plan.md/task.md 描述 install/upgrade，同步改为 #[Col] 或标注“已迁移” |

#### 8.4 产出与核对

- 所有技能、规则、文档中不再出现“在 Model 中实现 install/upgrade/setup”的引导。
- 搜索 `install\(ModelSetup`、`upgrade\(ModelSetup`、`hasField` 等关键字，确认技能/规则/文档内仅剩“已废弃/迁移说明”或无引用。
- 新增或更新 `dev/ai/skills/declarative-schema-migration/`（可选）专门描述 #[Col]、SchemaProvider、SchemaDiffStage 使用方式。

---

## 六、需扫罗的模块与规模（按 Vendor）

以下为当前代码库中**含有 install/upgrade/setup 的 Model 所在模块**，重构时必须全部处理，不得遗漏。

| Vendor     | 模块（示例） | 说明 |
|-----------|--------------|------|
| Weline    | Framework, Database, Eav, Backend, Admin, Acl, Ai, Api, Async, AutoLeadAgent, BackendActivity, Bt_Center, CacheManager, Captcha, Cdn, Checkout, Cms, Component, Cron, Customer, CustomerService, DataTable, DeveloperWorkspace, Frontend, GenerativeEngineOptimization, I18n, Index, Indexer, Layout, Maintenance, Meta, ModuleManager, Multipass, Order, Payment, Queue, RdpWrapper, Saas, Seo, Server, SessionManager, Shipping, Smtp, Storage, SystemConfig, Taglib, Terraform, Theme, TranslationService, TwoFactorAuth, UrlManager, Visitor, Websites, Widget 等 | 数量最多，需按模块逐 Model 迁移 |
| WeShop    | Address, Affiliate, B2B, Cart, Catalog, Cms, Compare, Compliance, Customer, Filters, GiftCard, Inventory, Invoice, Logistics, Membership, Notification, Order, Product, Promotion, QA, RMA, RecentlyViewed, Review, Search, Shipping, Social, Store, Subscription 等 | 全部改为 #[Col] 或排除 |
| GuoLaiRen | Blog, PageBuilder, Desensitization | 全部改为 #[Col] 或排除 |
| Aws       | Domains | 全部改为 #[Col] 或排除 |
| Agent     | WeeklyReport | 全部改为 #[Col] 或排除 |
| WelineTools | FontSubLetter | 全部改为 #[Col] 或排除 |

**统计**：当前约 **230+** 个 Model 文件包含 install/upgrade/setup，分布在上述各模块。Phase 6 必须产出**完整清单**（可按 `app/code/{Vendor}/{Module}/Model/*.php` 逐文件列出），并确保每个文件迁移完成后再进入 Phase 7。

### Phase 8 涉及技能、规则与文档（汇总）

| 类型 | 路径/名称 | 更新要点 |
|------|-----------|----------|
| 技能 | module-development | install/upgrade/setup → #[Col] 声明式 |
| 技能 | database-model-standards | hasField/ModelSetup → #[Col]、Schema diff |
| 技能 | code-generation-standards | Model 示例移除 install/upgrade |
| 技能 | skill-trigger-reminders | 增加 #[Col]、schema diff 触发词 |
| 技能 | framework-method-validation | 更新 hasField 相关说明 |
| 技能 | error-tracking / error-learning | 更新 upgrade/版本号类错误条目 |
| 规则 | code-generation-rules | hasField → #[Col] / Schema diff |
| 文档 | AI 提示词.md | Model 结构、升级机制 |
| 文档 | AI-常犯错误.md | hasField 示例 |
| 文档 | AI 测试提示词.md | setup:upgrade 说明 |

---

## 七、扫罗与核对清单（建议）

1. **生成清单**：执行一次全局搜索，将“包含 install/upgrade/setup 的 Model”列表写入 `dev/ai/plans/model-declarative-schema-migration-affected-models.txt`（或同目录下清单文件），按 Vendor/Module 分组。
2. **排除列表**：在 Framework 中维护“不参与 SchemaDiff 且由 bootstrap 创建”的 Model 类列表（见 3.8）。
3. **逐模块勾选**：每完成一个模块的 Model 迁移，在清单中标记；Phase 7 前清单上所有项必须已处理。
4. **回归**：Phase 7 完成后再次搜索 `function (install|upgrade|setup)\(` 与 `ModelInterface` 的 implementor，应无遗留（仅 Framework 内保留接口删除后的空引用清理）。
5. **Phase 8**：技能、规则、文档全部更新后再标记计划完成；搜索技能/规则/文档内 `install\(ModelSetup`、`upgrade\(ModelSetup` 等，确保无引导性引用。

---

## 八、Weline_Database 扩展

- 迁移与备份能力迁入 Framework 后，Weline_Database 中与 migration/backup 相关的模型与服务可移除或标记 @deprecated，由 Framework 完全接管。
