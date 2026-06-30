# WLS Panel Humanized Redesign Prototype

Date: 2026-06-28

This document supersedes the old plugin workspace direction. The old panel grew by stacking sections, anchors, framed workspace headers, and plugin-internal sidebars. That produced a page that is technically connected but hard to use. The redesign treats WLS Panel as an operations product, not as a demo page.

## Product Principle

WLS Panel is an independent server operations panel.

The framework backend only provides an authorized menu entry. Once the operator enters WLS Panel, every navigation item should feel native to the WLS Panel shell, including capabilities contributed by WLS plugins.

## Non-Negotiable UX Rules

1. No anchor navigation for primary or secondary menus.
2. No page should expose multiple unrelated workflows by stacking all sections vertically.
3. Plugin content may be loaded in an iframe, but WLS Panel must not add a visible plugin-workspace wrapper around it.
4. No plugin-internal sidebar when the plugin is opened from WLS Panel; WLS Panel owns plugin secondary menus.
5. Every visible sidebar item must open a real backend route.
6. A route should render one focused task page, not a giant template with many hidden or distant sections.
7. Lists with many records must use table/list, search, pagination, and detail drawers or detail pages.
8. Destructive or runtime actions must stay close to the affected object and show state, consequence, and recovery path.
9. Light and dark modes must use the same layout and density.
10. Mobile layout must preserve task order and avoid horizontal overflow.

## Navigation Model

```text
---------------------------------------------------------------+
| WLS Panel                                                    |
|---------------------------------------------------------------|
| Sidebar                         | Header                      |
| - Dashboard                     | Page title / context        |
| - Projects                      | Theme / Project Admin / Exit|
| - Gateway                       |-----------------------------|
| - Security                      | Focused page body           |
| - Marketplace                   |                             |
|                                 |                             |
| Plugin Capabilities             |                             |
| - Database Manager              | Real route page             |
|   - Overview                    |                             |
|   - Profiles                    |                             |
|   - Health Check                |                             |
|   - Lifecycle                   |                             |
|   - Backup & Restore            |                             |
| - PHP Manager                   |                             |
|   - Runtime                     |                             |
|   - Profiles                    |                             |
|   - php.ini                     |                             |
|   - Extensions                  |                             |
| - File Manager                  |                             |
|   - Browser                     |                             |
|   - Policies                    |                             |
|   - Queue                       |                             |
|   - Audit                       |                             |
| - Deploy                        |                             |
|   - Overview                    |                             |
|   - Profiles                    |                             |
|   - Preflight                   |                             |
|   - Webhooks                    |                             |
|   - Releases                    |                             |
+---------------------------------------------------------------+
```

The sidebar is the only navigation surface for WLS plugin submenus. Plugins can provide child menu entries through `etc/marketplace/meta.json`, but WLS Panel owns rendering and active state. Plugin content is embedded with `embedded=1`, so the plugin renders task content only while WLS Panel renders the navigation shell.
Only the active native nav group or active plugin group expands its secondary menu; inactive groups stay compact so the sidebar remains usable when many plugins are installed.
Plugin capability groups must also be manually collapsible from the WLS sidebar. The current plugin parent and current child item must stay visibly highlighted, and the active group opens by default on route load so the operator can see where they are.

Installed AppStore snapshots and local module meta can both describe the same
plugin entry. If they resolve to the same route, WLS Panel must merge the
entries and keep `children`; it must not let a stale top-level installed entry
hide the plugin's current secondary menu declaration.

## Route Model

Each child menu opens a real backend route.

