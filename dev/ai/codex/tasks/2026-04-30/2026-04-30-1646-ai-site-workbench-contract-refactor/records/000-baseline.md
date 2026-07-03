# Baseline Record

Date: 2026-04-30

## Git State

Command:

```powershell
git status --short --branch
```

Observed:

```text
## dev...origin/dev [ahead 2663, behind 1606]


```

## Documentation Split

Command:

```powershell
Get-ChildItem dev\ai\codex\tasks\2026-04-30\2026-04-30-1646-ai-site-workbench-contract-refactor\atoms -Filter *.md
```

Observed:

- Atom docs including template: 44
- Executable atom docs excluding template: 43

## Test Baseline

No product-code tests were run during documentation split. Tests should be run by the implementation atoms that modify product behavior, then summarized in `REL02-final-target-tests.md`.
