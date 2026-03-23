# Task: websites ai workbench provider scope tools

- Task ID: 2026-03-23-0435-websites-ai-workbench-provider-scope-tools
- Started: 2026-03-23 04:35
- Status: in_progress
- Owner: Codex
- Source: continue from workbench shell to provider-driven scope/tool capabilities

## Goal

- Extend the Websites AI workbench provider contract so other modules can create provider-owned scope workspaces and expose provider-specific workbench tools without editing `SiteBuilderAgent` hardcoded mappings.
- Keep the existing unified Websites-owned session/workspace persistence while making provider initialization, handoff, and tool metadata declarative.

## Scope

- In scope:
- Add a provider-side workbench capability contract and a Websites service that resolves normalized provider workspace metadata.
- Allow Websites session creation to accept provider-driven initial scope/provider-state and optional initial stage.
- Render provider-defined workbench tools inside the Websites workspace and remove controller hardcoded provider match blocks where practical.
- Upgrade the built-in `websites_default` provider and the `pagebuilder` provider to use the new contract as the first real examples.
- Add focused unit coverage for the new provider-workbench resolution logic.
- Out of scope:
- Migrating PageBuilder legacy session tables into Websites persistence.
- Replacing the full legacy PageBuilder AI workspace implementation.
- Building every provider-specific action server-side; this slice only needs the shared workbench contract and first supported tool types.

## Constraints

- Worktree is dirty; do not revert unrelated changes.
- Use `apply_patch` for manual edits.
- Be careful with existing mojibake/encoding in touched Chinese text.
- Route or extension-contract changes should be followed by the relevant Weline setup refresh command.

## Related Plans

- Previous slice: `dev/ai/codex/tasks/2026-03-23/2026-03-23-0249-websites-ai-workbench-workspace-shell/`

## Related Files

- `app/code/Weline/Websites/Api/AiSiteBuilderProviderInterface.php`
- `app/code/Weline/Websites/extends.php`
- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `app/code/Weline/Websites/Service/AiWorkbench/SessionService.php`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml`
- `app/code/Weline/Websites/extends/module/Weline_Websites/AiSiteBuilderProvider/WebsitesDefaultProvider.php`
- `app/code/GuoLaiRen/PageBuilder/extends/module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProvider.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
