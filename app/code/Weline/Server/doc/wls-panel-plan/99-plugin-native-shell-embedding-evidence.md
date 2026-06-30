# WLS Panel Plugin Native Shell Embedding Evidence

Date: 2026-06-29

## Goal

Make WLS plugin capabilities feel like native WLS Panel pages:

- WLS Panel renders the primary and secondary plugin menus.
- Plugin content is loaded without a visible plugin-workspace wrapper.
- Plugin pages opened from WLS Panel keep `embedded=1`, so plugin-local sidebars and shell actions stay hidden.
- Parent plugin menu entries open the default plugin child page.

## Implemented Contract

| Area | Contract |
| --- | --- |
| WLS shell | `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml` owns plugin parent and child menus. |
| Plugin menu density | Inactive plugin capability groups are collapsed by default; the active group opens on route load and can be manually collapsed. |
| Plugin location state | The active plugin parent and active child item use explicit active classes and `aria-current` so the operator can see the current location. |
| Plugin host | WLS plugin page renders the iframe directly without titlebar, new-tab action, or back-to-dashboard action. |
| Plugin discovery | `WlsPanelPluginDiscoveryService` merges duplicate plugin entries by URL/key so local `wls_panel.menu.children` declarations are not swallowed by older AppStore-installed snapshots. |
| Dashboard density | Dashboard plugin capability and plugin entry summaries render as compact tables instead of large plugin cards. |
| Gateway density | Gateway instances render as a searchable paginated table with row details instead of large instance cards. |
| Projects density | Projects page renders the managed project table first, reveals add/edit forms only by explicit action, and shows operation readiness in a compact table instead of cards. |
| Marketplace density | Marketplace renders one AppStore entry surface plus a real installed-plugin table; static candidate plugin cards and hard-coded search values are removed. |
| Capability actions | Installed operation capabilities open the WLS plugin shell route, not a raw child plugin page, so the native sidebar controls secondary navigation. |
| DB/PHP plugins | Child route URLs, form action URLs, and post-action redirects preserve `embedded=1`. |
| File/Deploy plugins | Route-builder URLs, form action URLs, and post-action redirects preserve `embedded=1`. |
| Embedded plugin templates | DB, PHP, File Manager, and Deploy plugin templates do not render their own sidebar DOM when `embedded=1`; WLS Panel is the only visible navigation shell. |
| WLS security | Security overview, attack logs, rules, project policy, and policy audit are separate WLS Panel routes instead of `#security-*` anchors. |

## Static Validation

```text
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\PhpManager\Controller\Backend\WlsPhpManager.php
php -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -l app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml
php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
git diff --check -- app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
```

All lint checks reported `No syntax errors detected`, and `git diff --check`
reported no whitespace errors. The local PHP binary still prints known
duplicate-extension warnings before lint output.

```text
rg -n -- 'wls-operation-grid|wls-operation-card|wls-plugin-contribution-grid|wls-plugin-contribution-card|location\.hash|hashchange|Plugin Workspace|Open in New Tab|Back to Dashboard|href="[^"\n]*#' ...
```

The residual scan returned no matches in the WLS Panel shell and current WLS plugin templates.
The earlier standalone `wls-panel-plugins.js` prototype was removed because no
template loaded it; WLS Panel navigation is now server-route driven.

```text
rg -n "wls-plugin-card|wls-plugin-grid|wls-market-source-card|wls-market-source-grid|wls-installed-plugin-card|wls-installed-plugin-grid|wls-file-manager|Open Install Flow" app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

The Marketplace density scan must return no matches. WLS Panel does not keep a
separate static catalogue of candidate plugins; it opens AppStore for
`module:wls` search/install and renders only the installed WLS plugin table.

```text
rg -n "server/backend/wls-panel/security-(logs|rules|policy|audit)::GET|getSecurity(Logs|Rules|Policy|Audit)" generated\routers\backend_pc.php
```

The generated backend route table contains these WLS Panel security routes:

- `server/backend/wls-panel/security-logs::GET`
- `server/backend/wls-panel/security-rules::GET`
- `server/backend/wls-panel/security-policy::GET`
- `server/backend/wls-panel/security-audit::GET`

Route registration was refreshed with:

```text
php bin/w setup:upgrade --route -m Weline_Server --skip-composer-dump --skip-reflection-compile --skip-env-check --sync
```

The first route-only run without `--skip-env-check` was stopped by the local
Windows/PATH environment repair path (`chcp`, `php`, and `SCHTASKS` not found
from child commands). The second run skipped env repair, wrote the route file,
generated optimization caches, and completed.

```text
php -r "<reflection check for mergePanelContributionItems>"
```

The merge check returned `1`, proving that a duplicate plugin entry with the
same URL keeps the incoming child menu declaration.

```text
WLS PHP Manager:7
WLS Database Manager:9
WLS File Manager:7
WLS Deploy:8
```

The live service readout from `WlsPanelPluginDiscoveryService::getPanelContributions()`
confirms that the current installed WLS plugins now expose secondary menu
children to the WLS shell.

## Runtime Reload

```text
php bin/w server:reload
```

The default WLS instance completed rolling reload; all 8 workers returned READY.

## Route Smoke

```text
php bin/w http:request server/backend/wls-panel -b --filter=Fatal
php bin/w http:request server/backend/wls-panel/projects -b --filter=Fatal
php bin/w http:request server/backend/wls-panel/plugin -b --filter=Fatal
php bin/w http:request server/backend/wls-panel/marketplace -b --filter=Fatal
php bin/w http:request server/backend/wls-panel/security-logs -b --filter=Fatal
php bin/w http:request server/backend/wls-panel/security-rules -b --filter=Fatal
php bin/w http:request server/backend/wls-panel/security-policy -b --filter=Fatal
php bin/w http:request server/backend/wls-panel/security-audit -b --filter=Fatal
```

The current CLI has no valid backend session, so each request stopped at the
backend session gate before route execution. This confirms the remaining
runtime blocker is authentication state, not a PHP syntax failure.

## Browser Note

The current CLI and in-app browser have no backend login session. Screenshot-level
verification must be completed from an authenticated browser session.
