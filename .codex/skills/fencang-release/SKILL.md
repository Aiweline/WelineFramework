---
name: fencang-release
description: >-
  Load ONLY when the user says passphrase 分仓 (fencang). Sync Weline modules from DEV-workspace
  to independent Composer repos under the DEV-workspace sibling directory 框架-分仓 by default,
  auto-clone missing repos from Gitee/GitHub, bump vMAJOR.MINOR.PATCH git tags, push to both remotes,
  and refresh Packagist. Single-module scope unless user says --all.
  Canonical skill: dev/ai/skills/CI发布工程师-分仓发布/SKILL.md
---

# Fencang Release (passphrase-gated)

Codex alias for `CI发布工程师-分仓发布`. **Do not run unless the user message contains `分仓`.**

## Canonical paths

| Purpose | Path |
|---|---|
| Full skill body (keep in sync) | `dev/ai/skills/CI发布工程师-分仓发布/SKILL.md` |
| PowerShell scripts | `dev/tools/fencang/` |
| AI routing index | `dev/ai/skills/_index.md` |

## Passphrase scope

| User says | Scope |
|---|---|
| `分仓 Framework` | **Only** `Framework` |
| `分仓 Admin,Backend` | **Only** listed modules |
| `分仓 --all` | All mapped modules |
| `分仓` alone | Ask which module; do not run |

## Quick commands (from repo root)

```powershell
$Fencang = 'dev\tools\fencang'

.\$Fencang\fencang-sync.ps1 -Modules Framework
.\$Fencang\fencang-sync.ps1 -Modules Admin,Backend
.\$Fencang\fencang-sync.ps1 -All
.\$Fencang\fencang-sync.ps1 -Modules Framework -WelineRoot E:\WelineFramework\weline
pwsh ./dev/tools/fencang/fencang-sync.ps1 -Modules Framework
.\$Fencang\bump-tag.ps1 -CurrentTag v1.2.9
.\$Fencang\refresh-packagist.ps1 -RepoPath E:\WelineFramework\框架-分仓\weline-module-admin
```

## Workflow summary

1. Resolve split root: default `{DEV_ROOT}/../框架-分仓`; use `-WelineRoot` only when explicitly provided.
2. Ensure each mapped repo exists: existing `.git` repos are reused; missing repos are cloned from `origin=https://gitee.com/aiweline/{repo}.git` with GitHub fallback and `github=https://github.com/Aiweline/{repo}.git`.
3. Pre-check with `robocopy /L /MIR` on Windows or portable hash compare on macOS/Linux; if no diff, skip copy, commit, tag, push, and Packagist.
4. Mirror `app/code/Weline/{Module}/` into the repo root while preserving `.git`, `vendor`, `.idea`, `node_modules`, and `.DS_Store`.
5. Review git status/diff; dirty existing repos are refused, remote fetch failures are reported as warnings, and empty syncs skip commit/tag/push.
6. Bump tag (patch +1, carry at 9), commit, push `origin` + `github` (branch + tag), then call Packagist update.
7. Report per module: root path, clone/reuse status, old/new tag, Packagist result, push result.

For mapping table, remote URL rules, Packagist credential configuration, and guardrails, read the canonical skill file above.
