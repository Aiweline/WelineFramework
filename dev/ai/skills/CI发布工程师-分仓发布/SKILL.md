---
name: CI发布工程师-分仓发布
description: >-
  仅由用户当前请求中的精确口令「分仓」触发。把明确命名的 Weline 模块或 --all 范围同步到独立
  Composer 仓库并执行版本/tag/远端/Packagist 发布；不得因普通发布、部署或 Composer 讨论触发。
---

# 分仓发布

## Scope

- `分仓 Framework` or `分仓 Admin,Backend`: only the named module(s).
- `分仓 --all`: only this form authorizes every module in the script map.
- `分仓` without a module or `--all`: ask for the target; never assume all.
- Similar phrases without the exact passphrase do not trigger this workflow.

The exact passphrase authorizes the named split-release workflow, including its required commit, tag, remote pushes, and Packagist refresh. It does not authorize unrelated modules or repositories.

## Executable authority

Use `dev/tools/fencang/fencang-sync.ps1`; its current parameter block, module map, repository mapping, exclusions, remote handling, version calculation, and report are executable truth. Do not copy environment snapshots or maintain a second map in a skill/adapter.

```powershell
# Preview one or several modules
pwsh ./dev/tools/fencang/fencang-sync.ps1 -Modules Framework -DryRun
pwsh ./dev/tools/fencang/fencang-sync.ps1 -Modules Admin,Backend -DryRun

# Execute only after reviewing the preview
pwsh ./dev/tools/fencang/fencang-sync.ps1 -Modules Framework

# All modules only for exact "分仓 --all"
pwsh ./dev/tools/fencang/fencang-sync.ps1 -All -DryRun
```

## Gate

1. Resolve only the user-named scope and inspect the current script/options.
2. Run `-DryRun`; review source/target paths, changes, current/highest tag, next tag, remotes, and Packagist target.
3. Stop a target with a dirty or non-repository destination, unexpected deletion/path, secret, invalid mapping, missing required credential, or failed remote preflight.
4. No source/target difference means no mirror, commit, tag, push, or Packagist refresh.
5. Execute the same reviewed scope. A failed commit/tag/push/refresh remains a partial failure; do not describe it as fully released.

Credentials come only from configured Git credentials, environment variables, or the script's ignored local credential file. Never write them into repository guidance or output.

## Report

For every requested module report source/target, change/no-change, old/new tag, commit, each remote push, Packagist status, and blocker/recovery step. State explicitly when no module was changed.
