---
name: CI发布工程师-分仓发布
description: >-
  口令「分仓」触发：将 DEV-workspace 中 app/code/Weline 模块同步到项目兄弟目录“框架-分仓”
  下的独立 Composer 分仓；本地缺仓库时自动从 Gitee/GitHub clone，递增 git tag 后推送并刷新 Packagist。
  仅在用户明确说出口令「分仓」时加载；不得因闲聊、发布、部署等相近词自动触发。
  Keywords: 分仓, fencang, split-repo, composer sync, weline mirror.
version: 1.3.0
---

# 分仓发布（口令门控）

## 全平台入口（canonical）

| 平台 | 技能路径 |
|---|---|
| 全 AI 路由索引 | `dev/ai/skills/_index.md`（口令「分仓」行） |
| **Canonical 技能正文** | `dev/ai/skills/CI发布工程师-分仓发布/SKILL.md` |
| **分仓脚本（唯一位置）** | `dev/tools/fencang/` |
| Codex | `.codex/skills/fencang-release/SKILL.md` |
| Cursor（本地） | `.cursor/skills/CI发布工程师-分仓发布/SKILL.md`（须与 canonical 同步） |

脚本目录固定为 `dev/tools/fencang/`，各平台不得在 `.cursor/` 等处另写副本。

## 口令与范围（强制）

**仅当用户消息包含口令 `分仓` 时**，才加载并执行本技能。

### 口令格式

| 用户说法 | 处理范围 |
|---|---|
| `分仓 Framework` | **只**处理 `Framework` 一个模块 |
| `分仓 Admin` | **只**处理 `Admin` 一个模块 |
| `分仓 Admin,Backend` | **只**处理列出的模块（逗号/空格分隔） |
| `分仓 --all` | 处理映射表内**全部**可分仓模块 |
| 仅说 `分仓`（无模块名、无 `--all`） | **不执行**；询问要分仓哪个模块 |

### 关键规则

- **「分仓 + 模块名」= 单模块（或用户点名的少数模块），不得擅自扩大到其他模块。**
- 不得因用户说了「分仓 Framework」就顺带处理 `Backend`、`Acl` 等同批模块。
- 口令缺失或仅说「同步模块」「发布 composer 包」「分仓库」等近似但不含「分仓」的表述 → **拒绝执行**，并提示须说「分仓」。
- 模块名使用 DEV 目录名（`PascalCase`），例如 `Framework`、`Admin`、`BackendActivity`、`ThemeFancy`。

## 目标

| 源（开发单体） | 目标（Composer 分仓） |
|---|---|
| `{DEV_ROOT}/app/code/Weline/{Module}/` | `{DEV_ROOT}/../框架-分仓/{repo}/` |

默认不再要求用户指定本机分仓集合路径。脚本从当前 DEV-workspace 反推项目父目录，并使用同级兄弟目录 `框架-分仓` 作为分仓集合；如果用户明确传 `-WelineRoot`，才使用指定路径（例如兼容旧 Windows 本地 `E:\WelineFramework\weline`）。

目标仓库不存在时，脚本自动按下方“远端仓库记录”clone 到 `框架-分仓/{repo}`；目标仓库已存在且有 `.git` 时不重新 clone，只补缺失 remote、fetch、检查工作区并继续原分仓流程。目标目录已存在但不是 git 仓库且非空时，必须停止，禁止覆盖。

分仓完成后，对每个有变更的仓库：

1. 按 **git tag 三位版本号** 递增（见下文规则）
2. `git commit`
3. 创建新 tag
4. 推送到 **Gitee（`origin`）** 与 **GitHub（`github`）**
5. **触发 Packagist 包索引刷新**（解决 git 已推送但 Composer 包管理未及时更新）

## 版本号规则（git tag）

格式：`vMAJOR.MINOR.PATCH`（前缀 `v` + 三位数字，点分隔）。

**尾数加一，满十进位**：

1. `PATCH`（末位）+1
2. 若 `PATCH > 9`，则 `PATCH = 0`，`MINOR + 1`
3. 若 `MINOR > 9`，则 `MINOR = 0`，`MAJOR + 1`

示例：

| 当前 tag | 新 tag |
|---|---|
| `v1.2.0` | `v1.2.1` |
| `v1.2.8` | `v1.2.9` |
| `v1.2.9` | `v1.3.0` |
| `v1.9.9` | `v2.0.0` |

