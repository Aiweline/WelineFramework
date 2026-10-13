# Composer path repository：必须 symlink（权威）

> 更新时间: 2026-10-12  
> 归属：仓库根 Composer 安装合同（`composer.json` / `composer.lock`）+ Framework 护栏契约  
> 交叉引用：Theme [`布局固化与默认注入.md`](../../../Theme/doc/布局固化与默认注入.md) §3.5（design→generated 权威源 vs 派生物，同类口令）  
> 护栏：`Weline\Framework\Test\Unit\Composer\ComposerPathSymlinkContractTest`  
> 工具：`php bin/w composer:path-transport-sync`（只同步 transport-options，不 update 依赖图）

## 1. 口令

| 层 | 权威源（可编辑） | 派生物 / 安装物 | 合法传播 |
|----|------------------|-----------------|----------|
| 模块 PHP | `app/code/Weline/{Module}/**` | `vendor/weline/module-*`（path 安装） | Composer path **symlink**（同 inode） |

**只改权威源；安装物与权威同树；禁止人手双写 vendor。**

## 2. 合同（硬）

1. 仓库根 `composer.json` 的 path repository（`app/code/Weline/*`）**必须** `options.symlink: true`。  
2. `composer.lock` 对应 path 包须在**包根**带 `transport-options.symlink: true`（不是仅 `dist.transport-options`）。  
3. 安装结果：`vendor/weline/module-theme` 等应为 symlink → `../../app/code/Weline/Theme`（或等价相对路径）；`stat` inode 与 `app/code` 一致。  
4. **PHPUnit 与 `app/code` 同源**：`vendor/autoload` 与 Framework `generated/psr4_map` 加载的类文件必须落在同一真实树（`ReflectionClass::getFileName()` 抽检）。  
5. **禁止双写**：禁止长期 `cp` / `rsync` `app/code` ↔ `vendor` 当开发流程；发现内容 diff → 当作安装事故，修 symlink/重装，禁止手工抄平结案。  
6. **整组一致**：path 下全部 `weline/*` 须 symlink；禁止只 symlink Theme、其它模块仍 directory 副本。  
7. **与 compile 禁令区分**：历史约束「**`app/code` 内**禁止放模块自引用 symlink，否则 `framework:compile` 失败」仍然有效。Composer **从 `vendor` 链到 `app/code`** 不属于「在 `app/code` 内放 symlink」，二者勿混。  
8. **禁止孤儿 directory**：`vendor/weline/*` 每个条目必须满足——在 `composer.lock` path 集合内 **且** 为 symlink → `app/code/...`；或 **不存在**。禁止第三态：非 lock 的 directory / 破损 link。权威在 `app/code` 时，确认后删除孤儿，禁止再 `cp` 填回。

## 3. reinstall 生命周期（硬 · path 日常运维面）

Path 包 lock 内 `version` 常钉在历史分支名（如 `dev-codex/...`）。工作区切换分支后，Composer 解析器可能认为 lock 与 path 暴露 version 不一致 → **`composer update --lock` 失败是预期**，不是「坏了就要全图 update」。

### 3.1 合法收敛（只改运输，不改依赖图）

1. 确认 `composer.json` path repo `options.symlink`（本仓为 `true`）。  
2. 对齐 lock / installed 的**包根** `transport-options.symlink`（可用工具，禁止手改 `version`）：  
   `php bin/w composer:path-transport-sync`  
   （预览：`--dry-run`；会备份 lock / installed 再写。）  
3. 可控重装（本仓已验证）：  
   `php composer.phar reinstall 'weline/*' --no-scripts`  
   或系统 Composer 等价命令。  
4. 抽检：`readlink vendor/weline/module-theme`；契约 `ComposerPathSymlinkContractTest` 绿。

### 3.2 明确禁止

1. **禁止**把 `composer update` / `update --lock` / `update -W` 当 path 包日常工具（分支钉死下易失败或拖动全图）。  
2. **禁止**手改 lock 里 path 包的 `version` 字段去对齐当前 git 分支（伪绿、难审计）。  
3. **禁止**每个会话发明第三种安装故事（`cp` / 半 symlink / 只修一个包）。  
4. **禁止**在未备份 lock 时重写 `content-hash`（本工具**不**改 content-hash）。  
5. **消钉**（统一 path `version` / `branch-alias`）属独立 plan（`composer-path-version-stabilize`），**不**在本生命周期内半套执行。

### 3.3 Agent / 开发者操作摘要

1. **只编辑** `app/code/Weline/...`（权威源）。  
2. 确认：`ls -ld vendor/weline/module-theme` 为 symlink；若仍是 directory → 走 §3.1，不要双写。  
3. 必要时 `php bin/w framework:compile` / `setup:upgrade` 刷新生成映射（**禁止手改** `generated/psr4_map.php`）。  
4. 跑 PHPUnit 前抽检关键类 `ReflectionClass` 路径落在 `app/code` 实树。

## 4. 护栏与验收指针

- 契约：`app/code/Weline/Framework/Test/Unit/Composer/ComposerPathSymlinkContractTest.php`  
  - 抽样 theme/framework/i18n symlink；Theme 反射落在 app/code；`app/code` 内无自引用 symlink  
  - **孤儿 directory = fail**；Captcha/Shipping 权威在 `app/code` 且 vendor 无对应孤儿  
  - lock path 包 `transport-options.symlink=true`  
- 工具：`composer:path-transport-sync`（只同步 transport-options → 提示 reinstall）  
- 真机抽检：`readlink vendor/weline/module-theme`；`vendor/weline` 下无非 symlink 目录。

## 5. not_to_do

- 禁止只改 vendor 副本，或只改 app/code 却依赖「碰巧 OM 优先 psr4_map」假装已同步。  
- 禁止为修单测去改测试加载另一棵树而不修安装合同。  
- 禁止在 Theme（或其它业务模块）内再造「同步到 vendor」Service。  
- 禁止手改 `generated/psr4_map.php`。  
- 禁止把 orphan 再 rsync 成「完整副本」冒充修复。  
- 禁止为「过 lock」而 `composer update -W` 全图升级。
