# 模块卸载 / 数据包（MDP）架构扩展方案（完整版）

> 本文在已实现 **MDP v1**（见同目录 `module-mdp-uninstall-restore.md`）之上，描述可演进完整架构，供产品与技术分阶段实施。

---

## 1. 目标与边界

| 目标 | 说明 |
|------|------|
| **卸载必有可追溯数据副本** | 默认策略下，硬卸载前生成可离线保存的 MDP；失败可配置中止。 |
| **重装可恢复** | 同主版本或兼容版本内，通过 CLI/后台从 MDP 灌回；跨大版本需迁移脚本。 |
| **元数据与真实归属一致** | `weline_module_table` 与「谁拥有表、谁可写 DDL」不脱节，避免 Saas/Websites 类冲突。 |
| **运维可审计** | 谁在何时卸载、包路径、是否恢复、是否删表。 |

**边界**：MDP 不替代整库灾备；多租户场景需在包内带租户维度（若未来启用）。

---

## 2. 概念模型

### 2.1 卸载层级

| 层级 | 行为 | MDP | 业务表 | `weline_module_table` |
|------|------|-----|--------|------------------------|
| **禁用** | 模块不加载 | 否 | 不动 | 不动 |
| **软卸载** | 从 `modules` 移除、清注册表 | 建议强制 | **保留** | **释放或转移归属**（见 §3） |
| **硬卸载** | 上 + 可选删表 | **强制** | 按策略删或保留 | 删除该模块登记行 |

### 2.2 表策略（Table Policy）

在 `weline_module_table` 扩展或通过 **`weline_module_table_policy`** 关联表：

| 策略 | 含义 | 卸载时 MDP | 软卸载后 DDL |
|------|------|------------|--------------|
| **owned** | 模块独占 | 全量导出 | 仅该模块可改结构；卸模块可删登记或标记 orphan |
| **shared** | 多模块读、单一 owner 写结构 | owner 负责备份；subscriber 不重复占 `name` | `TableDdlBefore` 仅 owner 模块通过 |
| **successor** | 表已迁到新模块（如 Saas→Websites） | 由 **successor** 模块打包 | 旧模块行删除或 `module_name` 指向 successor |

### 2.3 模块数据包 MDP（版本演进）

| 字段 / 文件 | v1（已有） | v2+ 建议 |
|-------------|------------|----------|
| manifest | schema_version, module_name, tables[] | 增加 **module_version**, **db_driver**, **prefix**, **tenant_id** |
| 数据文件 | 整表 JSON | 大表 **chunk_001.jsonl** + 总行数校验 |
| 注册快照 | module_table_registry | 同上 + **field_definitions 可选快照**（利于跨版本对齐） |
| 完整性 | sha256 per file | **manifest 总签名**（HMAC 或 GPG） |

---

## 3. 元数据与冲突治理

### 3.1 `weline_module_table` 扩展

建议新增（迁移脚本）：

- `table_policy`：`owned | shared | successor`
- `owner_module_name`：shared 时结构 owner
- `successor_module_name` / `deprecated_at`：合并迁移用

### 3.2 `TableDdlBefore` / `TableDdlAfter` 联动

- **创建表**：写入 policy，默认 `owned`。
- **合并模块**：提供 **`module:table-handover --from=Saas --to=Websites --tables=...`**：更新 `module_name` + `model` + `successor`，避免双登记。
- **冲突检测**：若 `name` 已存在且非同一 `model` 且非 `successor` 链 → 仍抛错；若配置为 successor 则允许新模块 DDL 与旧行合并逻辑（由运维先 handover）。

### 3.3 软卸载后的「孤儿表」

- 软卸载：**不删表**，将 `weline_module_table` 中该模块行改为 `module_name = __orphan__` 或删除行但保留 **MDP + 文档** 说明表仍存活。
- 后续 **`setup:upgrade`** 若某 Model 指向未登记表 → 可提示「从 MDP 恢复登记或执行 handover」。

---

## 4. 编排与服务边界

### 4.1 `ModuleUninstallOrchestrator`（扩展职责）

| 阶段 | 动作 |
|------|------|
| T0 | 解析影响表（注册表 + policy） |
| T1 | **MDP 生成**（v2：分块/压缩） |
| T2 | 写 **审计记录**（DB 表 `weline_module_uninstall_audit` 或仅写日志 + manifest 路径） |
| T3 | 软/硬分支：重命名备份或跳过 |
| T4 | 硬卸载：`DROP` 仅当 policy 允许且用户二次确认 |
| T5 | 清理 ACL/路由/模块列表（现有逻辑） |

