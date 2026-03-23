# Task: rename site builder menus to ai workbench

- Task ID: 2026-03-23-0553-rename-site-builder-menus-to-ai-workbench
- Started: 2026-03-23 05:53
- Status: completed
- Owner: Codex
- Source: rename site builder agent labels and move pagebuilder ai workbench under quick build

## Goal

- Rename remaining "建站智能体" entry labels to "AI 建站工作台".
- Make the PageBuilder Quick Build menu entry open the unified Websites AI workbench for the `pagebuilder` provider.

## Scope

- In scope:
  - PageBuilder Quick Build menu action adjustment.
  - Remaining user-visible PageBuilder/Websites AI workbench naming cleanup.
  - Related translation entries and naming-aligned comments/table comments.
- Out of scope:
  - Deeper PageBuilder session/workspace behavior changes.
  - New provider features or scope/tool contract work.

## Constraints

- Repo worktree is already dirty; do not revert unrelated edits.
- Keep this slice limited to naming/menu/route cleanup.
- Use recoverable handling for any stale `setup:upgrade` lock state.

## Related Plans

- None yet.

## Related Files

- `app/code/GuoLaiRen/PageBuilder/etc/backend/menu.xml`
- `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/index.phtml`
- `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml`
- `app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php`
- `app/code/Weline/Websites/extends/module/Weline_Ai/Agent/WebsiteBuilderAgent.php`
- `app/code/Weline/Websites/Service/WebsiteAgentService.php`
- `app/code/GuoLaiRen/PageBuilder/i18n/zh_Hans_CN.csv`
- `app/code/GuoLaiRen/PageBuilder/i18n/en_US.csv`
- `app/code/Weline/Websites/i18n/zh_Hans_CN.csv`
- `app/code/Weline/Websites/i18n/en_US.csv`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
