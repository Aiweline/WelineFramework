---
name: fenxiang-update
description: >-
  Load ONLY when the user says passphrase 分项. Commit and push the current core repo
  (Windows default E:\WelineFramework\DEV-workspace; macOS default script repo such as
  /Users/weline/Project/Official/框架), then run php bin/w core:update -b <branch>
  in the configured child projects under E:\WelineFramework\Framework-Official on Windows,
  or discovered child projects under /Users/weline/Project/Official on macOS; each child
  project commits and pushes only framework changes produced by core:update, then reloads WLS
  if it is running. If the user says only 分项, use dev;
  if the user says 分项 <branch>, use that branch. Canonical skill:
  dev/ai/skills/CI发布工程师-分项更新/SKILL.md
---

# Fenxiang Update (passphrase-gated)

Codex alias for `CI发布工程师-分项更新`. **Do not run unless the user message contains `分项`.**

## Meaning

`分项` means "sync core code by project":

1. Windows uses `E:\WelineFramework\Framework-Official` child site projects plus `E:\公司\远程\src\weline`; macOS uses `/Users/weline/Project/Official` and auto-discovers child projects with `bin/w` or `weline/bin/w`. The default core repository is `E:\WelineFramework\DEV-workspace` on Windows and the script repo, such as `/Users/weline/Project/Official/框架`, on macOS.
2. Resolve the target branch from the passphrase: `分项` defaults to `dev`; `分项 <branch>` targets `<branch>`.
3. Commit and push the current core branch online to the target branch.
4. Each configured site project updates its core code through `php bin/w core:update -b <branch>`.
5. Each site commits and pushes only framework-path changes produced by the update; business paths, sensitive paths, and dirty pre-update worktrees block that site.
6. After a successful site framework commit/push, run `php bin/w server:reload -n`; the command detects whether WLS is running and skips when there are no workers.

## Canonical paths

| Purpose | Path |
|---|---|
| Full skill body | `dev/ai/skills/CI发布工程师-分项更新/SKILL.md` |
| Windows PowerShell script | `dev/tools/fenxiang/fenxiang-update.ps1` |
| macOS/Linux shell script | `dev/tools/fenxiang/fenxiang-update-mac.sh` |
| AI routing index | `dev/ai/skills/_index.md` |

## Quick command

From `E:\WelineFramework\DEV-workspace`:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1
```

From macOS core repo `/Users/weline/Project/Official/框架`:

```bash
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh
```

Equivalent explicit dev branch:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 dev
```

macOS equivalent explicit dev branch:

```bash
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh dev
```

With explicit branch and commit message:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 master -CommitMessage "core: 修复升级 registry stale 信息清理"
```

Without `-CommitMessage`, the script uses normal `git commit` so the commit message reflects the actual change instead of a fixed default.

When unrelated local changes exist, restrict the commit:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -IncludePaths app/autoload.php,app/code/Weline/Framework/Event/Event.php
```

Dry run:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -DryRun
```

macOS dry run:

```bash
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh --dry-run
```

Skip WLS reload:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipWlsReload
```

Skip child-site commit or push:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipSiteCommit
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipSitePush
```

## Target sites

- `E:\WelineFramework\Framework-Official\A2A`
- `E:\WelineFramework\Framework-Official\App`
- `E:\WelineFramework\Framework-Official\Bbs`
- `E:\WelineFramework\Framework-Official\Official`
- `E:\WelineFramework\Framework-Official\Skill`
- `E:\WelineFramework\Framework-Official\Tools`
- `E:\WelineFramework\Framework-Official\WeShop`
- `E:\公司\远程\src\weline`

On macOS, the script scans `/Users/weline/Project/Official` and currently finds:

- `/Users/weline/Project/Official/App`
- `/Users/weline/Project/Official/Skill`
- `/Users/weline/Project/Official/摩托车`
- `/Users/weline/Project/Official/Official-Site`
- `/Users/weline/Project/Official/WeShop`

The script resolves either `<site>/bin/w` or `<site>/weline/bin/w`, using platform-native path separators.

Read the canonical skill for guardrails, failure handling, and final evidence requirements.
