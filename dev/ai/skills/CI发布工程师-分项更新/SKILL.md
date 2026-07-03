---
name: CI发布工程师-分项更新
description: >-
  口令「分项」触发：当前核心仓（Windows 默认 E:\WelineFramework\DEV-workspace；
  macOS 默认脚本所在核心仓，如 /Users/weline/Project/Official/框架）先提交并推送当前核心分支，
  再让默认分项目标执行 php bin/w core:update -b <branch> 同步最新核心代码，
  并在子项目中只提交/推送框架更新产生的变更；
  若站点 WLS 正在运行，更新后执行 server:reload。
  用户只说「分项」时默认分支为 dev；用户说「分项 <分支>」时按指定分支处理。
  仅在用户明确说出口令「分项」时加载；不得因“子项”“分项目”“同步站点”等相近词自动触发。
  Keywords: 分项, fenxiang, core update, site core sync, update:core, core:update.
version: 1.0.0
---

# 分项更新（口令门控）

## 定义

**分项 = 分项目同步核心代码。**

Windows 下 `E:\WelineFramework\Framework-Official` 是分项子项目容器，里面的 `A2A`、`App`、`Bbs`、`Official`、`Skill`、`Tools`、`WeShop` 各自通过 `weline\bin\w` 运行；默认目标还包含发布工作区 `E:\公司\远程\src\weline`，它通过根目录 `bin\w` 运行。macOS 下 `/Users/weline/Project/Official` 是分项子项目容器，脚本默认从核心仓父级目录自动发现带 `bin/w` 或 `weline/bin/w` 的站点，当前扫描到的有效站点为 `App`、`Skill`、`摩托车`、`Official-Site`、`WeShop`。核心仓 Windows 默认是 `E:\WelineFramework\DEV-workspace`，macOS 默认是脚本所在核心仓（如 `/Users/weline/Project/Official/框架`），也可通过脚本 `-CoreRepo` 显式指定；当核心修复完成后，口令「分项」用于把当前核心分支推送到线上，再通知各子项目通过 `core:update` 拉取指定分支的最新核心代码，然后在各子项目中只提交并推送框架更新产生的变更，业务代码不纳入分项提交。

## 全平台入口

| 平台 | 路径 |
|---|---|
| 全 AI 路由索引 | `dev/ai/skills/_index.md`（口令「分项」行） |
| Canonical 技能正文 | `dev/ai/skills/CI发布工程师-分项更新/SKILL.md` |
| Windows 分项脚本 | `dev/tools/fenxiang/fenxiang-update.ps1` |
| macOS/Linux 分项脚本 | `dev/tools/fenxiang/fenxiang-update-mac.sh` |
| Codex | `.codex/skills/fenxiang-update/SKILL.md` |

## 口令与范围

**仅当用户消息包含口令 `分项` 时**，才加载并执行本技能。

分支解析规则：

- 用户只说 `分项`：目标分支为 `dev`。
- 用户说 `分项 <分支名>`，例如 `分项 master`、`分项 release/1.2`：目标分支为用户给出的 `<分支名>`。
- 执行脚本时必须把解析出的分支传给 `-Branch`，或作为第一个位置参数传入；不要继续把 `dev` 写死。

Windows 默认范围为固定 8 个项目：

| 站点目录 | 项目根解析 |
|---|---|
| `E:\WelineFramework\Framework-Official\A2A` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Official\App` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Official\Bbs` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Official\Official` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Official\Skill` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Official\Tools` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Official\WeShop` | 优先 `weline\bin\w` |
| `E:\公司\远程\src\weline` | 优先 `bin\w` |

脚本会自动判断站点根目录下是否存在 `bin\w`；若没有，再判断 `weline\bin\w`。

macOS 默认范围从核心仓父级 `/Users/weline/Project/Official` 自动扫描，只纳入存在 `bin/w` 或 `weline/bin/w` 的目录；当前有效分项目录为：

| 站点目录 | 项目根解析 |
|---|---|
| `/Users/weline/Project/Official/App` | 优先 `bin/w` |
| `/Users/weline/Project/Official/Skill` | 优先 `bin/w` |
| `/Users/weline/Project/Official/摩托车` | 优先 `bin/w` |
| `/Users/weline/Project/Official/Official-Site` | 优先 `bin/w` |
| `/Users/weline/Project/Official/WeShop` | 优先 `bin/w` |

## 执行语义

1. 确认核心仓是 git 仓库，Windows 默认 `E:\WelineFramework\DEV-workspace`；macOS 默认脚本所在核心仓（如 `/Users/weline/Project/Official/框架`）；或用户通过 `-CoreRepo` 指定仓库。
2. 确认当前核心分支与目标更新分支一致；默认目标分支为 `dev`，若用户在 `分项` 后提供分支名则使用该分支。
3. 检查核心仓待提交文件，拒绝提交 `.env`、`app/etc/env.php`、私钥、证书等敏感文件。
4. 若核心仓有变更：
   - `git add -A`
   - `git diff --cached --check`
   - 未传 `-CommitMessage` 时执行普通 `git commit`，按实际改动填写提交信息
   - 传入 `-CommitMessage` 时执行 `git commit -m "<message>"`