### 4.2 `ModuleReinstallOrchestrator`（新建）

- 检测：**已安装模块 + 空表 + 存在历史 MDP** → 提示恢复向导。
- 输入：包路径、是否 **truncate**、**字段映射版本**（可选 YAML）。
- 输出：行数校验、失败回滚策略（事务 per table 或全失败停）。

### 4.3 与现有能力关系

- **ModuleBackupService（重命名表）**：与 MDP **互补**；长期可配置「仅 MDP / MDP+重命名 / 仅重命名」。
- **`var/backup/module_uninstall`（Create 向导 SQL）**：逐步统一到 MDP 格式或双写同一 manifest 索引。

---

## 5. 存储与安全

| 项 | 建议 |
|----|------|
| **路径** | 保留 `var/module_data_packages/`；生产可 **软链到 NFS/对象存储挂载点**。 |
| **保留期** | 配置 `mdp_retention_days`、最大包数；超期归档或删（需审计）。 |
| **加密** | 敏感模块：`mdp_encrypt=1`，密钥 `env` / KMS；manifest 仅存密文路径与 key id。 |
| **权限** | 目录 `0700` / 仅 PHP-FPM 与 CLI 用户可读。 |

---

## 6. 规模与性能

| 场景 | 方案 |
|------|------|
| 大表（百万行+） | **流式游标**导出 JSONL；chunk 大小可配；恢复用 **批量 INSERT**（如 500～2000 行/批）。 |
| 宽表 / BLOB | BLOB **base64** 或 **旁路文件** `blobs/{table}/{pk}.bin` + manifest 引用。 |
| 内存 | T1 阶段限制 `memory_limit` 或强制仅流式路径。 |

---

## 7. 恢复与版本兼容

- **manifest.module_version** 与 **当前安装模块 version** 对比：  
  - 同 **minor**：直接恢复。  
  - **major 升级**：必须存在 **`Setup/Db/Migration` 或 `mdp_restore_map.php`** 做列重命名/缺列默认。  
- **dry-run**：`module:data-package restore --dry-run` 仅校验行数与 NOT NULL 冲突。

---

## 8. 后台与管理 UI（可选产品化）

- **模块管理 → 数据包**：列表、下载 zip、删除包、**从包恢复**（高危二次确认 + ACL）。  
- **卸载向导**：展示将备份的表清单、预估体积、勾选「硬卸载删表」。  
- **审计页**：模块名、时间、操作人、包路径、是否已恢复。

---

## 9. 多租户（若适用）

- manifest 增加 **`tenant_id`**；恢复时 **禁止跨租户** 导入。  
- 备份目录可按 `tenant_id/module/package_id` 分桶。

---

## 10. 事件与扩展点

| 事件名（建议） | 时机 |
|----------------|------|
| `Weline_ModuleManager::mdp_before_create` | 允许插件过滤表列表或加密 |
| `Weline_ModuleManager::mdp_after_create` | 上传异地备份 |
| `Weline_ModuleManager::restore_before` / `restore_after` | 二次校验 |

---

## 11. 实施路线图（建议）

| 阶段 | 内容 | 依赖 | 状态 |
|------|------|------|------|
| **P0** | MDP v1 + 卸载编排 + CLI restore | — | ✅ |
| **P1** | `table_policy` + **handover 命令** + 文档 | DB 迁移 | ✅（迁移 + CLI，见 uninstall-restore 摘要） |
| **P1** | `TableDdlBefore` / `TableDdlAfter` successor + shared | P1 元数据 | ✅ |
| **P2** | 大表 JSONL 分块 + 批量恢复 + `restore --dry-run` | — | ✅ |
| **P2** | 审计表 `module_uninstall_audit` + 卸载/恢复写入 | — | ✅ |
| **P3** | 加密 MDP、后台 UI | P2 | 未做 |
| **P3** | Reinstall 向导 + 版本映射 | P2 | 未做 |

---

## 12. 与当前代码的映射

| 扩展项 | 当前落点建议 |
|--------|----------------|
| policy 字段 | `Framework/Setup` 迁移 + `ModuleTable` 模型 |
| handover | `Weline\ModuleManager\Console\Module\TableHandover` |
| 分块 MDP | `ModuleDataPackageService::createPackage` 重构 |
| 审计 | 新 Model `ModuleUninstallAudit` |
| 后台 | `Weline_ModuleManager\Controller\Backend\DataPackage` |

---

**文档维护**：随实现进度在「§11 路线图」勾选完成项，并在 `module-mdp-uninstall-restore.md` 保持「已实现」摘要同步。
