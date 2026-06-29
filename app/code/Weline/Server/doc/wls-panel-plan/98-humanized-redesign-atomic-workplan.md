# WLS Panel Humanized Redesign Atomic Workplan

Date: 2026-06-28

This workplan implements `97-humanized-redesign-prototype.md`.

## Current Defects To Remove

| Defect | Evidence Pattern | Required Change |
| --- | --- | --- |
| Plugin workspace wrapper | plugin workspace titlebar, extra new-tab/back actions, plugin-internal sidebar | Keep only the WLS shell plus embedded plugin content. |
| Anchor navigation | `build*AnchorUrl()`, `#summary`, `#runtime`, `#roots` | Replace with real backend route links. |
| Mega pages | one template renders all workflows | Render focused page by route/page key. |
| Nested plugin shell | plugin sidebar inside WLS shell | Hide plugin local navigation in WLS mode. |
| Long project or gateway cards | many project/gateway instance cards | Use searchable paginated list/table with row details. |
| Default project form wall | add/edit form always visible before the list | Show the project list first; reveal add/edit form only after explicit action. |
| Project operation card wall | project config center renders large per-project cards | Use a compact operations table with readiness and quick links. |
| Marketplace demo card wall | static WLS candidate plugins, hard-coded search value, source cards | Keep Marketplace as AppStore entry plus real installed plugin table. |
| Plugin capability menu sprawl | every plugin child menu visible, or current location hidden | Collapse inactive plugin groups, allow manual collapse, and keep current parent/child highlighted. |

## Parallel Work Packets

### Packet A - WLS Shell And Plugin Navigation

Owner scope:

- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`
- `app/code/Weline/Server/Service/WlsPanelPluginDiscoveryService.php`
- WLS plugin meta consumption only if needed.

Tasks:

1. Remove visible plugin workspace chrome around embedded plugin content.
2. Render plugin parent and child menus as normal WLS sidebar links.
3. Preserve active state for native pages and plugin pages.
4. Collapse inactive plugin capability groups by default.
5. Add a dedicated expand/collapse control for plugin child menus.
6. Keep the active plugin parent and active child visibly highlighted.
7. Ensure WLS Panel top header remains independent and compact.
8. Keep marketplace and dashboard as ordinary routes, not plugin frame targets.
9. Force plugin iframe URLs to carry `embedded=1` and hide plugin-local navigation.

Acceptance:

- No visible plugin-workspace titlebar, new-tab action, or back-to-dashboard action remains in WLS Panel plugin host.
- WLS plugin parent and child links open the WLS plugin route instead of raw plugin pages.
- Browser URL changes when opening a plugin child page.
- Only the active plugin group is expanded by default.
- Plugin groups can be collapsed and expanded without route changes.
- The active plugin parent and active child are highlighted.
- Login redirects preserve the child route return URL.
- Plugin iframe `src` carries `embedded=1`.

### Packet B - Database And PHP Plugin Focused Pages

Owner scope:

- `app/code/Weline/DbManager/Controller/Backend/WlsDbManager.php`
- `app/code/Weline/DbManager/view/templates/Backend/WlsDbManager/index.phtml`
- `app/code/Weline/DbManager/etc/marketplace/meta.json`
- `app/code/Weline/PhpManager/Controller/Backend/WlsPhpManager.php`
- `app/code/Weline/PhpManager/view/templates/Backend/WlsPhpManager/index.phtml`
- `app/code/Weline/PhpManager/etc/marketplace/meta.json`

Tasks:

1. Remove plugin internal anchor menu in WLS panel embedded mode.
2. Replace `buildDbManagerAnchorUrl()` and `buildPhpManagerAnchorUrl()` menu usage with real route URLs.
3. Add or reuse page keys from controller actions.
4. Render only the focused page content for each route:
   - DB: summary, profiles, health, project profile, lifecycle, backup plan, slaves, test.
   - PHP: summary, runtime, inheritance, project profile, ini, extensions, audit.
5. Keep standalone mode usable if plugin is opened outside WLS Panel.

Acceptance:

- `rg "build.*AnchorUrl|href=.*#"` in DB/PHP plugin templates returns no primary navigation links.
- `/summary` reload shows only summary-level content.
- `/profiles` reload shows profile management without scrolling through unrelated lifecycle/backup sections.
- Embedded route links, form actions, and post-action redirects keep `embedded=1`.
- PHP lint passes.

### Packet C - File Manager And Deploy Focused Pages

Owner scope:

- `app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php`
- `app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml`
- `app/code/Weline/FileManager/etc/marketplace/meta.json`
- `app/code/Weline/Deploy/Controller/Backend/WlsDeploy.php`
- `app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml`
- `app/code/Weline/Deploy/etc/marketplace/meta.json`

Tasks:

1. Remove plugin internal anchor menu in WLS panel embedded mode.
2. Replace `buildFileManagerAnchorUrl()` and `buildDeployAnchorUrl()` menu usage with real route URLs.
3. Render focused page content:
   - File: roots, browser, policy, write, queue, log, capabilities.
   - Deploy: overview, release path, configuration, project profile, preflight, webhooks, releases, manual plan.
4. File browser remains task-first; queue/audit/policy are separate pages.
5. Deploy does not show release path, webhook replay, manual plan, and releases all stacked by default.

Acceptance:

- No plugin menu href contains `#`.
- File browser route opens browser content directly.
- Deploy releases route opens release history directly.
- Embedded route links, form actions, and post-action redirects keep `embedded=1`.
- PHP lint passes.

