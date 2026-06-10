---
name: fencang-release
description: >-
  Load ONLY when the user says passphrase 分仓 (fencang). Sync Weline modules from DEV-workspace
  to independent Composer repos under E:\WelineFramework\weline, bump vMAJOR.MINOR.PATCH git tags,
  push to Gitee and GitHub, and refresh Packagist. Single-module scope unless user says --all.
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
.\$Fencang\bump-tag.ps1 -CurrentTag v1.2.9
.\$Fencang\refresh-packagist.ps1 -RepoPath E:\WelineFramework\weline\weline-module-admin
```

## Workflow summary

1. Mirror `app/code/Weline/{Module}/` → `E:\WelineFramework\weline/{repo}/` via robocopy `/MIR`.
2. Review `git diff`; skip if no meaningful change.
3. Bump tag (patch +1, carry at 9), commit, push `origin` + `github` (branch + tag).
4. Call Packagist `update-package` via `refresh-packagist.ps1`.
5. Report per module: sync status, old/new tag, Packagist result, push result.

For mapping table, version rules, Packagist credentials, and guardrails, read the canonical skill file above.