- 以仓库 **已有 tag 中语义最高** 的版本为基准；无 tag 时首个 tag 为 `v1.0.0`。
- **只改 git tag**；`register.php` 内模块版本字段不在此流程自动修改，除非用户另行要求。

## 模块 ↔ 分仓目录映射

| DEV 模块目录 | 分仓目录 / 远端仓库名 |
|---|---|
| `Framework` | `weline-framework` |
| `ThemeFancy` | `weline-module-theme-francy`（目录名历史拼写，Composer 包名仍为 `weline/module-theme-fancy`） |
| 其余 `PascalCase` 模块 | `weline-module-{kebab-case}` |

kebab-case 示例：`BackendActivity` → `weline-module-backend-activity`，`CKEditorEditorManager` → `weline-module-ck-editor-editor-manager`。

完整可分仓清单记录在 `dev/tools/fencang/fencang-sync.ps1` 的 `$ModuleRepoMap` 中。`Framework`、`ThemeFancy` 使用历史特例，其余模块按 kebab-case 生成。`Base`、`Deploy`、`Ai` 等未进入映射表的 DEV 模块不自动分仓。

**禁止**用 DEV 内容覆盖 weline 独有仓库（如 `weline-module-ali-ddns-server`）——DEV 无对应模块时不操作。

## 远端仓库记录与自动 clone

脚本按分仓目录名生成双端 remote：

| remote | URL |
|---|---|
| `origin`（Gitee） | `https://gitee.com/aiweline/{repo}.git` |
| `github`（GitHub） | `https://github.com/Aiweline/{repo}.git` |

自动 clone 规则：

- 未传 `-WelineRoot`：先确保 `{DEV_ROOT}/../框架-分仓/` 存在。
- `{repo}/.git` 已存在：不重新 clone；若缺 `origin` 或 `github` remote，则按上表补齐；已有 remote URL 不强制覆盖。
- `{repo}` 不存在或为空目录：先 clone Gitee `origin`，失败再尝试 GitHub，并在 clone 后补齐双端 remote。
- `{repo}` 已存在、非空、但不是 git 仓库：停止并报告，禁止把 DEV 内容镜像进去。
- 如需保留“缺仓库就报错”的旧行为，显式传 `-NoAutoClone`。

## 执行流程

### 0. 前置检查

- 确认口令 `分仓` 已出现。
- **解析范围**：用户若指定模块名 → 目标列表**仅含该模块**（或用户列出的多个）；仅 `--all` 时才全量。
- 未指定模块且未说 `--all` → 停止并询问模块名，**禁止默认全量**。
- 未指定 `-WelineRoot` 时，分仓集合默认为 DEV-workspace 兄弟目录 `框架-分仓`。
- 每个目标分仓：缺仓库先按远端记录自动 clone；已有仓库则不重新 clone。
- 每个目标分仓：`git status --porcelain` 必须干净；不干净时停止该仓库，禁止镜像覆盖本地未提交改动。
- 每个目标分仓：逐个 remote 执行 `git fetch <remote> --tags`；fetch 失败记录 warning 但不阻断 dry-run/差异预检，真正 `push origin` / `push github` 仍然严格失败即报。tag 以本地和成功 fetch 后可见的最高语义版本为准。

### 1. 预检差异（无变更则整仓跳过）

**先**对比 DEV 源目录与分仓目录（排除 `.git`、`vendor`、`.idea`、`node_modules`、`.DS_Store` 等，规则与实际同步一致）：

- Windows 有 `robocopy` 时使用 `robocopy /L /MIR` 预检。
- macOS / Linux / 无 `robocopy` 环境使用 PowerShell 文件索引 + SHA256 hash 预检。
- 无差异 → 该仓库**整仓跳过**：不镜像、不 `git add/commit/tag/push`、不刷新 Packagist；报告状态 `no-change`。
- 有差异 → 进入下一步实际同步。

`DryRun` 模式同样先做预检：无差异则报告「无需分仓」；有差异才预览新 tag。

### 2. 拷贝源码

将 DEV 模块**内容**镜像到分仓根目录（不是套一层模块名子目录）：

Windows 有 `robocopy` 时使用 `robocopy /MIR`；macOS / Linux / 无 `robocopy` 环境使用脚本内置镜像器（复制新增/变更文件，删除 DEV 已删除且未被排除的文件，保留 `.git`、`vendor` 等排除目录）。

- `/MIR`：DEV 已删除的文件在分仓也删除。
- 若用户要求非镜像，改用 `/E` 仅覆盖不删除。

