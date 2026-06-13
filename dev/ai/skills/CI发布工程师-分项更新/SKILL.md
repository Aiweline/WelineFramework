---
name: CI发布工程师-分项更新
description: >-
  口令「分项」触发：DEV-workspace 作为 WelineFramework 核心仓，先提交并推送当前核心分支，
  再让 E:\WelineFramework 下指定站点执行 php bin/w core:update -b dev 同步最新核心代码；
  若站点 WLS 正在运行，更新后执行 server:reload。
  仅在用户明确说出口令「分项」时加载；不得因“子项”“分项目”“同步站点”等相近词自动触发。
  Keywords: 分项, fenxiang, core update, site core sync, update:core, core:update.
version: 1.0.0
---

# 分项更新（口令门控）

## 定义

**分项 = 分项目同步核心代码。**

`E:\WelineFramework\DEV-workspace` 是核心仓库，只承载核心代码修改。其他站点项目的核心代码来自该仓库；当核心修复完成后，口令「分项」用于把当前核心分支推送到线上，再通知各站点通过 `core:update` 拉取最新核心代码。

## 全平台入口

| 平台 | 路径 |
|---|---|
| 全 AI 路由索引 | `dev/ai/skills/_index.md`（口令「分项」行） |
| Canonical 技能正文 | `dev/ai/skills/CI发布工程师-分项更新/SKILL.md` |
| 分项脚本 | `dev/tools/fenxiang/fenxiang-update.ps1` |
| Codex | `.codex/skills/fenxiang-update/SKILL.md` |

## 口令与范围

**仅当用户消息包含口令 `分项` 时**，才加载并执行本技能。

默认范围为固定 6 个站点：

| 站点目录 | 项目根解析 |
|---|---|
| `E:\WelineFramework\Framework-Office-A2a-Site` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Office-App-Site` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Office-Bbs-Site` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Office-Site` | 根目录 `bin\w` |
| `E:\WelineFramework\Framework-Office-Skill-Site` | 优先 `weline\bin\w` |
| `E:\WelineFramework\Framework-Office-WeShop-Site` | 优先 `weline\bin\w` |

脚本会自动判断站点根目录下是否存在 `bin\w`；若没有，再判断 `weline\bin\w`。

## 执行语义

1. 确认当前仓库是 `E:\WelineFramework\DEV-workspace`。
2. 确认当前核心分支与目标更新分支一致；默认目标分支为 `dev`。
3. 检查核心仓待提交文件，拒绝提交 `.env`、`app/etc/env.php`、私钥、证书等敏感文件。
4. 若核心仓有变更：
   - `git add -A`
   - `git diff --cached --check`
   - `git commit -m "core: 分项同步核心更新"`（可按任务改更具体提交信息）
5. 推送当前 HEAD 到线上：
   - `git push origin HEAD:dev`
   - 若存在 `github` remote，再执行 `git push github HEAD:dev`
6. 对每个站点项目执行：
   - `php bin/w core:update -b dev`
7. 每个站点更新成功后执行：
   - `php bin/w server:reload -n`
   - `server:reload` 自身会检测运行实例；有 WLS Worker 时发送重载，没有运行实例时只提示并跳过，不启动新 WLS。
8. 汇总每个站点的更新和 WLS reload 结果；任一站点失败时，整体结果标记为失败并列出失败站点。

## 推荐命令

从 `E:\WelineFramework\DEV-workspace` 执行：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1
```

显式指定分支或提交信息：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -Branch dev -CommitMessage "core: 修复升级 registry stale 信息清理"
```

工作区有无关改动时，只提交指定核心路径：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -IncludePaths app/autoload.php,app/code/Weline/Framework/Event/Event.php
```

只预演、不提交、不推送、不更新站点：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -DryRun
```

仅推送核心，不更新站点：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipSiteUpdate
```

更新站点但跳过 WLS reload：

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipWlsReload
```

## 强制约束

- 无口令 `分项` 不得执行本技能。
- 默认分支是 `dev`；如果当前分支不是 `dev`，停止并说明，除非用户明确指定其他分支。
- 不要手工拷贝核心文件到站点；站点必须通过 `php bin/w core:update -b dev` 更新。
- 不要修改站点项目级模块目录作为分项的一部分；`core:update` 只维护核心范围。
- 提交前必须检查敏感文件，禁止提交 token、密钥、环境配置。
- 若工作区存在无关改动，必须使用 `-IncludePaths` 限定本次提交范围，禁止把用户或其他任务改动混入分项提交。
- 推送失败时不得继续声称站点已更新到最新核心；先修复核心推送。
- 站点更新失败时继续记录失败站点并在最终结果显式报告，不把部分成功说成全部成功。
- 站点更新成功后默认执行 `php bin/w server:reload -n`；如用户明确不需要 WLS reload，才使用 `-SkipWlsReload`。
- 不要在无运行 WLS 的站点强行启动 WLS；分项只负责“运行中则重载”。

## 验收输出

最终报告必须包含：

- 核心仓分支、commit SHA、推送 remote 结果。
- 每个站点实际项目根目录。
- 每个站点 `php bin/w core:update -b dev` 的 PASS/FAIL。
- 每个站点 WLS reload 的 PASS/FAIL/SKIP。
- 未更新或失败站点的下一步阻塞原因。