5. 推送当前 HEAD 到线上：
   - `git push origin HEAD:<branch>`
   - 若存在 `github` remote，再执行 `git push github HEAD:<branch>`
6. 对每个站点项目先确认工作区干净；若已有本地改动，停止该站点，避免混入业务或人工改动。
7. 对每个站点项目执行：
   - `php bin/w core:update -b <branch>`
8. 站点 `core:update` 成功后，只允许框架范围变更进入提交：
   - 框架范围包括 `app/code/Weline`、`app/autoload.php`、`app/bootstrap.php`、`app/bootstrap_phpunit.php`、`app/code/config.php`、`app/etc/env.sample.php`、`app/etc/module_dependencies.php`、`app/etc/modules.php`、`bin`、`dev`、`pub`、`setup` 等核心更新路径。
   - 若出现 `.env`、`app/etc/env.php`、密钥/证书或非框架路径变更，停止该站点并报告，不提交业务改动。
   - 有框架变更时执行 `git add -A -- <framework changes>`、`git diff --cached --check`、`git commit -m "core: update framework core from <branch>"`。
   - 默认推送子项目 `origin HEAD:<branch>`；若子项目有 `github` remote，也推送 `github HEAD:<branch>`。
9. 每个站点框架提交/推送成功后执行：
   - `php bin/w server:reload -n`
   - `server:reload` 自身会检测运行实例；有 WLS Worker 时发送重载，没有运行实例时只提示并跳过，不启动新 WLS。
10. 汇总每个站点的更新、框架提交/推送和 WLS reload 结果；任一站点失败时，整体结果标记为失败并列出失败站点。

## 推荐命令

从 `E:\WelineFramework\DEV-workspace` 执行：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1
```

从 macOS 核心仓 `/Users/weline/Project/Official/框架` 执行：

```bash
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh
```

未传 `-CommitMessage` 时，脚本会执行普通 `git commit`，由 Git/editor 或仓库 commit template 填写本次真实提交信息。

显式指定分支（第一个位置参数）：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 master
```

macOS 显式指定分支：

```bash
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh master
```

显式指定分支和提交信息：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 release/1.2 -CommitMessage "core: 修复升级 registry stale 信息清理"
```

工作区有无关改动时，只提交指定核心路径：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -IncludePaths app/autoload.php,app/code/Weline/Framework/Event/Event.php
```

只预演、不提交、不推送、不更新站点：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -DryRun
```

macOS 预演：

```bash
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh --dry-run
```

仅推送核心，不更新站点：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipSiteUpdate
```

更新站点但跳过 WLS reload：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipWlsReload
```

只更新站点但跳过子项目提交或推送：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipSiteCommit
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipSitePush
```

## 强制约束

- 无口令 `分项` 不得执行本技能。
- Windows 执行 `dev/tools/fenxiang/fenxiang-update.ps1`；macOS/Linux 执行 `dev/tools/fenxiang/fenxiang-update-mac.sh`，不要在 macOS/Linux 上走 PowerShell 脚本。
- 默认分支是 `dev`；如果用户说 `分项 <分支名>`，必须使用该分支；如果当前核心分支与目标分支不一致，停止并说明。
- 不要手工拷贝核心文件到站点；站点必须通过 `php bin/w core:update -b <branch>` 更新。
- 不要修改或提交站点业务代码作为分项的一部分；`core:update` 只维护核心范围，脚本只提交框架更新产生的路径。
- 子项目执行 `core:update` 前必须是干净工作区；若已有本地改动，停止该站点，避免混入业务或人工改动。
- 提交前必须检查敏感文件，禁止提交 token、密钥、环境配置。
- 若工作区存在无关改动，必须使用 `-IncludePaths` 限定本次提交范围，禁止把用户或其他任务改动混入分项提交。
- 推送失败时不得继续声称站点已更新到最新核心；先修复核心推送。
- 站点更新失败时继续记录失败站点并在最终结果显式报告，不把部分成功说成全部成功。
- 站点出现非框架路径、敏感路径或业务路径变更时，不提交该站点，必须显式报告阻塞原因。
- 站点更新成功后默认执行 `php bin/w server:reload -n`；如用户明确不需要 WLS reload，才使用 `-SkipWlsReload`。
- 不要在无运行 WLS 的站点强行启动 WLS；分项只负责“运行中则重载”。

## 验收输出

最终报告必须包含：

- 核心仓分支、commit SHA、推送 remote 结果。
- 每个站点实际项目根目录。
- 每个站点 `php bin/w core:update -b <branch>` 的 PASS/FAIL。
- 每个站点框架变更 commit SHA 与 push remote 结果，或无框架变更的 SKIP。
- 每个站点 WLS reload 的 PASS/FAIL/SKIP。
- 未更新或失败站点的下一步阻塞原因。