### 3. 审查 git 工作区

```powershell
git diff --stat
git diff composer.json
```

同步后若 git 仍无有效变更（极端边界）→ 跳过 commit/tag/push。

### 4. 递增 tag、提交、双端推送

对每个有变更的仓库：

```powershell
# 计算新 tag（见 scripts/bump-tag.ps1）
git add -A
git commit -m "sync: 从 DEV-workspace 同步 {Module} 模块"
git tag vX.Y.Z
git push origin master
git push origin vX.Y.Z
git push github master
git push github vX.Y.Z
```

- 默认分支：`master`（各分仓若不同，以 `git branch --show-current` 为准）。
- 远程约定：`origin` = Gitee，`github` = GitHub。
- 任一端 push 失败 → 停止该仓库后续步骤，报告错误，不假装成功。

### 5. 刷新 Packagist（Composer 包管理）

git 推送成功后，**立即主动调用 Packagist API**，不要干等 WebHook：

```powershell
# 由 dev/tools/fencang/refresh-packagist.ps1 自动执行
POST https://packagist.org/api/update-package
Authorization: Bearer {username}:{token}
Body: {"repository":{"url":"https://github.com/Aiweline/{repo}"}}
```

说明：

- Weline 分仓包在 **packagist.org** 发布（如 `weline/module-admin`）；Packagist 以 **GitHub 仓库 URL** 为 canonical repository。
- 分仓 push 完成 → 立刻 `update-package`，让 Packagist 重新抓取 tag/分支元数据。
- Packagist 凭据不写入仓库；脚本按顺序读取：
  - `WELINE_PACKAGIST_USERNAME` / `WELINE_PACKAGIST_API_TOKEN`
  - `PACKAGIST_USERNAME` / `PACKAGIST_API_TOKEN`
  - 本机私有文件 `dev/tools/fencang/packagist.local.json`，格式：`{"username":"...","token":"..."}`
- Packagist 刷新失败但 git 已推送 → 状态记为 `ok-push-only`，报告中单独提示，不假装 Composer 已更新。

### 6. 输出报告

汇总每个模块：是否同步、旧 tag、新 tag、Composer 包名、Packagist 刷新结果、Gitee/GitHub 推送结果。

## 脚本

优先调用 `dev/tools/fencang/` 下脚本，保证版本递增规则一致：

```powershell
$Fencang = 'dev\tools\fencang'

# 预览下一个 tag
.\$Fencang\bump-tag.ps1 -CurrentTag v1.2.9

# Windows：单模块分仓（默认目标 E:\WelineFramework\框架-分仓\...）
.\$Fencang\fencang-sync.ps1 -Modules Framework

# 多模块（用户点名多个时）
.\$Fencang\fencang-sync.ps1 -Modules Admin,Backend

# 全量（仅用户明确说「分仓 --all」时）
.\$Fencang\fencang-sync.ps1 -All

# 兼容旧 Windows 本地分仓集合
.\$Fencang\fencang-sync.ps1 -Modules Framework -WelineRoot E:\WelineFramework\weline

# macOS / Linux：使用 PowerShell Core，默认目标为 DEV-workspace 同级“框架-分仓”
pwsh ./dev/tools/fencang/fencang-sync.ps1 -Modules Framework

# 仅手动刷新某个分仓的 Packagist 索引（git 已推但 Composer 未更新时）
.\$Fencang\refresh-packagist.ps1 -RepoPath E:\WelineFramework\框架-分仓\weline-module-admin
```

## 约束

- 遵守 `dev/ai/global-constraints.md`：禁止未经审阅的批量机械改写；本技能是**经口令授权的受控分仓流程**，逐模块执行并审查 diff。
- 不得在口令缺失时主动建议或执行分仓。
- Gitee/GitHub 凭据由本机 git credential/remote 提供；Packagist 凭据只从环境变量或本机私有 `packagist.local.json` 读取，不写入仓库。
- 用户未要求时，不修改 DEV-workspace 根 `composer.json` 或 `vendor/`。

## 相关路径

- 开发单体：当前 DEV-workspace，即 `{DEV_ROOT}`
- 默认分仓集合：`{DEV_ROOT}/../框架-分仓`
- 旧 Windows 本地集合：`E:\WelineFramework\weline`（仅显式传 `-WelineRoot` 时使用）
- Composer 聚合依赖参考：`app/code/Weline/Base/composer.json`
