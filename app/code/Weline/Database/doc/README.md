<!-- weline:module-readme:auto-generated -->
# Weline_Database 模块文档

> 本 README 由 `prepare_project 文档修复流程` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前必须先完成 `prepare_project`；进入 `ready` 后调用 `resolve_task_context`，由 MCP 按当前任务返回本模块的最小文档集合。全局门禁见 `app/code/Weline/Ai/doc/AI开发治理.md`。

## 模块定位

- 模块代码：`Weline_Database`
- 目录：`app/code/Weline/Database`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。
- 数据库管理页挂载到 Backend 的数据工具 ACL 资源，Backend 是必需依赖；命令行数据库能力仍保持模块内聚。
- 模块代码、Model Schema、数据迁移、备份、补偿和版本游标的联动回滚由本模块单一持有；ModuleManager 只通过公开接口引用。

## 代码面概览

入口文件：
- `app/code/Weline/Database/etc/module.xml`
- `app/code/Weline/Database/etc/backend/menu.xml`

- `Console`：php bin/w 命令入口。 文件数：6
- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：1
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：1
- `Helper`：模块内辅助能力。 文件数：2
- `Interface`：已发布接口契约；跨模块依赖优先使用这里。 文件数：1
- `Model`：ORM 模型与字段 schema。 文件数：5
- `Observer`：事件观察者与订阅逻辑。 文件数：2
- `Service`：业务编排与模块服务层。 文件数：9
- `Setup`：安装/升级装配。 文件数：1
- `etc`：模块配置。 文件数：4
- `i18n`：国际化资源。 文件数：2
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## Setup 迁移游标契约

- 每次非 hot `setup:upgrade` 会显式传递同一个 `operation_id` 给 Schema Diff/checkpoint 与 Setup Observer 脚本迁移；字段上限为 64 字符，一次命令不得分裂为多个 ID。
- `MigrationService::upgradeMigration()` 的 ID 参数保持可选；只有 Setup Observer 显式传入时才关联本轮命令，独立 migration/rollback 不会隐式继承进程上下文。
- script 记录先以 `running` 落盘时即写入 ID，后续成功或失败状态变化不得替换该 ID。无 diff/无 pending 的 Run2 不创建空 ID 占位记录。
- `module_version.current_version` 与 `last_migration` 是两个独立游标：完整 `setup:upgrade` 即使没有待执行脚本，也仍可把较旧的 runtime version 对齐到当前代码版本。
- 没有待执行迁移时，Observer 向 VersionService 传递“本轮无新迁移”，不得用空字符串覆盖既有 `last_migration`；runtime version 已相同时直接保持原记录。
- 只有 `MigrationService::upgradeMigration()` 明确返回成功后，Observer 才把该迁移文件名作为新的 `last_migration`；一批迁移全部成功后提交最后一条成功文件名。
- 任一迁移返回失败或抛异常时，模块 reconciliation 不执行，runtime version 与 `last_migration` 都不得伪装成成功状态；数据库版本高于 runtime version 的漂移阻断保持不变。
- `upgrade_migrations` 位于 ModuleSetup 之前：模块仍带 `installing`、`upgrading` 或 `pending_setup_upgrade` 时只执行文件迁移并校验数据库游标不得高于目标代码版本，不得用旧 `setup_version` 提交游标；ModuleSetup 成功清除 pending 标志后，由 `upgrade_after` 跳过二次迁移并以已完成的 `setup_version` 原子 reconcile。

## 本模块文档资产

- `app/code/Weline/Database/doc/开发/plan.md`
- `app/code/Weline/Database/doc/开发/task.md`
- `app/code/Weline/Database/doc/开发/数据库管理后台路由烟测.md`
- `app/code/Weline/Database/doc/开发/数据库迁移系统开发文档.md`
- `app/code/Weline/Database/doc/开发/模块代码与数据库一致性回滚.md`
- `app/code/Weline/Database/doc/用户/数据库迁移系统使用手册.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
