# 模块数据包 MDP（卸载备份 / 重装恢复）

## 已实现

### v1 / v2 数据包

1. **卸载前**（`module:remove` → `Weline_Framework_Module::remove_before_backup`）  
   - 先写 **MDP** 到 `var/module_data_packages/{模块名}/{package_id}/`：`manifest.json`、`module_table_registry.json`、`tables/*.json`（小表）或 **`tables/*_part00001.jsonl`…**（大表，v2）。  
   - 再执行 **表重命名备份**（`ModuleBackupService`）。  
2. **配置** `Weline_ModuleManager/etc/env.php`：  
   - `module_uninstall_mdp_strict`（默认 `1`，MDP 失败则中止卸载）  
   - `mdp_chunk_rows`（默认 `10000`，超过则按表输出 JSONL 分块，`manifest.schema_version=2`）  
3. **命令**  
   - `php bin/w module:data-package list [筛选]`  
   - `php bin/w module:data-package create <模块名>`  
   - `php bin/w module:data-package restore --path=<包目录> [--no-truncate] [--dry-run]`  
   - `php bin/w module:table-handover --from=<模块> --to=<模块> --map=逻辑表=模型FQCN [--map=...] [--mark-successor]`（需执行 `command:upgrade` 注册后可用）  

### 表策略与冲突

- `weline_module_table` 扩展：`table_policy`（owned/shared/successor）、`owner_module_name`、`successor_module_name`、`deprecated_at`（迁移 `add_module_table_policy_and_audit_20250318-v1.0.2`）。  
- **TableDdlBefore**：successor 且当前模块为承接方时放行 DDL；shared 仅 owner 可 DDL。  
- **TableDdlAfter**：successor 承接方跑完 DDL 后 **更新登记行**（module_name/model，清 successor）。  

### 审计

- 表 **`module_uninstall_audit`**：卸载成功后备份链路写 `uninstall_before`；`module:data-package restore` 成功写 `restore`。  

## 完整架构扩展（加密 / 后台 UI / Reinstall 向导等）

见同目录：**[module-mdp-architecture-extension.md](./module-mdp-architecture-extension.md)**。
