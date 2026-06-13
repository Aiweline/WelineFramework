---
name: fenxiang-update
description: >-
  Load ONLY when the user says passphrase 分项. Treat DEV-workspace as the WelineFramework
  core repo: commit and push the current core branch, then run php bin/w core:update -b dev
  in the configured Office site projects under E:\WelineFramework; after each successful site
  update, reload WLS if it is running. Canonical skill:
  dev/ai/skills/CI发布工程师-分项更新/SKILL.md
---

# Fenxiang Update (passphrase-gated)

Codex alias for `CI发布工程师-分项更新`. **Do not run unless the user message contains `分项`.**

## Meaning

`分项` means "sync core code by project":

1. `E:\WelineFramework\DEV-workspace` is the core repository.
2. Commit and push the current core branch online.
3. Each configured site project updates its core code through `php bin/w core:update -b dev`.
4. After a successful site update, run `php bin/w server:reload -n`; the command detects whether WLS is running and skips when there are no workers.

## Canonical paths

| Purpose | Path |
|---|---|
| Full skill body | `dev/ai/skills/CI发布工程师-分项更新/SKILL.md` |
| PowerShell script | `dev/tools/fenxiang/fenxiang-update.ps1` |
| AI routing index | `dev/ai/skills/_index.md` |

## Quick command

From `E:\WelineFramework\DEV-workspace`:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1
```

With explicit commit message:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -Branch dev -CommitMessage "core: 修复升级 registry stale 信息清理"
```

When unrelated local changes exist, restrict the commit:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -IncludePaths app/autoload.php,app/code/Weline/Framework/Event/Event.php
```

Dry run:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -DryRun
```

Skip WLS reload:

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -SkipWlsReload
```

## Target sites

- `E:\WelineFramework\Framework-Office-A2a-Site`
- `E:\WelineFramework\Framework-Office-App-Site`
- `E:\WelineFramework\Framework-Office-Bbs-Site`
- `E:\WelineFramework\Framework-Office-Site`
- `E:\WelineFramework\Framework-Office-Skill-Site`
- `E:\WelineFramework\Framework-Office-WeShop-Site`

The script resolves either `<site>\bin\w` or `<site>\weline\bin\w`.

Read the canonical skill for guardrails, failure handling, and final evidence requirements.
