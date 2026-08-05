---
name: CI发布工程师-分项更新
description: >-
  仅由用户当前请求中的精确口令「分项」触发。把指定核心分支同步到已配置站点，并只提交/推送
  core:update 产生的框架差异；不得因“子项”“分项目”或普通站点更新讨论触发。
---

# 分项更新

## Scope

- `分项` targets branch `dev`.
- `分项 <branch>` targets only the named branch.
- Similar wording without the exact passphrase does not trigger this workflow.

The exact passphrase authorizes the configured core-to-site update, including required core/site commits and pushes for that branch. It does not authorize business changes or repositories outside script discovery/explicit parameters.

## Executable authority

Use the platform script; its current parameters, path discovery, framework allowlist, remotes, and result handling are executable truth. Do not keep a static site snapshot in this skill.

```powershell
# Windows preview / execute
./dev/tools/fenxiang/fenxiang-update.ps1 -Branch dev -DryRun
./dev/tools/fenxiang/fenxiang-update.ps1 -Branch dev
```

```bash
# macOS/Linux preview / execute
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh --branch dev --dry-run
bash ./dev/tools/fenxiang/fenxiang-update-mac.sh --branch dev
```

Pass the user-specified branch instead of hard-coding `dev`. Use explicit include/site parameters only when the user scope requires them.

## Gate

1. Confirm the core repository, current branch, discovered/explicit sites, and relevant script options.
2. Run dry-run and review all paths and staged scopes. Stop if the current core branch differs from the target.
3. Never include secrets, environment files, unrelated dirty changes, or site business code.
4. Each site must be clean before `php bin/w core:update -b <branch>`; a blocked site is reported, not overwritten.
5. Commit/push only framework changes produced by the update. A failed core push prevents downstream success claims.
6. Reload WLS only where the script detects a running instance; do not start a site runtime merely for distribution.
7. Treat partial site success as partial failure and preserve the per-site evidence.

## Report

Report core branch/commit/remotes and, per site, resolved root, `core:update`, framework diff/commit/remotes, WLS reload, and blocker. Do not claim all sites updated when any configured target failed or was skipped unexpectedly.
