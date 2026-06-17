---
name: fenxiang-update
description: >-
  Load ONLY when the user says passphrase 分项. Commit and push the current core repo
  (default E:\WelineFramework\DEV-workspace), then run php bin/w core:update -b dev
  in the configured child projects under E:\WelineFramework\Framework-Official; after each
  successful site update, reload WLS if it is running. Canonical skill:
  dev/ai/skills/CI发布工程师-分项更新/SKILL.md
---

# Fenxiang Update (passphrase-gated)

Codex alias for `CI发布工程师-分项更新`. **Do not run unless the user message contains `分项`.**

## Meaning

`分项` means "sync core code by project":

1. `E:\WelineFramework\Framework-Official` contains the child site projects; the default core repository is `E:\WelineFramework\DEV-workspace`.
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

- `E:\WelineFramework\Framework-Official\A2A`
- `E:\WelineFramework\Framework-Official\App`
- `E:\WelineFramework\Framework-Official\Bbs`
- `E:\WelineFramework\Framework-Official\Official`
- `E:\WelineFramework\Framework-Official\Skill`
- `E:\WelineFramework\Framework-Official\Tools`
- `E:\WelineFramework\Framework-Official\WeShop`

The script resolves either `<site>\bin\w` or `<site>\weline\bin\w`.

Read the canonical skill for guardrails, failure handling, and final evidence requirements.