### Packet D - Visual QA And Browser Verification

Owner scope:

- Browser verification and small CSS corrections only.
- Do not reintroduce anchor navigation, plugin workspace chrome, or plugin-local sidebars inside WLS Panel.

Tasks:

1. Run route checks for WLS native and plugin child routes.
2. Run browser smoke at desktop, tablet, and mobile widths.
3. Check light/dark mode.
4. Check project list/table pagination behavior.
5. Capture remaining visual defects as exact file/selector issues.

Acceptance:

- No visible nested plugin shell.
- No horizontal overflow at 1440, 1024, 768, and 390 widths.
- Sidebar plugin submenu opens the WLS Panel plugin shell URL with a real child selection.
- Back/forward navigation behaves naturally.

### Packet E - WLS Native Security Route Split

Owner scope:

- `app/code/Weline/Server/Controller/Backend/WlsPanel.php`
- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`

Tasks:

1. Add real WLS Panel routes for security logs, rules, project policy, and policy audit.
2. Render only the relevant security section for each route.
3. Move security filter and save redirects from `#security-*` anchors to the focused routes.
4. Add security secondary menu entries under the WLS sidebar Security item.
5. Keep the advanced ServerMonitor rule editor as a separate explicit action, not the default WLS security route.

Acceptance:

- `server/backend/wls-panel/security-logs::GET`
- `server/backend/wls-panel/security-rules::GET`
- `server/backend/wls-panel/security-policy::GET`
- `server/backend/wls-panel/security-audit::GET`
- Static scan finds no `#security-*` menu or redirect targets in the WLS Panel shell.
- `php bin/w setup:upgrade --route -m Weline_Server --skip-composer-dump --skip-reflection-compile --skip-env-check --sync` writes the routes.

### Packet F - Gateway Instance List Density

Owner scope:

- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`

Tasks:

1. Replace Gateway instance cards with a searchable paginated table.
2. Keep the default row compact: instance, Gateway listen, state, main listen, topology, actions.
3. Move control port, master state, process readiness, and full listener detail into an inline row drawer.
4. Keep the selected Gateway target visually clear without stretching the row.
5. Reuse the WLS Panel list density patterns used by managed projects.

Acceptance:

- Gateway page no longer renders `.wls-instance-card` records for discovered instances.
- Operators can search Gateway instances by name, listener, topology, or state.
- Operators can change page size and move between pages without scrolling through every instance.
- Detail expansion opens one row at a time and does not change routes.

### Packet G - Projects Page List-First Density

Owner scope:

- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`

Tasks:

1. Make managed projects list the first visible workflow on the Projects page.
2. Hide the add/edit project form unless `new_project=1` or an edit action is active.
3. Replace project operation cards with a compact table.
4. Keep project operation links inside WLS Panel plugin shell URLs.
5. Remove old card/grid CSS selectors so future static scans do not preserve the old product direction.

Acceptance:

- Opening `/server/backend/wls-panel/projects` shows the searchable project table before add/edit controls.
- Clicking `New Project` opens the same route with an explicit create state.
- `rg` finds no `wls-project-config-card`, `wls-project-config-grid`, or `data-wls-project-config-card` in the WLS Panel template.
- Project operation readiness is readable as rows, not stacked cards.

### Packet H - Marketplace Real AppStore Flow Density

Owner scope:

- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`

Tasks:

1. Remove static candidate plugin arrays and demo cards from the WLS Panel marketplace.
2. Replace Marketplace source cards with one compact AppStore control surface.
3. Keep install/search/account authorization inside the AppStore route opened with `module:wls` and backend surface filters.
4. Render installed WLS plugins as a compact table with module, tags, panel-entry state, and action.
5. Remove stale card/grid CSS selectors for the old marketplace prototype.

Acceptance:

- `rg` finds no `wls-plugin-card`, `wls-plugin-grid`, `wls-market-source-card`, `wls-market-source-grid`, `wls-installed-plugin-card`, or `wls-installed-plugin-grid` in the WLS Panel template.
- `rg` finds no hard-coded `wls-file-manager` marketplace search value.
- The Marketplace page has a single AppStore entry point and a real installed-plugin table.
- Installed plugin actions open the WLS plugin shell route when a panel menu declaration exists.

## Sequencing

1. Packet A must finish before browser verification.
2. Packets B and C can run in parallel after the prototype is accepted.
3. Packets F, G, and H can run independently after Packet A.
4. Packet D starts after A plus at least one plugin packet, then repeats after integration.

## Integration Gate

Run these checks before claiming success:

```powershell
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -l app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml
php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
rg -n "location\.hash|hashchange|href=\"[^\"\n]*#|#security-|#gateway-|#projects|#marketplace" app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
rg -n "wls-plugin-card|wls-plugin-grid|wls-market-source-card|wls-market-source-grid|wls-installed-plugin-card|wls-installed-plugin-grid|wls-file-manager|Open Install Flow" app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
rg -n "build.*AnchorUrl|href=\"[^\"\n]*#" app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
```

The `rg` commands should return no primary navigation anchor violations. Non-navigation CSS colors such as `#ffffff` are not violations.
