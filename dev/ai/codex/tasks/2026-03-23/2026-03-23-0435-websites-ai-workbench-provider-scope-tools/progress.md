# Progress - websites ai workbench provider scope tools

- 2026-03-23 04:35 Created the task workspace.
- 2026-03-23 18:34 Reviewed the previous workspace-shell slice, loaded repo skills, and confirmed the current gap: providers can register into Websites and sessions can persist arbitrary scope, but provider-defined workbench scope initialization and tool metadata are still missing as a shared contract.
- 2026-03-23 18:37 Scoped this slice to a provider-driven workbench contract plus first-party PageBuilder example, rather than trying to migrate the whole PageBuilder legacy workspace.
- 2026-03-23 13:08 Added `AiSiteBuilderWorkbenchProviderInterface` and `ProviderWorkbenchService` so provider modules can declare initial scope/provider-state, handoff metadata, initial stage, and workbench tools from a shared Websites-side contract.
- 2026-03-23 13:16 Extended `SessionService::createSession()` with an optional initial-stage parameter and updated the persistence test helper to track sessions created with custom stages.
- 2026-03-23 13:22 Refactored the Websites `SiteBuilderAgent` flow so workspace rendering, session creation, state JSON, recent-session cards, and handoff artifacts now read provider-driven workbench config instead of depending only on controller hardcoded mappings.
- 2026-03-23 13:28 Upgraded `WebsitesDefaultProvider` and `PageBuilderProvider` to implement the new workbench interface; `PageBuilder` now contributes real provider tools including legacy workspace links and a `scope_patch` visual-edit action.
- 2026-03-23 13:31 Extended the Websites workspace template with a provider-tools panel and client-side handling for `scope_patch` tools, so modules can now expose both external links and scope-mutating actions inside the shared workbench.
- 2026-03-23 13:35 Added `ProviderWorkbenchServiceTest`, expanded `SessionServiceTest`, fixed an `extends.php` parse regression by rewriting the file cleanly, recovered from a stale timed-out setup lock by renaming it, and completed syntax / PHPUnit / setup validation.