```text
Database Manager
  /weline_dbmanager/backend/wls-db-manager/summary
  /weline_dbmanager/backend/wls-db-manager/profiles
  /weline_dbmanager/backend/wls-db-manager/health
  /weline_dbmanager/backend/wls-db-manager/lifecycle
  /weline_dbmanager/backend/wls-db-manager/backup-plan
  /weline_dbmanager/backend/wls-db-manager/slaves
  /weline_dbmanager/backend/wls-db-manager/test-page

PHP Manager
  /weline_phpmanager/backend/wls-php-manager/summary
  /weline_phpmanager/backend/wls-php-manager/runtime
  /weline_phpmanager/backend/wls-php-manager/project-profile
  /weline_phpmanager/backend/wls-php-manager/ini
  /weline_phpmanager/backend/wls-php-manager/extensions
  /weline_phpmanager/backend/wls-php-manager/audit

File Manager
  /weline_filemanager/backend/wls-file-manager/browser
  /weline_filemanager/backend/wls-file-manager/roots
  /weline_filemanager/backend/wls-file-manager/policy-page
  /weline_filemanager/backend/wls-file-manager/write-page
  /weline_filemanager/backend/wls-file-manager/queue-page
  /weline_filemanager/backend/wls-file-manager/log-page

Deploy
  /deploy/backend/wls-deploy/overview
  /deploy/backend/wls-deploy/release-path
  /deploy/backend/wls-deploy/configuration
  /deploy/backend/wls-deploy/preflight
  /deploy/backend/wls-deploy/webhooks
  /deploy/backend/wls-deploy/releases
  /deploy/backend/wls-deploy/manual-plan

Security
  /server/backend/wls-panel/security
  /server/backend/wls-panel/security-logs
  /server/backend/wls-panel/security-rules
  /server/backend/wls-panel/security-policy
  /server/backend/wls-panel/security-audit
```

The route may reuse a controller class, but the rendered page must be scoped by route. A route must not depend on `#anchor` to reveal the intended task. When loaded inside WLS Panel, child route URLs and post-action redirects must keep `embedded=1`.

## Page Archetypes

### Dashboard

Purpose: current operational state and next action.

```text
+---------------------------------------------------------------+
| Dashboard                                                     |
+---------------------------------------------------------------+
| Health summary: Projects / Gateway / Security / Plugins       |
+---------------------------------------------------------------+
| Attention Queue                                               |
| - Gateway not applied                                         |
| - Security critical events                                    |
| - Missing plugin capabilities                                 |
+---------------------------------------------------------------+
| Managed Projects table                                        |
| Search | Status | Gateway | Security | Page size              |
| Project | Domain | Gateway | Security | Actions               |
+---------------------------------------------------------------+
| Operations shortcuts                                          |
| PHP / Database / Files / Deploy / Security                    |
+---------------------------------------------------------------+
```

Rules:

- The dashboard is not a dumping ground.
- Show only summary, attention, and top-level navigation.
- Large project sets use a list/table with pagination.
- Plugin capability overview uses compact tables, not repeated plugin cards.
- Installed plugin actions open the WLS plugin shell route so the sidebar owns secondary navigation.
- Dashboard plugin tables can link to child pages, but child navigation still belongs to the WLS sidebar.

### Projects

Purpose: manage project registry and jump to project-specific operations.

```text
+---------------------------------------------------------------+
| Projects                                      [Add Project]   |
+---------------------------------------------------------------+
| Search | Status | Gateway | Page size                         |
+---------------------------------------------------------------+
| Name | Domain | Admin | Child Panel | Gateway | Status | ...  |
+---------------------------------------------------------------+
| Detail drawer or detail page                                  |
| - PHP profile                                                 |
| - DB profile                                                  |
| - path                                                        |
| - security summary                                            |
| - operations                                                  |
+---------------------------------------------------------------+
```

Rules:

- Project cards are not acceptable for large instance counts.
- Default view is list/table.
- Add/edit forms are not shown by default; they appear only from an explicit add/edit action.
- Project operation readiness is a compact table, not a card wall.
- Detail opens inline as a row drawer or a separate route, not another long stack.

### Gateway

Purpose: configure and apply routing.

```text
+---------------------------------------------------------------+
| Gateway                                                       |
+---------------------------------------------------------------+
| Runtime status: enabled / listener / traffic mode / capability|
+---------------------------------------------------------------+
| Gateway targets table                                         |
+---------------------------------------------------------------+
| Route rules table                                             |
| Domain | Target | SSL | Status | Last Applied | Actions       |
+---------------------------------------------------------------+
| Apply bar                                                     |
| Target instance | Save only / Reload / Restart | Apply        |
+---------------------------------------------------------------+
```

Rules:

- Direct-listen and passthrough are shown as modes with runtime capability.
- Apply action must name the exact target process.
- Gateway target instances use a searchable paginated list/table, not a card wall.
- Default rows show only instance, listener, state, main listen, topology, and actions.
- Process counts, control port, master state, and listener details live in a row drawer or detail page.

### Security

Purpose: security posture, policy, and incident review.

```text
+---------------------------------------------------------------+
| Security                                                      |
+---------------------------------------------------------------+
| Risk summary: Events / Blocked / Critical / Blocked IPs       |
+---------------------------------------------------------------+
| Project risk list                                             |
| Project | Events | Critical | Top type | Latest | Actions     |
+---------------------------------------------------------------+
| Attack logs table                                             |
| Scope | Instance | IP | Severity | Type | Blocked | Time      |
+---------------------------------------------------------------+
| Policy editor opens as focused route or drawer                |
+---------------------------------------------------------------+
```

Rules:

- Logs must be table-first.
- Policy editing is a focused surface; avoid showing raw JSON first.
- JSON remains advanced mode, collapsed by default.

### Marketplace

Purpose: install WLS-compatible capabilities.

```text
+---------------------------------------------------------------+
| Marketplace                                                   |
+---------------------------------------------------------------+
| AppStore endpoint / account source / refresh action           |
+---------------------------------------------------------------+
| Installed WLS plugins table                                   |
+---------------------------------------------------------------+
| Plugin | Module | Tags | Panel Entry | Action                 |
+---------------------------------------------------------------+
```

Rules:

- Marketplace is not the plugin runtime area.
- Installed plugin entries appear in the WLS sidebar after refresh.
- `module:wls` typed tag is the compatibility gate.
- The WLS Panel marketplace page must not render static candidate/demo plugin cards.
- Searching, account authorization, and install flow belong to the AppStore page opened with the WLS tag filter.
- WLS Panel only shows the resolved AppStore endpoint, refresh action, and the real installed WLS plugin table.
- Do not hard-code a search term such as `wls-file-manager` in the WLS Panel marketplace.

## Plugin Page Rules

Plugin pages must support two modes:

```text
standalone mode:
  plugin may show its own header and local navigation if opened outside WLS Panel

wls-panel mode:
  plugin hides local sidebar/header actions
  plugin renders only the current focused route content
  WLS Panel owns title, sidebar, theme, and breadcrumbs
```

In WLS Panel mode, a plugin page should look like this:

```text
+---------------------------------------------------------------+
| WLS Panel header: Database Manager / Profiles                 |
+---------------------------------------------------------------+
| Filter / action bar                                           |
+---------------------------------------------------------------+
| Focused content                                               |
+---------------------------------------------------------------+
```

It should not look like this:

```text
WLS Panel -> iframe -> Plugin shell -> Plugin sidebar -> Anchor section
```

## Implementation Direction

1. Plugin menu clicks open the WLS Panel plugin shell route, so browser URL, active state, and return URL are owned by WLS Panel.
2. The plugin shell may load plugin content in an iframe, but it must render the iframe directly without a visible workspace wrapper, duplicate titlebar, or back/open-new-tab chrome.
3. Treat plugin child menu URLs as task routes, not as anchors.
4. Keep `embedded=1` or `wls_panel=1` only as a display-mode hint for plugin templates.
5. Remove `build*AnchorUrl()` usage from plugin menus.
6. Add controller action page keys such as `summary`, `profiles`, `runtime`, `browser`, and `overview`.
7. In each template, render by page key:
   - page key `profiles` renders only profile management
   - page key `runtime` renders only runtime state
   - page key `browser` renders only file browser
   - page key `releases` renders only release history
8. Split WLS-native security into focused routes for overview, logs, rules, project policy, and policy audit.
9. Move shared blocks into local partial helpers only after the first focused pages are working.

## Acceptance Checklist

- No `#summary`, `#runtime`, `#roots`, or similar anchors in WLS sidebar or plugin sidebars.
- No visible plugin workspace wrapper, duplicate plugin sidebar, open-new-tab chrome, or back-to-dashboard chrome in WLS Panel plugin host.
- WLS sidebar plugin child item hrefs point to WLS Panel shell routes that select a plugin child page.
- WLS sidebar plugin capability groups are collapsed by default except the active group.
- Plugin groups can be manually expanded and collapsed without changing routes.
- The active plugin parent and active child item are visibly highlighted.
- Opening a plugin child page changes the browser URL.
- Reloading a plugin child page keeps the same focused page.
- Back/forward browser navigation works naturally.
- WLS security sidebar children open real routes, not `#security-*` anchors.
- Each focused page has one primary job.
- Large lists have search and pagination.
- Desktop, tablet, and mobile have no horizontal overflow.
- Light/dark mode both pass.
- Unauthorized access redirects to login with return URL preserved.
