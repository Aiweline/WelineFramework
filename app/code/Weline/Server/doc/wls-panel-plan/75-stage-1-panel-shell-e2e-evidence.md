# Stage 1 Panel Shell Evidence

Status: passed in this checkout on 2026-06-17.

Scope:

- Independent WLS Panel shell opened from the backend route.
- Desktop dashboard screenshot.
- Mobile marketplace screenshot after dark theme toggle.
- Legacy backend footer/version strip hidden in the standalone panel.
- Typed WLS plugin tag display checked with `module:wls`.
- Horizontal overflow checked on desktop and mobile.

Route refresh:

```powershell
$env:WELINE_COMPOSER_COMMAND="composer"
php bin\w setup:upgrade --stage=route_update -m Weline_Server -s --skip-background-optimize --skip-reflection-compile
```

Route evidence:

- `generated\routers\backend_pc.php` contains `server/backend/wls-panel::GET`.
- `generated\routers\backend_pc.php` contains `server/backend/wls-panel/marketplace::GET`.

Runtime:

```powershell
php bin\w server:start ai-test-wls-panel-shell-9624 -p 9624 --no-ssl -r
php bin\w server:status ai-test-wls-panel-shell-9624
```

The instance ran on `http://p11005ce4.weline.test:9624` with dispatcher port `9624`.

Validation command:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN="http://p11005ce4.weline.test:9624"
$env:PLAYWRIGHT_DISABLE_PROXY="1"
$env:PLAYWRIGHT_INSTANCE_NAME="ai-test-wls-panel-shell-9624"
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- `1 passed (8.2s)`
- `E2E test completed successfully.`

Artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

## 2026-06-18 - Operations Capability Center

Scope:

- Added the dashboard Operations Capability Center for PHP Profiles, Database
  Profiles, File Manager, and Deploy Releases.
- Capability slots are resolved by installed WLS plugin typed tags:
  `custom:wls-php-manager`, `custom:wls-database-manager`,
  `custom:wls-file-manager`, and `custom:wls-deploy`.
- Missing capabilities link back to AppStore with
  `tag=module:wls&surface=backend` and a slot-specific query.

Static validation:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result: all three reported `No syntax errors detected`.

Service assertion:

- `getOperationCapabilities()` returned `count=4`.
- A mocked installed plugin with `custom:wls-file-manager` resolved the
  File Manager slot as installed and exposed `/admin/wls-file-manager`.

## 2026-06-19 - File Manager Project Context

Scope:

- Added backend-only `server.wlsPanelProject` query operation for WLS Panel
  plugin context resolution.
- `Weline_FileManager` now reads `project_id` or `domain` from GET and POST,
  resolves managed project metadata through the query provider, and switches
  the active project root to the child project when available.
- Child project `project_var` and `project_pub` roots are exposed as the
  guarded write targets; source roots remain read-only.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\extends\module\Weline_Framework\Query\ServerQueryProvider.php
extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
extend\server\php\php.exe -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
git diff --check
```

Result:

- PHP syntax checks reported `No syntax errors detected`.
- `git diff --check` reported no whitespace errors; the repository still emits
  pre-existing CRLF normalization warnings.
- `query:help server` includes `wlsPanelProject` with `project_id` and `domain`
  params.

Runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-file-project-9944 -p 9944 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
```

Browser smoke:

- Target:
  `http://127.0.0.1:9944/{backend_key}/weline_filemanager/backend/wls-file-manager?operation=files.read&project_id=6&domain=file-project-9944.wls.test&project_type=wls&root=project_var`
- HTTP status: `200`.
- The standalone file-manager shell rendered with `wls-file-manager-shell`.
- The page displayed child `project_var` and `project_pub` roots.
- The page listed `context.txt` from the temporary child project `var` root.
- The project-root card used the managed-registry description instead of the
  local-project fallback description.
- Desktop `1366x768` and mobile `390x844` checks reported no horizontal
  overflow.

Artifacts:

- `var/wls-panel-evidence/wls-file-manager-project-context-desktop-20260619-rerun.png`
- `var/wls-panel-evidence/wls-file-manager-project-context-mobile-20260619-rerun.png`
- PHP and database slots stayed missing without their custom tags.

Runtime validation:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-panel-ops-capabilities-9788 -p 9788 --no-ssl -c 2
```

Clean run:

- Instance: `ai-test-wls-panel-ops-capabilities-9788`
- Port: `9788`
- Master PID: `44168`
- Playwright command:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9788'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-ops-capabilities-9788'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\@playwright\test\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result: `1 passed`.

New browser assertions:

- `[data-wls-operation-capabilities]` is visible on the dashboard.
- `[data-wls-operation-card]` count is `4`.
- Each card contains its required typed custom tag.
- Mobile viewport keeps the operation cards in a single-column layout without
  horizontal overflow.

Screenshots:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-operation-capabilities-mobile.png`

Note:

- One repeated run before the clean restart hit the existing WLS output capture
  memory headroom guard while rendering the native Security page. Restarting the
  AI test instance cleared the runtime worker memory state and the clean run
  passed. The final validation above is from the clean instance.

Environment notes:

- PHP startup currently prints duplicate extension warnings; the E2E runtime JSON parser was hardened to ignore non-JSON warning prefixes.
- On this Windows checkout `cmd` can see an empty PATH, so `e2e:run` now resolves Node explicitly and injects a minimal PATH for Playwright child processes instead of relying on temporary `node.bat` / `chcp.bat` wrappers.
- This validation used a non-default WLS port and must be followed by `php bin\w server:stop ai-test-wls-panel-shell-9624` during task cleanup.

## Responsive And Theme Rerun

Status: passed in this checkout on 2026-06-17 after the responsive breakpoint and translated theme-toggle label refinement.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- `No syntax errors detected` for the WLS Panel controller.
- `No syntax errors detected` for the WLS Panel template.
- PHP startup still prints duplicate extension warnings from the local PHP configuration.

Route refresh note:

- `setup:upgrade --stage=route_update -m Weline_Server -s --skip-background-optimize --skip-reflection-compile` was attempted.
- The command reached registry refresh work but stopped at composer detection because this Windows PHP `exec()` path returns non-zero when composer prints `chcp is not recognized as an internal or external command`.
- Existing generated route evidence remains present:
  - `generated\routers\backend_pc.php` contains `server/backend/wls-panel::GET`.
  - `generated\routers\backend_pc.php` contains `server/backend/wls-panel/marketplace::GET`.

Runtime:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-panel-ui-9636 -p 9636 --no-ssl -r
```

The instance ran on `http://p11005ce4.weline.test:9636` with dispatcher port `9636`.

Validation command:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN="http://p11005ce4.weline.test:9636"
$env:PLAYWRIGHT_DISABLE_PROXY="1"
$env:PLAYWRIGHT_INSTANCE_NAME="ai-test-wls-panel-ui-9636"
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- `1 passed (15.2s)`
- `E2E 测试执行成功。`

Updated artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
  - Updated at `2026-06-17 21:18:25`, size `65629` bytes.
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`
  - Updated at `2026-06-17 21:18:27`, size `33480` bytes.

Visual notes:

- Desktop panel shows the independent WLS shell with left navigation and content aligned without overlap.
- Mobile marketplace uses the dark theme and keeps the navigation above content without horizontal overflow.
- Theme toggle label is now translated through the template (`Dark` / `Light`, Chinese `暗色` / `亮色`) instead of being hard-coded by JavaScript.
- This validation used a non-default WLS port and must be followed by `php bin\w server:stop ai-test-wls-panel-ui-9636` during task cleanup.

## Marketplace AppStore Entry Rerun

Status: passed in this checkout on 2026-06-17 after adding the WLS typed-tag AppStore entry cards.

Scope:

- Marketplace page exposes `data-wls-marketplace-source="appstore"`.
- Marketplace page keeps `data-wls-marketplace-tag="module:wls"`.
- Online AppStore entry uses `tag=module:wls&surface=backend`.
- Installed module entry uses `tag=module:wls&surface=backend`.
- Candidate plugin cards open AppStore search with `tag=module:wls&surface=backend&q=<plugin name>`.
- Mobile dark-theme marketplace layout remains single-column without horizontal overflow.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- `No syntax errors detected` for the WLS Panel controller.
- `No syntax errors detected` for the WLS Panel template.
- PHP startup still prints duplicate extension warnings from the local PHP configuration.

Runtime:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-panel-marketplace-9648 -p 9648 --no-ssl -c 2
```

The instance ran on `http://p11005ce4.weline.test:9648` with dispatcher port `9648`.

Validation command:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN="http://p11005ce4.weline.test:9648"
$env:PLAYWRIGHT_DISABLE_PROXY="1"
$env:PLAYWRIGHT_INSTANCE_NAME="ai-test-wls-panel-marketplace-9648"
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- `1 passed (7.6s)`
- `E2E test completed successfully.`

DOM link assertion:

```json
{
  "source": "appstore",
  "tag": "module:wls",
  "onlineHref": "http://p11005ce4.weline.test:9648/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/appstore/backend?tag=module%3Awls&surface=backend",
  "installedHref": "http://p11005ce4.weline.test:9648/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/appstore/backend/installed?tag=module%3Awls&surface=backend",
  "installHref": "http://p11005ce4.weline.test:9648/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/appstore/backend?tag=module%3Awls&surface=backend&q=WLS+File+Manager"
}
```

Updated artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

Cleanup:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-marketplace-9648
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:status ai-test-wls-panel-marketplace-9648
```

Status confirmed `Master status: stopped`.

## Dashboard Data Source Rerun

Status: passed in this checkout on 2026-06-17 after replacing the dashboard prototype counts and fake child project with real WLS data sources.

Scope:

- `WlsPanelDashboardDataService` reads dashboard metrics from existing WLS services.
- Gateway counts and interim project cards come from `ReverseProxy::getAllRules()`.
- Security event counts come from `AttackLog::getStatistics('', 7)`.
- Runtime summary comes from `ServerInstanceManager::getAllPersistedInstanceInfo()` and runtime stats.
- Empty gateway state renders only the current project; the previous sample child project is no longer emitted.
- The dashboard gateway link now targets the real backend route `server/backend/reverse-proxy-manager`.
- The database profile link now opens the standalone `Weline_DbManager` shell when the `custom:wls-database-manager` typed-tag plugin is installed; missing installs still fall back to the filtered WLS AppStore flow.

## DbManager Plugin Slice Rerun

Status: passed in this checkout on 2026-06-18 after adding the first
`Weline_DbManager` WLS plugin slice.

Scope:

- `Weline_DbManager` declares `module:wls`,
  `custom:wls-database-manager`, `feature:database-profile`,
  `capability:database-read`, `capability:database-test`, and `system:true`.
- Route refresh registered:
  `weline_dbmanager/backend/wls-db-manager::GET` and
  `weline_dbmanager/backend/wls-db-manager/test-connection::POST`.
- The standalone plugin shell reads effective DB profiles through
  `Env::getDbConfig()`, masks username/password state, accepts safe WLS project
  context, and exposes a POST-only sanitized connection test.
- The WLS Panel operation center now detects `database-profile` as installed by
  exact typed tag match.
- Browser validation covered desktop and mobile DbManager shell screenshots,
  dark-theme inheritance, project context rendering, profile rendering,
  connection-test form rendering, WLS marketplace installed-module card, and
  no horizontal overflow.

Validation:

```powershell
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
php bin\w setup:upgrade --stage=route_update -m Weline_DbManager --skip-classmap --skip-reflection-compile -s
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-dbmanager-9864'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9864'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- Syntax checks passed.
- Route update completed; setup output also reported 3 new ACL permissions for
  the super administrator.
- E2E passed: `1 passed (1.3m)`.
- Screenshots:
  `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-shell-desktop.png`
  and
  `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-shell-mobile.png`.
- Environment note: the first E2E attempt timed out before tests because the
  runner picked a stale stopped WLS instance. The final run used an explicit AI
  test instance on port `9864`. `tests/e2e/framework/runtime-info.php` now sets
  a short PDO timeout for theme discovery so unreachable DB endpoints cannot
  stall Playwright configuration initialization indefinitely.

Impact scan:

```powershell
npx gitnexus impact "Weline\\Server\\Controller\\Backend\\WlsPanel::renderPanel" --direction upstream
```

Result:

- The command timed out while `npx` attempted package setup and npm cache cleanup failed with `EPERM`.
- The change was therefore validated with focused syntax, service, E2E, DOM, and screenshot checks.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelDashboardDataService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- `No syntax errors detected` for all three checked files.
- PHP startup still prints duplicate extension warnings from the local PHP configuration.

Direct service assertion:

```powershell
@'
<?php
require __DIR__ . '/app/bootstrap.php';
$service = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Server\Service\WlsPanelDashboardDataService::class);
$data = $service->getDashboardData();
echo json_encode([
    'metrics' => $data['metrics'] ?? [],
    'project_count' => count($data['projects'] ?? []),
    'first_project' => $data['projects'][0]['domain'] ?? null,
    'gateway' => [
        'total' => $data['gateway']['total'] ?? null,
        'active' => $data['gateway']['active'] ?? null,
    ],
    'security' => [
        'events_7d' => $data['security']['events_7d'] ?? null,
        'blocked_7d' => $data['security']['blocked_7d'] ?? null,
    ],
    'runtime' => [
        'instances' => $data['runtime']['instances'] ?? null,
        'running_instances' => $data['runtime']['running_instances'] ?? null,
        'workers' => $data['runtime']['workers'] ?? null,
    ],
    'errors' => $data['errors'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
'@ | E:\WelineFramework\DEV-workspace\extend\server\php\php.exe
```

Result:

```json
{"metrics":{"managed_projects":1,"gateway_rules":0,"security_events":0},"project_count":1,"first_project":"localhost","gateway":{"total":0,"active":0},"security":{"events_7d":0,"blocked_7d":0},"runtime":{"instances":9,"running_instances":1,"workers":8},"errors":[]}
```

Runtime:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-panel-dashboard-9656 -p 9656 --no-ssl -c 2
```

The instance ran on `http://p11005ce4.weline.test:9656` with dispatcher port `9656`.

Validation command:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN="http://p11005ce4.weline.test:9656"
$env:PLAYWRIGHT_DISABLE_PROXY="1"
$env:PLAYWRIGHT_INSTANCE_NAME="ai-test-wls-panel-dashboard-9656"
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- `1 passed (17.8s)`
- `E2E test completed successfully.`

DOM assertion:

```json
{
  "metricValues": ["1", "0", "4", "0"],
  "projectCards": [
    {
      "title": "当前项目",
      "domain": "p11005ce4.weline.test:9656",
      "status": "本地",
      "pathLabel": "路径",
      "dbHref": "http://p11005ce4.weline.test:9656/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/appstore/backend?tag=module%3Awls&surface=backend&q=WLS+Database+Manager"
    }
  ],
  "gatewayHref": "http://p11005ce4.weline.test:9656/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/reverse-proxy-manager",
  "hasFakeChild": false,
  "hasFatal": false,
  "scroll": {"html":0,"body":0}
}
```

Updated artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

Visual notes:

- Desktop dashboard shows a data-backed current-project card and no sample child project.
- Mobile dark marketplace remains single-column and has no horizontal overflow.

Cleanup:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-dashboard-9656
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:status ai-test-wls-panel-dashboard-9656
```

Status confirmed `Master status: stopped` and `Status: all stopped (0/0)`.

## WLS File Manager Plugin Shell Rerun

Status: passed in this checkout on 2026-06-18 after adding the first real WLS panel plugin slice.

Scope:

- `Weline_FileManager` declares WLS panel capability through `etc/marketplace/meta.json`.
- Typed tags include `module:wls`, `custom:wls-file-manager`, `category:server-tools`, `feature:file-manager`, `capability:files-read`, and `system:true`.
- AppStore installed-module query now falls back to enabled local module marketplace meta when no install record exists.
- WLS plugin discovery accepts `wls_panel.menu[].backend_route`, so plugin menu entries can open standalone backend shells.
- File Manager shell opens independently from the framework backend and can jump back to WLS Panel or the project backend.
- Desktop and mobile layouts support dark/light theme switching without visible footer/version leakage or horizontal body overflow.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\AppStore\extends\module\Weline_Framework\Query\AppStoreQueryProvider.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\AppStore\Service\InstalledModuleMetaService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
```

Result:

- PHP lint reported `No syntax errors detected` for the checked PHP and PHTML files.
- `node --check` reported no JavaScript syntax error.
- PHP startup still prints duplicate extension warnings from the local PHP configuration.

Marketplace meta assertion:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w module:info Weline_FileManager --locale=zh_Hans_CN
```

Result:

- `marketplace_meta` resolves from `app/code/Weline/FileManager/etc/marketplace/meta.json`.
- Resolved tags include `module:wls` and `custom:wls-file-manager`.

AppStore query assertion:

```powershell
@'
<?php
require __DIR__ . '/app/bootstrap.php';
$items = w_query('appstore', 'installedModules', [
    'tag' => 'module:wls',
    'surface' => 'backend',
    'module_name' => 'Weline_FileManager',
    'locale' => 'zh_Hans_CN',
]);
echo json_encode([
    'count' => count($items),
    'module' => $items[0]['module_name'] ?? null,
    'custom_tag' => $items[0]['custom_tag_code'] ?? null,
    'backend_route' => $items[0]['marketplace_meta']['wls_panel']['menu'][0]['backend_route'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
'@ | E:\WelineFramework\DEV-workspace\extend\server\php\php.exe
```

Expected result shape:

```json
{"count":1,"module":"Weline_FileManager","custom_tag":"custom:wls-file-manager","backend_route":"weline_filemanager/backend/wls-file-manager"}
```

Route refresh:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w setup:upgrade --route --module Weline_FileManager --skip-env-check --skip-reflection-compile --skip-classmap --skip-background-optimize
```

Route evidence:

- `generated\classmap.php` contains `Weline\FileManager\Controller\Backend\WlsFileManager`.
- `generated\routers\backend_pc.php` contains `weline_filemanager/backend/wls-file-manager::GET`.
- `php bin\w http:request <backend route> -b` reached backend auth protection and returned the expected missing-session message instead of a route miss.

Runtime:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-file-manager-9806 -p 9806 --no-ssl -c 2
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe tests\e2e\framework\backend-session-bootstrap.php --mode=wls --username=admin --password=admin
```

The instance ran on `http://p11005ce4.weline.test:9806` with dispatcher port `9806`.

Validation command:

```powershell
$env:PATH = 'C:\Windows\System32;C:\Windows;E:\WelineFramework\DEV-workspace\extend\server\php;E:\WelineFramework\DEV-workspace\extend\server;' + $env:PATH
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-file-manager-9806'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
Push-Location tests\e2e
node node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium --timeout=300000
Pop-Location
```

Result:

- `1 passed (25.9s)`.

New browser assertions:

- File Manager standalone route renders `[data-wls-file-manager-shell]`.
- Desktop view shows project context values, four path cards, three capability cards, and a visible theme toggle.
- Mobile view keeps the shell, context cards, path cards, and top navigation responsive without horizontal body or sidebar overflow.
- Marketplace view includes the locally bundled `Weline_FileManager` card via typed-tag discovery.
- Security page still preserves the WLS panel dark theme expectation after the File Manager theme toggle sequence.

Updated artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-mobile.png`

Visual notes:

- Desktop shell now has high-contrast headings and no leaked `1.0.1` footer/version strip.
- Mobile shell uses a two-column wrapped top toolbar and no horizontal scrollbar.
- File operations remain intentionally read-only shell placeholders until the path whitelist, ACL confirmation, and operation-log tasks are implemented.

Cleanup:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:stop ai-test-wls-file-manager-9806
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:status ai-test-wls-file-manager-9806
```

Status confirmed `Master status: stopped` and `Status: all stopped (0/0)`.

## Installed WLS Plugin Discovery Rerun

Status: passed in this checkout on 2026-06-17 after adding the AppStore QueryProvider and WLS panel installed-plugin discovery layer.

Scope:

- AppStore exposes a cross-module-safe query provider: `appstore.installedModules`.
- WLS Panel consumes installed plugin state through `WlsPanelPluginDiscoveryService`.
- WLS Panel marketplace renders an installed plugin capability section with `data-wls-installed-plugin-count`.
- WLS still does not directly call AppStore internal PHP services.
- Empty installed-plugin state is rendered as a panel empty state.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\AppStore\extends\module\Weline_Framework\Query\AppStoreQueryProvider.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- `No syntax errors detected` for all four checked files.
- PHP startup still prints duplicate extension warnings from the local PHP configuration.

Registry/query refresh:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w extends:rebuild
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w query:help appstore
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w query:help appstore installedModules
```

Result:

- `extends:rebuild` completed and found `Weline_AppStore` with `extension_files=1`.
- `query:help appstore` lists provider `appstore`.
- `query:help appstore installedModules` lists params `tag`, `surface`, `module_name`, and `locale`.

Direct query assertion:

```powershell
@'
<?php
require __DIR__ . '/app/bootstrap.php';
$result = w_query('appstore', 'installedModules', ['tag' => 'module:wls', 'surface' => 'backend']);
echo json_encode(['count' => $result['count'] ?? null, 'first' => $result['items'][0]['module_name'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
'@ | E:\WelineFramework\DEV-workspace\extend\server\php\php.exe
```

Result:

```json
{"count":0,"first":null}
```

Runtime:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-panel-discovery-9652 -p 9652 --no-ssl -c 2
```

The instance ran on `http://p11005ce4.weline.test:9652` with dispatcher port `9652`.

Validation command:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN="http://p11005ce4.weline.test:9652"
$env:PLAYWRIGHT_DISABLE_PROXY="1"
$env:PLAYWRIGHT_INSTANCE_NAME="ai-test-wls-panel-discovery-9652"
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- `1 passed (14.9s)`
- `E2E test completed successfully.`

DOM assertion:

```json
{
  "countAttr": "0",
  "emptyText": true,
  "candidateCards": 4,
  "installedCards": 0
}
```

Updated artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

Visual notes:

- Desktop dashboard remains clean after the metric uses installed plugin capability count fallback.
- Mobile dark marketplace shows the installed-plugin count section before the candidate cards without horizontal overflow.

Cleanup:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-discovery-9652
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:status ai-test-wls-panel-discovery-9652
```

Status confirmed `Master status: stopped`.

## Project Registry And Gateway Sync Smoke

Status: partially passed in this checkout on 2026-06-17.

Passed scope:

- The panel project registry model can persist managed WLS project metadata.
- The registry service can save and delete projects from panel form data.
- Gateway-enabled project saves create a linked ReverseProxy rule.
- Project deletion removes the linked ReverseProxy rule.
- The panel route table includes project save/delete POST routes.
- The independent WLS Panel renders the project editor, child project links, PHP/database action links, security links, theme toggle, and responsive layout.

Not yet complete at the time of this smoke, later completed by the "Full Gateway Role E2E Apply Rerun" section below:

- Full Gateway-role E2E routing was not proven from the project save/delete path at this point in the log. It is completed by the later "Full Gateway Role E2E Apply Rerun" section.
- A dedicated validation instance on port `9664` did not become a stable listener in this checkout, even though startup logs reached dispatcher setup. The failed instance was cleaned up and no listener remained on `9664`.
- Browser smoke therefore used the already running non-9501 WLS instance `codex-binquery-docs-live` on port `9520` as fallback evidence.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Model\WlsPanelProject.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelProjectRegistryService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelDashboardDataService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- `No syntax errors detected` for all checked files.
- PHP startup still prints duplicate extension warnings from the local PHP configuration.

Schema and route refresh:

```powershell
C:\Windows\System32\cmd.exe /d /c "set PATH=C:\Windows\System32;C:\Windows;E:\WelineFramework\DEV-workspace\extend\server\php;E:\WelineFramework\DEV-workspace\extend\server;%PATH%&&set WELINE_COMPOSER_COMMAND=echo&&extend\server\php\php.exe bin\w setup:upgrade --model -m Weline_Server --skip-background-optimize --skip-reflection-compile --skip-classmap"
C:\Windows\System32\cmd.exe /d /c "set PATH=C:\Windows\System32;C:\Windows;E:\WelineFramework\DEV-workspace\extend\server\php;E:\WelineFramework\DEV-workspace\extend\server;%PATH%&&set WELINE_COMPOSER_COMMAND=echo&&extend\server\php\php.exe bin\w setup:upgrade --route -m Weline_Server --skip-background-optimize --skip-reflection-compile --skip-classmap"
```

Result:

- Both commands exited with code `0`.
- Model setup created/updated the `Weline_Server` schema.
- Route setup wrote backend route cache entries.
- A no-op composer command was used only for the setup gate because the local Windows PHP/composer child process path had earlier failed with `chcp is not recognized` / composer timeout behavior.

Route evidence:

- `generated\routers\backend_pc.php` contains `server/backend/wls-panel::GET`.
- `generated\routers\backend_pc.php` contains `server/backend/wls-panel/project-save::POST`.
- `generated\routers\backend_pc.php` contains `server/backend/wls-panel/project-delete::POST`.

Direct service lifecycle assertion:

```json
{
  "ok": true,
  "domain": "ai-panel-smoke-20260617144757.weline.test",
  "save": {
    "success": true,
    "message": "Managed project saved.",
    "project_id": 1,
    "gateway_synced": true,
    "gateway_message": "Gateway rule saved."
  },
  "project_id": 1,
  "proxy_id": 1,
  "card_backend": "http://127.0.0.1:9664",
  "card_gateway_enabled": 1,
  "delete": {
    "success": true,
    "message": "Managed project removed."
  },
  "project_exists_after_delete": false,
  "proxy_exists_after_delete": false
}
```

HTTP fallback check:

- Direct HTTP request to the existing WLS instance on `127.0.0.1:9520` returned `HTTP/1.1 200`.
- Response headers included `X-Wls-Instance: codex-binquery-docs-live`.
- The rendered panel HTML included the project management form, database profile link, PHP config link, security/attack-log links, theme script, and responsive CSS.
- `php bin/w http:request` was not used as final evidence for this URL because the default generated HTTPS form timed out; direct HTTP was the working runtime path.

Browser smoke:

```json
{
  "ok": true,
  "status": 200,
  "isLogin": false,
  "hasFatal": false,
  "hasShell": 1,
  "hasProjectForm": 1,
  "hasThemeToggle": 1,
  "hasSecurityLink": 3,
  "hasPhpConfig": 1,
  "hasDbProfile": 2,
  "desktopOverflow": {"htmlScrollWidth":1440,"htmlClientWidth":1440,"bodyScrollWidth":1440,"bodyClientWidth":1440},
  "themeAfterClick": "dark",
  "mobileOverflow": {"htmlScrollWidth":390,"htmlClientWidth":390,"bodyScrollWidth":390}
}
```

Updated artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-projects-desktop-smoke.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-projects-mobile-smoke.png`

Cleanup evidence:

- Stuck `ai-test-wls-panel-projects-9664` startup processes were stopped.
- `php bin/w server:stop ai-test-wls-panel-projects-9664` reported the master PID was not running and metadata was marked stopped.
- `Get-NetTCPConnection -LocalPort 9664` showed no remaining listener.

## Proxy Apply Control Path Smoke

Status: passed for Master control-path reachability on 2026-06-17. Full Gateway-role E2E was completed later in this file.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\Control\IpcControlGatewayInterface.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\Control\IpcControlGateway.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\ServiceOrchestrator.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelProjectRegistryService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\ReverseProxyManager.php
```

Result:

- `No syntax errors detected` for every checked file.
- PHP startup still prints duplicate extension / OPcache warnings from the local PHP configuration.

Runtime setup:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-panel-proxy-9668 -p 9668 --no-ssl -c 2
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:list | Select-String 'ai-test-wls-panel-proxy-9668|9668'
```

Result:

- `ai-test-wls-panel-proxy-9668` was running on port `9668` with Master PID `41312`.

Direct project registry apply assertion:

```json
{
    "domain": "ai-panel-proxy-apply-20260617151402.weline.test",
    "save_success": true,
    "project_id": 3,
    "gateway_synced": true,
    "gateway_applied": false,
    "gateway_apply_message": "没有已连接的 Gateway 进程可应用代理配置。",
    "delete_success": true,
    "delete_gateway_applied": false,
    "delete_gateway_apply_message": "没有已连接的 Gateway 进程可应用代理配置。"
}
```

Interpretation:

- Save/delete both reached the target WLS Master instance through `IpcControlGateway::proxyApply()`.
- The result is no longer `Unknown command` and no longer falls back to the `default` instance during delete.
- The failure is the expected and visible runtime boundary for this smoke: no Gateway role process was connected to receive `TYPE_PROXY_RELOAD`.

Cleanup evidence:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-proxy-9668
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:list | Select-String 'ai-test-wls-panel-proxy-9668|9668'
Get-NetTCPConnection -LocalPort 9668 -ErrorAction SilentlyContinue
```

Result:

- `server:stop` completed the IPC stop flow and marked `ai-test-wls-panel-proxy-9668` as stopped.
- `server:list` shows the instance as stopped with `PID: -`.
- `Get-NetTCPConnection -LocalPort 9668` returned no listener (`port-9668-free`).

Impact-analysis note:

- `npx gitnexus impact ...` was attempted before the runtime control-path edits, but the local Windows `npx` cleanup failed with npm `_npx/.../gitnexus/...` `EPERM` errors. This was treated as tooling evidence failure, not as product validation evidence.

## Full Gateway Role E2E Apply Rerun

Status: passed in this checkout on 2026-06-17 after fixing Gateway role registration identity and role-targeted proxy reload delivery.

Scope:

- Start a parent WLS instance with `WLS_GATEWAY_ENABLED=1`.
- Start a child HTTPS WLS backend on a non-default test port.
- Prove the Gateway role registers to Master, reaches `ready`, and is targetable by role.
- Apply a runtime route through `IpcControlGateway::proxyApply()` without restarting WLS.
- Prove a real TLS SNI request reaches the child backend through the Gateway listen port.
- Prove the WLS Panel project registry save/delete path persists metadata, syncs the linked ReverseProxy rule, and applies Gateway config.
- Clean up temporary panel projects, linked ReverseProxy rules, test instances, and temp files.

Code checks:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\Control\ControlPlaneServerInterface.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\IPC\MasterControlServer.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\Control\HybridControlPlaneServer.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\Control\IpcControlGatewayInterface.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\Control\IpcControlGateway.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\ServiceOrchestrator.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsGateway.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\bin\gateway.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\Provider\GatewayProvider.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\MasterProcess.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\ServerInstanceManager.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Console\Server\Start.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\ReverseProxyManager.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelProjectRegistryService.php
```

Result:

- `No syntax errors detected` for all checked files.
- PHP startup still prints duplicate extension / OPcache warnings from the local PHP configuration.
- `rg -n "sleep\(|usleep\(|die\(|exit\(" app/code/Weline/Server/Service/WlsGateway.php` returned no matches.

Runtime setup:

```powershell
$env:WLS_GATEWAY_ENABLED='1'
$env:WLS_GATEWAY_LISTEN='127.0.0.1:9672'
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-gateway-master-9671 -p 9671 -c 1 --no-ssl
```

Result:

- Parent WLS Master started on `9671`.
- Gateway listened on `127.0.0.1:9672`.
- Child HTTPS WLS backend was already running on `9673` for the route target.

Gateway control-plane status:

```json
{
  "role": "gateway",
  "instance_id": 1,
  "epoch": 23,
  "launch_id": "gateway-1-05804a010a50",
  "pid": 49104,
  "port": 9672,
  "state": "ready",
  "ipc_client_id": 452,
  "metadata": {
    "slot_id": "gateway#1",
    "lease_id": "gateway-1-05804a010a50",
    "generation": 26,
    "lease_state": "ready",
    "ready_at": 1781715047.312908,
    "ack_ready_at": 1781715047.31298
  }
}
```

Direct proxy apply assertion:

```json
{
  "success": true,
  "accepted": true,
  "completed": true,
  "status": "completed",
  "action": "proxy_apply",
  "message": "代理配置已应用到 1 个 Gateway 进程。",
  "data": {
    "routes": 1,
    "gateways": 1,
    "targets": ["gateway(ipc:452)"]
  }
}
```

SNI routing assertion:

```powershell
curl.exe --noproxy "*" -k -I --resolve wls-gateway-e2e.local:9672:127.0.0.1 https://wls-gateway-e2e.local:9672/
```

Result:

- `HTTP/1.1 200 OK`
- Response included `X-Weline-Route-Hint: port=26125,sni=wls-gateway-e2e.local,ttl=3600`.

Panel project registry apply assertion:

```json
{
  "success": true,
  "message": "Managed project saved.",
  "project_id": 5,
  "gateway_synced": true,
  "gateway_message": "Gateway rule saved.",
  "gateway_applied": true,
  "gateway_apply_message": "代理配置已应用到 1 个 Gateway 进程。",
  "domain": "ai-panel-service-e2e-20260618005340.weline.test"
}
```

Panel-service SNI assertion:

```powershell
curl.exe --noproxy "*" -k -I --resolve ai-panel-service-e2e-20260618005340.weline.test:9672:127.0.0.1 https://ai-panel-service-e2e-20260618005340.weline.test:9672/
```

Result:

- `HTTP/1.1 200 OK`
- Response included `X-Weline-Route-Hint: port=26125,sni=ai-panel-service-e2e-20260618005340.weline.test,ttl=3600`.

Panel project delete assertion:

```json
{
  "success": true,
  "message": "Managed project removed.",
  "gateway_applied": true,
  "gateway_apply_message": "代理配置已应用到 1 个 Gateway 进程。"
}
```

Cleanup evidence:

- `WlsPanelProject::select()->fetchArray()` returned `[]`.
- ReverseProxy rules with description `WLS Panel:%` returned `[]`.
- `server:stop ai-test-wls-gateway-master-9671` completed the IPC stop flow and stopped Dispatcher, Gateway, and HTTP Worker.
- `server:stop ai-test-wls-gateway-backend-9673` completed the IPC stop flow and stopped Dispatcher and HTTP Worker.
- `Get-NetTCPConnection -LocalPort 9671,9672,9673 -State Listen` returned no listener.
- Removed `E:\WelineFramework\DEV-workspace\var\tmp\wls-gateway-e2e`.
- Removed `E:\WelineFramework\DEV-workspace\var\tmp\wls_gateway_e2e_backend.php`.

Design note:

- Gateway registration now carries the WLS instance name as `instance_code`; this satisfies `MasterControlServer` instance-code validation and lets the Gateway connection stay registered.
- `WlsPanelProjectRegistryService` now accepts explicit `instance`, `gateway_instance`, or `wls_instance` inputs and can auto-select exactly one running Gateway-enabled WLS instance. Ambiguous multi-instance apply remains a Stage 2 UX task and should get a native target selector before broad use.
- Direct-listen multi-worker public-port optimization is still a separate runtime design task. This rerun validates the passthrough Gateway control path, not the future direct-listen mode.

## Native Gateway Settings Panel Slice

Status: passed in this checkout on 2026-06-17 for the first native settings slice.

Scope:

- Add `WlsPanelGatewaySettingsService`.
- Add `WlsPanel::postGatewayApply()`.
- Add the dashboard `#gateway-settings` section before managed projects.
- Add `gateway_instance` target selection to Gateway Settings, project save, and project delete forms.
- Keep stopped historical instances visible as status cards, but restrict apply-target options to running/control-capable targets.
- Avoid raw `<dl>/<dt>/<dd>` in the WLS Panel template because the Weline template compiler treats `<dd>` as a taglib candidate and can generate invalid PHP when short echo syntax is nested in it.

Route refresh:

```powershell
cmd.exe /d /c 'set "PATH=C:\Windows\System32;C:\Windows;E:\WelineFramework\DEV-workspace\extend\server\php;E:\WelineFramework\DEV-workspace\extend\server;%PATH%" && set "WELINE_COMPOSER_COMMAND=echo" && extend\server\php\php.exe bin\w setup:upgrade --route -m Weline_Server --skip-background-optimize --skip-reflection-compile --skip-classmap'
```

Result:

- `Weline_Server` routes updated successfully.
- `generated\routers\backend_pc.php` contains `server/backend/wls-panel/gateway-apply::POST`.
- The first attempt without the `cmd.exe` PATH wrapper failed at composer discovery and `chcp` lookup; the wrapped command passed.

Syntax checks:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelGatewaySettingsService.php
extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- All reported `No syntax errors detected`.
- The local PHP runtime still prints duplicate extension warnings and `Cannot load Zend OPcache - it was already loaded`.

Runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-gateway-9684 -p 9684 --no-ssl -c 2
```

The instance ran on:

- `http://p11005ce4.weline.test:9684`
- Backend prefix: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`

Primary browser smoke:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9684'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-gateway-9684'
cmd.exe /d /c 'set "PATH=C:\Windows\System32;C:\Windows;E:\WelineFramework\DEV-workspace\extend\server\node;E:\WelineFramework\DEV-workspace\extend\server\php;E:\WelineFramework\DEV-workspace\extend\server;%PATH%" && extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium'
```

Result:

- First run exposed a real template compile failure:
  `ParseError: syntax error, unexpected token "<"` in compiled `com_index.phtml`, caused by raw `<dd><?= ... ?></dd>`.
- After replacing that markup with `div/span` and clearing the module template compile cache, the rerun passed:
  `1 passed (6.6s)` / `E2E 测试执行成功。`
- A later rerun briefly hit the WLS startup maintenance page while Workers were unavailable; after `server:status` reported `全部运行中 (3/3)`, the same spec passed again:
  `1 passed (6.6s)`.

Focused Gateway Settings DOM smoke:

```json
{
  "hasFatal": false,
  "gatewayVisible": true,
  "targetOptions": [
    "自动选择 Gateway",
    "ai-test-wls-panel-gateway-9684",
    "codex-binquery-docs-live"
  ],
  "applyFormVisible": true,
  "instanceCardCount": 15,
  "overflowX": false,
  "metrics": {
    "htmlScrollWidth": 1440,
    "htmlClientWidth": 1440,
    "bodyScrollWidth": 1440,
    "bodyClientWidth": 1440
  }
}
```

Manual apply POST smoke:

```json
{
  "url": "http://p11005ce4.weline.test:9684/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel?gateway_instance=ai-test-wls-panel-gateway-9684&panel_error=...#gateway-settings&",
  "hasFatal": false,
  "hasGatewaySection": true,
  "resultText": "没有已连接的 Gateway 进程可应用代理配置。"
}
```

Cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-gateway-9684
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-gateway-9684
```

Result:

- Stop completed through IPC.
- Final status: `全部停止 (0/0)`.

Remaining work:

- Gateway mode env persistence and listen-address editing are still not implemented in the panel.
- Direct-listen public-port optimization remains a runtime design and implementation task.
- Multi-target verification still needs a live test with two simultaneously running Gateway-enabled WLS instances.

## Gateway Runtime Action And Native Security Page Rerun

Status: passed in this checkout on 2026-06-17.

Scope:

- Gateway Settings save now exposes `runtime_action` with `reload`, `restart`,
  and `none`.
- `reload` calls `IpcControlGateway::reloadAsync(...)`.
- `restart` submits `php bin/w server:start <instance> -r -f` through
  `Processer::create()` for listener/Gateway-role changes.
- `none` saves config and optional route apply without a runtime action.
- The native Security page renders inside the independent WLS Panel shell.
- Security page data comes from `AttackLog` and `AttackDetector`.
- Security rules can be saved as validated JSON through the panel.
- Recent attack logs and rule summary cards are visible without leaving WLS
  Panel.

Route refresh:

```powershell
cmd.exe /d /c "set PATH=C:\Windows\System32;C:\Windows;E:\WelineFramework\DEV-workspace\extend\server\php;E:\WelineFramework\DEV-workspace\extend\server;%PATH%&& set WELINE_COMPOSER_COMMAND=echo&& E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w setup:upgrade --route -m Weline_Server --skip-background-optimize --skip-reflection-compile --skip-classmap"
```

Result:

- `setup:upgrade --route` exited with code `0`.
- `generated\routers\backend_pc.php` contains
  `server/backend/wls-panel/security::GET`.
- `generated\routers\backend_pc.php` contains
  `server/backend/wls-panel/security-rules-save::POST`.

Syntax checks:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelGatewaySettingsService.php
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelSecurityDataService.php
extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- All reported `No syntax errors detected`.
- `rg -n "\b(sleep|die|exit)\s*\("` on the modified WLS Panel files returned
  no matches.
- The local PHP runtime still prints duplicate extension / OPcache warnings.

Runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-security-9732 -p 9732 -c 2 --no-ssl
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-security-9732
```

Result:

- `ai-test-wls-panel-security-9732` ran on `http://127.0.0.1:9732`.
- `server:status` reported 2 HTTP Workers and 1 Dispatcher, all running
  `(3/3)`.
- Direct `Invoke-WebRequest http://127.0.0.1:9732/` returned `STATUS=200`.

Primary browser E2E:

```powershell
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-security-9732'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9732'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
$env:PLAYWRIGHT_TEST_FILES='["tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js"]'
cd tests\e2e
C:\nvm4w\nodejs\node.exe .\node_modules\playwright\cli.js test --config=.\playwright.config.js --project=chromium
```

Result:

- `1 passed (10.0s)`.
- The spec covered desktop dashboard, Gateway runtime-action selector, dark
  theme persistence, native Security page, mobile dark marketplace,
  `module:wls` plugin tag display, and no horizontal overflow.

Visual artifacts inspected:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

Visual notes:

- Desktop dashboard keeps the independent WLS shell, left navigation, and
  Gateway Settings form aligned without overlap.
- Mobile marketplace keeps the dark theme, wraps header actions, and avoids
  horizontal scroll.

Cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-security-9732
```

Result:

- Stop completed through IPC.
- Dispatcher and both HTTP Workers drained and disconnected.
- Instance `ai-test-wls-panel-security-9732` was marked stopped.

Operational note:

- The older `codex-binquery-docs-live` validation instance on port `9520`
  showed stale duplicate Master/Dispatcher/Worker processes and returned
  `503`. The successful rerun used a fresh non-default WLS instance on port
  `9732` to keep the product validation independent from that dirty runtime.

## Native Attack Log Filter And Pagination Rerun

Status: passed in this checkout on 2026-06-18.

Scope:

- WLS Panel Security page now keeps attack-log operations inside the
  independent panel shell.
- Attack logs can be filtered by instance, IP, severity, attack type, and
  blocked status.
- Page size and previous/next pagination are rendered from
  `WlsPanelSecurityDataService`.
- The Security page keeps the JSON rules editor and rule summary in the same
  native panel view.
- Chinese labels for the new filter controls are present (`实例`, `严重级别`,
  `攻击类型`, `拦截状态`, `每页数量`).

Impact scan:

```powershell
gitnexus impact --repo dev-workspace --direction upstream "WlsPanelSecurityDataService::getSecurityData"
```

Result:

- GitNexus reported the target was not found because the WLS Panel service file
  is still untracked and not yet present in the local GitNexus index.
- Source grep showed current callers are limited to the WLS Panel controller and
  template/test paths.

Syntax and static checks:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelSecurityDataService.php
extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
rg -n "\b(sleep|die|exit)\s*\(" app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
```

Result:

- All PHP syntax checks reported `No syntax errors detected`.
- The forbidden `sleep` / `die` / `exit` scan returned no matches.
- The local PHP runtime still prints duplicate extension / OPcache warnings.

Direct service assertion:

```json
{
  "filters": {
    "instance": "default",
    "ip": "",
    "severity": "critical",
    "type": "",
    "blocked": "1",
    "page": 1,
    "limit": 10
  },
  "pagination": {
    "total": 0,
    "page": 1,
    "limit": 10,
    "total_pages": 1,
    "has_prev": false,
    "has_next": false
  },
  "recent_count": 0,
  "severity_options": 4,
  "type_options": 7,
  "error": ""
}
```

Runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-security-filter-9746 -p 9746 -c 2 --no-ssl
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-security-filter-9746
```

Result:

- `ai-test-wls-panel-security-filter-9746` ran on `http://127.0.0.1:9746`.
- `server:status` reported 2 HTTP Workers and 1 Dispatcher, all running
  `(3/3)`.
- Direct `Invoke-WebRequest http://127.0.0.1:9746/` returned `STATUS=200`.

Primary browser E2E:

```powershell
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-security-filter-9746'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9746'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
$env:PLAYWRIGHT_TEST_FILES='["tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js"]'
cd tests\e2e
C:\nvm4w\nodejs\node.exe .\node_modules\playwright\cli.js test --config=.\playwright.config.js --project=chromium
```

Result:

- `1 passed (10.4s)`.
- The spec covers dashboard, Gateway runtime-action selector, dark-theme
  persistence, native Security page, native attack-log filter controls,
  filter submission state retention, mobile dark marketplace, typed plugin tags,
  and horizontal-overflow assertions.

Focused visual smoke:

```json
{
  "desktop": {
    "hasShell": true,
    "hasFilter": true,
    "hasFooter": true,
    "fatal": false,
    "htmlScrollWidth": 1440,
    "htmlClientWidth": 1440,
    "bodyScrollWidth": 1440,
    "bodyClientWidth": 1440
  },
  "mobile": {
    "htmlScrollWidth": 390,
    "htmlClientWidth": 390,
    "bodyScrollWidth": 390,
    "bodyClientWidth": 390
  }
}
```

Additional DOM label assertion:

```json
{
  "hasShell": true,
  "hasSecurity": true,
  "hasLogs": true,
  "hasLogin": false,
  "status": 200
}
```

The page body contained the translated filter labels:
`实例`, `IP`, `严重级别`, `攻击类型`, `拦截状态`, `每页数量`.

Artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-filter-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-logs-filter.png`

Runtime note:

- A repeated run on the older `ai-test-wls-panel-security-filter-9744` instance
  hit WLS output-capture memory headroom after several manual screenshot and DOM
  probes. The instance was stopped, and the final pass was taken on a fresh
  non-default instance `ai-test-wls-panel-security-filter-9746`.

## WLS-SEC-005 Project-Aware Security Scope Slice

Implementation:

- `WlsPanelSecurityDataService::getSecurityDataFromFilters()` now accepts a
  `scope` / `security_scope` filter plus the current dashboard project list.
- Scope options are built from `panelDashboardData['projects']` and include:
  `all`, `current`, registered project IDs, and gateway-derived domains.
- Selecting a project scope resolves to `AttackLog.domain` and applies that
  domain to both the filtered attack-log query and the 7-day security metrics.
- `WlsPanel::renderPanel()` now reuses one dashboard data result and passes its
  project cards into the security data service.
- The native Security page renders a `Project Scope` select before the instance
  filter, and pagination links preserve the selected scope.

Impact scan:

```powershell
gitnexus impact --repo dev-workspace --direction upstream "WlsPanelSecurityDataService::getSecurityDataFromFilters"
gitnexus impact --repo dev-workspace --direction upstream "Weline\\Server\\Controller\\Backend\\WlsPanel::renderPanel"
```

Result:

- GitNexus reported both targets were not found because the WLS Panel files are
  still untracked and not yet present in the local GitNexus index.
- Source grep showed the modified chain is limited to the WLS Panel controller,
  template, security service, i18n files, and focused E2E spec.

Syntax and static checks:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelSecurityDataService.php
extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
rg -n "\b(sleep|usleep|die|exit)\s*\(" app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
```

Result:

- All PHP syntax checks reported `No syntax errors detected`.
- The forbidden WLS call scan returned no matches.
- The local PHP runtime still prints duplicate extension / OPcache warnings.

Direct service assertion:

```json
{
  "scope": "project:42",
  "domain": "child.example.test",
  "blocked": "1",
  "scope_options": [
    "all",
    "current",
    "project:42"
  ],
  "pagination": {
    "total": 0,
    "page": 1,
    "limit": 10,
    "total_pages": 1,
    "has_prev": false,
    "has_next": false
  },
  "error": ""
}
```

Runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-security-scope-9752 -p 9752 -c 2 --no-ssl
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-security-scope-9752
Invoke-WebRequest http://127.0.0.1:9752/
```

Result:

- `ai-test-wls-panel-security-scope-9752` ran on `http://127.0.0.1:9752`.
- `server:status` reported 2 HTTP Workers and 1 Dispatcher, all running
  `(3/3)`.
- Direct `Invoke-WebRequest http://127.0.0.1:9752/` returned `STATUS=200`.

Primary browser E2E:

```powershell
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-security-scope-9752'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9752'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
$env:PLAYWRIGHT_TEST_FILES='["tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js"]'
cd tests\e2e
C:\nvm4w\nodejs\node.exe .\node_modules\playwright\cli.js test --config=.\playwright.config.js --project=chromium
```

Result:

- `1 passed (8.8s)` after adding the project-scope assertion and screenshot.
- The spec now confirms `select[name="security_scope"]` is visible, selecting
  `current` keeps `security_scope=current` in the URL, and the filter state is
  retained after submit.
- Existing desktop/mobile no-horizontal-overflow assertions still pass.

Artifact:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-scope-filter.png`

Cleanup:

- `server:stop ai-test-wls-panel-security-scope-9752` completed the WLS stop
  protocol and reported the instance stopped.
- `server:status ai-test-wls-panel-security-scope-9752` reported Master stopped
  and `0/0`.
- `Get-NetTCPConnection -LocalPort 9752` only showed transient `TimeWait`
  entries with `OwningProcess=0`; no listener remained.
- Listener check reported `PORT_LISTEN_FREE=9752` and `PORT_LISTEN_FREE=9753`.
## Stage 3 WLS-SEC-003 First Slice - Visual Rule Editor

Date: 2026-06-18

Scope:

- Added a native common-rule editor inside `server/backend/wls-panel/security`.
- Covered `rate_limit`, `path_scan`, `ssl_handshake_failure`,
  `unknown_route_ban`, `ip_whitelist`, and `protected_paths`.
- Kept the merged JSON editor as the advanced surface.
- Preserved the existing save contract:
  `WlsPanel::postSecurityRulesSave()` -> `WlsPanelSecurityDataService` ->
  `AttackDetector::updateRules()`.
- Fixed `AttackDetector` list merge semantics so explicitly supplied numeric
  list fields replace default lists instead of leaving default tail entries.

Implementation files:

- `app/code/Weline/Server/Security/AttackDetector.php`
- `app/code/Weline/Server/Service/WlsPanelSecurityDataService.php`
- `app/code/Weline/Server/Controller/Backend/WlsPanel.php`
- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`
- `app/code/Weline/Server/i18n/en_US.csv`
- `app/code/Weline/Server/i18n/zh_Hans_CN.csv`
- `tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js`
- `app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md`
- `app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md`
- `app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md`

Design evidence:

- The Security page now renders `Common Rule Editor` above `Merged Rules JSON`.
- Visual fields overwrite only the supported common keys on save.
- Unexposed advanced rules remain editable in JSON and are preserved.
- `AttackDetector::mergeRules()` keeps default fallback for missing sections
  but replaces explicitly supplied list fields:
  `path_rate_limits.rules`, `cdn_trusted_ips.ips`, `ip_whitelist.ips`,
  `malicious_patterns.patterns`, `bad_user_agents.patterns`,
  `protected_paths.paths`, and `ban_on_path_match.paths`.

Service assertion:

```powershell
@'
<?php
require 'app/bootstrap.php';
// Calls WlsPanelSecurityDataService::saveRulesFromPanel(), asserts merged
// values, then restores the original rules JSON.
'@ | php
```

Result:

```text
visual rule merge assertions passed
original security rules restored
```

The assertion verified:

- `rate_limit.max_requests` visual override.
- `path_scan.enabled=false`.
- `ssl_handshake_failure.fast_close_threshold=0.33`.
- `unknown_route_ban.only_in_spike_mode=false`.
- `ip_whitelist.ips` line splitting and de-duplication.
- `protected_paths.paths` exact list replacement.
- `protected_paths.block_duration` visual override.

Syntax and constraint checks:

```powershell
php -l app/code/Weline/Server/Security/AttackDetector.php
php -l app/code/Weline/Server/Service/WlsPanelSecurityDataService.php
php -l app/code/Weline/Server/Controller/Backend/WlsPanel.php
php -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
rg -n "\b(sleep|usleep|die|exit)\s*\(|alert\(|confirm\(|prompt\(" ...
```

Result:

- All PHP/template syntax checks passed.
- Forbidden runtime/API scan had no matches.
- PHP printed existing local warnings about duplicate extension loading and
  OPcache already loaded; these warnings were unrelated to the edited files.

WLS runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-rule-editor-9762 -p 9762 -c 2 --no-ssl
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-rule-editor-9762
```

Runtime evidence:

- Instance: `ai-test-wls-panel-rule-editor-9762`
- Target: `http://127.0.0.1:9762`
- Master PID during validation: `15392`
- Dispatcher: `9762`
- Workers: `26214`, `26215`
- HTTP reachability: `Invoke-WebRequest http://127.0.0.1:9762/` returned `200`.

Playwright regression:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9762'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-rule-editor-9762'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\@playwright\test\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result:

```text
1 passed (13.3s)
```

The spec now checks the native visual rule editor fields:

- `visual_rules[rate_limit][max_requests]`
- `visual_rules[path_scan][max_unique_paths]`
- `visual_rules[ssl_handshake_failure][fast_close_threshold]`
- `visual_rules[unknown_route_ban][only_in_spike_mode]`
- `visual_rules[ip_whitelist][ips]`
- `visual_rules[protected_paths][paths]`

Responsive focused smoke:

```text
{"htmlScrollWidth":390,"htmlClientWidth":390,"bodyScrollWidth":390,"bodyClientWidth":390,"shellTheme":"dark","ruleGridColumns":"324px"}
```

Evidence screenshot paths:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-scope-filter.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-mobile-rule-editor.png`

Visual review:

- Desktop dark theme: security title uses the panel text color and the rule
  summary panel aligns from the top.
- Mobile dark theme: sidebar stacks above content, rule editor becomes one
  column, and no horizontal overflow was detected at `390x844`.

Cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-rule-editor-9762
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-rule-editor-9762
Get-NetTCPConnection -LocalPort 9762,9763 -ErrorAction SilentlyContinue
```

Result:

- `server:stop` completed.
- `server:status` reported Master stopped and `0/0` running processes.
- Port check showed only transient `TIME_WAIT` rows for `9762`, with no owning
  listener process.

## Stage 3 WLS-SEC-003 Second Slice - Path Rate-Limit Row Editor

Date: 2026-06-18

Scope:

- Added `path_rate_limits.rules` to the native WLS Panel Security visual rule editor.
- Kept the advanced merged JSON editor visible and unchanged as the escape hatch.
- Added a responsive row editor for enabled state, path prefix, window seconds,
  max requests, and block duration.
- Added one blank row for append behavior; clearing a path removes that row on save.
- Saved through the existing path:
  `WlsPanel::postSecurityRulesSave()` -> `WlsPanelSecurityDataService` ->
  `AttackDetector::updateRules()`.

Implementation files:

- `app/code/Weline/Server/Service/WlsPanelSecurityDataService.php`
- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`
- `app/code/Weline/Server/i18n/en_US.csv`
- `app/code/Weline/Server/i18n/zh_Hans_CN.csv`
- `tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js`
- `app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md`
- `app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md`
- `app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md`

Service assertion:

```powershell
@'
<?php
require 'app/bootstrap.php';
// Saves visual_rules[path_rate_limits], asserts normalized merged rules, then
// restores the original rules JSON.
'@ | php
```

Result:

```text
path rate visual merge assertions passed
original security rules restored
```

The assertion verified:

- `path_rate_limits.enabled=true`.
- Blank path rows are skipped.
- Plain paths are normalized with a leading slash.
- Full URLs are reduced to their URL path.
- Disabled row state is preserved.
- Numeric values are stored as bounded integers.

Syntax and constraint checks:

```powershell
php -l app/code/Weline/Server/Service/WlsPanelSecurityDataService.php
php -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
php -l app/code/Weline/Server/Controller/Backend/WlsPanel.php
rg -n "\b(sleep|usleep|die|exit)\s*\(|alert\(|confirm\(|prompt\(" ...
```

Result:

- All PHP/template syntax checks passed.
- Forbidden runtime/API scan had no matches.
- PHP printed existing local warnings about duplicate extension loading and
  OPcache already loaded; these warnings were unrelated to the edited files.

WLS runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-path-rate-9774 -p 9774 -c 2 --no-ssl
Invoke-WebRequest http://127.0.0.1:9774/ -UseBasicParsing -TimeoutSec 20
```

Runtime evidence:

- Instance: `ai-test-wls-panel-path-rate-9774`
- Target: `http://127.0.0.1:9774`
- Dispatcher: `9774`
- Workers: `26226`, `26227`
- HTTP reachability returned `StatusCode=200`.

Playwright regression:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9774'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-path-rate-9774'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\@playwright\test\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result:

```text
1 passed (10.4s)
```

The spec now checks the path-rate visual editor fields:

- `visual_rules[path_rate_limits][enabled]`
- `.wls-path-rate-panel`
- `visual_rules[path_rate_limits][rules][0][path]`
- `visual_rules[path_rate_limits][rules][0][max_requests]`

Responsive focused smoke:

```json
{
  "htmlScrollWidth": 390,
  "htmlClientWidth": 390,
  "bodyScrollWidth": 390,
  "bodyClientWidth": 390,
  "theme": "dark",
  "pathRateVisible": true
}
```

Evidence screenshot paths:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-scope-filter.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-mobile-path-rate.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-mobile-path-rate-panel.png`

Visual review:

- Desktop dark theme: the path-rate editor sits as a full-width rule panel with
  a compact table layout.
- Mobile dark theme: the same rows stack into labeled vertical fields, and no
  horizontal overflow was detected at `390x844`.

Cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-path-rate-9774
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-path-rate-9774
Get-NetTCPConnection -LocalPort 9774,9775 -ErrorAction SilentlyContinue
```

Result:

- `server:stop` completed through the WLS stop protocol.
- `server:status` reported Master stopped and `0/0` running processes.
- Port check showed only transient `TIME_WAIT` rows for `9774`, with
  `OwningProcess=0` and no listener process.

## Stage 3 WLS-SEC-003 Third Slice - Rule Change Preview

Date: 2026-06-18

Scope:

- Added a before-save rule change preview between the visual rule editor and
  the merged JSON editor.
- The preview shows changed rule paths plus before/after values.
- Invalid JSON now shows an invalid preview state instead of presenting a stale
  or misleading diff.
- The browser preview is local-only and does not send a background request.
- The server service exposes `previewRulesFromPanel()` so assertions can prove
  the preview uses the same merge contract as the save path.

Implementation files:

- `app/code/Weline/Server/Service/WlsPanelSecurityDataService.php`
- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`
- `app/code/Weline/Server/i18n/en_US.csv`
- `app/code/Weline/Server/i18n/zh_Hans_CN.csv`
- `tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js`
- `app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md`
- `app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md`
- `app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md`

Service assertion:

```powershell
@'
<?php
require 'app/bootstrap.php';
// Calls WlsPanelSecurityDataService::previewRulesFromPanel(), asserts the
// changed path list, and verifies invalid JSON returns no changes.
'@ | php
```

Result:

```text
rule preview assertions passed
```

The assertion verified:

- Changing `visual_rules[rate_limit][max_requests]` produces
  `rate_limit.max_requests` in the preview list.
- The preview target value matches the visual input after merge.
- Invalid JSON returns `success=false` and `change_count=0`.

Syntax and constraint checks:

```powershell
php -l app/code/Weline/Server/Service/WlsPanelSecurityDataService.php
php -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
rg -n "\b(sleep|usleep|die|exit)\s*\(|alert\(|confirm\(|prompt\(" ...
```

Result:

- Service and template syntax checks passed.
- Forbidden runtime/API scan had no matches.
- PHP printed existing local warnings about duplicate extension loading and
  OPcache already loaded; these warnings were unrelated to the edited files.

WLS runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-rule-diff-9784 -p 9784 -c 2 --no-ssl
Invoke-WebRequest http://127.0.0.1:9784/ -UseBasicParsing -TimeoutSec 20
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-rule-diff-9784
```

Runtime evidence:

- Instance: `ai-test-wls-panel-rule-diff-9784`
- Target: `http://127.0.0.1:9784`
- Master PID during validation: `35636`
- Dispatcher: `9784`
- Workers: `26236`, `26237`
- HTTP reachability returned `StatusCode=200`.
- `server:status` reported Master, 2 HTTP Workers, and 1 Dispatcher running
  `(3/3)`.

Playwright regression:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9784'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-rule-diff-9784'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\@playwright\test\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result:

```text
1 passed (10.7s)
```

The spec now checks:

- `[data-wls-rule-diff-preview]` is visible.
- Initial preview state is `empty`.
- Editing `visual_rules[rate_limit][max_requests]` changes preview state to
  `changed`.
- `data-wls-rule-diff-count-value` is non-zero.
- The preview contains `rate_limit.max_requests`.

Responsive focused smoke:

```json
{
  "htmlScrollWidth": 390,
  "htmlClientWidth": 390,
  "bodyScrollWidth": 390,
  "bodyClientWidth": 390,
  "state": "changed"
}
```

Evidence screenshot paths:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-rule-diff-preview.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-rule-diff-preview-mobile.png`

Visual review:

- Desktop dark theme: preview uses a compact two-column before/after layout.
- Mobile dark theme: before/after values stack into one column and no horizontal
  overflow was detected at `390x844`.

Cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-rule-diff-9784
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-rule-diff-9784
Get-NetTCPConnection -LocalPort 9784,9785 -ErrorAction SilentlyContinue
```

Result:

- `server:stop` completed.
- `server:status` reported Master stopped and `0/0` running processes.
- Port check showed only transient `TIME_WAIT` rows for `9784`, with
  `OwningProcess=0` and no listener process.

## Stage 3 WLS-SEC-005 Second Slice - Project Security Drilldown

Scope:

- Added project-level security posture cards to the native WLS Panel Security
  page.
- The cards are built from the same `panelDashboardData['projects']` source as
  the project list, so current project, registered child projects, and
  gateway-derived domains share the same scope vocabulary.
- Each card shows 7-day events, blocked events, critical count, risk, top
  attack type, latest event, latest IP, and a `View Logs` link that opens the
  native filtered attack-log list with the matching `security_scope`.

Implementation files:

- `app/code/Weline/Server/Service/WlsPanelSecurityDataService.php`
- `app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml`
- `app/code/Weline/Server/i18n/en_US.csv`
- `app/code/Weline/Server/i18n/zh_Hans_CN.csv`
- `tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js`
- `app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md`
- `app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md`
- `app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md`

GitNexus:

```powershell
gitnexus impact "Weline\\Server\\Service\\WlsPanelSecurityDataService" --repo dev-workspace --direction upstream
gitnexus impact "Weline\\Server\\Controller\\Backend\\WlsPanel" --repo dev-workspace --direction upstream
```

Result:

- GitNexus ran through the global command.
- Both symbols returned `Target not found` because the WLS Panel classes are
  still unindexed/untracked in the current checkout.
- Risk was treated as unknown and controlled through source-template reading,
  service assertions, syntax checks, WLS runtime, and browser validation.

Service assertion:

```powershell
@'
<?php
require 'app/bootstrap.php';
// Calls WlsPanelSecurityDataService::getSecurityDataFromFilters() with
// all/current project scopes and asserts project_security_summaries shape.
'@ | php
```

Result:

```text
project security summaries assertions passed
```

The assertion verified:

- `project_security_summaries` includes an `all` scope and a `current` scope.
- The active current scope keeps its normalized domain.
- Each summary exposes the fields required by the template:
  events, blocked, risk label, top type label, latest time, and latest IP.

Syntax and constraint checks:

```powershell
php -l app/code/Weline/Server/Service/WlsPanelSecurityDataService.php
php -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
rg -n "\b(sleep|usleep|die|exit)\s*\(|alert\(|confirm\(|prompt\(" ...
```

Result:

- Service and template syntax checks passed.
- Forbidden runtime/API scan had no matches.
- PHP printed existing local warnings about duplicate extension loading and
  OPcache already loaded; these warnings were unrelated to the edited files.

Runtime and browser validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-security-drilldown-9786 -p 9786 -c 2 --no-ssl
Invoke-WebRequest http://127.0.0.1:9786/ -UseBasicParsing -TimeoutSec 20
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-security-drilldown-9786
```

Runtime evidence:

- Instance: `ai-test-wls-panel-security-drilldown-9786`
- Target: `http://127.0.0.1:9786`
- Fresh validation Master PID: `4012`
- Dispatcher: `9786`
- Workers: `26238`, `26239`
- HTTP reachability returned `StatusCode=200`.

Playwright regression:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9786'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-security-drilldown-9786'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\@playwright\test\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result:

```text
1 passed (10.6s)
```

The spec now checks:

- `[data-wls-security-projects]` is visible on the Security page.
- `[data-wls-security-project-card]` renders at least one card.
- The current project card is visible.
- After selecting `security_scope=current`, the matching card has `is-active`.

Responsive focused smoke:

```json
{
  "htmlScrollWidth": 390,
  "htmlClientWidth": 390,
  "bodyScrollWidth": 390,
  "bodyClientWidth": 390,
  "cardCount": 2,
  "hasProjectDrilldown": true,
  "activeScope": "all"
}
```

Evidence screenshot paths:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-project-drilldown.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-project-drilldown-mobile.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-project-drilldown-mobile-section.png`

Visual review:

- Desktop dark theme: the project drilldown sits between the security metrics
  and rule editor, with the active scope highlighted.
- Mobile dark theme: cards stack into one column, labels and buttons fit, and
  no horizontal overflow was detected at `390x844`.

Correction found during validation:

- The first Playwright run caught a compiled-template `ParseError` because the
  source template used native `<dt>/<dd>` tags inside the WLS template compiler.
- The source template was changed to use simple `div/span/strong` markup and
  precomputed fallback labels; generated files were not edited.

## 2026-06-18 - Project Operation Deep Links

Scope:

- Project cards now render four operation actions:
  `PHP Config`, `Database Config`, `Files`, and `Deploy`.
- These actions reuse the Operations Capability Center slot keys:
  `php-profile`, `database-profile`, `file-manager`, and `deploy`.
- Missing plugins open AppStore with `tag=module:wls&surface=backend`.
- Installed plugin URLs receive safe project context only:
  `operation`, `project_id`, `domain`, and `project_type`.
- Raw local `project_path` stays out of URL query strings.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/Service/WlsPanelPluginDiscoveryService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP reported `No syntax errors detected`.
- Node syntax check exited successfully.
- Forbidden-call scan returned no matches.

Runtime validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-project-ops-9790 -p 9790 --no-ssl -c 2
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9790'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-project-ops-9790'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result: `1 passed`.

Additional project-card DOM proof:

- First project card had exactly four `[data-wls-project-operation]` links.
- Keys found: `php-profile`, `database-profile`, `file-manager`, `deploy`.
- Current missing-plugin state linked each action to AppStore with
  `tag=module%3Awls&surface=backend&q=<slot query>`.

New screenshot:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-project-ops-card.png`

## 2026-06-18 - Installed Plugin Entry And Refresh Action

Scope:

- `appstore.installedModules` now exposes the installed module's raw
  `marketplace_meta`, `capabilities`, and optional WLS panel entry fields:
  `wls_panel_url`, `panel_url`, `backend_url`, `capability_url`, `url`,
  `panel_entry`, `backend_entry`, and `wls_panel`.
- `WlsPanelPluginDiscoveryService` resolves installed operation entry URLs from
  the query result, `marketplace_meta`, or `capabilities`, and ignores unsafe
  `javascript:`, `data:`, and `vbscript:` href schemes.
- Template href generation converts non-absolute plugin entry strings such as
  `server/backend/wls-file-manager` through `getBackendUrl()` so plugin authors
  do not need to know the randomized backend prefix.
- WLS Panel Marketplace now has a native `plugin-refresh` POST action. Clicking
  `Refresh Panel Plugins` reruns discovery and returns to Marketplace with a
  refreshed notice.

Impact-analysis note:

- `npx gitnexus impact --repo dev-workspace --direction upstream ...` was
  attempted for `AppStoreQueryProvider::normalizeInstalledModule`,
  `WlsPanelPluginDiscoveryService`, `WlsPanel::getMarketplace`, and
  `WlsPanel::renderPanel`.
- The available `npx` path did not return usable impact output: two commands
  ended with npm cleanup `EPERM`, and two commands timed out. Work continued
  from direct source review and focused route/query/runtime validation.

Route refresh:

```powershell
cmd.exe /d /c "set PATH=C:\Windows\System32;C:\Windows;E:\WelineFramework\DEV-workspace\extend\server\php;E:\WelineFramework\DEV-workspace\extend\server;%PATH%&& set WELINE_COMPOSER_COMMAND=echo&& E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w setup:upgrade --route -m Weline_Server --skip-background-optimize --skip-reflection-compile --skip-classmap"
rg -n "wls-panel/plugin-refresh" generated/routers app/code/Weline/Server/Controller/Backend/WlsPanel.php
```

Result:

- `setup:upgrade --route` exited with code `0`.
- `generated/routers/backend_pc.php` contains
  `server/backend/wls-panel/plugin-refresh::POST`.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\AppStore\extends\module\Weline_Framework\Query\AppStoreQueryProvider.php
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php
extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
php bin\w query:help appstore installedModules
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/Service/WlsPanelPluginDiscoveryService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/AppStore/extends/module/Weline_Framework/Query/AppStoreQueryProvider.php tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP reported `No syntax errors detected` for all touched PHP/template files.
- Node syntax check exited successfully.
- `query:help appstore installedModules` lists the existing params `tag`,
  `surface`, `module_name`, and `locale`.
- Forbidden-call scan returned no matches.

Runtime validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-plugin-refresh-9794 -p 9794 --no-ssl -c 2
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9794'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-plugin-refresh-9794'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result: `1 passed`.

Runtime cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-plugin-refresh-9794
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-plugin-refresh-9794
Get-NetTCPConnection -LocalPort 9794 -ErrorAction SilentlyContinue | Select-Object LocalAddress,LocalPort,State,OwningProcess
```

Result:

- Instance status reported `全部停止 (0/0)`.
- Port `9794` only had `TIME_WAIT` rows with `OwningProcess=0`.

Browser/UI evidence:

- Playwright clicked `[data-wls-plugin-refresh]` on the Marketplace page and
  asserted the refreshed success notice.
- Latest screenshot:
  `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`.
  Visual check: mobile dark mode keeps the Marketplace layout readable, the
  refreshed notice appears before source cards, and no horizontal overflow was
  detected.

## 2026-06-18 - Plugin Menu Contribution Slice

Scope:

- Added installed-plugin menu contribution parsing from `wls_panel.menu[]`.
- Rendered plugin-contributed entries in the standalone WLS Panel sidebar and
  dashboard contribution section.
- Updated typed tag / marketplace meta docs and panel prototype docs.

Static validation:

```powershell
php -l app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\s*\(" app/code/Weline/Server/Service/WlsPanelPluginDiscoveryService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
extend\server\php\php.exe bin\w query:help appstore installedModules
```

Result:

- PHP reported `No syntax errors detected` for the touched service,
  controller, and template.
- Node syntax check exited successfully.
- Forbidden-call scan returned no matches.
- `appstore.installedModules` still documents `tag`, `surface`,
  `module_name`, and `locale`.
- GitNexus impact analysis was attempted for
  `Weline\Server\Service\WlsPanelPluginDiscoveryService` and
  `Weline\Server\Controller\Backend\WlsPanel`, but `npx gitnexus impact`
  failed before graph output because npm cleanup hit Windows `EPERM` under the
  local npm cache. Scope was kept module-local as fallback.

Runtime validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-contrib-9798 -p 9798 --no-ssl -c 2
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9798'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-contrib-9798'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
node .\node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium
```

Result: `1 passed`.

Runtime cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-panel-contrib-9798
extend\server\php\php.exe bin\w server:status ai-test-wls-panel-contrib-9798
Get-NetTCPConnection -LocalPort 9798 -ErrorAction SilentlyContinue | Select-Object LocalAddress,LocalPort,State,OwningProcess
```

Result:

- Instance status reported `全部停止 (0/0)`.
- Port `9798` only had `TIME_WAIT` rows with `OwningProcess=0`.

Browser/UI evidence:

- Playwright asserted `[data-wls-plugin-contributions]` on desktop and mobile.
- Desktop, mobile operation capability, and mobile dark marketplace screenshots
  were refreshed under `tests/e2e/artifacts/backend/Weline_Server/`.
- Visual check: desktop panel keeps a stable operations dashboard layout;
  mobile dark mode remains readable and has no horizontal overflow.

## 2026-06-18 - FileManager Read-only Browser Slice

Scope:

- Added guarded read-only directory browsing to the WLS File Manager plugin
  shell.
- Browser roots stay limited to project root, `app/code`, `var`, and `pub`.
- E2E now asserts root tabs, visible entries, directory drill-down, go-up
  affordance, traversal fallback for `path=..%2F..`, and no raw
  `project_path` propagation.
- Mobile FileManager smoke still checks visible browser content and no page
  horizontal overflow.

Static validation:

```powershell
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
extend\server\php\php.exe -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
```

Result:

- Node syntax check exited successfully.
- PHP reported `No syntax errors detected` for the FileManager controller and
  template.
- PHP startup still prints duplicate extension warnings and an already-loaded
  OPcache warning from the local PHP configuration.

Route evidence:

- `generated\routers\backend_pc.php` contains
  `weline_filemanager/backend/wls-file-manager::GET`.

Runtime validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-file-manager-9806 -p 9806 --no-ssl -c 2
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9806'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-file-manager-9806'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium --timeout=300000
```

Result:

- `1 passed (18.9s)`.

Runtime cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-file-manager-9806
extend\server\php\php.exe bin\w server:status ai-test-wls-file-manager-9806
```

Result:

- Instance status reported all stopped: `0/0`.

Browser/UI evidence:

- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-desktop.png`
  shows the dark standalone FileManager shell, path summary cards, root tabs,
  current path, table header, and go-up action after directory drill-down.
- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-mobile.png`
  shows the mobile FileManager shell with the sidebar converted to top
  navigation and no visible horizontal layout break.
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png` was also
  refreshed by the same run and keeps the independent WLS Panel layout intact.

## 2026-06-18 - Deploy WLS Meta Slice

Scope:

- Added `app/code/Weline/Deploy/etc/marketplace/meta.json`.
- Declared Deploy as a WLS panel plugin with `module:wls`,
  `custom:wls-deploy`, `feature:tag-deploy`,
  `capability:deploy-webhook`, `capability:deploy-tag`, and `system:true`.
- Added WLS panel menu contribution metadata for the Deploy entry.
- No release execution behavior changed in this slice.

Static validation:

```powershell
node -e "const fs=require('fs'); const meta=JSON.parse(fs.readFileSync('app/code/Weline/Deploy/etc/marketplace/meta.json','utf8')); if(meta.module_name!=='Weline_Deploy') process.exit(1); console.log(meta.tags.map(t=>t.code).join(','));"
extend\server\php\php.exe -r "require 'app/code/Weline/Framework/MarketplaceMeta/MarketplaceTag.php'; require 'app/code/Weline/Framework/MarketplaceMeta/MarketplaceMetaValidator.php'; `$meta = json_decode((string)file_get_contents('app/code/Weline/Deploy/etc/marketplace/meta.json'), true); `$validator = new Weline\Framework\MarketplaceMeta\MarketplaceMetaValidator(); `$result = `$validator->validate(`$meta, 'Weline_Deploy', true); if (!empty(`$result['errors'])) { fwrite(STDERR, json_encode(`$result)); exit(1); } echo json_encode(`$result, JSON_UNESCAPED_UNICODE);"
```

Result:

- JSON parse succeeded and printed
  `module:wls,custom:wls-deploy,category:server-tools,feature:tag-deploy,capability:deploy-webhook,capability:deploy-tag,system:true`.
- Strict marketplace meta validation returned `{"errors":[],"warnings":[]}`.

Runtime validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-deploy-meta-9812 -p 9812 --no-ssl -c 2
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9812'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-meta-9812'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
node .\node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium --timeout=300000
```

Result:

- `1 passed (18.9s)`.
- The test now directly asserts
  `[data-operation-key="deploy"].is-installed` and
  `[data-wls-plugin-nav][data-plugin-module="Weline_Deploy"]`.

Runtime cleanup:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-deploy-meta-9812
extend\server\php\php.exe bin\w server:status ai-test-wls-deploy-meta-9812
```

Result:

- Instance status reported all stopped: `0/0`.

Browser/UI evidence:

- Updated `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
  shows the operations capability center with Deploy marked installed.
- The sidebar now includes the Deploy menu contribution from
  `Weline_Deploy` marketplace meta.
- The operations summary changed from one installed plugin to two installed
  plugins while preserving the same responsive layout.

## 2026-06-18 - Deploy Webhook Orchestrator Slice

Scope:

- Reworked both Deploy webhook controllers to delegate auth, ref resolution,
  health payloads, and release handoff to
  `DeployWebhookReleaseService`.
- Added `DeployWebhookReleaseService` so webhook requests call
  `DeployOrchestratorService::release()` instead of shell-only
  `dev/deploy/webhook.sh deploy`.
- Changed Deploy defaults to tag-only at stored-config fallback and file-config
  resolver levels. Legacy `webhook_allow_tag_deploy` remains supported.
- Passed webhook runtime branch, remote, update mode, backup, and post-deploy
  command config into the orchestrator.
- Updated Deploy backend guidance and docs to use the random `~wh~` webhook
  path produced by `deploy:webhook:setup --base-url`, not `/deploy`.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Deploy\Controller\Webhook.php
extend\server\php\php.exe -l app\code\Weline\Deploy\Controller\Api\Webhook.php
extend\server\php\php.exe -l app\code\Weline\Deploy\Service\DeployWebhookReleaseService.php
extend\server\php\php.exe -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
extend\server\php\php.exe -l app\code\Weline\Deploy\Service\DeployConfigService.php
extend\server\php\php.exe -l app\code\Weline\Deploy\Service\DeployWebhookRefResolver.php
extend\server\php\php.exe -l app\code\Weline\Deploy\Service\DeployGitMetadataService.php
extend\server\php\php.exe -l app\code\Weline\Deploy\view\templates\Backend\Config\index.phtml
```

Result:

- All linted files reported `No syntax errors detected`.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these were environment
  warnings and did not change lint exit status.

Logic validation:

```powershell
extend\server\php\php.exe -r "require 'app/code/Weline/Deploy/Service/DeployConfigService.php'; require 'app/code/Weline/Deploy/Service/DeployWebhookRefResolver.php'; `$r=new Weline\Deploy\Service\DeployWebhookRefResolver(); `$branch=`$r->resolve('refs/heads/main', []); `$tag=`$r->resolve('refs/tags/v1.2.3', []); if (!`$branch['skipped'] || `$branch['reason'] !== 'trigger_mode_tag_only') { fwrite(STDERR, json_encode(`$branch)); exit(1); } if (`$tag['skipped'] || `$tag['type'] !== 'tag' || `$tag['deploy_version_hint'] !== 'v1.2.3') { fwrite(STDERR, json_encode(`$tag)); exit(1); } echo 'tag-only resolver ok';"

@'
<?php
require 'app/code/Weline/Deploy/Service/DeployWebhookReleaseService.php';
$svc = (new ReflectionClass(Weline\Deploy\Service\DeployWebhookReleaseService::class))->newInstanceWithoutConstructor();
$body = '{"ref":"refs/tags/v1.2.3"}';
$secret = 's3';
$sig = 'sha256=' . hash_hmac('sha256', $body, $secret);
$checks = [
    'bearer' => $svc->isValidToken($secret, $body, '', '', 'Bearer s3', '', ''),
    'github_hmac' => $svc->isValidToken($secret, $body, '', '', '', '', $sig),
    'reject_bad_bearer' => !$svc->isValidToken($secret, $body, '', '', 'Bearer bad', '', ''),
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, $name . " failed\n");
        exit(1);
    }
}
echo "token validation ok\n";
'@ | extend\server\php\php.exe
```

Result:

- Resolver printed `tag-only resolver ok`.
- Token checks printed `token validation ok`.

Route and runtime validation:

```powershell
extend\server\php\php.exe bin\w reflection:compile
extend\server\php\php.exe bin\w setup:upgrade --stage=route_update -m Weline_Deploy --sync --skip-env-check
extend\server\php\php.exe bin\w server:start ai-test-wls-deploy-webhook-9814 -p 9814 --no-ssl -c 2
curl.exe -s -i http://p11005ce4.weline.test:9814/deploy/webhook/deploy?health=1
curl.exe -s -i -X POST http://p11005ce4.weline.test:9814/deploy/webhook/deploy -H "Content-Type: application/json" -H "Authorization: Bearer test" --data "{\"ref\":\"refs/heads/main\"}"
extend\server\php\php.exe bin\w server:stop ai-test-wls-deploy-webhook-9814
extend\server\php\php.exe bin\w server:status ai-test-wls-deploy-webhook-9814
```

Result:

- `reflection:compile` completed and regenerated reflection metadata plus
  compiled factories.
- The first route-update attempt without `--skip-env-check` failed at local
  environment auto-repair because this Windows PATH did not expose `php` and
  `chcp`. The rerun with `--skip-env-check` succeeded and logged
  `Weline_Deploy：更新路由完成`.
- WLS health request returned `HTTP/1.1 200 OK` and `{"ok":true}`.
- The POST with an intentionally wrong token returned
  `{"ok":false,"error":"invalid webhook token"}`, proving the runtime auth
  gate is active without exposing the real stored secret or triggering deploy.
- Cleanup confirmed instance `ai-test-wls-deploy-webhook-9814` stopped with
  status `0/0`.

Residual risk:

- A real tag POST with the production webhook secret was not executed in this
  development workspace to avoid checkout/reset/reload side effects. The
  tag-only decision path and token validation were covered by service-level
  checks; health and invalid-token paths were covered through WLS.

## 2026-06-18 - Panel Responsive And Theme Refinement

Scope:

- Hardened the independent WLS Panel fullscreen shell so the framework
  fullscreen wrapper does not create nested scroll or allow the sidebar to
  visually overlay desktop content.
- Explicitly placed the sidebar and main content into separate desktop grid
  columns, then reset them into a stacked mobile layout under `900px`.
- Added sticky desktop sidebar behavior, mobile reset behavior, button wrapping
  safeguards, and compact `520px` header-action layout.
- Improved theme switching by syncing the browser `color-scheme`, preserving
  local storage, and using the OS dark preference when the user has not saved a
  panel theme yet.

Static validation:

```powershell
extend\server\php\php.exe -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
```

Result:

- `php -l` reported `No syntax errors detected`.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these are environment
  warnings and did not change lint exit status.
- The forbidden blocking/dialog scan returned no matches.

Runtime and UI validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-ui-theme-9816 -p 9816 --no-ssl -c 2
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9816'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-ui-theme-9816'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- WLS instance `ai-test-wls-panel-ui-theme-9816` started on
  `http://p11005ce4.weline.test:9816` with two workers in Windows Dispatcher
  mode.
- `Weline_Server-panel-shell.spec.js` passed: `1 passed`.
- The run covered the desktop dashboard, theme toggle to dark, File Manager
  shell jump, Security page, mobile dashboard, mobile File Manager shell, and
  mobile dark Marketplace.
- Refreshed screenshot evidence:
  - `tests/e2e/artifacts/backend/Weline_Server/wls-panel-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

Visual check:

- Desktop screenshot shows the content column starting to the right of the WLS
  sidebar instead of underneath it.
- Mobile dark screenshot shows the panel navigation stacked above content,
  readable dark cards, wrapped header actions, and no horizontal overflow.

Tooling note:

- Codex in-app Browser control tools were not exposed in this turn's callable
  toolset, so final visible validation used the repository Playwright browser
  runner and screenshot artifacts.

## 2026-06-18 - Deploy Standalone WLS Plugin Shell

Scope:

- Added `Weline\Deploy\Controller\Backend\WlsDeploy` as the WLS Deploy plugin
  landing controller.
- Added `app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml`
  as an independent fullscreen shell with desktop/mobile responsive layout and
  light/dark theme persistence through `wls_panel_theme`.
- Updated `Weline_Deploy` marketplace meta so the WLS panel menu contribution
  and webhook/tag capability entries open `deploy/backend/wls-deploy`.
- The shell accepts safe WLS project context (`project_id`, `domain`,
  `operation`, `project_type`), shows current Deploy configuration summary,
  current runtime stamp, capability roadmap, and recent release records.
- Release history failures are caught and rendered as an in-panel warning so a
  missing/unhealthy history table cannot crash the WLS Deploy page.

Static validation:

```powershell
extend\server\php\php.exe -l app/code/Weline/Deploy/Controller/Backend/WlsDeploy.php
extend\server\php\php.exe -l app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml
Get-Content -Raw -Encoding UTF8 app/code/Weline/Deploy/etc/marketplace/meta.json | ConvertFrom-Json | Out-Null
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app/code/Weline/Deploy/Controller/Backend/WlsDeploy.php app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml
```

Result:

- PHP syntax checks passed for the controller and template.
- Deploy marketplace meta parsed as JSON.
- The blocking/dialog scan returned no matches.
- Translation coverage check found no missing keys in
  `app/code/Weline/Deploy/i18n/en_US.csv` or
  `app/code/Weline/Deploy/i18n/zh_Hans_CN.csv`.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these are environment
  warnings and did not change lint exit status.

Route validation:

```powershell
extend\server\php\php.exe bin\w setup:upgrade --route -m Weline_Deploy --skip-env-check --sync --skip-reflection-compile
rg -n "WlsDeploy|deploy/backend/wls-deploy" generated/classmap.php generated/routers/backend_pc.php
```

Result:

- Module route refresh succeeded and logged `Weline_Deploy 已更新！` plus
  `✓ 路由文件写入完成！`.
- Windows scheduled-task setup printed local `schtasks` permission/path
  warnings during setup, but route generation completed successfully.
- `generated/classmap.php` contains
  `Weline\Deploy\Controller\Backend\WlsDeploy`.
- `generated/routers/backend_pc.php` contains
  `deploy/backend/wls-deploy::GET`,
  `deploy/backend/wls-deploy/get-index::GET`, and
  `deploy/backend/wls-deploy/getindex::GET`.
- `php bin/w http:request deploy/backend/wls-deploy -b --filter=Fatal` could
  not be used as final validation because the backend route correctly requires
  a valid backend session.

Focused browser validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-deploy-panel-9818 -p 9818 --no-ssl -c 2
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9818'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-panel-9818'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
```

Result:

- Initial route smoke exposed a real page fatal from
  `DeployReleaseHistoryService::getRecent(12)` when release-history storage
  returned `false`; the WLS Deploy controller now catches that failure and
  renders a panel warning.
- WLS `server:reload ai-test-wls-deploy-panel-9818` returned exit code 0 but
  the rolling restart reported `Batch [1] not all READY within timeout`, so the
  instance was stopped and started cleanly before rerun.
- Focused Playwright validation then passed:
  - `[data-wls-deploy-shell]` rendered without WLS Runtime Error;
  - project context `e2e-deploy` and `e2e.deploy.test` rendered;
  - configuration summary and current runtime panels rendered;
  - primary Deploy Config action rendered;
  - desktop and mobile checks had no horizontal overflow;
  - dark theme persisted into the mobile viewport.

Screenshot artifacts:

- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-panel-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-panel-mobile-dark.png`

Full-panel E2E status:

```powershell
extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- The existing full WLS Panel shell spec was attempted after the Deploy shell
  passed.
- It failed in the FileManager route, not the Deploy route, while waiting for
  `[data-wfm-browser] .wfm-browser-current code`.
- Failure page reported WLS output capture protection:
  `WLS output capture exceeded safe memory limits; request output was discarded before it could crash the worker.`
- Runtime details in the failure page included
  `reason=memory_headroom`,
  `memory_usage=251751296`,
  `memory_real_usage=257949696`, and
  `memory_limit=268435456` for
  `/weline_filemanager/backend/wls-file-manager`.

Full-panel E2E rerun with panel test memory profile:

```powershell
extend\server\php\php.exe bin\w server:stop ai-test-wls-deploy-panel-9818
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-full-9819 -p 9819 --no-ssl -c 2 --worker-memory-limit=512M
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9819'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-full-9819'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- `ai-test-wls-deploy-panel-9818` stopped cleanly before the rerun.
- `ai-test-wls-panel-full-9819` started on
  `http://p11005ce4.weline.test:9819` with two workers and
  `--worker-memory-limit=512M`.
- `Weline_Server-panel-shell.spec.js` passed:
  `1 passed (18.3s)` / `E2E 测试执行成功。`
- This proves the FileManager failure was not a Deploy regression and was not a
  large-output template problem. It was the 256M worker memory profile reaching
  the WLS output-buffer memory-headroom guard during the full panel browser
  chain.
- Cleanup stopped both AI instances used in this slice:
  `ai-test-wls-panel-full-9819` and `ai-test-wls-deploy-panel-9818` both
  reported `状态：全部停止 (0/0)`.

Residual risk:

- The Deploy standalone plugin shell is validated with focused browser checks.
- The full WLS Panel shell spec passes when the AI panel test instance uses a
  512M worker memory profile.
- Panel-mode WLS instances should use at least 512M worker memory for full
  admin-panel E2E and plugin-heavy sessions. The FileManager
  route is still worth profiling later, but it did not need an emergency code
  change in this slice.

## 2026-06-18 - Deploy Project Profile Persistence Slice

Scope:

- Added `Weline\Deploy\Model\DeployProjectProfile` for project-scoped WLS
  Deploy settings keyed by stable `profile_key` values such as
  `project:<project_id>` or `domain:<domain>`.
- Added `Weline\Deploy\Service\DeployProjectProfileService` to load profile
  form data, save WLS panel input, normalize domain/project keys, and fall back
  to global Deploy config when no project profile exists.
- Extended `Weline\Deploy\Controller\Backend\WlsDeploy` with
  `postProfileSave()` and effective-summary profile application.
- Extended the standalone WLS Deploy shell with a `Project Profile` form for
  repository URL, branch, remote, deploy root, trigger mode, tag prefix,
  webhook branch filter, git update mode, Composer command, post-deploy
  command, rollback reference, and enablement toggles.
- Added focused browser coverage in
  `tests/e2e/specs/backend/Weline_Deploy-wls-deploy-profile.spec.js`.

Static and schema validation:

```powershell
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\Model\DeployProjectProfile.php
php -l app\code\Weline\Deploy\Service\DeployProjectProfileService.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node --check tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
php bin\w setup:upgrade --route -m Weline_Deploy --skip-env-check --skip-reflection-compile --sync
php bin\w setup:upgrade --model -m Weline_Deploy --skip-env-check --skip-reflection-compile --sync
```

Result:

- PHP syntax checks passed for controller, model, service, and template.
- Node syntax check passed for the focused Playwright spec.
- Route refresh completed and generated
  `deploy/backend/wls-deploy/profile-save::POST`.
- Model upgrade completed and created/updated the project profile model table.
- Setup output still includes duplicate PHP extension warnings and local
  Windows `schtasks`/`chcp` environment warnings, but both route and model
  upgrades completed successfully.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-panel-full-9819 -p 9819 -c 2 --no-ssl --worker-memory-limit=512M
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
```

Result:

- The focused spec passed:
  `1 passed (1.2m)` / `E2E 测试执行成功。`
- The spec uses direct WLS target navigation (`useProxy:false`) because the
  independent WLS plugin page is being validated against the WLS listener, not
  the Playwright proxy alias.
- Browser assertions covered:
  - standalone Deploy shell renders without fatal text;
  - WLS project context hidden values match `project_id`, `domain`, and
    generated `profile_key`;
  - Profile form saves and redirects with `deploy_notice=profile_saved`;
  - enabled project profile is reloaded as `项目 Profile`;
  - effective configuration summary reflects the saved repo and
    `pull_ff_only` git update mode;
  - dark theme toggle switches the shell to `data-theme="dark"`;
  - desktop and 390px mobile checks have no horizontal overflow.

Regression found and fixed during validation:

- The first project Profile implementation used `fetchRow()` in
  `DeployProjectProfileService::loadForContext()`. The row was persisted, but
  the service did not receive a `DeployProjectProfile` instance and therefore
  kept rendering the inherited global config state.
- The service now uses the framework-supported
  `select()->pagination(1, 1)->fetch()->getItems()` path, which also keeps the
  ORM chain aligned with the project rule that queries execute via
  `fetch()`/`fetchArray()`.
- A WLS worker reload after this change did not complete all READY checks on
  the test instance, so the instance was stopped and started cleanly before the
  passing E2E rerun.

Screenshot artifacts:

- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-mobile.png`

Residual risk:

- Project Profile persistence is now validated for the first WLS Deploy
  vertical slice.
- Remaining Deploy work: secret binding, command allowlists, project-scoped
  release log filtering, rollback action policy, and real webhook POST dry-run
  coverage.

## 2026-06-18 - Deploy Project Command Allowlist Slice

Scope:

- Added `Weline\Deploy\Service\DeployProjectCommandPolicyService` for
  project Profile command safety.
- Composer Profile commands are limited to `composer install` plus a small
  safe flag allowlist.
- Post-deploy Profile commands must be `php bin/w <allowed-command>` and may
  chain allowed maintenance commands with `&&`.
- Shell control characters, redirects, pipes, semicolons, quotes, command
  substitution, single ampersands, and arbitrary script paths are rejected
  before persistence.
- The WLS Deploy standalone Profile form now shows command allowlist examples
  and the blocked-command hint in the panel.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Service\DeployProjectCommandPolicyService.php
php -l app\code\Weline\Deploy\Service\DeployProjectProfileService.php
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Deploy\Service\DeployProjectCommandPolicyService.php app\code\Weline\Deploy\Service\DeployProjectProfileService.php app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
```

Result:

- PHP syntax checks passed for all four files.
- The forbidden blocking/dialog scan returned no matches.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these are environment
  warnings and did not change command exit status.

Service validation:

```powershell
@'
<?php
require __DIR__ . '/app/bootstrap.php';

use Weline\Deploy\Model\DeployProjectProfile;
use Weline\Deploy\Service\DeployProjectProfileService;
use Weline\Framework\Manager\ObjectManager;

$service = ObjectManager::getInstance(DeployProjectProfileService::class);
$safe = $service->saveFromPanel([
    'profile_key' => 'project:codex-command-policy-safe',
    'project_id' => 'codex-command-policy-safe',
    'domain' => 'codex-command-policy.test',
    'project_type' => 'wls',
    'enabled' => '1',
    'project_repo_url' => 'https://example.com/weline/demo.git',
    'project_branch' => 'master',
    'project_remote' => 'origin',
    'deploy_root' => '/tmp/weline-demo',
    'deploy_trigger_mode' => 'tag',
    'git_update_mode' => 'pull_ff_only',
    'backup_before_deploy' => '1',
    'run_composer_install' => '1',
    'composer_command' => 'composer install --no-dev --prefer-dist --no-interaction --no-progress',
    'post_deploy_command' => 'php bin/w setup:upgrade --route && php bin/w server:reload -r',
]);

$unsafe = $service->saveFromPanel([
    'profile_key' => 'project:codex-command-policy-safe',
    'project_id' => 'codex-command-policy-safe',
    'post_deploy_command' => 'php bin/w setup:upgrade --route; whoami',
]);

ObjectManager::getInstance(DeployProjectProfile::class)
    ->clearQuery()
    ->where(DeployProjectProfile::schema_fields_PROFILE_KEY, 'project:codex-command-policy-safe')
    ->delete()
    ->fetch();

echo json_encode([
    'safe_success' => (bool)($safe['success'] ?? false),
    'safe_post_command' => (string)($safe['profile'][DeployProjectProfile::schema_fields_POST_DEPLOY_COMMAND] ?? ''),
    'unsafe_success' => (bool)($unsafe['success'] ?? false),
    'unsafe_message' => (string)($unsafe['message'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
'@ | php
```

Result:

```json
{
  "safe_success": true,
  "safe_post_command": "php bin/w setup:upgrade --route && php bin/w server:reload -r",
  "unsafe_success": false,
  "unsafe_message": "命令包含不允许的 shell 控制字符。"
}
```

Browser validation:

```powershell
php bin\w server:start ai-test-wls-panel-full-9819 -p 9819 -c 2 --no-ssl --worker-memory-limit=512M
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9819'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-full-9819'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --project=chromium --headless
```

Result:

- Focused WLS Deploy Profile browser spec passed: `1 passed (6.1s)`.
- Full WLS Panel shell browser spec passed: `1 passed (18.1s)`.
- Both runs used the non-default WLS instance
  `ai-test-wls-panel-full-9819` on port `9819` with a 512M worker memory
  profile.

Cleanup:

```powershell
php bin\w server:stop ai-test-wls-panel-full-9819
php bin\w server:status ai-test-wls-panel-full-9819
Test-NetConnection 127.0.0.1 -Port 9819
```

Result:

- WLS status reported `全部停止 (0/0)`.
- `Test-NetConnection 127.0.0.1 -Port 9819` returned
  `TcpTestSucceeded: False`.

Residual risk:

- This slice protects the WLS Deploy panel save path. The release runner should
  reuse the same policy before executing Profile-derived commands so older
  persisted values and non-panel inputs receive the same enforcement.

## 2026-06-18 - Deploy Execution Command Policy Slice

Scope:

- Reused `DeployProjectCommandPolicyService` inside
  `DeployOrchestratorService::loadConfig()` after global and runtime release
  config are merged.
- Added `RUN_COMPOSER_INSTALL` and `COMPOSER_COMMAND` to
  `DeployConfigService::getProjectDeployConfig()` so global project deploy
  Composer fields are visible to execution-time validation.
- Validation now happens before backup, Git update, post-deploy command
  execution, version stamp writes, and server reload.
- This covers release flows that enter through CLI or webhook, including older
  persisted values that predate the panel save-time allowlist.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
php -l app\code\Weline\Deploy\Service\DeployConfigService.php
php -l app\code\Weline\Deploy\Service\DeployProjectCommandPolicyService.php
```

Result:

- PHP syntax checks passed for all three files.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these are environment
  warnings and did not change command exit status.

Execution policy smoke:

```powershell
@'
<?php
require __DIR__ . '/app/bootstrap.php';

use Weline\Deploy\Service\DeployOrchestratorService;
use Weline\Framework\Manager\ObjectManager;

$service = ObjectManager::getInstance(DeployOrchestratorService::class);
$method = new ReflectionMethod($service, 'loadConfig');
$method->setAccessible(true);

$safe = $method->invoke($service, [
    'COMPOSER_COMMAND' => 'composer install --no-dev --prefer-dist --no-interaction --no-progress',
    'POST_DEPLOY_COMMAND' => 'php bin/w setup:upgrade --route && php bin/w cache:clear',
]);

$unsafePost = null;
try {
    $method->invoke($service, [
        'POST_DEPLOY_COMMAND' => 'php bin/w setup:upgrade --route; whoami',
    ]);
} catch (Throwable $throwable) {
    $unsafePost = $throwable->getMessage();
}

$unsafeComposer = null;
try {
    $method->invoke($service, [
        'COMPOSER_COMMAND' => 'composer update',
    ]);
} catch (Throwable $throwable) {
    $unsafeComposer = $throwable->getMessage();
}

echo json_encode([
    'safe_composer' => (string)($safe['COMPOSER_COMMAND'] ?? ''),
    'safe_post' => (string)($safe['POST_DEPLOY_COMMAND'] ?? ''),
    'unsafe_post' => $unsafePost,
    'unsafe_composer' => $unsafeComposer,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
'@ | php
```

Result:

```json
{
  "safe_composer": "composer install --no-dev --prefer-dist --no-interaction --no-progress",
  "safe_post": "php bin/w setup:upgrade --route && php bin/w cache:clear",
  "unsafe_post": "命令包含不允许的 shell 控制字符。",
  "unsafe_composer": "Composer 命令只允许 composer install。"
}
```

Browser validation:

```powershell
php bin\w server:start ai-test-wls-panel-full-9819 -p 9819 -c 2 --no-ssl --worker-memory-limit=512M
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9819'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-full-9819'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --project=chromium --headless
```

Result:

- Focused WLS Deploy Profile browser spec passed: `1 passed (5.7s)`.
- Full WLS Panel shell browser spec passed: `1 passed (18.0s)`.
- Both runs used the non-default WLS instance
  `ai-test-wls-panel-full-9819` on port `9819` with a 512M worker memory
  profile.

Cleanup:

```powershell
php bin\w server:stop ai-test-wls-panel-full-9819
php bin\w server:status ai-test-wls-panel-full-9819
Test-NetConnection 127.0.0.1 -Port 9819
```

Result:

- WLS status reported `全部停止 (0/0)`.
- `Test-NetConnection 127.0.0.1 -Port 9819` returned
  `TcpTestSucceeded: False`.

Residual risk:

- The policy intentionally allows a narrow initial command set. Operators who
  need module-scoped flags such as `setup:upgrade --route -m Vendor_Module`
  will need a small follow-up extension to the token allowlist and argument
  parser instead of arbitrary shell execution.

## 2026-06-18 - Deploy Project-Scoped Release History Slice

Scope:

- Added project context fields to `Weline\Deploy\Model\DeployRelease`:
  `profile_key`, `project_id`, `domain`, and `project_type`.
- Added release-history indexes for `profile_key`, `project_id`, and `domain`.
- Extended `DeployReleaseHistoryService::start()` to persist project context.
- Added `DeployReleaseHistoryService::getRecentForContext()` for WLS Panel
  project/domain filtering while keeping `getRecent()` as an all-scope legacy
  list for CLI and ordinary backend release history.
- Changed `is_current` cleanup to run only within the same release
  `profile_key`, so child projects do not clear each other's current marker.
- Extended `DeployOrchestratorService::release()` to read context from explicit
  params, nested `context`, or runtime config keys (`PROFILE_KEY`, `PROJECT_ID`,
  `DOMAIN`, `PROJECT_TYPE`).
- Updated the standalone WLS Deploy shell to show Current Project/Domain/Global
  release scopes and scoped empty states.
- Updated ordinary Deploy release history with a `Scope` column.
- Updated prototype and atomic Stage 5 plan docs for project-scoped release
  history behavior.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Model\DeployRelease.php
php -l app\code\Weline\Deploy\Service\DeployReleaseHistoryService.php
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
php -l app\code\Weline\Deploy\view\templates\Backend\Release\index.phtml
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Deploy\Model\DeployRelease.php app\code\Weline\Deploy\Service\DeployReleaseHistoryService.php app\code\Weline\Deploy\Service\DeployOrchestratorService.php app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml app\code\Weline\Deploy\view\templates\Backend\Release\index.phtml
git diff --check -- app\code\Weline\Deploy\Model\DeployRelease.php app\code\Weline\Deploy\Service\DeployReleaseHistoryService.php app\code\Weline\Deploy\Service\DeployOrchestratorService.php app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml app\code\Weline\Deploy\view\templates\Backend\Release\index.phtml app\code\Weline\Deploy\i18n\zh_Hans_CN.csv app\code\Weline\Deploy\i18n\en_US.csv app\code\Weline\Server\doc\wls-panel-plan\30-atomic-task-plan.md app\code\Weline\Server\doc\wls-panel-plan\10-prototype.md
```

Result:

- PHP syntax checks passed for all six changed PHP/template files.
- The disallowed WLS/frontend function scan returned no matches.
- `git diff --check` passed with line-ending warnings only.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these are environment
  warnings and did not change command exit status.

Schema validation note:

```powershell
php bin\w setup:upgrade --model -m Weline_Deploy --skip-env-check --skip-reflection-compile --sync
php bin\w setup:upgrade --stage=schema_diff,database_update -m Weline_Deploy --skip-env-check --skip-reflection-compile --skip-classmap --sync
```

Result:

- The first command timed out after 124 seconds.
- The second command timed out after 184 seconds.
- Both attempts spawned `composer dump-autoload` children and did not return.
  Only the setup-related PHP PIDs from those attempts were stopped.
- For local verification only, the validation database was prepared with
  idempotent PostgreSQL `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` and
  `CREATE INDEX IF NOT EXISTS` statements matching the model declarations.
- Schema probe confirmed columns
  `domain`, `profile_key`, `project_id`, `project_type` and indexes
  `idx_wls_release_domain`, `idx_wls_release_profile_key`,
  `idx_wls_release_project`.

Service smoke:

```json
{
  "project_a_count": 2,
  "project_b_count": 1,
  "project_a_new_profile": "project:codex-release-a",
  "project_a_old_current": 0,
  "project_a_new_current": 1,
  "project_b_current": 1,
  "global_contains_scoped": false,
  "all_contains_scoped_count": 3
}
```

Result:

- Project A scoped query returned only Project A records.
- Project B scoped query returned only Project B records.
- Empty-context WLS Deploy history did not mix scoped records into the global
  host view.
- Legacy all-scope `getRecent()` still included scoped records for operations
  history.
- A newer Project A success cleared only the older Project A `is_current`
  marker; Project B remained current.
- Cleanup verified `codex-scope-*` test records remaining: `0`.

Browser validation:

```powershell
php bin\w server:start ai-test-wls-panel-full-9819 -p 9819 -c 2 --no-ssl --worker-memory-limit=512M
$env:PLAYWRIGHT_TARGET_ORIGIN='http://p11005ce4.weline.test:9819'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-full-9819'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --project=chromium --headless
```

Result:

- Focused WLS Deploy Profile browser spec passed: `1 passed (16.6s)`.
- Full WLS Panel shell browser spec passed: `1 passed (31.2s)`.
- Both runs used the non-default WLS instance
  `ai-test-wls-panel-full-9819` on port `9819` with a 512M worker memory
  profile.

Cleanup:

```powershell
php bin\w server:stop ai-test-wls-panel-full-9819
php bin\w server:status ai-test-wls-panel-full-9819
Test-NetConnection 127.0.0.1 -Port 9819
```

Result:

- WLS status reported `全部停止 (0/0)`.
- `Test-NetConnection 127.0.0.1 -Port 9819` returned
  `TcpTestSucceeded: False`.

Residual risk:

- The local `setup:upgrade` timeout is now a separate tooling/runtime issue.
  The production schema path for this slice remains the declared Model
  attributes; the direct SQL was used only to let the current validation
  database match those declarations.

## 2026-06-18 - Setup Fast Path For WLS Plugin Refresh

Scope:

- Added a WLS Panel-safe setup fast path for plugin install/update refreshes
  where Composer dependencies and autoload configuration did not change.
- `setup:upgrade --skip-classmap` now skips the local Composer
  `dump-autoload` step in addition to generated classmap cache work.
- Added the explicit `setup:upgrade --skip-composer-dump` option for cases
  where Composer is unavailable but setup stages still need to run.
- `setup:background-optimize --skip-classmap` now honors the forwarded flag and
  skips classmap generation instead of regenerating it in the background.

Impact scan:

```powershell
npx gitnexus impact "Weline\\Framework\\Setup\\Console\\Setup\\Upgrade::prepareUpgrade" --direction upstream
npx gitnexus impact "Weline\\Framework\\Setup\\Console\\Setup\\Upgrade::runComposerDump" --direction upstream
rg -n "prepareUpgrade\\(|runComposerDump\\(" app\code dev tests -S --glob "*.php"
```

Result:

- GitNexus was attempted before editing. One `npx` run exited with npm
  cleanup `EPERM` noise and no useful impact output; another timed out after
  49 seconds.
- `rg` fallback showed both affected methods are private to
  `Weline\Framework\Setup\Console\Setup\Upgrade`; the blast radius is the
  `setup:upgrade` command path.

Composer reproduction:

```powershell
extend\server\php\php.exe -c extend\server\php\php.installer.ini extend\server\composer.phar dump-autoload -vvv
```

Result:

- The direct Composer child process timed out after 74 seconds with no useful
  output.
- The matching `composer.phar` PHP process was explicitly stopped by PID.

Static validation:

```powershell
php -l app\code\Weline\Framework\Setup\Console\Setup\Upgrade.php
php -l app\code\Weline\Framework\Setup\Console\Setup\BackgroundOptimize.php
```

Result:

- Both files reported `No syntax errors detected`.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these are environment
  warnings and did not change command exit status.

Setup validation:

```powershell
php bin\w setup:upgrade --stage=schema_diff,database_update -m Weline_Deploy --skip-env-check --skip-reflection-compile --skip-classmap --sync
```

Result:

- Exit code: `0`.
- Output included `已跳过 composer dump-autoload（快速更新模式）`.
- Synchronous optimization also printed
  `- 已跳过类映射缓存（--skip-classmap）`.
- A process check after validation found no remaining `composer.phar`,
  `setup:upgrade`, or `setup:background` process.

Residual risk:

- The underlying Composer hang is still a separate local toolchain issue; the
  WLS Panel refresh path can avoid it when Composer metadata did not change.
- The validated setup run still took 141 seconds after Composer was skipped.
  The long tail came from later `setup:upgrade` stages and `upgrade_after`
  observers such as cron/i18n/module collection. WLS Panel plugin install/update
  needs a follow-up narrower registry refresh or after-observer profiling task
  to feel instant.

## 2026-06-18 - Native WLS Panel Plugin Refresh Reload

Scope:

- Added `WlsPanelPluginRefreshService` as the native POST handler service behind
  the `Refresh Panel Plugins` button.
- The Controller now delegates to the refresh service instead of calling
  discovery directly.
- The refresh service reloads the module list, runs
  `RegistryUpdateService::updateAllRegistries(true, false, true)`, discovers
  installed `module:wls` plugins, route-refreshes only those plugin modules, and
  then reruns plugin capability discovery.
- The visible success notice remains the existing
  `Panel plugin capabilities refreshed.` text for E2E compatibility; the backend
  behavior now refreshes registries and routes as well.

Impact scan:

```powershell
npx gitnexus impact "Weline\\Server\\Controller\\Backend\\WlsPanel::postPluginRefresh" --direction upstream
npx gitnexus impact "Weline\\Server\\Service\\WlsPanelPluginDiscoveryService::refreshCapabilities" --direction upstream
rg -n "postPluginRefresh\\(|refreshCapabilities\\(|WlsPanelPluginDiscoveryService" app\code tests -S --glob "*.php" --glob "*.phtml" --glob "*.js"
```

Result:

- GitNexus was attempted before editing. The first `npx` run exited with npm
  cleanup `EPERM` noise; the second exited without useful impact output.
- `rg` fallback showed `postPluginRefresh()` is only reached by the WLS Panel
  refresh form, and `refreshCapabilities()` is only used inside the WLS Panel
  controller/service path.

Static validation:

```powershell
php -l app\code\Weline\Server\Service\WlsPanelPluginRefreshService.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Server\Service\WlsPanelPluginRefreshService.php app\code\Weline\Server\Controller\Backend\WlsPanel.php app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/Server/Service/WlsPanelPluginRefreshService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/20-plugin-tag-logic.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md dev/ai/codex/tasks/2026-06-18/2026-06-18-0151-setup-upgrade-composer-timeout-for-wls-panel/plan.md dev/ai/codex/tasks/2026-06-18/2026-06-18-0151-setup-upgrade-composer-timeout-for-wls-panel/progress.md dev/ai/codex/tasks/2026-06-18/2026-06-18-0151-setup-upgrade-composer-timeout-for-wls-panel/result.md
```

Result:

- Both files reported `No syntax errors detected`.
- The local PHP runtime still emits duplicate-extension warnings and
  `Cannot load Zend OPcache - it was already loaded`; these are environment
  warnings.
- `node --check` passed.
- The disallowed frontend/runtime-function scan returned no matches in the
  targeted files.
- `git diff --check` passed for the targeted files.

Service validation:

```powershell
@'
<?php
require getcwd() . '/app/bootstrap.php';
$start = microtime(true);
$result = \Weline\Framework\Manager\ObjectManager::getInstance(
    \Weline\Server\Service\WlsPanelPluginRefreshService::class
)->refreshPanelCapabilities();
echo json_encode([
    'success' => $result['success'] ?? null,
    'error' => $result['error'] ?? '',
    'registry_refreshed' => $result['registry_refreshed'] ?? null,
    'routes_refreshed' => $result['routes_refreshed'] ?? null,
    'route_modules' => $result['route_modules'] ?? [],
    'plugin_count' => $result['plugin_count'] ?? null,
    'contribution_count' => $result['contribution_count'] ?? null,
    'elapsed_seconds' => round(microtime(true) - $start, 3),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
'@ | php
```

Result:

```json
{
    "success": true,
    "error": "",
    "registry_refreshed": true,
    "routes_refreshed": true,
    "route_modules": [
        "Weline_Deploy",
        "Weline_FileManager"
    ],
    "plugin_count": 2,
    "contribution_count": 2,
    "elapsed_seconds": 4.01
}
```

Residual risk:

- This synchronous refresh is acceptable in the current checkout at about four
  seconds. If a production instance grows many WLS plugins, the same service can
  be moved behind a background job and polled by the existing panel POST entry.

E2E validation:

```powershell
$env:PLAYWRIGHT_HEADLESS='1'
$env:PLAYWRIGHT_TEST_TIMEOUT='300000'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- The first run did not reach the test body. Playwright config selected
  `http://127.0.0.1:9819` as target origin and timed out after 180 seconds while
  waiting for proxy/webServer readiness.

Dedicated direct WLS validation:

```powershell
php bin\w server:start ai-test-wls-panel-refresh-9826 -p 9826 --no-ssl -r
Test-NetConnection 127.0.0.1 -Port 9826
$env:PLAYWRIGHT_HEADLESS='1'
$env:PLAYWRIGHT_TEST_TIMEOUT='300000'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-refresh-9826'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
php bin\w server:stop ai-test-wls-panel-refresh-9826
Test-NetConnection 127.0.0.1 -Port 9826
```

Result:

- Dedicated instance started on non-default port `9826`.
- Direct Playwright run passed: `1 passed`.
- The covered spec includes the standalone WLS Panel shell, responsive layout,
  dark/light theme state, marketplace plugin refresh click, WLS File Manager
  plugin entry/deep link, native Security page, and no-horizontal-overflow
  assertions.
- Cleanup passed: `server:stop` stopped
  `ai-test-wls-panel-refresh-9826`; port `9826` returned
  `TcpTestSucceeded: False`, and process scan found no matching WLS test
  instance.

## 2026-06-18 - AppStore Return Context For WLS Panel

Scope:

- WLS Panel marketplace links to AppStore online and installed plugin pages now
  include `wls_panel_return=1` together with `tag=module:wls` and
  `surface=backend`.
- AppStore index, authorize-install, and installed-module pages preserve
  `wls_panel_return=1` through filter forms, download/install forms, check
  update forms, and update forms.
- AppStore shows a `Back To WLS Panel` action while it is opened from the WLS
  Panel context.
- Successful AppStore install/update with `wls_panel_return=1` redirects to
  `server/backend/wls-panel/marketplace?panel_notice=plugins_refreshed&panel_auto_refresh=plugins#installed-plugins`.
- The returned WLS Panel page exposes `data-wls-auto-refresh="plugins"` and
  auto-submits the existing `plugin-refresh` POST form once. The POST redirect
  clears `panel_auto_refresh`, preventing refresh loops.
- This keeps WLS Panel visually independent from the ordinary project backend
  while leaving AppStore as the package verification and installation owner.

Static validation:

```powershell
php -l app\code\Weline\AppStore\Controller\Backend\Index.php
php -l app\code\Weline\AppStore\Controller\Backend\Installed.php
php -l app\code\Weline\AppStore\view\templates\Backend\Index\index.phtml
php -l app\code\Weline\AppStore\view\templates\Backend\Index\authorize-install.phtml
php -l app\code\Weline\AppStore\view\templates\Backend\Installed\index.phtml
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Server\Controller\Backend\WlsPanel.php app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml app\code\Weline\AppStore\Controller\Backend\Index.php app\code\Weline\AppStore\Controller\Backend\Installed.php tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/AppStore/Controller/Backend/Index.php app/code/Weline/AppStore/Controller/Backend/Installed.php app/code/Weline/AppStore/view/templates/Backend/Index/index.phtml app/code/Weline/AppStore/view/templates/Backend/Index/authorize-install.phtml app/code/Weline/AppStore/view/templates/Backend/Installed/index.phtml app/code/Weline/AppStore/i18n/en_US.csv app/code/Weline/AppStore/i18n/zh_Hans_CN.csv app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the two AppStore controllers, three AppStore
  templates, the WLS Panel controller, and the WLS Panel template.
- `node --check` passed for the WLS Panel E2E spec.
- The disallowed WLS/frontend function scan returned no matches.
- `git diff --check` passed with line-ending warnings only.

Browser validation:

```powershell
php bin\w server:start ai-test-wls-panel-autorefresh-9826 -p 9826 --no-ssl -r
$env:PLAYWRIGHT_HEADLESS='1'
$env:PLAYWRIGHT_TEST_TIMEOUT='300000'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-autorefresh-9826'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
php bin\w server:stop ai-test-wls-panel-autorefresh-9826
Test-NetConnection 127.0.0.1 -Port 9826
```

Result:

- Direct Playwright validation passed: `1 passed`.
- The spec now asserts that WLS Panel marketplace links to both
  `appstore/backend` and `appstore/backend/installed` include
  `wls_panel_return=1`.
- The spec also opens
  `server/backend/wls-panel/marketplace?panel_auto_refresh=plugins`, waits for
  the existing POST refresh flow to return a success notice, asserts
  `panel_auto_refresh` is no longer present in the URL, and then verifies the
  manual refresh button still works.
- Cleanup passed: `server:stop` stopped
  `ai-test-wls-panel-autorefresh-9826`; port `9826` returned
  `TcpTestSucceeded: False`, and process scan found no matching WLS test
  instance beyond the current PowerShell check process.

## 2026-06-18 - AppStore Page-Level WLS Return Context E2E

Scope:

- Added `tests/e2e/specs/backend/Weline_AppStore-wls-panel-return.spec.js`.
- Corrected WLS Panel and AppStore self links to use the generated AppStore
  index route `appstore/backend`; action routes continue to use
  `appstore/backend/index/download`, `appstore/backend/index/install`, and
  `appstore/backend/index/authorize-install`.
- The new browser spec opens AppStore online plugins, installed modules, and
  authorize-install pages with `wls_panel_return=1`.
- The spec asserts visible `Back To WLS Panel` actions, hidden return-context
  fields on relevant forms, and preserved `tag=module:wls` plus
  `surface=backend` filters.

Route refresh:

```powershell
php bin\w setup:upgrade --route -m Weline_AppStore --skip-env-check --skip-composer-dump --skip-classmap --skip-reflection-compile --skip-background-optimize --sync
rg -n -i "appstore|Weline_AppStore" generated\routers\backend_pc.php
```

Result:

- Route stage logged `Weline_AppStore：更新路由完成`, `路由文件写入完成`, and
  `系统升级完成`.
- The command still returned exit code `1` because local PHP emits
  `Cannot load Zend OPcache - it was already loaded` and Windows `schtasks`
  is unavailable during after-observer cron installation, but AppStore routes
  were generated successfully.
- `generated\routers\backend_pc.php` contains `appstore/backend`,
  `appstore/backend/installed`, and
  `appstore/backend/index/authorize-install`.

Static validation:

```powershell
php -l app\code\Weline\AppStore\Controller\Backend\Index.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_AppStore-wls-panel-return.spec.js
```

Result:

- PHP syntax checks passed for the AppStore controller and WLS Panel template.
- `node --check` passed for the new AppStore WLS return-context spec.

Browser validation:

```powershell
php bin\w server:start ai-test-wls-appstore-return-9827 -p 9827 --no-ssl -r
$env:PLAYWRIGHT_HEADLESS='1'
$env:PLAYWRIGHT_TEST_TIMEOUT='300000'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-appstore-return-9827'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_AppStore-wls-panel-return.spec.js --headless --project=chromium
```

Result:

- Direct HTTP probe to `http://127.0.0.1:9827/` returned `200` after a clean
  restart.
- Direct Playwright validation passed: `3 passed`.
- An earlier run hit the WLS maintenance page after route refresh because the
  test instance runtime state was inconsistent; `server:stop` followed by a
  clean `server:start` restored the instance.

## 2026-06-18 - Native Project Config Center

Scope:

- Added `WlsPanelProjectConfigCenterService` as a read-only dashboard
  aggregation service.
- The independent WLS Panel now renders a Project Config Center above managed
  project cards.
- Each project row aggregates Project Admin, Child Panel, Security, Gateway,
  PHP Config, Database Config, Files, and Deploy links.
- The center reuses the same operation slot keys as the Operations Capability
  Center and the same project list as the project cards.
- Operation URLs still pass only safe context: `operation`, `project_id`,
  `domain`, and `project_type`. Raw local `project_path` is not copied into
  query strings.
- The WLS Panel shell E2E assertion was updated to the canonical AppStore index
  route `appstore/backend` instead of the old `appstore/backend/index` list
  route.

Impact scan:

```powershell
gitnexus impact Weline\\Server\\Controller\\Backend\\WlsPanel --repo dev-workspace --direction upstream --depth 2
gitnexus impact Weline\\Server\\Service\\WlsPanelPluginDiscoveryService --repo dev-workspace --direction upstream --depth 2
```

Result:

- The global `gitnexus` command ran, but both targets were not found in the
  current index because the WLS Panel controller and service slices are still
  untracked in this checkout.
- Blast radius was kept low by adding a new read-only aggregation service and
  not changing routes, models, or persistence contracts.

Static validation:

```powershell
php -l app\code\Weline\Server\Service\WlsPanelProjectConfigCenterService.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app\code\Weline\Server\Service\WlsPanelProjectConfigCenterService.php app\code\Weline\Server\Controller\Backend\WlsPanel.php app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js app\code\Weline\Server\i18n\en_US.csv app\code\Weline\Server\i18n\zh_Hans_CN.csv
```

Result:

- PHP syntax checks passed for the new service, WLS Panel controller, and WLS
  Panel template.
- `node --check` passed for the WLS Panel E2E spec.
- `git diff --check` passed with i18n line-ending warnings only.
- Local PHP still prints duplicate extension and OPcache warnings; these did
  not change command exit status.

HTTP smoke:

```powershell
php bin\w server:start ai-test-wls-panel-config-9828 -p 9828 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri http://127.0.0.1:9828/ -UseBasicParsing -TimeoutSec 15
```

Result:

- Dedicated non-default WLS instance `ai-test-wls-panel-config-9828` started on
  port `9828`.
- Direct HTTP probe to `http://127.0.0.1:9828/` returned `200`.

Browser validation:

```powershell
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-config-9828'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- First run reached the marketplace section and failed only because the spec
  still expected the old `appstore/backend/index` listing URL.
- After updating the assertion to `appstore/backend`, the full direct
  Playwright run passed: `1 passed`.
- The spec now checks `[data-wls-project-config-center]`, at least one
  `[data-wls-project-config-card]`, four project operation links, Security and
  Gateway actions, desktop/mobile no-horizontal-overflow, dark-theme
  persistence, File Manager shell navigation, Security page controls, AppStore
  return context, and plugin refresh.

Cleanup:

```powershell
php bin\w server:stop ai-test-wls-panel-config-9828
Test-NetConnection -ComputerName 127.0.0.1 -Port 9828
```

Result:

- `server:stop` stopped `ai-test-wls-panel-config-9828`.
- Port `9828` returned `TcpTestSucceeded: False`.

## 2026-06-18 - PlatformAppStore Typed Tag Server Contract

Scope:

- Confirmed the online marketplace source for WLS plugin filtering is
  `E:\WelineFramework\Framework-Official\App\weline\app\code\Weline\PlatformAppStore`.
- Updated the PlatformAppStore marketplace meta protocol and implementation
  docs so `type:value` is the primary tag format.
- WLS plugin discoverability remains metadata-only: modules declare
  `module:wls` in `etc/marketplace/meta.json`; no WLS-specific PHP inheritance
  contract is required for marketplace discovery.
- `custom:*`, `category:*`, `feature:*`, `capability:*`, `surface:*`, and
  `system:*` stay ordinary typed tags used by marketplace filters and panel
  capability matching.
- `POST /api/v1/platform/module/list` accepts `tag`, `tags`, and `tag_match`.
- `ModuleCatalogService::normalizeTypedTagFilter()` accepts typed strings,
  arrays, JSON arrays, and structured tag objects.
- `ModuleCatalogService::moduleHasTypedTags()` uses exact normalized matching,
  so `module:wls-extra` does not match `module:wls`.

Static validation:

```powershell
php -l app\code\Weline\PlatformAppStore\Service\ModuleCatalogService.php
php -l app\code\Weline\PlatformAppStore\Controller\Api\V1\Platform\Module.php
php -l app\code\Weline\PlatformAppStore\test\Unit\Service\ModuleCatalogServiceTagFilterTest.php
```

Result:

- All three files reported `No syntax errors detected`.
- Local PHP still prints duplicate extension and OPcache warnings; these are
  environment warnings and did not change syntax-check exit status.

Focused PHPUnit validation:

```powershell
$env:XDEBUG_MODE='off'
php extend\phpunit.phar --no-coverage app\code\Weline\PlatformAppStore\test\Unit\Service\ModuleCatalogServiceTagFilterTest.php
```

Result:

- `OK (3 tests, 6 assertions)`.
- Covered normalization of uppercase/string/structured tags, exact
  `module:wls` matching, `module:wls-extra` non-match behavior, and
  `tag_match=any`.

## 2026-06-18 - FileManager Preview and Restricted Download Slice

Scope:

- `Weline_FileManager` WLS plugin moved from read-only directory browsing to a
  fuller read-only file-management sample for the panel plugin architecture.
- The standalone FileManager page now exposes inline text preview for common
  text/config/code files, capped at 64 KB per preview.
- The download endpoint is routed through
  `weline_filemanager/backend/wls-file-manager/download` and only resolves
  readable files inside the selected allowlisted root. Downloads are capped at
  20 MB and use framework response headers so WLS can emit the response without
  raw `header()`/`readfile()` behavior.
- Upload, rename, delete, compression, and other writes remain intentionally
  disabled until the path allowlist, ACL, confirmation UI, and operation-log
  slices are implemented.

Static validation:

```powershell
php -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "wls-file-manager/download::GET" generated\routers\backend_pc.php
```

Result:

- PHP reported `No syntax errors detected` for the FileManager controller and
  template.
- Node syntax check for the WLS panel shell E2E spec exited 0.
- Generated backend routes include
  `weline_filemanager/backend/wls-file-manager/download::GET`.
- Local PHP still prints duplicate extension and OPcache warnings; these are
  environment warnings and did not change the validation exit status.

Route refresh:

```powershell
php bin\w setup:upgrade --route --module Weline_FileManager --skip-env-check --skip-reflection-compile --skip-classmap --skip-background-optimize
```

Result:

- Command exited 0 and reported `Weline_FileManager` route update completed.
- The local environment still reports `chcp`/`schtasks` PATH or permission
  warnings during cron maintenance; route write completed before those warnings.

Runtime and browser validation:

```powershell
php bin\w server:start ai-test-wls-file-preview-9832 -p 9832 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri http://127.0.0.1:9832/ -UseBasicParsing -TimeoutSec 20
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-file-preview-9832'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- Dedicated non-default WLS instance
  `ai-test-wls-file-preview-9832` started on port `9832` in dispatcher mode.
- Direct HTTP probe returned `200`.
- Final Playwright run passed: `1 passed`.
- The E2E now verifies the `README.md` row in
  `app/code/Weline/FileManager/doc`, checks visible preview and download
  actions, fetches the download URL inside the logged-in browser context,
  asserts HTTP `200`, asserts the `Content-Disposition` filename contains
  `README.md`, and asserts the downloaded body contains
  `WLS Panel Integration`.
- The same run still covers WLS standalone shell, dark/light theme persistence,
  responsive no-horizontal-overflow checks, project config center links,
  security rule UI, marketplace return context, plugin refresh, and FileManager
  desktop/mobile screenshots.

Artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-mobile.png`

UI review notes:

- Desktop dark theme keeps FileManager aligned with the standalone WLS Panel
  visual language.
- Mobile layout collapses the sidebar to a top navigation grid and the E2E
  overflow guard passed.
- Preview/download actions use compact row actions so the directory browser
  remains scan-friendly.

## 2026-06-18 - Deploy Release Preflight Slice

Scope:

- Added a read-only WLS Deploy release preflight via
  `DeployProjectProfileService::buildPanelPreflight()`.
- The preflight checks Profile source, repository URL shape, deploy-root text
  safety, tag/branch trigger mode, Webhook path/secret state, and Composer /
  post-deploy command allowlist.
- `deploy/backend/wls-deploy` now assigns `wlsDeployPreflight` and renders a
  standalone "Release Preflight" section in the WLS Deploy shell.
- The preflight is intentionally non-executing: it does not run Git, create
  directories, write version stamps, execute post-deploy commands, or reload
  WLS.
- The Deploy E2E now asserts the preflight exists and that saved project
  Profile values make `profile`, `repo`, `trigger`, and `commands` pass.
- Added dedicated desktop and mobile preflight screenshots for UI review.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Service\DeployProjectProfileService.php
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node --check tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Deploy\Service\DeployProjectProfileService.php app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
git diff --check -- app\code\Weline\Deploy\Service\DeployProjectProfileService.php app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml app\code\Weline\Deploy\i18n\zh_Hans_CN.csv app\code\Weline\Deploy\i18n\en_US.csv app\code\Weline\Deploy\doc\README.md app\code\Weline\Deploy\doc\backend-config.md app\code\Weline\Server\doc\wls-panel-plan\10-prototype.md app\code\Weline\Server\doc\wls-panel-plan\30-atomic-task-plan.md tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
```

Result:

- PHP reported `No syntax errors detected` for the Deploy Profile service,
  WLS Deploy backend controller, and WLS Deploy template.
- Node syntax check for the focused Deploy E2E spec exited 0.
- Forbidden runtime / browser calls scan returned no matches.
- `git diff --check` exited 0; Git only reported existing LF-to-CRLF working
  copy warnings for edited docs/i18n files.
- No route refresh was required in this slice because no controller route was
  added or renamed.

Runtime and browser validation:

```powershell
php bin\w server:start ai-test-wls-deploy-preflight-9834 -p 9834 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri http://127.0.0.1:9834/ -UseBasicParsing -TimeoutSec 20
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-preflight-9834'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-preflight-9834
Test-NetConnection -ComputerName 127.0.0.1 -Port 9834
php bin\w server:status ai-test-wls-deploy-preflight-9834
```

Result:

- Dedicated non-default WLS instance
  `ai-test-wls-deploy-preflight-9834` started on port `9834` in Dispatcher
  mode.
- Direct HTTP probe returned `200`.
- Focused WLS Deploy Profile browser spec passed: `1 passed (20.4s)`.
- The spec covered desktop layout, project Profile save/reload, preflight
  status attributes, dark theme switch, mobile layout, and no-horizontal-
  overflow guards.
- `server:stop` stopped the instance, `Test-NetConnection` returned
  `TcpTestSucceeded: False`, and `server:status` reported `全部停止 (0/0)`.

Artifacts:

- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-preflight-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-preflight-mobile.png`
- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-mobile.png`

UI review notes:

- Desktop preflight presents six checks in a compact three-column grid below
  the Project Profile form and above the configuration/runtime panels.
- Mobile dark theme collapses the checks into a readable one-column list with
  clear state pills and no horizontal overflow.
- The preflight status uses the same visual language as the rest of the WLS
  standalone plugin shell, so it reads as part of the panel instead of an
  ordinary backend page.

## 2026-06-18 - Deploy Explicit Preflight Button Slice

Scope:

- Added `WlsDeploy::postPreflightRun()` as a button-backed dry-run checkpoint
  for the WLS Deploy shell.
- The action reloads the same selected project context, recomputes the
  read-only preflight, redirects back to `#preflight`, and returns only a
  success notice or blocker message.
- The action does not call the release orchestrator and does not run Git, write
  files, execute post-deploy commands, or reload WLS.
- The preflight panel header now shows both the status pill and an explicit
  `Run Preflight` button.
- The focused Deploy E2E clicks the button and asserts
  `deploy_notice=preflight_checked`.

Static and route validation:

```powershell
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node --check tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\Service\DeployProjectProfileService.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
php bin\w setup:upgrade --route --module Weline_Deploy --skip-env-check --skip-reflection-compile --skip-classmap --skip-background-optimize
rg -n "wls-deploy/preflight-run|preflight-run" generated\routers\backend_pc.php
```

Result:

- PHP and Node syntax checks passed.
- Forbidden runtime / browser calls scan returned no matches.
- Route refresh exited 0 and wrote the Weline_Deploy route update.
- Generated backend routes include:
  `deploy/backend/wls-deploy/preflight-run::POST` and
  `deploy/backend/wls-deploy/post-preflight-run::POST`.
- Local `chcp` / `schtasks` PATH or permission warnings still appeared during
  setup maintenance; they did not stop route generation.

Runtime and browser validation:

```powershell
php bin\w server:start ai-test-wls-deploy-preflight-button-9835 -p 9835 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri http://127.0.0.1:9835/ -UseBasicParsing -TimeoutSec 20
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-preflight-button-9835'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-preflight-button-9835
Test-NetConnection -ComputerName 127.0.0.1 -Port 9835
php bin\w server:status ai-test-wls-deploy-preflight-button-9835
```

Result:

- Dedicated non-default WLS instance
  `ai-test-wls-deploy-preflight-button-9835` started on port `9835` in
  Dispatcher mode.
- Direct HTTP probe returned `200`.
- Focused WLS Deploy Profile browser spec passed: `1 passed (21.2s)`.
- The spec caught and fixed the duplicated hidden-input selector introduced by
  the second form, then verified the scoped project Profile form and the
  explicit preflight button path.
- `server:stop` stopped the instance, `Test-NetConnection` returned
  `TcpTestSucceeded: False`, and `server:status` reported `全部停止 (0/0)`.

UI review notes:

- Desktop keeps the Run Preflight button in the preflight panel header beside
  the status pill without crowding the check grid.
- Mobile dark theme stacks the status pill and button cleanly above the
  one-column check list.

## 2026-06-18 - Deploy Webhook Replay Dry-Run Slice

Scope:

- Added `WlsDeploy::postWebhookReplay()` as a safe WLS Deploy panel checkpoint.
- The action accepts a webhook `payload.ref` such as `refs/tags/v1.0.0` or
  `refs/heads/main`, reloads the selected WLS project context, applies the
  enabled project Profile over the global Deploy settings, and resolves the ref
  with `DeployWebhookRefResolver::resolve()`.
- The replay renders `ready` or `skipped` plus ref type, deploy-version hint,
  git checkout, and skip reason inside the standalone Deploy shell.
- The action deliberately does not call
  `DeployWebhookReleaseService::releaseFromWebhook()` or
  `DeployOrchestratorService::release()`, so it does not run Git, write files,
  execute commands, write release stamps, or reload WLS.
- `DeployWebhookRefResolver` now also accepts lowercase panel settings
  `webhook_branch` and `project_branch` when resolving branch filters.

Static and route validation:

```powershell
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\Service\DeployWebhookRefResolver.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node --check tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\Service\DeployWebhookRefResolver.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
php -r "require 'app/code/Weline/Deploy/Service/DeployConfigService.php'; require 'app/code/Weline/Deploy/Service/DeployWebhookRefResolver.php'; `$r=new Weline\Deploy\Service\DeployWebhookRefResolver(); `$tag=`$r->resolve('refs/tags/v1.2.3',['deploy_trigger_mode'=>'tag','webhook_tag_prefix'=>'v']); `$branch=`$r->resolve('refs/heads/main',['deploy_trigger_mode'=>'tag','webhook_branch'=>'main']); if (`$tag['skipped'] || `$tag['deploy_version_hint'] !== 'v1.2.3') { fwrite(STDERR, json_encode(`$tag)); exit(1); } if (!`$branch['skipped'] || `$branch['reason'] !== 'trigger_mode_tag_only') { fwrite(STDERR, json_encode(`$branch)); exit(1); } echo 'webhook replay resolver ok';"
php bin\w setup:upgrade --route --module Weline_Deploy --skip-env-check --skip-reflection-compile --skip-classmap --skip-background-optimize
rg -n "wls-deploy/webhook-replay|webhook-replay|post-webhook-replay" generated\routers\backend_pc.php
git diff --check -- app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\Service\DeployWebhookRefResolver.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml app\code\Weline\Deploy\i18n\zh_Hans_CN.csv app\code\Weline\Deploy\i18n\en_US.csv app\code\Weline\Deploy\doc\README.md app\code\Weline\Deploy\doc\backend-config.md app\code\Weline\Server\doc\wls-panel-plan\10-prototype.md app\code\Weline\Server\doc\wls-panel-plan\30-atomic-task-plan.md tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
```

Result:

- PHP syntax checks passed for the controller, resolver, and template.
- Node syntax check passed for the focused Deploy E2E spec.
- Forbidden runtime / browser calls scan returned no matches.
- Resolver one-liner passed with `webhook replay resolver ok`.
- Route refresh exited 0 and generated:
  `deploy/backend/wls-deploy/webhook-replay::POST` and
  `deploy/backend/wls-deploy/post-webhook-replay::POST`.
- `git diff --check` exited 0; Git only warned that several LF files will be
  normalized to CRLF on the next Git touch.
- Local `chcp` / `schtasks` PATH or permission warnings still appeared during
  setup maintenance; they did not stop route generation.

Runtime and browser validation:

```powershell
php bin\w server:start ai-test-wls-deploy-webhook-replay-9836 -p 9836 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri http://p11005ce4.weline.test:9836/ -UseBasicParsing -TimeoutSec 20
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-webhook-replay-9836'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9836'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-webhook-replay-9836
php bin\w server:status ai-test-wls-deploy-webhook-replay-9836
Test-NetConnection -ComputerName 127.0.0.1 -Port 9836
```

Result:

- Dedicated non-default WLS instance
  `ai-test-wls-deploy-webhook-replay-9836` started on port `9836` in Dispatcher
  mode with 8 workers and `--worker-memory-limit=512M`.
- Direct HTTP probe returned `200`.
- Focused WLS Deploy Profile browser spec passed: `1 passed (13.7s)`.
- The spec saves a project Profile, clicks the explicit preflight button, then
  verifies Webhook Replay for `refs/tags/v9.9.9` as `ready` and
  `refs/heads/main` as `skipped` with reason `trigger_mode_tag_only`.
- `server:stop` stopped the instance, `server:status` reported all stopped
  `(0/0)`, and `Test-NetConnection` returned `TcpTestSucceeded: False`.

Artifacts:

- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-webhook-replay-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-webhook-replay-mobile.png`

UI review notes:

- Desktop layout keeps Webhook Replay directly below release preflight and above
  configuration, so operators can run checks in the natural order: Profile,
  Preflight, Webhook Replay, Configuration.
- The desktop result grid shows the skipped branch decision, ref, type, checkout
  and reason without horizontal overflow.
- Mobile dark theme collapses the replay input, run button, examples, and empty
  state into a readable single-column panel.

## 2026-06-18 - WLS Deploy Project-Scoped Real Webhook Context Slice

Scope:

- Added project-context extraction to the public Deploy webhook flow. The
  frontend and REST webhook controllers now pass safe query context
  (`profile_key`, `project_id`, `domain`, `project_type`) into
  `DeployWebhookReleaseService::releaseFromWebhook()`.
- `DeployWebhookReleaseService` also recognizes the same context keys from the
  JSON payload top level or nested `wls`, `wls_project`, and `project` objects.
- `DeployProjectProfileService` now centralizes release-context normalization
  and builds one-shot runtime config overlays from enabled project Profiles.
  The overlay deliberately includes only non-secret fields; `webhook_secret`
  and the random `~wh~` path remain global until the secret-binding slice.
- `DeployOrchestratorService`, `DeployGitMetadataService`, and
  `DeployReleaseRuntimeService` now honor `DEPLOY_ROOT` for Git operations,
  backup source, post-deploy command, runtime stamp writes, and `server:reload`.
  A non-empty root must be an existing absolute path.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Service\DeployProjectProfileService.php
php -l app\code\Weline\Deploy\Service\DeployWebhookReleaseService.php
php -l app\code\Weline\Deploy\Controller\Webhook.php
php -l app\code\Weline\Deploy\Controller\Api\Webhook.php
php -l app\code\Weline\Deploy\Service\DeployGitMetadataService.php
php -l app\code\Weline\Deploy\Service\DeployReleaseRuntimeService.php
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Deploy\Service\DeployProjectProfileService.php app\code\Weline\Deploy\Service\DeployWebhookReleaseService.php app\code\Weline\Deploy\Service\DeployOrchestratorService.php app\code\Weline\Deploy\Service\DeployGitMetadataService.php app\code\Weline\Deploy\Service\DeployReleaseRuntimeService.php app\code\Weline\Deploy\Controller\Webhook.php app\code\Weline\Deploy\Controller\Api\Webhook.php
git diff --check -- app\code\Weline\Deploy\Service\DeployProjectProfileService.php app\code\Weline\Deploy\Service\DeployWebhookReleaseService.php app\code\Weline\Deploy\Service\DeployOrchestratorService.php app\code\Weline\Deploy\Service\DeployGitMetadataService.php app\code\Weline\Deploy\Service\DeployReleaseRuntimeService.php app\code\Weline\Deploy\Controller\Webhook.php app\code\Weline\Deploy\Controller\Api\Webhook.php
```

Result:

- PHP syntax checks passed for all seven touched PHP files.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited 0; Git only warned that LF files will be normalized
  to CRLF on the next Git touch.
- GitNexus impact was attempted for the edited Deploy services, but npm/npx
  cleanup failed with EPERM/timeout in the local cache, so the change used
  direct call-chain inspection as the fallback impact path.

Service validation:

```powershell
@'
<?php
require __DIR__ . '/app/bootstrap.php';

use Weline\Deploy\Model\DeployProjectProfile;
use Weline\Deploy\Service\DeployProjectProfileService;
use Weline\Deploy\Service\DeployWebhookReleaseService;
use Weline\Framework\Manager\ObjectManager;

$profileKey = 'project:codex-webhook-context-skip';
$projectId = 'codex-webhook-context-skip';

try {
    $profileService = ObjectManager::getInstance(DeployProjectProfileService::class);
    $save = $profileService->saveFromPanel([
        'profile_key' => $profileKey,
        'project_id' => $projectId,
        'domain' => 'codex-webhook-context.test',
        'project_type' => 'wls',
        'enabled' => '1',
        'project_repo_url' => 'https://example.com/org/codex-webhook-context.git',
        'project_branch' => 'main',
        'project_remote' => 'origin',
        'deploy_root' => BP,
        'deploy_trigger_mode' => 'branch',
        'webhook_branch' => 'main',
        'webhook_tag_prefix' => 'v',
        'git_update_mode' => 'pull_ff_only',
        'backup_before_deploy' => '0',
        'run_composer_install' => '0',
    ]);

    $releaseService = ObjectManager::getInstance(DeployWebhookReleaseService::class);
    $result = $releaseService->releaseFromWebhook(
        json_encode(['ref' => 'refs/tags/v9.9.9'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        [
            'WEBHOOK_SECRET' => 'test-secret',
            'DEPLOY_TRIGGER_MODE' => 'tag',
            'WEBHOOK_TAG_PREFIX' => '',
            'GIT_BRANCH' => 'fallback-main',
            'GIT_REMOTE_NAME' => 'origin',
        ],
        ['project_id' => $projectId, 'domain' => 'codex-webhook-context.test', 'project_type' => 'wls']
    );

    echo json_encode([
        'save_success' => (bool)($save['success'] ?? false),
        'status' => (int)($result['status'] ?? 0),
        'skipped' => (bool)($result['payload']['skipped'] ?? false),
        'reason' => (string)($result['payload']['reason'] ?? ''),
        'profile_key' => (string)($result['payload']['profile_key'] ?? ''),
        'project_id' => (string)($result['payload']['project_id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    ObjectManager::getInstance(DeployProjectProfile::class)
        ->clearQuery()
        ->where(DeployProjectProfile::schema_fields_PROFILE_KEY, $profileKey)
        ->delete()
        ->fetch();
}
'@ | php
```

Result:

```json
{
    "save_success": true,
    "status": 202,
    "skipped": true,
    "reason": "trigger_mode_branch_only",
    "profile_key": "project:codex-webhook-context-skip",
    "project_id": "codex-webhook-context-skip"
}
```

Cleanup verification:

```json
{"remaining":0}
```

This proves that the real webhook service reads the selected project Profile
before deciding whether a payload should proceed to release. The test used a
branch-only Profile and a tag payload, so it safely exercised the real webhook
entry path without running Git, writing version stamps, or reloading WLS.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-deploy-project-webhook-9844 -p 9844 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri 'http://127.0.0.1:9844/' -UseBasicParsing -TimeoutSec 20
Test-NetConnection -ComputerName 127.0.0.1 -Port 9844
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-project-webhook-9844'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9844'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-project-webhook-9844
php bin\w server:status ai-test-wls-deploy-project-webhook-9844
Test-NetConnection -ComputerName 127.0.0.1 -Port 9844
```

Result:

- WLS started on `9844` with `--worker-memory-limit=512M`; `/` returned HTTP
  `200`, and the port probe returned `TcpTestSucceeded=True`.
- A first run with `--workers=1` did not reach Playwright because the
  `e2e:run` wrapper emitted a bare `--workers` argument. The focused rerun
  without a worker override executed normally.
- `Weline_Deploy-wls-deploy-profile.spec.js` passed on Chromium:
  `1 passed (18.9s)`.
- The spec covered the standalone WLS Deploy shell, project Profile save,
  preflight, webhook replay, desktop viewport, mobile viewport, and dark-theme
  toggle.
- Generated screenshots were reviewed manually:
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-mobile.png`
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-webhook-replay-mobile.png`
- No horizontal overflow was reported by the spec. Manual screenshot review
  found the desktop light layout readable and the mobile dark layout responsive
  without obvious overlap or clipped controls.
- Cleanup passed: `server:status` reported the instance stopped, and the final
  port probe returned `TcpTestSucceeded=False`.
- Test data cleanup also passed for `project:e2e-deploy-profile` and
  `project:codex-webhook-context-skip`: `{"remaining":[]}`.

## 2026-06-18 - Deploy Profile project-level webhook secret binding

Scope:

- Added a `webhook_secret` field to Deploy Project Profiles so each WLS Panel
  project can bind an independent webhook verification secret.
- Updated both real webhook controllers to resolve the project effective
  release config before token verification, so project-scoped payload context
  can select the Profile secret.
- Kept the random `~wh~` webhook path as a global hardening layer; project
  Profile secret overlays only `WEBHOOK_SECRET` after a payload/request context
  selects the project.
- Added WLS Deploy panel UI support for entering a project webhook secret
  without exposing the stored secret back to the browser. A blank password field
  preserves the existing secret, and the panel displays whether verification is
  currently using `Project Secret` or `Global Secret`.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Model\DeployProjectProfile.php
php -l app\code\Weline\Deploy\Service\DeployProjectProfileService.php
php -l app\code\Weline\Deploy\Service\DeployWebhookReleaseService.php
php -l app\code\Weline\Deploy\Controller\Webhook.php
php -l app\code\Weline\Deploy\Controller\Api\Webhook.php
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node -c tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app\code\Weline\Deploy tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
```

Result:

- PHP syntax checks passed for all touched PHP/template files.
- E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.

Schema update:

```powershell
php bin\w setup:upgrade
```

Result:

- The full upgrade did not finish because the local Windows environment tried
  to run auto-fix scripts through PATH entries where `php` and `chcp` were not
  resolvable.
- The failure was environment/tooling related and left the new database column
  unavailable, proven by an SQLSTATE `42703` undefined-column error when saving
  a Profile with `webhook_secret`.

Focused schema update:

```powershell
php bin\w setup:upgrade --stage=schema_diff,database_update -m Weline_Deploy -s --skip-classmap --skip-reflection-compile --skip-background-optimize
```

Result:

- The focused schema/database update exited `0`.
- Existing stopped WLS instances were skipped by maintenance sync warnings.
- Windows cron install still emitted `schtasks/chcp` warnings, but the command
  completed with the framework's upgrade success message.
- The Deploy Project Profile `webhook_secret` column became available for
  service and browser validation.

Service validation:

```json
{
  "save_success": true,
  "effective_secret_is_project": true,
  "project_token_accepts": true,
  "global_token_rejected": true,
  "release_status": 202,
  "release_skipped": true,
  "release_reason": "trigger_mode_branch_only",
  "profile_key": "project:codex-secret-binding"
}
```

This proves the real webhook path now resolves the project effective config
before verification: the project token is accepted, the global token is
rejected for the same project payload, and the branch-only Profile safely skips
a tag payload with HTTP `202` instead of running deployment commands.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-deploy-project-secret-9845 -p 9845 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri 'http://127.0.0.1:9845/' -UseBasicParsing -TimeoutSec 20
Test-NetConnection -ComputerName 127.0.0.1 -Port 9845
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-project-secret-9845'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9845'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-project-secret-9845
php bin\w server:status ai-test-wls-deploy-project-secret-9845
Test-NetConnection -ComputerName 127.0.0.1 -Port 9845
```

Result:

- WLS started on `9845`; `/` returned HTTP `200`, and the port probe returned
  `TcpTestSucceeded=True`.
- `Weline_Deploy-wls-deploy-profile.spec.js` passed on Chromium:
  `1 passed (19.0s)`.
- The spec covered standalone WLS Deploy shell rendering, project Profile save,
  project webhook secret entry, non-disclosure after save, project/global
  verification-source display, preflight, desktop viewport, mobile viewport,
  and dark-theme toggle.
- Manual screenshot review passed:
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-mobile.png`
- The desktop light view shows the webhook entry preflight card using
  `项目密钥`. The mobile dark view shows the `项目 Webhook 密钥` field with
  the configured-secret placeholder and `当前验签来源: 项目密钥`, with readable
  alignment and no obvious clipped controls.
- Cleanup passed: `server:status` reported the instance stopped, the final
  port probe returned `TcpTestSucceeded=False`, and temporary service/E2E
  Profile data was removed with `{"remaining":[]}`.

## 2026-06-18 - Security Policy inheritance map

Scope:

- Added a `Policy Inheritance Map` to the native WLS Panel Security project
  policy editor.
- The map compares common rules with the selected project/domain effective
  rules and marks inherited, overridden, and custom-equals-global fields.
- The service merge uses the same domain override shape and protected-path
  replacement behavior as `AttackDetector` for the editable project policy
  fields.
- The prototype and atomic task plan now record this as the third
  `WLS-SEC-006` slice.

Static validation:

```powershell
php -l app\code\Weline\Server\Service\WlsPanelSecurityDataService.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/i18n/en_US.csv app/code/Weline/Server/i18n/zh_Hans_CN.csv app/code/Weline/Server/doc/wls-panel-plan tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the touched service/controller/template files.
- E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited `0`; Git only emitted LF/CRLF normalization
  warnings for existing i18n files.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-security-inheritance-9883 -p 9883 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:9883/ -TimeoutSec 20
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-security-inheritance-9883'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9883'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_WEBSERVER_TIMEOUT_MS='240000'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
php bin\w server:stop ai-test-wls-security-inheritance-9883
php bin\w server:status ai-test-wls-security-inheritance-9883
Test-NetConnection -ComputerName 127.0.0.1 -Port 9883
```

Result:

- WLS started on `9883`; `/` returned HTTP `200`.
- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (1.3m)`.
- The spec now asserts `data-wls-domain-policy-inheritance`, inherited count,
  rate-limit/protected-path inheritance rows, saved override count, and an
  overridden `rate_limit.max_requests` row after project policy save.
- The mobile Security policy screenshot path remains covered:
  `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-domain-policy-mobile.png`.
- Cleanup passed: `server:status ai-test-wls-security-inheritance-9883`
  reported the instance stopped, the final port probe returned
  `TcpTestSucceeded=False`, and `git status --short -- app/etc/env.php
  var/server/security-rules.json var/server/security-rules-update.flag
  extend/server/php/php.ini` returned no dirty runtime config entries.

## 2026-06-18 - WLS Security Policy audit history

Scope:

- Added compact JSONL audit records for successful WLS Security rule saves.
- `WlsPanelSecurityDataService::saveRulesJson()` now accepts an audit context
  and appends `var/log/wls/security-policy-audit.jsonl` after
  `AttackDetector::updateRules()` succeeds.
- Common visual-rule saves, advanced JSON saves, project-policy saves, and
  project-policy removals identify their action/source/scope/domain and
  changed rule sections without storing the full rule payload.
- The native Security page now renders `Security Policy Audit` below
  `Project Security Policy`, with responsive dark/light-safe cards.
- `Weline_Server-panel-shell.spec.js` snapshots and restores
  `var/server/security-rules.json`, saves a current-project policy, and asserts
  the audit entry appears in the standalone panel.

Static validation:

```powershell
php -l app\code\Weline\Server\Service\WlsPanelSecurityDataService.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/i18n/en_US.csv app/code/Weline/Server/i18n/zh_Hans_CN.csv app/code/Weline/Server/doc/wls-panel-plan tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the touched service/controller/template.
- E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited `0`; Git only emitted LF/CRLF normalization
  warnings for the already-edited WLS i18n CSV files.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-security-audit-9882 -p 9882 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:9882/ -TimeoutSec 20
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-security-audit-9882'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9882'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_WEBSERVER_TIMEOUT_MS='240000'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
Get-Content var\log\wls\security-policy-audit.jsonl -Tail 5
php bin\w server:stop ai-test-wls-security-audit-9882
php bin\w server:status ai-test-wls-security-audit-9882
Test-NetConnection -ComputerName 127.0.0.1 -Port 9882
```

Result:

- WLS started on `9882`; `/` returned HTTP `200`, and the port probe returned
  `TcpTestSucceeded=True`.
- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (1.4m)`.
- The spec now asserts `[data-wls-security-policy-audit]`, a non-zero
  `data-wls-security-policy-audit-count`, and the latest
  `[data-wls-security-audit-entry]` after saving a project policy.
- Audit tail contained compact entries such as:

```json
{
  "action": "domain_policy_saved",
  "source": "domain_override_panel",
  "scope": "current",
  "domain": "127.0.0.1",
  "changed_sections": ["rate_limit", "path_scan", "protected_paths"],
  "success": true
}
```

- Cleanup passed: `server:status` reported the instance stopped, the final
  port probe returned `TcpTestSucceeded=False`, and `git status --short --`
  for `app/etc/env.php`, `var/server/security-rules.json`,
  `var/server/security-rules-update.flag`, and `extend/server/php/php.ini`
  returned no entries.

## 2026-06-18 - PHP Manager reversible php.ini apply

Scope:

- Added the guarded `php.ini` apply and rollback slice for the standalone
  `Weline_PhpManager` WLS Panel plugin.
- The panel now previews pending directive drift, writes only a managed block,
  creates a backup before apply, exposes latest-backup rollback, appends JSONL
  audit events, and keeps runtime reload optional.
- The E2E flow saves a Project PHP Profile, applies the bundled
  `extend/server/php/php.ini` with `APPLY_PHP_INI`, then restores it with
  `ROLLBACK_PHP_INI`.

Static validation:

```powershell
php -l app\code\Weline\PhpManager\Service\WlsPhpIniService.php
php -l app\code\Weline\PhpManager\Controller\Backend\WlsPhpManager.php
php -l app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/PhpManager tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/PhpManager app/code/Weline/Server/doc/wls-panel-plan tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the new service, controller, and template.
- E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited `0`.
- The local PHP runtime still emits duplicate-extension and OPcache warnings;
  these did not block validation.

Route and plugin-refresh validation:

```powershell
php bin\w setup:upgrade --stage=route_update -m Weline_PhpManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump -s
rg -n "ini-apply|ini-rollback|WlsPhpManager|weline_phpmanager|wls-php-manager" generated/routers/backend_pc.php
```

Result:

- `generated/routers/backend_pc.php` contains:
  - `weline_phpmanager/backend/wls-php-manager/ini-apply::POST`
  - `weline_phpmanager/backend/wls-php-manager/ini-rollback::POST`
- A direct `WlsPanelPluginRefreshService::refreshPanelCapabilities('en_US')`
  call returned `success=true`, `routes_refreshed=true`,
  `plugin_count=4`, `contribution_count=4`, and route modules:
  `Weline_DbManager`, `Weline_Deploy`, `Weline_FileManager`,
  `Weline_PhpManager`.
- After that refresh, the PhpManager generated routes remained present.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-php-ini-9870 -p 9870 --no-ssl --worker-memory-limit=512M
php bin\w server:maintenance disable ai-test-wls-php-ini-9870
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-php-ini-9870'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9870'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_WEBSERVER_TIMEOUT_MS='240000'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
php bin\w server:stop ai-test-wls-php-ini-9870
Test-NetConnection 127.0.0.1 -Port 9870
```

Result:

- The dedicated WLS instance started on port `9870`.
- `server:maintenance status ai-test-wls-php-ini-9870` reported
  `maintenance=false` and `maintenance_workers=0` before the final run.
- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (1.5m)` / `E2E 测试执行成功。`
- The E2E now verifies:
  - PHP operation card is installed by `custom:wls-php-manager`.
  - Standalone PHP Manager shell opens with safe project context.
  - Project PHP Profile save succeeds.
  - `php.ini` plan is visible and has pending changes.
  - Apply creates a backup and shows the success notice.
  - Rollback restores the backup and shows the restore notice.
  - Mobile PHP Manager panel remains visible without horizontal overflow.
- Refreshed screenshot evidence:
  `tests/e2e/artifacts/backend/Weline_Server/wls-php-manager-ini-apply-desktop.png`.

Cleanup and residue checks:

- `extend/server/php/php.ini` returned to its original values:
  `memory_limit = 512M`, `post_max_size = 8M`,
  `upload_max_filesize = 2M`.
- `extend/server/php/php.ini` no longer contains the
  `WLS PHP Manager managed directives` marker.
- `var/log/wls/php-manager-audit.jsonl` recorded the latest
  `profile_saved`, `ini_applied`, and `ini_rolled_back` events for
  `project:e2e-php-manager`.
- `server:stop ai-test-wls-php-ini-9870` completed through IPC; the final
  port probe returned `TcpTestSucceeded=False`, and no PHP process remained
  for `ai-test-wls-php-ini-9870` or port `9870`.

## 2026-06-18 - PhpManager guarded Project Profile slice

Scope:

- Added the first guarded PHP configuration slice for the bundled
  `Weline_PhpManager` WLS Panel plugin.
- `Weline_PhpManager` declares `module:wls`, `custom:wls-php-manager`,
  `feature:php-config`, `capability:php-runtime-read`,
  `capability:php-profile-write`, `capability:wls-reload-request`, and
  `system:true`.
- The plugin contributes a WLS Panel menu entry and satisfies the dashboard
  `php-profile` operation slot through the existing typed-tag discovery path.
- The standalone PHP Manager shell reads current runtime PHP state, receives
  safe project context, saves guarded project-level PHP Profiles, appends JSONL
  audit records under `var/log/wls/php-manager-audit.jsonl`, supports
  light/dark theme persistence, and can request an operator-selected WLS reload
  when explicitly selected.
- This slice does not write `php.ini` and does not install/remove PHP
  extensions. Those are future guarded operations.

Static validation:

```powershell
php -l app\code\Weline\PhpManager\Model\WlsPhpProfile.php
php -l app\code\Weline\PhpManager\Service\WlsPhpProfileService.php
php -l app\code\Weline\PhpManager\Controller\Backend\WlsPhpManager.php
php -l app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml
Get-Content -Raw -Path app\code\Weline\PhpManager\etc\marketplace\meta.json | ConvertFrom-Json | Select-Object -ExpandProperty module_name
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app\code\Weline\PhpManager tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app\code\Weline\PhpManager tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the model, service, controller, and template.
  The local PHP runtime still prints duplicate-extension and OPcache warnings.
- Marketplace meta JSON parsed and reported `Weline_PhpManager`.
- The E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited `0`.

Route and schema refresh:

```powershell
php bin\w setup:upgrade --stage=schema_diff,route_update -m Weline_PhpManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump -s
rg -n "weline_phpmanager/backend/wls-php-manager|postProfileSave|WlsPhpProfile|w_php_manager_project_profile" generated app\etc tests\e2e\modules.json app\code\Weline\PhpManager -g "*.php" -g "*.json"
```

Result:

- `setup:upgrade` exited `0`, installed `Weline_PhpManager`, generated module
  routes, generated `tests/e2e/modules.json`, and granted the new module ACL
  permissions to the super administrator role.
- `generated/routers/backend_pc.php` contains:
  - `weline_phpmanager/backend/wls-php-manager::GET`
  - `weline_phpmanager/backend/wls-php-manager/profile-save::POST`
- `app/code/Weline/PhpManager/Model/WlsPhpProfile.php` defines
  `w_php_manager_project_profile`.
- The setup run still emitted existing Windows PATH warnings for `chcp`,
  `schtasks`, and stale historical WLS instances, but route/schema generation
  completed successfully.

Focused WLS and browser validation:

```powershell
Test-NetConnection 127.0.0.1 -Port 9868
php bin\w server:start ai-test-wls-php-profile-9868 -p 9868 -c 2 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri 'http://127.0.0.1:9868/' -UseBasicParsing -TimeoutSec 20
php bin\w server:status ai-test-wls-php-profile-9868
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-php-profile-9868'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9868'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_WEBSERVER_TIMEOUT_MS='240000'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- Port `9868` was free before start.
- WLS started on HTTP port `9868`; `/` returned HTTP `200`.
- `server:status` showed Master, 2 HTTP workers, and 1 Dispatcher running.
- The full WLS Panel shell spec passed on Chromium:
  `1 passed (56.5s)`.
- The spec now asserts:
  - PHP operation card is installed by `custom:wls-php-manager`.
  - `Weline_PhpManager` appears in WLS plugin navigation.
  - Standalone PHP Manager desktop shell opens with safe project context.
  - Project PHP Profile save succeeds with confirmation and `runtime_action=none`.
  - Saved profile state and audit section render after redirect.
  - PHP Manager dark/light theme toggle works.
  - PHP Manager 390px mobile shell has no horizontal overflow.
  - Marketplace installed plugin cards include `Weline_PhpManager`.
- The first E2E attempt failed only because the new assertion expected the
  DbManager-specific text `Stored in Profile`. The saved state was already
  correct; the assertion was corrected to the PHP Manager UI text
  `Current source: Panel Profile`, and the rerun passed.

Screenshots reviewed:

- `tests/e2e/artifacts/backend/Weline_Server/wls-php-manager-shell-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-php-manager-shell-mobile.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

Visual notes:

- Desktop dark view shows a clearly independent `WLS PHP Manager` shell with
  left navigation, project context, runtime cards, saved profile state, and no
  footer/version leakage.
- Mobile dark view stacks the navigation above content, keeps action buttons
  full-width, and has no visible horizontal overflow.

## 2026-06-18 - DbManager Project Profile guarded write

Scope:

- Added the WLS Database Manager guarded Project Profile write path.
- The panel can save a project-scoped database Profile into
  `w_db_manager_project_profile`.
- Secrets are stored encrypted and are never rendered back into the panel.
- The save flow requires an explicit guarded confirmation checkbox.
- Runtime action defaults to `none`; `reload` is available as a reload request
  path for a named WLS instance.
- Recent Profile save/test actions are recorded in
  `var/log/wls/db-manager-audit.jsonl`.

Static validation:

```powershell
php -l app\code\Weline\DbManager\Model\WlsDatabaseProfile.php
php -l app\code\Weline\DbManager\Service\WlsDatabaseProfileService.php
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -r "$json=json_decode(file_get_contents('app/code/Weline/DbManager/etc/marketplace/meta.json'), true); echo json_last_error_msg(), PHP_EOL;"
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app\code\Weline\DbManager tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app\code\Weline\DbManager app\code\Weline\Server\doc\wls-panel-plan tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the DbManager model, service, controller, and
  template.
- Marketplace metadata JSON parsed with `No error`.
- The Playwright spec syntax check passed.
- Forbidden blocking/runtime/browser API scan returned no matches.
- `git diff --check` exited `0`.

Route and schema refresh:

```powershell
php bin\w setup:upgrade --stage=schema_diff,route_update -m Weline_DbManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump -s
```

Result:

- `schema_diff` and `route_update` completed.
- `generated\routers\backend_pc.php` contains
  `weline_dbmanager/backend/wls-db-manager/profile-save::POST`.
- Super administrator role received the new backend permission.
- The local Windows environment still prints duplicate PHP extension warnings
  and `chcp`/`schtasks` PATH warnings; the setup command still exited `0`.

Runtime validation:

```powershell
php bin\w server:start ai-test-wls-db-profile-9866 -p 9866 -c 2 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri 'http://127.0.0.1:9866/' -UseBasicParsing -TimeoutSec 20
php bin\w server:status ai-test-wls-db-profile-9866
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-db-profile-9866'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9866'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_WEBSERVER_TIMEOUT_MS='240000'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- WLS started on port `9866`; `/` returned HTTP `200`.
- `server:status` showed Master, two HTTP Workers, and Dispatcher all running.
- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (1.0m)`.
- The E2E flow saved a Project Profile for
  `project_id=e2e-db-manager` and `domain=db.e2e.wls.test`.
- The panel showed `Project database profile saved.`, switched the DbManager
  guard state to `Writable`, exposed `Project Profile` in the connection test
  selector, and did not render the submitted secret.
- `var/log/wls/db-manager-audit.jsonl` recorded a `profile_saved` event with
  masked username and `password_state=configured`.
- A focused text scan of `tests\e2e\artifacts`, `var\log\wls`, and
  `app\code\Weline\DbManager` did not find the submitted secret.

Screenshots:

- `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-shell-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-shell-mobile.png`

Visual notes:

- Desktop dark view shows the standalone `WLS Database Manager` shell, plugin
  navigation, success notice, and `Writable` guard card without overlap.
- Mobile dark view keeps navigation, theme toggle, and primary actions in a
  single-column layout without visible horizontal overflow.

## 2026-06-18 - WLS Panel project-level security policy overrides

Scope:

- Added `domain_overrides` to the AttackDetector rule model.
- `AttackDetector::detect()` now normalizes the request Host, builds temporary
  effective rules for the matched domain, restores common rules after the
  request, and logs the normalized domain.
- Added a native `Project Security Policy` panel section below project security
  drill-down cards.
- Concrete project/domain cards now expose `Edit Policy`; the policy form can
  save or remove per-domain overrides for rate limit, path scan, and protected
  paths through the existing `AttackDetector::updateRules()` hot-reload path.
- Updated the prototype, atomic task plan, and current-stage summary with
  `WLS-SEC-006`.

Static validation:

```powershell
php -l app/code/Weline/Server/Security/AttackDetector.php
php -l app/code/Weline/Server/Service/WlsPanelSecurityDataService.php
php -l app/code/Weline/Server/Controller/Backend/WlsPanel.php
php -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
node --check tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|die|exit)\s*\(" app/code/Weline/Server/Security/AttackDetector.php app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
git diff --check -- app/code/Weline/Server/Security/AttackDetector.php app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the touched PHP and PHTML files.
- E2E spec JavaScript syntax check passed.
- Forbidden WLS runtime scan returned no matches.
- `git diff --check` exited `0`; Git only emitted the existing LF/CRLF
  normalization warning for `AttackDetector.php`.

Service validation:

```json
{
  "hit": {
    "is_attack": true,
    "type": "protected_path",
    "reason": "访问受保护路径: /domain-only-secret",
    "should_block": true
  },
  "miss": {
    "is_attack": false,
    "type": "none",
    "reason": "",
    "should_block": false
  },
  "passed": true
}
```

This proves `Host: e2e-domain-policy.test:9862` can activate a
domain-specific protected-path override, while `Host: other.test:9862` keeps
the same path on common-rule fallback. The temporary rule-file changes were
backed up and restored after the assertion.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-security-domain-9862 -p 9862 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri http://127.0.0.1:9862/ -UseBasicParsing -TimeoutSec 15
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-security-domain-9862'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9862'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
php bin\w server:stop ai-test-wls-security-domain-9862
php bin\w server:status ai-test-wls-security-domain-9862
```

Result:

- WLS started on port `9862`; `/` returned HTTP `200`.
- `server:status` showed Master, 8 HTTP workers, and 1 Dispatcher running.
- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (57.5s)`.
- The spec now asserts the Security page domain policy area, the current-project
  `Edit Policy` entry, editable project policy fields, and no horizontal
  overflow on desktop and 390px mobile.
- Manual screenshot review passed:
  - `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-scope-filter.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-domain-policy-mobile.png`
- Cleanup passed: `server:status` reported the instance stopped, port `9862`
  was closed, and no `var/wls-file-manager-e2e-*` directory remained.

## 2026-06-18 - FileManager controlled write slice

Scope:

- Added the first guarded write slice for the bundled `Weline_FileManager`
  WLS Panel plugin.
- `var` and `pub` roots can create one-level directories and save small text
  files; `project` and `app_code` remain read-only.
- Write actions require `Weline_FileManager::wls_file_manager_write`, explicit
  `confirm_write=1`, and text saves require the `SAVE_TEXT` confirmation
  phrase.
- Text saves are capped at 128 KB and restricted to safe text extensions.
- Each write attempt is appended to
  `var/log/wls_file_manager_operations.log` and surfaced in the panel log
  section.
- Marketplace meta now advertises the plugin as
  `module:wls`, `custom:wls-file-manager`, `feature:file-manager`, and
  `capability:files-write`.

Static validation:

```powershell
php -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
node -e "JSON.parse(require('fs').readFileSync('app/code/Weline/FileManager/etc/marketplace/meta.json','utf8')); console.log('meta ok')"
node -c tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
git diff --check -- app/code/Weline/FileManager app/code/Weline/Server/doc/wls-panel-plan tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the controller and template. The local PHP
  runtime still prints duplicate-extension and OPcache already-loaded warnings.
- Marketplace meta JSON parse passed with `meta ok`.
- E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited `0`; Git only emitted LF/CRLF normalization
  warnings for existing FileManager docs/i18n files.

Route refresh:

```powershell
php bin\w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
rg -n "wls-file-manager/(create-directory|save-text)|postCreateDirectory|postSaveText" generated app/code/Weline/FileManager -g "*.php"
```

Result:

- Route generation completed for `Weline_FileManager`.
- `generated/routers/backend_pc.php` contains POST routes for:
  - `weline_filemanager/backend/wls-file-manager/create-directory::POST`
  - `weline_filemanager/backend/wls-file-manager/save-text::POST`
- The generated route table maps those routes to `postCreateDirectory` and
  `postSaveText`.

Focused WLS and browser validation:

```powershell
php bin\w server:start ai-test-wls-file-write-9860 -p 9860 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri 'http://127.0.0.1:9860/' -UseBasicParsing -TimeoutSec 20
Test-NetConnection 127.0.0.1 -Port 9860
php bin\w server:status ai-test-wls-file-write-9860
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-file-write-9860'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9860'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
php bin\w server:stop ai-test-wls-file-write-9860
php bin\w server:status ai-test-wls-file-write-9860
Test-NetConnection 127.0.0.1 -Port 9860
Get-ChildItem -Path var -Directory -Filter 'wls-file-manager-e2e-*'
```

Result:

- WLS started on `9860`; `/` returned HTTP `200`, the port probe returned
  `TcpTestSucceeded=True`, and `server:status` showed the master plus eight
  workers and one dispatcher running.
- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (54.1s)`.
- The spec now performs a real FileManager panel write flow:
  - opens the plugin with `root=var`;
  - creates a unique `wls-file-manager-e2e-*` directory;
  - saves `notes.txt` with `SAVE_TEXT`;
  - asserts the success banner, file list entry, and operation log entry;
  - removes the temporary directory from `var`.
- Manual screenshot review passed:
  - `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-write-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-mobile.png`
- The desktop dark write view keeps the two guarded forms aligned, shows the
  writable root and allowed extensions, and has no visible overlap. The mobile
  dark shell remains readable with the sidebar/action stack collapsed cleanly.
- Cleanup passed: `server:status` reported the instance stopped, the final
  port probe returned `TcpTestSucceeded=False`, and no
  `var/wls-file-manager-e2e-*` directory remained.

## 2026-06-18 - Deploy manual release plan dry-run

Scope:

- Added `WlsDeploy::postManualPlanRun()` as a read-only manual release planning
  action for the WLS Deploy standalone panel.
- Added a `Manual Release Plan` panel section with ref input, tag/branch
  samples, policy result summary, and future execution steps.
- Extended the focused Deploy E2E to save a project Profile, build a tag
  manual plan, verify `manual_status=ready`, assert the dry-run boundary step,
  and smoke the Manual Plan section on mobile.
- Updated the WLS Panel Plan prototype and Deploy project webhook contract docs
  with the manual plan flow and non-mutating boundary.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node -c tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/Deploy/Controller/Backend/WlsDeploy.php app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml tests/e2e/specs/backend/Weline_Deploy-wls-deploy-profile.spec.js
git diff --check -- app/code/Weline/Deploy/Controller/Backend/WlsDeploy.php app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml tests/e2e/specs/backend/Weline_Deploy-wls-deploy-profile.spec.js app/code/Weline/Deploy/doc/wls-panel-project-webhook.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
```

Result:

- PHP syntax checks passed for the controller and template.
- E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited `0`.

Route update:

```powershell
php bin\w setup:upgrade --route -m Weline_Deploy --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
rg -n "manual-plan-run|postManualPlanRun" generated app/code/Weline/Deploy -g "*.php" -g "*.phtml"
```

Result:

- Module-scoped route update completed and wrote
  `deploy/backend/wls-deploy/manual-plan-run::POST` into
  `generated/routers/backend_pc.php`.
- The first broad `setup:upgrade --route` attempt was stopped after a timeout.
  The focused rerun with `--module Weline_Deploy` and `--skip-env-check`
  completed. The command still emitted existing Windows PATH warnings for
  `chcp`/`schtasks`, but route generation succeeded.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-deploy-manual-plan-9850 -p 9850 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri 'http://127.0.0.1:9850/' -UseBasicParsing -TimeoutSec 20
Test-NetConnection -ComputerName 127.0.0.1 -Port 9850
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-manual-plan-9850'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9850'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-manual-plan-9850
php bin\w server:status ai-test-wls-deploy-manual-plan-9850
Test-NetConnection -ComputerName 127.0.0.1 -Port 9850
```

Result:

- WLS started on HTTP port `9850`; `/` returned HTTP `200`, and the port probe
  returned `TcpTestSucceeded=True`.
- `Weline_Deploy-wls-deploy-profile.spec.js` passed on Chromium:
  `1 passed (13.4s)`.
- The spec verifies:
  - Manual Plan section visible.
  - `refs/tags/v9.9.9` produces `manual_status=ready`.
  - The result card contains `v9.9.9`.
  - The plan steps include `Dry-run boundary`.
  - Mobile layout keeps `[data-wls-manual-plan]` visible without horizontal
    overflow.
- Screenshots captured:
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-manual-plan-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-manual-plan-mobile.png`
- Cleanup passed: `server:status` reported Master stopped and the final port
  probe returned `TcpTestSucceeded=False`.

## 2026-06-18 - Deploy rollback action wiring

Scope:

- Added confirmed rollback execution to the standalone WLS Deploy panel.
- `WlsDeploy::postRollbackRun()` now requires POST, a checked
  `confirm_rollback`, an enabled selected project Profile, a stored
  `rollback_ref`, and a non-danger preflight before execution.
- `DeployOrchestratorService::rollback()` executes the normalized rollback ref
  in the selected project's effective `deploy_root`, writes
  `var/deploy/current.json`, syncs the runtime env stamp, emits the normal
  release-after event, and records project-scoped release history.
- `DeployGitMetadataService` now runs Git through `proc_open()` argv arrays
  with `bypass_shell=true` so Windows `cmd` PATH/AutoRun issues do not turn a
  successful Git operation into a false non-zero exit.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Service\DeployGitMetadataService.php
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node -c tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
```

Result:

- PHP syntax passed for the Git service, orchestrator, backend controller, and
  WLS Deploy template.
- The E2E spec JavaScript syntax check passed.
- A direct service smoke confirmed `DeployGitMetadataService` can read the host
  checkout short commit, full 40-character commit, and current branch through
  the new `proc_open()` Git path.

Controlled rollback harness:

```json
{
  "rollback_success": true,
  "message": "ok",
  "deploy_version": "v-rollback-1",
  "profile_key": "project:codex-rollback-action",
  "head_matches_expected": true,
  "version_text": "rollback-target",
  "current_file_exists": true,
  "current_deploy_version": "v-rollback-1",
  "current_rollback_ref": "refs/tags/v-rollback-1",
  "current_profile_key": "project:codex-rollback-action",
  "success_release_count": 1,
  "cleanup": {
    "profiles": 0,
    "releases": 0,
    "env_restored": true
  }
}
```

This proves a saved project Profile can drive a real tag rollback against a
temporary project `deploy_root`: the temp clone HEAD matched the rollback tag
commit, `version.txt` returned to the rollback-target content, `current.json`
recorded the rollback ref/version/profile, and project-scoped release history
recorded one successful rollback before cleanup.

Cleanup validation:

- `git status --short -- app/etc/env.php` returned no dirty entry for
  `app/etc/env.php`.
- `var/tmp/wls-deploy-rollback-harness-live` no longer exists.
- DB cleanup rechecked `profiles=0` and `releases=0` for
  `project:codex-rollback-action`.

Focused browser/E2E validation:

```powershell
php bin\w server:start -p 9848 -n ai-test-wls-deploy-rollback-action-9848 --worker-memory-limit=512M
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-rollback-action-9848'
$env:PLAYWRIGHT_TARGET_ORIGIN='https://127.0.0.1:9848'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-rollback-action-9848
php bin\w server:status ai-test-wls-deploy-rollback-action-9848
Test-NetConnection -ComputerName 127.0.0.1 -Port 9848
```

Result:

- WLS started on dedicated port `9848` with the dedicated instance name
  `ai-test-wls-deploy-rollback-action-9848`.
- `Weline_Deploy-wls-deploy-profile.spec.js` passed on Chromium:
  `1 passed (12.8s)`.
- The spec now asserts the rollback action surface:
  `data-wls-deploy-rollback` has `data-ready="1"`, the confirmation checkbox
  is visible, and the run button is enabled when the Profile/preflight are
  ready; mobile validation also confirms the rollback block renders.
- Cleanup passed: `server:status` reported the instance stopped and the final
  port probe returned `TcpTestSucceeded=False`.

## 2026-06-18 - Deploy controlled success-path webhook POST harness

Scope:

- Validated the real public webhook success path without introducing a
  production fake-success or dry-run flag.
- Created a temporary bare Git remote and a temporary working clone under
  `var/tmp/wls-deploy-webhook-success-harness`.
- Saved a temporary enabled Deploy Project Profile:
  `profile_key=project:codex-webhook-success`,
  `project_id=codex-webhook-success`,
  `domain=codex-webhook-success.test`,
  `project_type=wls`,
  `deploy_root=<temp working clone>`,
  `webhook_secret=codex-success-secret`,
  `deploy_trigger_mode=tag`,
  `webhook_tag_prefix=v-harness`,
  `backup_before_deploy=0`,
  `run_composer_install=0`,
  and empty post-deploy command.
- Started a dedicated WLS instance on port `9847` and posted a real tag payload
  to `~wh~codex-success-harness` with project context and the project bearer
  token.

Live webhook result:

```json
{
  "http_status": 200,
  "ok": true,
  "deploy_version": "v-harness-1",
  "profile_key": "project:codex-webhook-success",
  "project_id": "codex-webhook-success",
  "current_file_exists": true,
  "current_deploy_version": "v-harness-1",
  "probe_status": 200
}
```

This proves the public webhook route resolved the temporary project Profile,
verified the project-level secret, resolved the tag as deployable, ran
`DeployOrchestratorService::release()` against the temporary `deploy_root`,
wrote `var/deploy/current.json`, and created project-scoped release history.

Cleanup validation:

```json
{
  "remaining_profiles": 0,
  "remaining_releases": 0
}
```

- `server:status ai-test-wls-deploy-webhook-success-9847` reported the instance
  stopped.
- `Test-NetConnection 127.0.0.1 -Port 9847` returned
  `TcpTestSucceeded=False`.
- `var/tmp/wls-deploy-webhook-success-harness` no longer exists.
- `git status --short -- app/etc/env.php` returned no dirty entry for
  `app/etc/env.php`, confirming the harness did not leave the runtime env file
  modified in the worktree.

## 2026-06-18 - Deploy Profile rollback reference policy

Scope:

- Added rollback reference normalization to `DeployProjectCommandPolicyService`.
- The Profile save path now normalizes `rollback_ref` before persistence.
- The WLS Deploy preflight now renders a dedicated `rollback` policy check.
- The WLS Deploy panel displays rollback examples and the allowed ref policy
  beside the `rollback_ref` field.
- This slice was intentionally non-destructive when it landed. The later
  rollback action wiring reused this project policy and context; see
  `2026-06-18 - Deploy rollback action wiring` in this evidence log.

Static validation:

```powershell
php -l app\code\Weline\Deploy\Service\DeployProjectCommandPolicyService.php
php -l app\code\Weline\Deploy\Service\DeployProjectProfileService.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
node -c tests\e2e\specs\backend\Weline_Deploy-wls-deploy-profile.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/Deploy/Service/DeployProjectCommandPolicyService.php app/code/Weline/Deploy/Service/DeployProjectProfileService.php app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml tests/e2e/specs/backend/Weline_Deploy-wls-deploy-profile.spec.js
git diff --check -- app/code/Weline/Deploy/Service/DeployProjectCommandPolicyService.php app/code/Weline/Deploy/Service/DeployProjectProfileService.php app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml app/code/Weline/Deploy/i18n/zh_Hans_CN.csv app/code/Weline/Deploy/i18n/en_US.csv app/code/Weline/Deploy/doc/backend-config.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md tests/e2e/specs/backend/Weline_Deploy-wls-deploy-profile.spec.js
```

Result:

- PHP syntax checks passed for the touched service/template files.
- E2E spec JavaScript syntax check passed.
- Forbidden blocking/runtime/browser scan returned no matches.
- `git diff --check` exited `0`; Git only emitted LF/CRLF normalization
  warnings for existing files.

Service validation:

```json
{
  "valid_ref": "refs/tags/v1.2.3",
  "kind": "tag",
  "invalid_blocked": true,
  "save_success": true,
  "rollback_state": "ok",
  "preflight_status": "ok"
}
```

This proves a valid rollback tag ref is accepted and classified, an unsafe
`bad;rm` ref is rejected before persistence, and the saved project Profile
produces a non-destructive preflight with `rollback=ok`.

Focused browser validation:

```powershell
php bin\w server:start ai-test-wls-deploy-rollback-policy-9846 -p 9846 --no-ssl --worker-memory-limit=512M
Invoke-WebRequest -Uri 'http://127.0.0.1:9846/' -UseBasicParsing -TimeoutSec 20
Test-NetConnection -ComputerName 127.0.0.1 -Port 9846
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-deploy-rollback-policy-9846'
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9846'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
php bin\w e2e:run specs/backend/Weline_Deploy-wls-deploy-profile.spec.js --project=chromium --headless
php bin\w server:stop ai-test-wls-deploy-rollback-policy-9846
php bin\w server:status ai-test-wls-deploy-rollback-policy-9846
Test-NetConnection -ComputerName 127.0.0.1 -Port 9846
```

Result:

- WLS started on `9846`; `/` returned HTTP `200`, and the port probe returned
  `TcpTestSucceeded=True`.
- `Weline_Deploy-wls-deploy-profile.spec.js` passed on Chromium:
  `1 passed (11.5s)`.
- The spec now asserts the new preflight check:
  `data-wls-deploy-preflight-check="rollback"` with `data-state="ok"`.
- Manual screenshot review passed:
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-preflight-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Deploy/wls-deploy-profile-mobile.png`
- The desktop light preflight view shows the new `回滚策略 / Tag 回滚` card
  without overlap. The mobile dark Profile form remains readable around the
  Deploy command and webhook secret controls.
- Cleanup passed: `server:status` reported the instance stopped, the final
  port probe returned `TcpTestSucceeded=False`, and temporary service/E2E
  Profile data was removed with `{"remaining":[]}`.

## 2026-06-18 - DbManager env apply and panel security worker sync

Scope:

- Added `Weline_DbManager` guarded env apply support for persistent
  `app/etc/env.php` database config.
- The DB profile shell now previews drift between the saved project DB Profile
  and persistent `db` / `db.master` env config, applies with a required phrase,
  writes backup files under `var/backups/wls/db-manager`, and supports latest
  backup rollback.
- Passwords remain masked in the UI and audit output. When the saved project
  Profile does not provide a password, the apply path preserves the existing
  persistent env password state.
- Added `withEnvSnapshot()` coverage to the full WLS Panel E2E so the test can
  prove apply and rollback without leaving `app/etc/env.php` modified.
- Fixed a multi-worker read-after-write gap in `AttackDetector::getRules()`:
  panel reads now force-check the persisted rules update flag before returning
  data, so the redirected Security page can immediately show freshly saved
  `domain_overrides` even when the request lands on a different WLS worker.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Security\AttackDetector.php
extend\server\php\php.exe -l app\code\Weline\DbManager\Service\WlsDatabaseEnvApplyService.php
extend\server\php\php.exe -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
extend\server\php\php.exe -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/Server/Security/AttackDetector.php app/code/Weline/DbManager app/code/Weline/Server/doc/wls-panel-plan tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the touched runtime/service/controller/template
  files. The local PHP runtime still emits existing duplicate-extension and
  OPcache warnings.
- E2E spec JavaScript syntax check passed.
- `git diff --check` exited `0`; Git only emitted an LF/CRLF normalization
  warning for `AttackDetector.php`.

Route/cache refresh:

```powershell
php bin\w setup:upgrade --stage=route_update -m Weline_DbManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump -s
```

Result:

- Command exited `0` and refreshed `Weline_DbManager` backend route metadata.
- The command granted new DB manager ACL resources to the admin role.
- The local Windows environment emitted stale WLS IPC warnings for stopped
  instances and scheduler-tool warnings, but the route update completed.

Focused browser validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-db-env-9884 -p 9884 --no-ssl -c 2 --worker-memory-limit=512M
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9884'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-db-env-9884'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
Push-Location tests\e2e
node .\node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium --timeout=300000
Pop-Location
```

Result:

- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (1.1m)`.
- The full panel flow now covers standalone shell responsiveness, dark/light
  theme switching, marketplace typed WLS tags, Project Config Center links,
  PHP manager shell, DB manager shell, DB profile save, env drift preview,
  DB env apply, DB env rollback, File Manager shell, Gateway/Security panels,
  project-level security policy save, policy inheritance map, and mobile
  screenshots.
- `git diff -- app/etc/env.php var/server/security-rules.json extend/server/php/php.ini --`
  returned no diff after the E2E run, proving the snapshot guards restored the
  sensitive runtime files.
- Recent screenshot artifacts:
  - `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-shell-desktop.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-shell-mobile.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-scope-filter.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-panel-security-domain-policy-mobile.png`
  - `tests/e2e/artifacts/backend/Weline_Server/wls-panel-marketplace-mobile-dark.png`

## Gateway Traffic Mode Contract Slice

Scope:

- Added the first `WLS-GATEWAY-010` implementation slice to the native Gateway
  Settings flow.
- Gateway Settings now saves `wls.gateway.traffic_mode` with
  `auto`, `direct_listen`, and `passthrough`.
- `WLS_GATEWAY_TRAFFIC_MODE` can override saved config at process level; the
  panel displays saved versus effective traffic mode and an operator-facing
  hint.
- Gateway restart maps concrete traffic modes into runtime topology:
  `direct_listen` -> `--topology direct`; `passthrough` ->
  `--topology dispatcher`.
- Ordinary `server:start` also maps saved or env-overridden Gateway traffic mode
  when the runtime `topology` is still `auto`.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelGatewaySettingsService.php
extend\server\php\php.exe -l app\code\Weline\Server\Console\Server\Start.php
extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/Server/Service/WlsPanelGatewaySettingsService.php app/code/Weline/Server/Console/Server/Start.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/doc/wls-panel-plan
```

Result:

- PHP syntax checks passed for the Gateway Settings service, `server:start`, and
  native panel template. The local PHP runtime still emits existing
  duplicate-extension and OPcache warnings.
- E2E spec JavaScript syntax check passed.
- `git diff --check` exited `0`; Git emitted the existing LF/CRLF
  normalization warning for `Start.php`.
- Added-diff forbidden-call scan returned no new `sleep`, `usleep`, `die`,
  `exit`, `alert`, `confirm`, or `prompt` calls. A full-file scan still reports
  existing `SchedulerSystem::*` waits and historical CLI `exit` paths inside
  `Start.php`.

Direct-listen contract check:

```powershell
$env:WLS_GATEWAY_TRAFFIC_MODE = 'direct_listen'
extend\server\php\php.exe bin\w server:start ai-test-wls-gateway-direct-contract-9893 -p 9893 --no-ssl -c 2 --worker-memory-limit=512M
Remove-Item Env:\WLS_GATEWAY_TRAFFIC_MODE
extend\server\php\php.exe bin\w server:status ai-test-wls-gateway-direct-contract-9893
extend\server\php\php.exe bin\w server:stop ai-test-wls-gateway-direct-contract-9893
```

Result:

- The command reached direct topology through the env traffic-mode override and
  was blocked by the expected Windows runtime guard:
  `WLS direct topology requires SO_REUSEPORT on this OS/kernel.`
- The startup command exited without creating a running instance.
- `server:status` and `server:stop` both reported
  `ai-test-wls-gateway-direct-contract-9893` did not exist, confirming no direct
  contract test process was left behind.

Browser/E2E validation:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-gateway-traffic-9895 -p 9895 --no-ssl -c 2 --worker-memory-limit=512M
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9895'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-gateway-traffic-9895'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
Push-Location tests\e2e
node .\node_modules\playwright\cli.js test specs/backend/Weline_Server-panel-shell.spec.js --config=playwright.config.js --project=chromium --timeout=300000
Pop-Location
extend\server\php\php.exe bin\w server:stop ai-test-wls-gateway-traffic-9895
extend\server\php\php.exe bin\w server:status ai-test-wls-gateway-traffic-9895
git diff -- app/etc/env.php var/server/security-rules.json extend/server/php/php.ini --
```

Result:

- Dedicated instance `ai-test-wls-gateway-traffic-9895` started on port `9895`
  in dispatcher topology with two workers.
- `Weline_Server-panel-shell.spec.js` passed on Chromium:
  `1 passed (1.3m)`.
- The run now explicitly asserts the Gateway traffic-mode selector is visible,
  defaults to a valid mode, and exposes exactly `auto`, `direct_listen`, and
  `passthrough`, in addition to the standalone panel shell, responsive layout,
  theme switching, marketplace typed WLS tags, Project Config Center links,
  PHP/DB manager shells, File Manager shell, Gateway/Security panels, project
  security policy flow, and mobile screenshots.
- The test instance stopped cleanly; `server:status` shows `Master 状态：○ 已停止`.
- `git diff -- app/etc/env.php var/server/security-rules.json extend/server/php/php.ini --`
  returned no diff after the run.

Remaining validation:

- Live direct-listen routing and throughput still require a runtime platform
  where SO_REUSEPORT is available. The Windows local checkout can prove the
  traffic-mode-to-topology contract and guard behavior, but not real concurrent
  direct listener sharing.

## File Manager Controlled Operations Rerun

Status: passed in this checkout on 2026-06-18 after enabling guarded upload,
same-folder rename, and file/empty-directory delete in the WLS File Manager
plugin.

Scope:

- File Manager write page exposes enabled create directory, save text, upload,
  rename, and delete forms when the current root is writable.
- Upload is limited to `var` / `pub`, allowed extensions, 5 MB, explicit
  confirmation, and no overwrite unless requested.
- Rename is same-directory only and requires explicit confirmation.
- Delete is limited to files or empty directories and rejects root deletion.
- Operation log records upload, rename, and delete decisions.
- WLS multipart uploads are exposed through `WlsRequest::getFiles()`,
  `Context::fromRequest()`, and `WelineEnv::getFiles()`.

Static validation:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Framework\Http\WlsRequest.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Framework\Test\Unit\Http\WlsRequestMultipartUploadTest.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
```

Result:

- `No syntax errors detected` for the WLS request object.
- `No syntax errors detected` for the multipart upload unit test.
- `No syntax errors detected` for the WLS File Manager controller.
- `node --check` passed for the WLS Panel E2E spec.
- PHP startup still prints duplicate extension warnings from the local PHP
  configuration.

Focused unit validation:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w phpunit:run --name=WlsRequestMultipartUploadTest --phpunit
```

Result:

- `Tests: 2, Assertions: 10`
- `OK, but there were issues!` with one existing PHPUnit deprecation.
- Covered that multipart file metadata is visible through `WlsRequest`,
  `Context`, and `WelineEnv`.

Runtime:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-file-ops-9897 -p 9897 --no-ssl -c 2 --worker-memory-limit=512M
```

The instance ran on `http://p11005ce4.weline.test:9897` with dispatcher port
`9897` and two workers.

Validation command:

```powershell
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-file-ops-9897'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --headless --project=chromium
```

Result:

- `1 passed (1.5m)`
- `E2E test completed successfully.`

Operation log evidence:

```json
{"action":"upload_file","result":"success","root":"var","relative_path":"wls-file-manager-e2e-1781801155158","target":"upload-e2e.txt","message":"file_uploaded","project_id":"e2e-file-manager","domain":"e2e.wls.test","project_type":"wls"}
{"action":"rename_entry","result":"success","root":"var","relative_path":"wls-file-manager-e2e-1781801155158/upload-e2e.txt","target":"renamed-e2e.txt","message":"entry_renamed","project_id":"e2e-file-manager","domain":"e2e.wls.test","project_type":"wls"}
{"action":"delete_entry","result":"success","root":"var","relative_path":"wls-file-manager-e2e-1781801155158/renamed-e2e.txt","target":"wls-file-manager-e2e-1781801155158/renamed-e2e.txt","message":"entry_deleted","project_id":"e2e-file-manager","domain":"e2e.wls.test","project_type":"wls"}
```

Updated artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-write-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-shell-mobile.png`

Visual note:

- The native upload file selector is styled to match the dark WLS panel surface
  while preserving the browser file-input behavior used by the E2E flow.

Cleanup:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:stop ai-test-wls-file-ops-9897
```

## Security Domain Policy Expanded Coverage Smoke

Status: passed in this checkout on 2026-06-19 after expanding project-level
security policy coverage beyond the first rate/path/protected-path slice.

Scope:

- Project policy form exposes rate limit, path rate limits, path scan, SSL
  handshake failures, unknown route bans, IP whitelist, and protected paths.
- Saving the selected project policy persists new sections through the existing
  `domain_overrides.domains[domain].rules` contract.
- The inheritance map marks changed `rate_limit.max_requests`,
  `path_rate_limits.rules`, `ssl_handshake_failure.fast_close_threshold`,
  `ip_whitelist.ips`, and `protected_paths.paths` as overridden.
- Security policy audit lists changed rule sections without leaking the raw JSON
  payload.
- Desktop security panel had no horizontal overflow.

Static validation:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelSecurityDataService.php
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "sleep\(|usleep\(|\bdie\b|\bexit\b|alert\(|confirm\(|prompt\(" app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the security data service and WLS Panel template.
- `node --check` passed for the WLS Panel E2E spec.
- Forbidden-call scan returned no matches on the changed security/template/spec
  paths.
- PHP startup still prints duplicate extension warnings from the local PHP
  configuration.

Runtime:

```powershell
E:\WelineFramework\DEV-workspace\extend\server\php\php.exe bin\w server:start ai-test-wls-panel-domain-policy-9794 -p 9794 -c 2 --no-ssl
```

The instance ran on `http://p11005ce4.weline.test:9794` with dispatcher port
`9794` and two workers.

Focused browser smoke:

```powershell
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-panel-domain-policy-9794'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
# Inline Playwright script opened server/backend/wls-panel/security with
# security_scope=current, saved a project policy, checked inheritance/audit rows,
# checked horizontal overflow, and captured the artifact below.
```

Result:

- `domain policy smoke passed`
- Final URL:
  `http://127.0.0.1:9794/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/security?panel_notice=security_rules_saved&security_scope=current#project-security-policy&`

Artifact:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-domain-policy-expanded.png`

Note:

- A direct-mode full monolithic `Weline_Server-panel-shell.spec.js` attempt
  entered the test body but stopped earlier in the DbManager env-apply section
  before reaching the Security assertions. The focused smoke isolates this
  Security slice and passed against the same non-default WLS instance.

## 2026-06-19 - DbManager Env Password Import

Status: passed in this checkout after adding explicit env password import into
the WLS Database Manager Project Profile form.

Scope:

- `WlsDatabaseProfileService::saveFromPanel()` now resolves password intent in
  one place: clear stored password, manual password input, explicit env import,
  or keep existing encrypted state.
- Env import requires the operator to check the import control and type
  `COPY_ENV_PASSWORD`.
- The selected source env profile password is encrypted into the Project
  Profile without rendering the clear value.
- Audit entries record only safe state such as
  `password_action:"imported_env"` and `password_state:"configured"`.
- The standalone DbManager shell exposes the import control on desktop and
  mobile without horizontal overflow.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\DbManager\Service\WlsDatabaseProfileService.php
extend\server\php\php.exe -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app\code\Weline\DbManager\Service\WlsDatabaseProfileService.php app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/DbManager app/code/Weline/Server/doc/wls-panel-plan tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- PHP syntax checks passed for the DbManager profile service and standalone
  template.
- `node --check` passed for the WLS Panel E2E spec.
- Forbidden-call scan returned no matches on the changed service/template/spec
  paths.
- `git diff --check` exited `0`.
- PHP startup still prints duplicate extension warnings from the local PHP
  configuration.

Runtime:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-db-password-import-9896 -p 9896 --no-ssl -c 2 --worker-memory-limit=512M
```

Focused browser smoke:

```powershell
$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9896'
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-db-password-import-9896'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
$env:PLAYWRIGHT_HEADLESS='1'
# Inline Playwright script opened Weline_DbManager with project_id
# e2e-db-import-9896, checked the import controls, saved with
# COPY_ENV_PASSWORD, verified Stored in Profile, checked blank password input,
# checked audit visibility, and asserted no horizontal overflow.
```

Result:

- Desktop focused smoke passed.
- Mobile 390px focused smoke passed with no horizontal overflow.
- Audit tail for `project:e2e-db-import-9896` showed
  `password_action:"imported_env"` and no password secret field.
- Test Profile cleanup returned `{"remaining":0}`.
- `server:status ai-test-wls-db-password-import-9896` reported
  `状态：全部停止 (0/0)`.
- `Test-NetConnection 127.0.0.1 -Port 9896` returned
  `TcpTestSucceeded=False`.

Artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-password-import-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-db-manager-password-import-mobile.png`

## 2026-06-19 - Deploy Project-Scoped Status Probes

Status: passed in this checkout after wiring project context into WLS Deploy
runtime status reads.

Scope:

- `DeployWebhookReleaseService::healthPayload()` now accepts safe project
  context and overlays a project Profile only when context is present.
- No-context `health` probes keep the global runtime path and avoid project
  Profile lookup.
- `deploy/version` applies the same context-aware runtime-root behavior.
- `WlsDeploy::getIndex()` reads the current runtime stamp from the selected
  effective `deploy_root`, so child project cards can show child release state.
- WLS Panel, Marketplace, and WLS Deploy still render as standalone pages with
  desktop/mobile no-overflow behavior.

Static validation:

```powershell
php -l app/code/Weline/Deploy/Service/DeployWebhookReleaseService.php
php -l app/code/Weline/Deploy/Controller/Version.php
git diff --check -- app/code/Weline/Deploy/Service/DeployWebhookReleaseService.php app/code/Weline/Deploy/Controller/Version.php app/code/Weline/Deploy/Controller/Webhook.php app/code/Weline/Deploy/Controller/Api/Webhook.php app/code/Weline/Deploy/Controller/Backend/WlsDeploy.php app/code/Weline/Deploy/doc/wls-panel-project-webhook.md app/code/Weline/Deploy/doc/backend-config.md app/code/Weline/Deploy/doc/README.md
```

Result:

- PHP syntax checks passed for the changed Deploy service/controller files.
- `git diff --check` exited `0`.
- Local PHP still prints duplicate extension warnings and duplicate OPcache
  load warnings before successful checks.

Service validation:

```powershell
# Inline PHP probe created var/tmp/wls-panel-version-probe-0619/var/deploy/current.json,
# called DeployWebhookReleaseService::healthPayload() with and without project
# context, then removed the temporary tree.
```

Result:

```json
{
  "with_context": {
    "ok": true,
    "release_recorded": true,
    "deploy_version": "v-probe-0619",
    "release_id": "probe-release-0619",
    "profile_key": "project:probe-0619",
    "project_id": "probe-0619",
    "project_type": "wls"
  },
  "without_context": {
    "ok": true,
    "release_recorded": true,
    "deploy_version": "v-probe-0619",
    "release_id": "probe-release-0619",
    "profile_key": "project:probe-0619",
    "project_id": "probe-0619",
    "project_type": "wls"
  },
  "profile_context_calls": 1,
  "profile_build_calls": 1
}
```

Cleanup:

- `var/tmp/wls-panel-version-probe-0619` was removed.

Route validation:

```powershell
php bin/w setup:upgrade --route
php bin/w http:request U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/deploy/backend/wls-deploy --port=9524 --filter="Fatal|404|WLS|Deploy|login|no_access_reason"
php bin/w http:request U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel --port=9524 --filter="Fatal|404|WLS|Panel|login|no_access_reason"
php bin/w http:request U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace --port=9524 --filter="Fatal|404|Marketplace|插件|login|no_access_reason"
```

Result:

- The old browser URL on port `9608` was not reachable, so validation used the
  running non-default WLS instance `dev-docs-api-9524`.
- `_wls/health` returned HTTP `200` on port `9524`.
- The prefixed backend paths returned HTTP `200` and redirected unauthenticated
  requests to the backend login page instead of 404/Fatal.
- `setup:upgrade --route` eventually finished after initially timing out while
  waiting on `composer dump-autoload`; this matches the known setup-cost risk
  recorded in Stage 4.

Focused browser validation:

```powershell
# Headless Playwright using the existing local Chromium binary opened:
# - server/backend/wls-panel
# - server/backend/wls-panel/marketplace
# - deploy/backend/wls-deploy
# It logged in with the dev admin/admin account, checked desktop 1440px,
# mobile 390px, and WLS theme toggles.
```

Result:

- WLS Panel, Marketplace, and WLS Deploy pages loaded while authenticated.
- No page contained Fatal/Parse/Warning/Notice text.
- Desktop `1440px` and mobile `390px` checks had no horizontal overflow.
- WLS Panel theme toggle changed `wls_panel_theme` from `light` to `dark` and
  changed `.wls-standalone-shell` background from `rgb(238, 246, 248)` to
  `rgb(16, 23, 25)`.
- WLS Deploy theme toggle changed the page background between dark and light
  surfaces.

Temporary artifacts:

- `var/tmp/wls-panel-desktop-0619.png`
- `var/tmp/wls-marketplace-desktop-0619.png`
- `var/tmp/wls-deploy-desktop-0619.png`
- `var/tmp/wls-panel-mobile-0619.png`
- `var/tmp/wls-marketplace-mobile-0619.png`
- `var/tmp/wls-deploy-mobile-0619.png`
- `var/tmp/wls-panel-dark-0619.png`

## 2026-06-19 - WLS plugin menu default-entry normalization

Scope:

- `WlsPanelPluginDiscoveryService` now normalizes menu-only plugin entries.
- A plugin that declares `marketplace_meta.wls_panel.menu[]` with a valid
  `backend_route`, `route`, `url`, or `href` can be opened from installed plugin
  cards and operation capability cards without also duplicating
  `wls_panel_url`.
- Unsafe URL schemes remain filtered.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelPluginDiscoveryService.php
git diff --check -- app/code/Weline/Server/Service/WlsPanelPluginDiscoveryService.php
```

Result:

- PHP syntax check passed.
- Diff whitespace check passed.

Reflection probe:

```powershell
# Private method probe against WlsPanelPluginDiscoveryService::resolvePluginPanelUrl()
# and normalizeInstalledPluginItems().
```

Result:

```text
list-menu=filemanager/backend/wls-file-manager
object-menu=deploy/backend/wls-deploy
unsafe=
wls_panel_url=filemanager/backend/wls-file-manager
panel_entry_url=filemanager/backend/wls-file-manager
```

Acceptance note:

- List-style `wls_panel.menu[]` entries and object-style `wls_panel.menu`
  entries both resolve to the contributed backend route.
- `javascript:` remains rejected.
- Installed plugin records now receive normalized `wls_panel_url` and
  `panel_entry_url` when their only declared entry is inside `wls_panel.menu[]`.

Runtime and browser smoke:

```powershell
extend\server\php\php.exe bin\w server:status dev-docs-api-9524 --doctor --json
extend\server\php\php.exe bin\w server:reload dev-docs-api-9524
```

Headless browser opened:

```text
https://p11005ce4.weline.test:9524/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace
```

Result:

- The non-default `dev-docs-api-9524` instance was stable before reload.
- `server:reload dev-docs-api-9524` completed a rolling reload of 8 workers.
- Marketplace page loaded after login with no Fatal/Parse/Warning/Notice text.
- Installed plugin cards: 4 cards, 4 open-entry buttons, 0 disabled entries.
- `No panel entry declared` / `未声明面板入口` count: 0.
- Desktop `1440px` check had no horizontal overflow.
- Screenshot artifact:
  `var/tmp/wls-marketplace-entry-normalization-0619.png`.

## 2026-06-19 - AppStore return auto-refresh smoke

Scope:

- Validate the WLS-origin AppStore return URL used after plugin install/update:
  `server/backend/wls-panel/marketplace?panel_notice=plugins_refreshed&panel_auto_refresh=plugins#installed-plugins`.
- Confirm the independent panel submits `plugin-refresh` once, clears
  `panel_auto_refresh`, and keeps installed WLS plugin cards openable.
- Fix and validate WLS Panel-local redirect fragment cleanup so complete
  backend URLs are not passed through `PcController::redirect()` and given a
  trailing empty query delimiter after the hash.

Static validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
git diff --check -- app/code/Weline/Server/Controller/Backend/WlsPanel.php
extend\server\php\php.exe bin\w server:reload dev-docs-api-9524
```

Result:

- PHP syntax check passed.
- Diff whitespace check passed.
- The non-default `dev-docs-api-9524` instance completed a rolling reload of 8
  workers.

Headless browser opened:

```text
https://p11005ce4.weline.test:9524/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace?panel_notice=plugins_refreshed&panel_auto_refresh=plugins#installed-plugins
```

Browser result:

```json
{
  "refreshRequests": [
    {
      "method": "POST",
      "url": "https://p11005ce4.weline.test:9524/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/plugin-refresh"
    }
  ],
  "refreshResponses": [
    {
      "status": 302,
      "location": "https://p11005ce4.weline.test:9524/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace?panel_notice=plugins_refreshed#installed-plugins"
    }
  ],
  "result": {
    "hash": "#installed-plugins",
    "shellAutoRefresh": "",
    "notice": "面板插件能力已刷新。",
    "fatalText": false,
    "noEntryTextCount": 0,
    "cardCount": 4,
    "openCount": 4,
    "disabledCount": 0,
    "horizontalOverflow": false
  }
}
```

Screenshot artifact:

- `var/tmp/wls-marketplace-auto-refresh-clean-url-0619.png`

## 2026-06-19 - Deploy manual release guarded execution gate

Scope:

- The WLS Deploy `Manual Release Plan` section now has a guarded `Run Release`
  action without adding a new backend route.
- The execution button posts to the existing registered
  `deploy/backend/wls-deploy/manual-plan-run` route with
  `manual_action=run_release`.
- Server-side execution reloads the project Profile, re-runs preflight,
  requires `confirm_manual_release=1`, resolves the ref through
  `DeployWebhookRefResolver`, and only then calls
  `DeployOrchestratorService::release()` with `trigger=manual`.
- `danger` preflight and skipped trigger-policy contexts remain side-effect
  free.

Static and route validation:

```powershell
php -l app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
rg -n "manual-plan-run::POST|manual-release-run" generated\routers\backend_pc.php
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app\code\Weline\Deploy\Controller\Backend\WlsDeploy.php app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
git diff --check -- app/code/Weline/Deploy/doc/README.md app/code/Weline/Deploy/doc/wls-panel-project-webhook.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md
```

Result:

- PHP syntax checks passed. The local PHP runtime still emits duplicate
  extension/opcache warnings before reporting `No syntax errors detected`.
- The generated backend route table contains `manual-plan-run::POST` and no
  `manual-release-run` route, by design.
- Safety search found no `sleep`, `usleep`, `die`, `exit`, `alert()`,
  `confirm()`, or `prompt()` in the changed controller/template.
- Whitespace check passed for tracked documentation files. Git reported the
  existing LF-to-CRLF warning for `app/code/Weline/Deploy/doc/README.md`.

Runtime validation:

```powershell
php bin/w server:reload dev-docs-api-9524
php bin/w server:start ai-test-wls-deploy-manual-run-9906 -p 9906 -c 2 --no-ssl
php tests\e2e\framework\backend-session-bootstrap.php --mode=wls --username=admin --password=admin
php bin/w server:stop ai-test-wls-deploy-manual-run-9906
```

Result:

- `dev-docs-api-9524` completed a rolling reload of 8 workers.
- The dedicated no-SSL AI test instance started on port `9906` with 2 workers.
- Backend session bootstrap succeeded for the WLS test browser session.
- The dedicated test instance was stopped after validation; all non-shared
  Dispatcher/Worker processes exited through the WLS stop protocol.

Headless browser result:

Desktop `1366x768`:

```json
{
  "isLogin": false,
  "hasShell": true,
  "hasInput": true,
  "hasConfirm": true,
  "hasRun": true,
  "formAction": "http://p11005ce4.weline.test:9906/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/deploy/backend/wls-deploy/manual-plan-run",
  "runName": "manual_action",
  "runValue": "run_release",
  "blocked": "1",
  "disabledAfterRefAndConfirm": true,
  "overflowX": false,
  "heading": "Manual Release Plan"
}
```

Mobile `390x844` after theme toggle:

```json
{
  "isLogin": false,
  "hasShell": true,
  "hasToggle": true,
  "beforeTheme": "light",
  "afterTheme": "dark",
  "clientWidth": 390,
  "scrollWidth": 390,
  "overflowX": false,
  "runButtonVisible": true
}
```

Acceptance note:

- In the current checkout the selected context has `danger` preflight, so the
  `Run Release` button correctly remains disabled even after entering
  `refs/tags/v1.0.0` and checking confirmation.
- This confirms the UI cannot bypass preflight from the browser. The controller
  repeats the same preflight/confirmation/ref-policy checks before the manual
  release branch can invoke the orchestrator.

Screenshot artifacts:

- `var/wls-panel-evidence/wls-deploy-manual-release-1366.png`
- `var/wls-panel-evidence/wls-deploy-manual-release-390-dark.png`

## 2026-06-19 - ACL route-upgrade blocker cleared for WLS Deploy gate

Scope:

- Cleared the route-refresh blocker exposed while validating the WLS Deploy
  manual release gate.
- `Weline_Framework::binquery` now declares a concrete menu parent through
  `parent_source: Weline_Backend::system_service_group`, so class-level ACL
  discovery no longer fails with the framework convention error.
- `ControllerAttributes` now normalizes ACL persistence payloads before batch
  save: empty `acl_id` is omitted, `order` becomes an integer, and boolean-like
  `is_enable`, `is_backend`, and `api_exposable` values are stored as integer
  flags. This prevents PostgreSQL from receiving an empty string for integer
  ACL columns such as `is_backend`.

Static validation:

```powershell
php -l app\code\Weline\Acl\Observer\ControllerAttributes.php
php -l app\code\Weline\Framework\Controller\Api\BinQuery.php
php -l app\code\Weline\Acl\Test\Unit\Observer\ControllerAttributesTest.php
```

Result:

- PHP syntax checks passed. The local PHP runtime still prints duplicate
  extension/opcache warnings before successful syntax output.

Focused PHPUnit validation:

```powershell
php vendor\phpunit\phpunit\phpunit --bootstrap app\bootstrap_phpunit.php app\code\Weline\Acl\Test\Unit\Observer\ControllerAttributesTest.php
php vendor\phpunit\phpunit\phpunit --bootstrap app\bootstrap_phpunit.php app\code\Weline\Acl\Test\Unit\Model\AclAccessMetadataTest.php app\code\Weline\Acl\Test\Unit\Observer\ControllerAttributesTest.php
```

Result:

- `ControllerAttributesTest`: `OK (1 test, 5 assertions)`.
- ACL focused suite: `OK (4 tests, 19 assertions)`.
- The project PHPUnit bootstrap is required here so the app-code ACL observer is
  loaded instead of the vendor module copy.

Route validation:

```powershell
php bin\w setup:upgrade --route --skip-reflection-compile --skip-composer-dump --skip-background-optimize --skip-env-check
Test-Path var\process\setup_upgrade.lock
Select-String -Path generated\routers\backend_pc.php -Pattern "manual-plan-run::POST|manual-release-run"
```

Result:

- Route upgrade exited `0` in the focused fast mode and no longer reports:
  `Weline_Framework::binquery 未依附菜单 ACL`.
- Route upgrade no longer reports the PostgreSQL error:
  `SQLSTATE[22P02]: Invalid text representation ... parameter $16 = ''`.
- No `setup_upgrade.lock` remained after validation.
- `generated\routers\backend_pc.php` still contains
  `deploy/backend/wls-deploy/manual-plan-run::POST`.
- `generated\routers\backend_pc.php` does not contain
  `manual-release-run`, which is intentional because manual execution reuses
  the registered manual-plan route.

ACL persistence probe:

```json
[
  {
    "source_id": "Weline_Framework::binquery",
    "parent_source": "Weline_Backend::system_service_group",
    "type": "pc",
    "acl_origin": "menu_xml",
    "is_backend": 0,
    "is_enable": 1,
    "api_exposable": 1,
    "scope_group": "framework",
    "access_mode": "edit"
  },
  {
    "source_id": "Weline_Framework::binquery::post",
    "parent_source": "Weline_Framework::binquery",
    "type": "pc",
    "acl_origin": "menu_xml",
    "is_backend": 1,
    "is_enable": 1,
    "api_exposable": 1,
    "scope_group": "framework",
    "access_mode": "edit"
  }
]
```

Log check:

- The latest ACL and PHP error log tails did not contain the old BinQuery
  parent-source convention error or the PostgreSQL invalid-text error after the
  route-upgrade validation.

Runtime note:

- `dev-docs-api-9524` remained healthy after the route/Deploy validation with
  `server:status --doctor --json` reporting `status: stable`, `worker_count: 8`,
  `topology: dispatcher`, and no strategy warnings. A rolling reload then
  completed with all 8 workers ready and rejoined to the dispatcher.

## 2026-06-19 - Gateway direct-listen capability disclosure

Scope:

- Added direct-listen runtime capability disclosure to native Gateway Settings.
- `WlsPanelGatewaySettingsService` now returns
  `config.direct_listen_capability` from `RuntimeCapabilityDetector`.
- The panel shows capability label, runtime OS, and the operator-facing support
  message beside the saved/effective Gateway traffic-mode state.
- This keeps the `direct_listen` option visible for planning, while making the
  current runtime limitation explicit before an operator chooses restart.

Static validation:

```powershell
php -l app\code\Weline\Server\Service\WlsPanelGatewaySettingsService.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
rg -n "direct_listen_capability|Direct Listen Capability" app\code\Weline\Server
```

Result:

- PHP syntax checks passed. The local PHP runtime still prints duplicate
  extension/opcache warnings before successful syntax output.
- The service, template, and Server i18n files contain the new capability keys.

Service assertion:

```json
{
  "traffic_mode": "auto",
  "effective_traffic_mode": "auto",
  "direct_listen_capability": {
    "supported": false,
    "os_family": "Windows",
    "reuse_port_constant": false,
    "label": "Direct listen unavailable",
    "message": "This runtime cannot use SO_REUSEPORT direct listen; keep Auto or Passthrough here and validate direct mode on a supported Linux or macOS runtime."
  },
  "instance_count": 69
}
```

Runtime validation:

```powershell
php bin\w server:reload dev-docs-api-9524
php bin\w server:status dev-docs-api-9524 --doctor --json
php bin\w server:start ai-test-wls-gateway-capability-9912 -p 9912 --no-ssl -c 2 --worker-memory-limit=512M
php bin\w server:stop ai-test-wls-gateway-capability-9912
php bin\w server:status ai-test-wls-gateway-capability-9912
```

Result:

- Shared non-default instance `dev-docs-api-9524` reported `status: stable`,
  `worker_count: 8`, `topology: dispatcher`, and no doctor warnings before the
  rolling reload; the reload completed with all 8 workers ready/rejoined.
- Browser and `php bin/w http:request` probes to the shared 9524 panel path
  then hit `ERR_CONNECTION_RESET` / `Recv failure: Connection was reset`.
  This was treated as shared-instance runtime risk, so final UI evidence used
  a dedicated no-SSL AI test instance.
- Dedicated instance `ai-test-wls-gateway-capability-9912` started on port
  `9912` with 2 workers, `--no-ssl`, and `--worker-memory-limit=512M`.
- The dedicated instance was stopped after validation; status reported
  `全部停止 (0/0)`.

Browser/UI evidence:

```json
{
  "hasShell": true,
  "hasCapability": true,
  "capabilityState": "unavailable",
  "capabilityText": "直连监听能力: 直连监听不可用 运行时系统: Windows 当前运行时不能使用 SO_REUSEPORT 直连监听；请在这里保留 Auto 或 Passthrough，并在支持的 Linux 或 macOS 运行时验证 direct 模式。",
  "fatalText": false,
  "overflowX": false,
  "width": 1440,
  "scrollWidth": 1440
}
```

Screenshot artifact:

- `var/wls-panel-evidence/wls-gateway-direct-listen-capability-0619.png`

Acceptance note:

- Windows correctly reports direct-listen unavailable because the runtime lacks
  the required SO_REUSEPORT path.
- The next direct-listen task must run on Linux/macOS or another verified
  SO_REUSEPORT-capable runtime and prove shared public-port routing plus
  throughput across multiple workers.

## 2026-06-19 - FileManager operation audit filters

Scope:

- Added native audit summary and filtering to the WLS FileManager plugin shell.
- The controller now scans the latest 200 JSONL operation records and returns
  filtered latest entries plus scanned/shown/success/denied/failed counters.
- The standalone FileManager page now exposes action, result, root, and keyword
  filters while preserving the selected WLS project context and browser path.
- The underlying write-operation log format remains unchanged:
  `var/log/wls_file_manager_operations.log`.

Static validation:

```powershell
php -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
rg -n "operationAuditData|wfm-log-summary|Audit Summary|Filter Logs" app\code\Weline\FileManager
rg -n "[ \t]+$" app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml app\code\Weline\FileManager\i18n\en_US.csv app\code\Weline\FileManager\i18n\zh_Hans_CN.csv
```

Result:

- PHP syntax checks passed. The local PHP runtime still prints duplicate
  extension/opcache warnings before successful syntax output.
- Tail-whitespace scan returned no matches.
- New FileManager i18n keys are present in `en_US.csv` and `zh_Hans_CN.csv`.

Dedicated WLS validation:

```powershell
php bin\w server:start ai-test-wls-file-audit-9914 -p 9914 --no-ssl -c 2 --worker-memory-limit=512M
$env:PLAYWRIGHT_INSTANCE_NAME='ai-test-wls-file-audit-9914'
$env:PLAYWRIGHT_DISABLE_PROXY='1'
# Inline Playwright smoke using tests/e2e/framework helpers.
php bin\w server:stop ai-test-wls-file-audit-9914
php bin\w server:status ai-test-wls-file-audit-9914
```

Result:

- Dedicated instance `ai-test-wls-file-audit-9914` started on port `9914` with
  2 workers, no SSL, Dispatcher topology, and 512M worker memory.
- Browser smoke used the existing backend session bootstrap helpers and direct
  WLS target navigation.
- The instance was stopped after validation; status reported `全部停止 (0/0)`.

Browser/UI evidence:

```json
{
  "desktop": {
    "hasShell": true,
    "hasSummary": true,
    "hasFilters": true,
    "summaryText": "扫描 58 展示 20 成功 56 已拒绝 2 失败 0",
    "fatalText": false,
    "overflowX": false,
    "width": 1440,
    "scrollWidth": 1440
  },
  "filtered": {
    "resultValue": "denied",
    "queryValue": "unlikely-audit-filter-value",
    "emptyText": "没有匹配的文件操作日志。",
    "shownText": "扫描 58 展示 0 成功 56 已拒绝 2 失败 0",
    "overflowX": false,
    "fatalText": false
  },
  "mobile": {
    "hasShell": true,
    "hasSummary": true,
    "hasFilters": true,
    "resultValue": "denied",
    "overflowX": false,
    "width": 390,
    "scrollWidth": 390,
    "fatalText": false
  }
}
```

Screenshot artifacts:

- `var/wls-panel-evidence/wls-file-manager-audit-desktop-0619.png`
- `var/wls-panel-evidence/wls-file-manager-audit-mobile-0619.png`

Acceptance note:

- This completes the non-destructive audit-control slice needed before opening
  more dangerous FileManager operations such as compression or recursive delete.
- Remaining FileManager work is compression, recursive delete policy, richer
  project-level path policy editing, and optional queue-backed large-file
  operations.

## 2026-06-19 - FileManager bounded ZIP compression

Scope:

- Added guarded same-folder ZIP compression to the WLS FileManager plugin shell.
- Compression is limited to writable allowlisted roots, currently `var` and
  `pub`, and rejects project root compression, existing archive overwrite,
  symlinks, oversized source data, and too many entries.
- The bounded limits are 200 entries and 10 MB source data.
- Successful and denied attempts are recorded as `compress_entry` in
  `var/log/wls_file_manager_operations.log`.

Static and route validation:

```powershell
php -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
php -r "json_decode(file_get_contents('app/code/Weline/FileManager/etc/marketplace/meta.json')); echo json_last_error_msg();"
php -r "require 'app/bootstrap.php'; $svc=\Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Router\Service\RouteUpdateService::class); $svc->updateRoutes(['Weline_FileManager']);"
rg -n "wls-file-manager/(compress|post-compress)|wlsfilemanager/(compress|postcompress)" generated\routers\backend_pc.php
```

Result:

- PHP syntax checks passed. The local PHP runtime still prints duplicate
  extension/opcache warnings before successful syntax output.
- Marketplace metadata JSON validated with `No error`.
- Focused route refresh registered `weline_filemanager/backend/wls-file-manager/compress::POST`
  and the compatible generated aliases.
- A full `setup:upgrade --route` reflection pass timed out in this dirty local
  environment; the owned residual `setup:upgrade` and `reflection:compile`
  processes were stopped before continuing.

Dedicated WLS validation:

```powershell
php bin\w server:start ai-test-wls-file-compress-9916 -p 9916 --no-ssl --worker-memory-limit=512M
php bin\w server:stop ai-test-wls-file-compress-9916
php bin\w server:start ai-test-wls-file-compress-9920 -p 9920 --no-ssl --worker-memory-limit=512M
```

Result:

- Instance `ai-test-wls-file-compress-9916` started, but its Dispatcher port
  returned empty HTTP 503 responses while direct worker ports returned normal
  application pages. The instance was stopped and confirmed stopped.
- Instance `ai-test-wls-file-compress-9920` reproduced the same Dispatcher 503
  behavior. Direct worker port `26372` returned the login page and FileManager
  page correctly, so FileManager functional validation proceeded through the
  worker port and the Dispatcher issue remains a runtime follow-up.

HTTP functional evidence:

```text
loginStatus=302
fmStatus=200 title=Weline_FileManager hasCompress=True
compressStatus=302
zipExists=True zipBytes=397
```

ZIP entry evidence:

```text
input/alpha.txt
input/nested/
input/nested/beta.txt
```

Operation log evidence:

```json
{"action":"compress_entry","result":"success","root":"var","relative_path":"wls-file-compress-test/input","target":"input-smoke.zip","message":"archive_created"}
{"action":"compress_entry","result":"success","root":"var","relative_path":"wls-file-compress-test/input","target":"input-browser.zip","message":"archive_created"}
```

Browser/UI evidence:

```json
{
  "smokeHasCompressText": true,
  "formCount": 1,
  "afterCompressUrl": "http://127.0.0.1:26372/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?operation=files.write&root=var&path=wls-file-compress-test&wfm_notice=archive_created#browser&",
  "noticeSeen": true,
  "desktopOverflow": {
    "clientWidth": 1280,
    "scrollWidth": 1280
  },
  "mobileOverflow": {
    "clientWidth": 390,
    "scrollWidth": 390
  }
}
```

Screenshot artifacts:

- `var/wls-panel-evidence/wls-file-manager-compress-desktop-20260619.png`
- `var/wls-panel-evidence/wls-file-manager-compress-mobile-20260619.png`

Acceptance note:

- The FileManager bounded compression slice is functionally verified through
  login, rendered form, POST submission, ZIP contents, operation logs, desktop
  screenshot, mobile screenshot, and overflow checks.
- Full WLS public-port acceptance is not claimed for this slice because the
  Dispatcher returned HTTP 503 while direct workers were healthy. The Dispatcher
  issue should be routed to a WLS runtime follow-up before declaring the whole
  panel runtime fully green.

## 2026-06-19 - Dispatcher startup maintenance routing follow-up

Scope:

- Closed the public Dispatcher port follow-up raised during FileManager bounded
  compression validation.
- Root cause for the reproduced `503` was explicit framework maintenance state
  in `app/etc/env.php` (`system.maintenance=true`), plus a smaller hardening gap
  where string env values such as `"false"` could be cast to sticky maintenance.
- `ServiceOrchestrator::autoStartMaintenanceMode()` now normalizes env-style
  booleans before deciding whether startup maintenance is sticky.

Static regression:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\ServiceOrchestrator.php
extend\server\php\php.exe -l app\code\Weline\Server\Test\Unit\Service\ServiceOrchestratorStartupTest.php
extend\server\php\php.exe vendor\bin\phpunit --filter testStringFalseMaintenanceConfigDoesNotCreateStickyStartupMaintenance app\code\Weline\Server\Test\Unit\Service\ServiceOrchestratorStartupTest.php
```

Result:

- Syntax checks passed.
- PHPUnit returned `OK (1 test, 7 assertions)`.

Runtime evidence:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-dispatcher-9924 -p 9924 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
curl.exe -i --max-time 10 http://127.0.0.1:9924/
curl.exe -i --max-time 10 http://127.0.0.1:26376/
extend\server\php\php.exe bin\w maintenance:disable -n ai-test-wls-dispatcher-9924
curl.exe -i --max-time 10 http://127.0.0.1:9924/
extend\server\php\php.exe bin\w server:stop ai-test-wls-dispatcher-9924
extend\server\php\php.exe bin\w server:start ai-test-wls-dispatcher-9926 -p 9926 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
curl.exe -i --max-time 10 http://127.0.0.1:9926/
curl.exe -i --max-time 10 http://127.0.0.1:26378/
extend\server\php\php.exe bin\w server:stop ai-test-wls-dispatcher-9926
extend\server\php\php.exe bin\w server:status ai-test-wls-dispatcher-9926
```

Result:

- With `system.maintenance=true`, public port `9924` correctly returned
  `HTTP/1.1 503 Service Unavailable` with
  `X-Weline-Route-Hint: port=26476`, while direct worker `26376` returned
  `HTTP/1.1 200 OK`.
- `maintenance:disable -n ai-test-wls-dispatcher-9924` set
  `system.maintenance=false` and synced WLS Dispatcher routing; public port
  `9924` then returned `HTTP/1.1 200 OK` with
  `X-Weline-Route-Hint: port=26376`.
- Clean startup instance `ai-test-wls-dispatcher-9926` logged
  `sticky=false`, published `SET_ROUTE_TABLE role=worker` for ports
  `26378,26379`, and public port `9926` returned `HTTP/1.1 200 OK`.
- Direct worker `26378` also returned `HTTP/1.1 200 OK`.
- `server:stop ai-test-wls-dispatcher-9926` completed the IPC stop flow;
  follow-up status showed `Master status: stopped` and `all stopped (0/0)`.

Acceptance note:

- The FileManager compression slice no longer has an open Dispatcher public-port
  blocker. Public-port failure under explicit maintenance mode is expected; when
  maintenance is disabled, Dispatcher startup and runtime sync route traffic to
  business workers successfully.

## 2026-06-19 - FileManager bounded recursive delete slice

Scope:

- Added bounded recursive directory delete to the standalone
  `Weline_FileManager` WLS Panel plugin.
- Files and empty directories still require `DELETE_ENTRY`.
- Non-empty directories require the recursive checkbox plus `DELETE_TREE`.
- The recursive scanner rejects symlinks, keeps every entry inside the selected
  writable `var`/`pub` root, caps the tree at 100 entries and 10 MB, and deletes
  from leaf nodes upward.
- Operation audit now accepts and renders `delete_tree`; plugin meta adds
  `capability:files-delete-tree` and `files.delete-tree`.

Static checks:

```powershell
extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
extend\server\php\php.exe -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
extend\server\php\php.exe -r "json_decode(file_get_contents('app/code/Weline/FileManager/etc/marketplace/meta.json'), true); if (json_last_error() !== JSON_ERROR_NONE) { fwrite(STDERR, json_last_error_msg()); exit(1); } echo 'json ok';"
node --check tests\e2e\specs\backend\Weline_Server-panel-shell.spec.js
git diff --check -- app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml app/code/Weline/FileManager/i18n/en_US.csv app/code/Weline/FileManager/i18n/zh_Hans_CN.csv app/code/Weline/FileManager/etc/marketplace/meta.json app/code/Weline/FileManager/doc/README.md app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/20-plugin-tag-logic.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md tests/e2e/specs/backend/Weline_Server-panel-shell.spec.js
```

Result:

- Controller/template syntax checks passed.
- `meta.json` decoded successfully.
- Playwright spec syntax passed.
- `git diff --check` reported no whitespace errors. Git only warned that a few
  existing tracked text files will be normalized from LF to CRLF when Git
  touches them.

Runtime setup:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-file-tree-9930 -p 9930 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
curl.exe -i --max-time 15 http://127.0.0.1:9930/
extend\server\php\php.exe tests\e2e\framework\backend-session-bootstrap.php --mode=wls
curl.exe -I --max-time 20 --cookie "WELINE_SESSID=<session>" "http://127.0.0.1:9930/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?operation=files.write&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls&root=var"
```

Result:

- Instance `ai-test-wls-file-tree-9930` started with Dispatcher `9930` and
  workers `26382,26383`.
- Public `http://127.0.0.1:9930/` returned `HTTP/1.1 200 OK` with route hint
  `port=26382`.
- `server:status ai-test-wls-file-tree-9930` showed Master, Dispatcher, and two
  workers running `(3/3)`.
- Backend session bootstrap returned a valid `WELINE_SESSID`.
- Direct FileManager route returned `HTTP/1.1 200 OK`.

Browser smoke:

- A focused Playwright script opened the real FileManager page with a bootstrap
  backend cookie, created
  `var/wls-file-tree-smoke-*/tree-delete-smoke/child/payload.txt`, selected
  recursive delete, typed `DELETE_TREE`, and submitted the panel form.
- The page showed success, the target directory disappeared from the file list,
  and `fs.existsSync(targetPath)` returned `false`.
- Operation log tail recorded:

```json
{"action":"delete_tree","result":"success","root":"var","relative_path":"wls-file-tree-smoke-1781814596884/tree-delete-smoke","target":"wls-file-tree-smoke-1781814596884/tree-delete-smoke","message":"tree_deleted","project_id":"e2e-file-manager","domain":"e2e.wls.test","project_type":"wls"}
```

- A separate denial smoke tried to delete a non-empty directory with
  `DELETE_ENTRY` but without the recursive checkbox. The panel kept the
  directory on disk and showed: `此目录非空。请启用递归删除并输入 DELETE_TREE 后再删除。`
- Mobile screenshot check at 390px reported
  `scrollWidth=390` and `clientWidth=390`, so the new delete form has no
  horizontal overflow.

Screenshot artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-recursive-delete-desktop.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-file-manager-recursive-delete-mobile.png`

Full-spec note:

```powershell
extend\server\php\php.exe bin\w e2e:run specs/backend/Weline_Server-panel-shell.spec.js --project=chromium
```

- The full WLS Panel spec did not reach FileManager. It timed out in
  `loginAsAdmin()` at `tests/e2e/framework/runtime.js:506` before the feature
  path, after `page.waitForTimeout` saw the page/context close at the 120s test
  timeout.
- This is tracked as an E2E login/helper stability blocker, not as a recursive
  delete regression. The focused browser smoke above uses the same backend
  session bootstrap path and validates the FileManager feature directly.

Acceptance note:

- `WLS-OPS-003` recursive delete policy is now implemented and browser-smoked.
  Remaining FileManager work is richer per-project path policy editing and
  optional queue-backed large-file operations.

## 2026-06-18 - Native Gateway Settings Live Restart Verification

Scope:

- Verify the independent WLS Panel Gateway Settings form can submit
  `runtime_action=restart` for a selected child WLS instance without losing the
  child's current runtime shape.
- Regression target: a previous panel-triggered restart submitted a bare
  `server:start <instance> -r -f` command. The child instance then fell back to
  default HTTPS/default workers instead of returning to its original HTTP port.

Code change:

- `WlsPanelGatewaySettingsService::restartInstance()` now reads
  `ServerInstanceInfo` and raw instance JSON for the selected target, then
  builds the restart command with current port, worker count, HTTP/HTTPS mode,
  topology, and memory limits before calling `Processer::create()`.

Runtime setup:

```powershell
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-restart-host-9936 -p 9936 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
extend\server\php\php.exe bin\w server:start ai-test-wls-panel-restart-target-9938 -p 9938 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
curl.exe -i --max-time 15 http://127.0.0.1:9936/
curl.exe -i --max-time 15 http://127.0.0.1:9938/
extend\server\php\php.exe tests\e2e\framework\backend-session-bootstrap.php --mode=wls
```

Initial evidence:

- Host instance `ai-test-wls-panel-restart-host-9936` started on
  `http://127.0.0.1:9936`, Master PID `51908`, Dispatcher `9936`, workers
  `26388,26389`, status `(3/3)`.
- Target instance `ai-test-wls-panel-restart-target-9938` started on
  `http://127.0.0.1:9938`, Master PID `45416`, Dispatcher `9938`, workers
  `26390,26391`, status `(3/3)`.
- Both direct HTTP probes returned `HTTP/1.1 200 OK`.

Browser submit:

- Playwright opened
  `http://127.0.0.1:9936/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel`
  with a bootstrap backend cookie.
- The script scrolled to `#gateway-settings`, selected target
  `ai-test-wls-panel-restart-target-9938`, selected
  `gateway_traffic_mode=passthrough`, selected `runtime_action=restart`,
  disabled `apply_routes`, enabled Gateway mode, and submitted the real form.
- The browser returned to
  `...?gateway_instance=ai-test-wls-panel-restart-target-9938&panel_notice=gateway_saved#gateway-settings`.
- The page kept `.wls-standalone-shell[data-wls-shell="standalone"]`, retained
  the target selector, retained `trafficMode=passthrough`, emitted no console
  errors, and had no desktop horizontal overflow:
  `scrollWidth=1440`, `clientWidth=1440`.

Restart result:

```text
poll=1; master_pid=9732; port=9938; count=2; ssl=False; dispatcher=True; http=200
```

- Target Master PID changed from `45416` to `9732`.
- Target remained on `http://127.0.0.1:9938`.
- Target retained `count=2`, `ssl_enabled=false`, `dispatcher_enabled=true`.
- `server:status ai-test-wls-panel-restart-target-9938` showed Master,
  Dispatcher, Gateway, and two workers running `(4/4)`.
- The post-restart direct probe returned `HTTP 200`.

Responsive and theme smoke:

- A mobile Playwright smoke opened the same independent panel at `390x844` with
  dark color scheme.
- Theme toggle existed, shell stayed at `data-wls-theme="dark"`,
  `aria-pressed="true"`, and the Gateway Settings section rendered without
  horizontal overflow: `scrollWidth=390`, `clientWidth=390`.

Screenshot artifacts:

- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-gateway-restart-9938-before.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-gateway-restart-9938-after.png`
- `tests/e2e/artifacts/backend/Weline_Server/wls-panel-gateway-mobile-dark-9936.png`

Cleanup:

- Test instances are stopped after this evidence section is recorded.
- `app/etc/env.php` is restored from
  `var/tmp/wls-panel-gateway-restart-env-backup-9936.php`.

## 2026-06-19 - WLS-TAG-005 Panel-Local Plugin Registry Refresh

Objective:

- Close the remaining WLS Panel plugin refresh gap by making the panel refresh
  path local to installed `module:wls` plugins instead of depending on a full
  setup-style registry rebuild for ordinary capability reloads.

Code change:

- `WlsPanelPluginRefreshService::refreshPanelCapabilities()` now refreshes the
  module list, queries installed WLS plugins, extracts a stable unique module
  list, calls `RegistryUpdateService::updateModuleRegistriesIncremental()` for
  those modules, route-refreshes the same modules, and then reloads panel
  capabilities.
- Full `updateAllRegistries()` remains available only as a fallback when the
  incremental registry refresh reports failure or throws.
- Empty plugin lists now return `registry_mode=noop` and do not trigger
  all-module registry or route rebuilds.
- The refresh result exposes `registry_mode` and `registry_modules` for future
  UI/debug evidence.

Validation:

```powershell
extend\server\php\php.exe -l app\code\Weline\Server\Service\WlsPanelPluginRefreshService.php
```

- Result: `No syntax errors detected`.
- Local PHP emitted existing duplicate extension warnings (`curl`, `fileinfo`,
  `gd`, `mbstring`, `exif`, `openssl`, `pdo_pgsql`, `pgsql`, `sockets`, `xsl`,
  `zip`) and `Cannot load Zend OPcache - it was already loaded`.

Static source assertion:

```powershell
$path = 'app/code/Weline/Server/Service/WlsPanelPluginRefreshService.php'
$source = Get-Content -Raw -Encoding UTF8 $path
([regex]::Matches($source, 'updateModuleRegistriesIncremental')).Count
([regex]::Matches($source, 'updateAllRegistries')).Count
```

- Result: exactly one incremental registry call and exactly one global registry
  fallback call.
- Result marker: `OK WlsPanelPluginRefreshService uses incremental registry refresh with global fallback modes.`

Non-writing PHP reflection check:

```powershell
@'
<?php
require 'app/code/Weline/Server/Service/WlsPanelPluginRefreshService.php';
$service = new \Weline\Server\Service\WlsPanelPluginRefreshService();
$method = new ReflectionMethod($service, 'extractRouteModules');
$method->setAccessible(true);
$result = $method->invoke($service, [
    ['module_name' => 'Weline_FileManager'],
    ['module_name' => ''],
    ['module_name' => 'Weline_Deploy'],
    ['module_name' => 'Weline_FileManager'],
    ['module_name' => '  Weline_DbManager  '],
]);
$expected = ['Weline_DbManager', 'Weline_Deploy', 'Weline_FileManager'];
if ($result !== $expected) {
    fwrite(STDERR, 'Unexpected module extraction: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
echo 'OK extractRouteModules returns sorted unique WLS plugin module names.' . PHP_EOL;
'@ | extend\server\php\php.exe
```

- Result: `OK extractRouteModules returns sorted unique WLS plugin module names.`

Generated-file safety note:

- A full runtime POST to `plugin-refresh` intentionally writes Registry/Route
  generated files. This evidence pass avoided that side effect and validated
  the primary decision points without creating generated-file churn.

## 2026-06-19 - WLS-SEC-004 Plan State Alignment

Objective:

- Remove a stale Stage 3 plan note that still listed project-aware attack-log
  scope as remaining work for WLS-SEC-004.

Evidence:

- `WlsPanelSecurityDataService::normalizeFilters()` resolves
  `scope` / `security_scope` into a concrete domain when the selected scope is
  a managed project.
- `WlsPanelSecurityDataService::applyAttackFilters()` applies
  `AttackLog::schema_fields_DOMAIN` when the resolved domain is present.
- `WlsPanelSecurityDataService::getFilteredStatistics()` also applies the same
  domain filter for 7-day metrics.
- The WLS Panel template renders `select[name="security_scope"]`, project
  security summary cards, `#project-security-policy`, and filtered log links.

Plan update:

- `WLS-SEC-004` now states that native attack-log filters include project
  scope/domain filtering, while WLS-SEC-005 remains the dedicated project-aware
  security-scope task.

## 2026-06-19 - FileManager Project Path Policy Slice

Objective:

- Add persisted project/domain path-policy editing to the WLS FileManager
  plugin without exposing raw `project_path` values in the browser.

Implementation:

- Added `Weline\FileManager\Service\WlsFileManagerPathPolicyService`.
- Policies are stored under `var/wls-panel/file-manager-path-policy.json` and
  keyed by `project:*`, `domain:*`, or `local`.
- `WlsFileManager::postPathPolicySave()` requires the write ACL, confirmation
  checkbox, and `SAVE_PATH_POLICY` phrase.
- `rootCards()` applies saved enabled-root policy before every guarded write
  operation, so disabled roots become read-only immediately.
- FileManager marketplace meta now declares `capability:files-policy`.

Static validation:

```text
extend\server\php\php.exe -l app\code\Weline\FileManager\Service\WlsFileManagerPathPolicyService.php
extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
extend\server\php\php.exe -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
extend\server\php\php.exe -r "json_decode(file_get_contents('app/code/Weline/FileManager/etc/marketplace/meta.json'), true); ..."
git diff --check -- app/code/Weline/FileManager app/code/Weline/Server/doc/wls-panel-plan
```

- PHP reported `No syntax errors detected` for the new service, controller,
  and template.
- Marketplace meta JSON parsed successfully.
- `git diff --check` exited 0 with only existing CRLF conversion warnings for
  FileManager docs/i18n files.

Route validation:

```text
extend\server\php\php.exe bin\w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
rg -n "path-policy-save|postPathPolicySave|wls-file-manager/path-policy" generated app/code/Weline/FileManager -g "*.php" -g "*.phtml"
```

- Route refresh exited 0 and wrote
  `weline_filemanager/backend/wls-file-manager/path-policy-save::POST` to
  `generated/routers/backend_pc.php`.
- The command also reported existing environment noise: stale non-running WLS
  test instances, unavailable `chcp`/`schtasks`, and Windows scheduled-task
  permission warnings. The FileManager route update itself completed.

Service validation:

```text
php stdin script: save domain policy, read it back through
WlsFileManagerPathPolicyService::getPolicyForContext(), then remove the
temporary domain entry.
```

- Result: `policy service ok`.

Browser validation:

```text
extend\server\php\php.exe bin\w server:start ai-test-wls-file-policy-9954 -p 9954 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
extend\server\php\php.exe tests\e2e\framework\backend-session-bootstrap.php --mode=wls --username=admin --password=admin
```

- Opened the real WLS FileManager page at port `9954` with
  `domain=policy-browser.wls.test&root=pub#path-policy`.
- HTTP status was `200`, page title was `WLS 文件管理器`, and `#path-policy`
  rendered.
- Saved a temporary policy with only `var` enabled and confirmed with
  `SAVE_PATH_POLICY`.
- The page showed the saved-policy notice, and the `pub` root immediately
  rendered as `策略只读根目录` / `当前根目录只读`.
- Mobile viewport `390x900` reported `scrollWidth=390`, `bodyScrollWidth=390`,
  and `overflowing=false`.
- Screenshots were captured at:
  `var/tmp/wls-file-policy-9954-desktop.png` and
  `var/tmp/wls-file-policy-9954-mobile.png`.

Final UI smoke after the enabled-root summary refinement:

- Reopened the page with `domain=policy-browser-final.wls.test`.
- Initial enabled-root summary showed only the current configurable roots:
  `启用根 var, pub`.
- Saved the same temporary `var`-only policy and again confirmed saved notice,
  `pubReadonly=true`, `writeReadonly=true`, desktop `scrollWidth=1366`, and
  mobile `390x900` `overflowing=false`.

Cleanup:

```text
extend\server\php\php.exe bin\w server:stop ai-test-wls-file-policy-9954
extend\server\php\php.exe bin\w server:status ai-test-wls-file-policy-9954
```

- Temporary policy entry `domain:policy-browser.wls.test` was removed.
- Temporary policy entry `domain:policy-browser-final.wls.test` was removed.
- Empty temporary policy file was removed from `var/wls-panel/`.
- WLS status confirmed `Master 状态：○ 已停止`.

## 2026-06-19 - FileManager Path Policy Reset Slice

Objective:

- Add a guarded reset path so a project/domain FileManager path policy can
  return to default WLS Panel inheritance without exposing raw `project_path`
  values.

Implementation:

- `WlsFileManagerPathPolicyService::resetFromPanel()` requires
  `confirm_path_policy_reset=1` and `RESET_PATH_POLICY`.
- Reset removes the current safe context entry from
  `var/wls-panel/file-manager-path-policy.json`; when no policies remain, the
  policy store is removed.
- `WlsFileManager::postPathPolicyReset()` is write-ACL guarded, appends the
  `path_policy_reset` operation log action, and redirects back to
  `#path-policy`.
- The standalone FileManager shell now renders a second guarded reset form in
  the path-policy section. The form is disabled until a saved policy exists.

Static and route validation:

```text
extend\server\php\php.exe -l app\code\Weline\FileManager\Service\WlsFileManagerPathPolicyService.php
extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
extend\server\php\php.exe -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
extend\server\php\php.exe bin\w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
rg -n "path-policy-reset|postPathPolicyReset" generated app/code/Weline/FileManager -g "*.php" -g "*.phtml"
```

- PHP syntax checks passed for the service, controller, and template.
- Route generation wrote backend POST aliases for
  `weline_filemanager/backend/wls-file-manager/path-policy-reset` pointing to
  `postPathPolicyReset`.
- The route refresh exited 0. Output still included existing environment noise
  from duplicate PHP extension loads and missing Windows command shims, but
  the FileManager route update completed.

Service validation:

```text
php stdin script:
  save domain policy with enabled_roots=["var"]
  assert getPolicyForContext(domain).has_policy=true
  reset with RESET_PATH_POLICY
  assert has_policy=false and default roots are var,pub,project_var,project_pub
```

Result:

```json
{
  "domain": "policy-reset-unit-20260618215745.wls.test",
  "save": true,
  "reset": true,
  "has_policy_after_reset": false,
  "enabled_roots_after_reset": ["var", "pub", "project_var", "project_pub"]
}
```

Browser validation:

```text
extend\server\php\php.exe bin\w server:start ai-test-wls-file-policy-reset-9956 -p 9956 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
extend\server\php\php.exe tests\e2e\framework\backend-session-bootstrap.php --mode=wls --username=admin --password=admin
Playwright Chromium:
  open /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?operation=files.write&domain=policy-reset-browser.wls.test&root=pub#path-policy
  save var-only policy
  assert reset form enabled and pub write button disabled
  reset with RESET_PATH_POLICY
  assert reset form disabled and pub write button enabled again
  assert desktop and 390px mobile have no horizontal overflow
```

Result:

```json
{
  "ok": true,
  "roots": ["var", "pub"],
  "saveTextDisabledAfterPolicy": true,
  "saveTextEnabledAfterReset": true,
  "desktopAfterSave": {
    "innerWidth": 1366,
    "htmlScrollWidth": 1366,
    "bodyScrollWidth": 1366,
    "overflowing": false
  },
  "desktopAfterReset": {
    "innerWidth": 1366,
    "htmlScrollWidth": 1366,
    "bodyScrollWidth": 1366,
    "overflowing": false
  },
  "mobileState": {
    "innerWidth": 390,
    "htmlScrollWidth": 390,
    "bodyScrollWidth": 390,
    "overflowing": false
  }
}
```

Screenshots:

- `var/tmp/wls-file-policy-reset-before-save-9956.png`
- `var/tmp/wls-file-policy-reset-after-reset-9956.png`
- `var/tmp/wls-file-policy-reset-mobile-9956.png`

Cleanup:

```text
php stdin cleanup reset for domain=policy-reset-browser.wls.test
extend\server\php\php.exe bin\w server:stop ai-test-wls-file-policy-reset-9956
extend\server\php\php.exe bin\w server:status ai-test-wls-file-policy-reset-9956
```

- Cleanup reset returned `cleanup_success=true` and
  `has_policy_after_cleanup=false`.
- WLS stop completed through IPC, and final status showed
  `Master 状态：○ 已停止`.

## 2026-06-19 - FileManager queue-backed ZIP compression slice

Objective:

- Move the first large FileManager operation out of the WLS request path by
  queuing ZIP compression through `Weline_Queue`, while keeping destructive
  queue-backed operations out of scope.

Implementation:

- Added `Weline\FileManager\Service\WlsFileManagerLargeOperationService`.
  The service creates ZIP archives with worker-side root revalidation,
  symlink/path-escape rejection, no overwrite, partial-ZIP cleanup, and default
  limits of 2000 entries / 512 MB source data.
- Added `Weline\FileManager\Queue\WlsFileManagerLargeOperationQueue`, a
  `QueueInterface` consumer for `compress_zip` payloads.
- Added `WlsFileManager::postCompressQueue()` with
  `Weline_FileManager::wls_file_manager_write`, confirmation checkbox,
  `QUEUE_COMPRESS`, deterministic `biz_key`, duplicate pending/running queue
  detection, and JSONL `compress_queue` audit records.
- The standalone FileManager shell now has a `#queue-operations` section with
  queue summary counters, a queued ZIP form, and recent queue job status.
- FileManager README and WLS Panel plan/prototype docs now classify queued ZIP
  compression as implemented; remaining queue work is destructive-operation
  recovery semantics and broader source-tree write policy.

Static and route validation:

```text
php -l app/code/Weline/FileManager/Service/WlsFileManagerLargeOperationService.php
php -l app/code/Weline/FileManager/Queue/WlsFileManagerLargeOperationQueue.php
php -l app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
php -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
php bin/w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
php bin/w query:help queue create
php bin/w query:help queue list
```

- PHP syntax checks passed for the new service, queue consumer, controller, and
  template.
- `setup:upgrade --route` exited 0 and reported `Weline_FileManager` route
  update completed plus queue type collection completed.
- Existing environment noise remains present: duplicate PHP extension load
  warnings, WLS maintenance warnings for already-stopped historical AI test
  instances, and Windows `schtasks/chcp` PATH/permission warnings during cron
  maintenance. The FileManager route update itself completed.
- Queue query help confirms `queue.create` supports `class`, `name`, `module`,
  `content`, `auto`, and `biz_key`; `queue.list` supports `module`, `type_id`,
  `status`, `q`, and pagination.

GitNexus impact note:

```text
gitnexus impact "WlsFileManager::getIndex" --repo dev-workspace --direction upstream --depth 2
gitnexus impact "WlsFileManager::operationLogFilters" --repo dev-workspace --direction upstream --depth 2
gitnexus impact "WlsFileManager::operationMessageLabel" --repo dev-workspace --direction upstream --depth 2
gitnexus impact "WlsFileManager::capabilityCards" --repo dev-workspace --direction upstream --depth 2
```

- GitNexus returned `Target not found` for these FileManager symbols because
  the current FileManager WLS panel controller is still part of the untracked
  WLS Panel plan work. No upstream blast radius could be computed.

Service validation:

```text
php stdin create script:
  create var/tmp/wfm-queue-service-20260618221647/source
  create a.txt and b.txt
  w_query('queue', 'create', class=WlsFileManagerLargeOperationQueue, auto=false)

php bin/w queue:run --id=3474

php stdin verify script:
  w_query('queue', 'get', queue_id=3474)
  assert status=done
  assert target ZIP exists and has non-zero size
  remove var/tmp/wfm-queue-service-20260618221647
```

Result:

```json
{
  "queue_id": 3474,
  "status": "done",
  "zip_exists": true,
  "zip_size": 231
}
```

- Queue run output included:
  `WLS 文件管理器队列压缩完成：source-queued.zip（2 个条目，9 字节）。`
- The verification script cleaned the temporary source/ZIP directory after the
  assertion passed.

Browser validation:

```text
php bin/w server:start ai-test-wls-file-queue-9960 -p 9960 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
php tests/e2e/framework/backend-session-bootstrap.php --mode=wls --username=admin --password=admin
Playwright Chromium:
  open /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?operation=files.write&root=var&path=tmp/wfm-queue-browser-20260619062020#queue-operations
  assert #queue-operations and data-wfm-compress-queue-form exist
  toggle dark theme and assert data-theme=dark
  submit source path tmp/wfm-queue-browser-20260619062020/source with QUEUE_COMPRESS
  assert queue-created notice is visible
  assert desktop 1366px and mobile 390px have no horizontal overflow
  query recent Weline_FileManager queue, run queue:run --id=3475
  refresh panel and assert #3475 is visible with done/已完成 status
```

Result:

```json
{
  "browser_submit": {
    "ok": true,
    "theme": "dark",
    "desktopOverflow": {
      "innerWidth": 1366,
      "htmlScrollWidth": 1366,
      "bodyScrollWidth": 1366,
      "overflowing": false
    },
    "mobileOverflow": {
      "innerWidth": 390,
      "htmlScrollWidth": 390,
      "bodyScrollWidth": 390,
      "overflowing": false
    }
  },
  "queue_run": {
    "queue_id": 3475,
    "status": "done",
    "zip_exists": true,
    "zip_size": 289
  },
  "panel_done_refresh": {
    "ok": true,
    "queue_id": 3475
  }
}
```

Screenshots:

- `var/tmp/wls-file-queue-desktop-9960.png`
- `var/tmp/wls-file-queue-mobile-9960.png`
- `var/tmp/wls-file-queue-done-9960.png`

Cleanup:

```text
Remove-Item var/tmp/wfm-queue-browser-20260619062020 -Recurse -Force
php bin/w server:stop ai-test-wls-file-queue-9960
php bin/w server:status ai-test-wls-file-queue-9960
```

- The temporary browser source/ZIP directory no longer exists.
- WLS stop completed through IPC.

## 2026-06-19 - FileManager recoverable queued trash slice

Objective:

- Add the first queue-backed destructive FileManager path without permanent
  deletion: move selected files/directories into same-root `.wls-trash`, then
  restore completed jobs from the recent queue list using server-side queue
  payload.

Implementation:

- Extended `Weline\FileManager\Service\WlsFileManagerLargeOperationService`
  with `moveToTrash()` and `restoreFromTrash()`. Both paths re-check root
  containment, reject symlinks/path escapes, and use bounded scan limits.
- Extended `Weline\FileManager\Queue\WlsFileManagerLargeOperationQueue` with
  `trash_entry`; the worker stores `trash_path`, `trash_relative_path`,
  `trash_entries`, and `trash_bytes` back into queue content.
- Added `WlsFileManager::postTrashQueue()` and
  `WlsFileManager::postTrashRestore()`. Queue creation requires
  `QUEUE_TRASH`; restore requires `RESTORE_TRASH` and loads the queue payload
  by `queue_id` instead of trusting a browser-submitted absolute path.
- Updated the standalone FileManager `#queue-operations` section with a
  recoverable-trash queue card and a per-job Restore action.
- Added `capability:files-trash` / `files.trash` marketplace metadata and
  i18n entries for the new panel strings.
- Updated FileManager README plus WLS Panel plan/prototype docs so remaining
  work is permanent purge, richer restore history, richer editing, and broader
  source-tree write policy.

Static and route validation:

```text
php -l app/code/Weline/FileManager/Service/WlsFileManagerLargeOperationService.php
php -l app/code/Weline/FileManager/Queue/WlsFileManagerLargeOperationQueue.php
php -l app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
php -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
php bin/w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
rg -n "trash-queue|trash-restore" generated/routers/backend_pc.php
rg -n "sleep\(|usleep\(|\bdie\b|\bexit\b|alert\(|confirm\(|prompt\(" app/code/Weline/FileManager/Service/WlsFileManagerLargeOperationService.php app/code/Weline/FileManager/Queue/WlsFileManagerLargeOperationQueue.php app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
git diff --check -- app/code/Weline/FileManager/Service/WlsFileManagerLargeOperationService.php app/code/Weline/FileManager/Queue/WlsFileManagerLargeOperationQueue.php app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml app/code/Weline/FileManager/doc/README.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
```

- Syntax checks passed for service, queue consumer, controller, and template.
- Route refresh exited 0 and generated:
  `weline_filemanager/backend/wls-file-manager/trash-queue::POST`,
  `post-trash-queue::POST`, `trash-restore::POST`, and
  `post-trash-restore::POST`.
- Forbidden API scan produced no matches.
- `git diff --check` passed with only an existing LF/CRLF warning on docs.
- Existing environment noise remains: duplicate PHP extension load warnings,
  historical stopped WLS maintenance warnings during `setup:upgrade`, and
  Windows `schtasks/chcp` PATH/permission warnings during cron maintenance.
  The FileManager route update itself completed.

Service validation:

```text
php stdin create script:
  create var/tmp/wfm-trash-service-20260618224300-61fc96/source/old.txt
  w_query('queue', 'create', class=WlsFileManagerLargeOperationQueue, operation=trash_entry, auto=false)

php bin/w queue:run --id=3476 --force

php stdin verify script:
  w_query('queue', 'get', queue_id=3476)
  assert status=done
  assert source path removed
  assert trash_path exists and starts with .wls-trash/
  call WlsFileManagerLargeOperationService::restoreFromTrash()
  assert source path restored
  cleanup var/tmp/wfm-trash-service-20260618224300-61fc96
```

Result:

```json
{
  "queue_id": 3476,
  "status": "done",
  "trash_relative_path": ".wls-trash/20260618-224301-b4a902e579bb-old.txt",
  "trash_entries": 1,
  "trash_bytes": 62,
  "restored": true,
  "assertions": 7
}
```

Browser validation:

```text
php bin/w server:start ai-test-wls-file-trash-9964 -p 9964 -c 1 --no-ssl --runtime-strategy compatibility
php tests/e2e/framework/backend-session-bootstrap.php --mode=wls --username=admin --password=admin
Playwright Chromium:
  open /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?operation=files.write&root=var&path=tmp/wfm-trash-browser-20260618224709-1dae62#queue-operations
  assert data-wfm-trash-queue-form exists
  submit entry path tmp/wfm-trash-browser-20260618224709-1dae62/trash-me.txt with QUEUE_TRASH
  locate queue #3478, run queue:run when still pending
  assert source removed and queue trash_path exists
  refresh panel and restore from the #3478 data-wfm-trash-restore-form
  assert source restored
  assert desktop 1366px and mobile 390px have no horizontal overflow
```

Result:

```json
{
  "queue_id": 3478,
  "status": "done",
  "trash_relative_path": ".wls-trash/20260618-224715-cdfe69393536-trash-me.txt",
  "restored": true,
  "desktop_overflow": 0,
  "mobile_overflow": 0
}
```

Screenshots:

- `var/tmp/wfm-trash-browser-20260618224709-1dae62-desktop.png`
- `var/tmp/wfm-trash-browser-20260618224709-1dae62-mobile.png`

Cleanup:

```text
cleanup var/tmp/wfm-trash-browser-20260618224709-1dae62 and matching .wls-trash test item
php bin/w server:stop ai-test-wls-file-trash-9964
php bin/w server:status ai-test-wls-file-trash-9964
```

- WLS stop completed through IPC.
- Final status showed `Master 状态：○ 已停止` and `状态：全部停止 (0/0)`.

## 2026-06-19 - FileManager Recoverable Trash History Slice

Scope:

- Added a dedicated recoverable trash history view to the WLS FileManager panel.
- General recent queue jobs remain capped at the latest 10 entries.
- Trash history is derived from the same queue query and shows up to 30
  `trash_entry` rows with restore availability state:
  restorable, waiting, blocked by existing restore target, unavailable, or
  failed.
- Restore still uses only the server-side queue payload plus `queue_id` and the
  existing `RESTORE_TRASH` confirmation form.

Static validation:

```text
php -l app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
php -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
php -l app/code/Weline/FileManager/Service/WlsFileManagerLargeOperationService.php
php -l app/code/Weline/FileManager/Queue/WlsFileManagerLargeOperationQueue.php
```

Result:

```text
No syntax errors detected in all four files.
PHP emitted duplicate-extension warnings from the local php.ini, but the lint
commands exited 0.
```

i18n and forbidden API validation:

```text
CSV parse check:
  app/code/Weline/FileManager/i18n/en_US.csv rows=347
  app/code/Weline/FileManager/i18n/zh_Hans_CN.csv rows=346

Forbidden API scan:
  rg "\b(alert|confirm|prompt|sleep|usleep|die|exit)\s*\("
```

Result:

```text
CSV files parsed successfully. The row-count difference already exists in this
module line, but all new history keys are present in both language files.
Forbidden API scan returned no matches.
```

Service validation:

```text
php stdin create script:
  create var/tmp/wfm-history-service-*/history-target.txt
  w_query('queue', 'create', class=WlsFileManagerLargeOperationQueue, operation=trash_entry, auto=false)

php bin/w queue:run --id=3479 --force

php stdin verify script:
  w_query('queue', 'get', queue_id=3479)
  assert status=done
  reflect WlsFileManager::queueOperationData()
  assert trash_history contains queue #3479
  assert restore_state=available and can_restore=1
  restore through WlsFileManagerLargeOperationService::restoreFromTrash()
  cleanup var/tmp/wfm-history-service-*
```

Result:

```json
{
  "queue_id": 3479,
  "status": "done",
  "trash_relative_path": ".wls-trash/20260619-013710-d812ee612e10-history-target.txt",
  "history_state": "available",
  "history_label": "可恢复回收项",
  "available_restore": 1,
  "restored": true
}
```

Browser validation:

```text
php bin/w server:start ai-test-wls-file-history-9976 -p 9976 -c 1 --no-ssl --runtime-strategy compatibility --worker-memory-limit=512M
php tests/e2e/framework/backend-session-bootstrap.php --mode=wls --username=admin --password=admin
Playwright Chromium:
  open /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?operation=files.write&root=var&path=tmp/wfm-history-browser-*
  assert data-wfm-trash-queue-form exists
  submit entry path tmp/wfm-history-browser-*/trash-history.txt with QUEUE_TRASH
  locate queue #3482 from queue payload and run queue:run when pending
  reload panel
  assert data-wfm-trash-history exists
  assert history includes #3482 and a restorable state
  restore using the data-wfm-trash-history restore form
  assert source file restored
  assert desktop 1366px and mobile 390px have no horizontal overflow
```

Result:

```json
{
  "queue_id": 3482,
  "history_visible": true,
  "restored": true,
  "desktop_overflow": 0,
  "mobile_overflow": 0
}
```

Screenshots:

- `var/tmp/wfm-history-browser-20260619014410-f6c98a-desktop.png`
- `var/tmp/wfm-history-browser-20260619014410-f6c98a-mobile.png`

Cleanup:

```text
php bin/w server:stop ai-test-wls-file-history-9976
php bin/w server:status ai-test-wls-file-history-9976
delete temporary queue rows #3479, #3480, #3481, #3482
```

- WLS stop completed through IPC.
- Final status showed `Master 状态：○ 已停止` and `状态：全部停止 (0/0)`.
- Temporary queue rows created by service/browser/debug validation were deleted
  through `w_query('queue', 'delete', ...)`.

## 2026-06-19 FileManager Permanent Trash Purge Slice

Scope:

- Added the first permanent purge path for queue-created WLS FileManager trash
  entries.
- Purge is intentionally narrower than restore: it reloads the recorded queue
  payload by `queue_id`, requires `PURGE_TRASH`, rejects symlinks, rejects the
  `.wls-trash` root itself, re-checks root boundaries, and updates the queue
  payload with `trash_purged_at` after deletion.

Static validation:

```text
php -l app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
php -l app/code/Weline/FileManager/Service/WlsFileManagerLargeOperationService.php
php -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml

CSV parse check:
  app/code/Weline/FileManager/i18n/en_US.csv OK (360 rows)
  app/code/Weline/FileManager/i18n/zh_Hans_CN.csv OK (359 rows)

php bin/w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
```

Result:

```text
All three PHP lint checks passed.
The FileManager route upgrade completed and generated the new backend POST
route for `weline_filemanager/backend/wls-file-manager/trash-purge`.
The route command emitted local duplicate-extension and scheduled-task
environment warnings, but exited 0 and wrote the router file.
```

Service validation:

```text
php stdin service script:
  create var/tmp/wls-purge-*/.wls-trash/20260619-purge-target.txt
  call WlsFileManagerLargeOperationService::purgeTrash()
  assert file purge succeeds
  assert .wls-trash root purge is rejected
  assert outside-root target is rejected
```

Result:

```json
{
  "ok": true,
  "purge": {
    "success": true,
    "result": "success",
    "error_code": "",
    "entries": 1,
    "bytes": 8
  },
  "root_guard": "trash_root_purge_forbidden",
  "outside_guard": "trash_entry_invalid"
}
```

Browser validation:

```text
php bin/w server:start -p 9978 -n ai-test-wls-file-purge-9978 -m 512M
php tests/e2e/framework/backend-session-bootstrap.php --mode=wls
Playwright Chromium using bundled Codex runtime:
  open /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/CNY/zh_Hans_CN/weline_filemanager/backend/wls-file-manager?operation=files.write&root=var&path=tmp/wls-file-purge-e2e-*
  submit queue trash form with QUEUE_TRASH
  locate queue id from the visible trash history row
  run php bin/w queue:run --id=<queue_id>
  reload the FileManager panel
  submit the row-level purge form with PURGE_TRASH
  assert queue content includes trash_purged_at
  assert the recorded .wls-trash target no longer exists
  assert trash history exposes the purged state
  assert desktop 1440px and mobile 390px have no horizontal overflow
```

Result:

```json
{
  "ok": true,
  "queueId": 3485,
  "token": "wls-file-purge-e2e-mqkaogh5",
  "desktopOverflow": 0,
  "mobileOverflow": 0,
  "purgedAt": "2026-06-19 02:13:41"
}
```

Cleanup:

```text
w_query('queue', 'delete', ['queue_id' => 3485, 'force' => true])
Remove-Item var/tmp/wls-file-purge-e2e-* after verifying targets remain inside var/tmp
```

## 2026-06-19 PhpManager Runtime Inheritance Map Slice

Scope:

- Added a read-only PHP Profile inheritance map to `Weline_PhpManager`.
- The map compares current runtime values, project Profile values, effective
  values, source labels, override/alignment state, and required-extension
  satisfaction before any php.ini apply or WLS reload action.
- This slice does not install/remove extensions and does not broaden php.ini
  write permissions.

GitNexus:

```json
{
  "target": "WlsPhpProfileService",
  "risk": "UNKNOWN",
  "reason": "new Weline_PhpManager module is not present in the 2026-06-14 dev-workspace index"
}
```

Static validation:

```text
php -l app/code/Weline/PhpManager/Service/WlsPhpProfileService.php
php -l app/code/Weline/PhpManager/Controller/Backend/WlsPhpManager.php
php -l app/code/Weline/PhpManager/view/templates/Backend/WlsPhpManager/index.phtml

app/code/Weline/PhpManager/i18n/en_US.csv OK 26 rows
app/code/Weline/PhpManager/i18n/zh_Hans_CN.csv OK 26 rows
```

Service validation:

```json
{
  "ok": true,
  "summary": {
    "total": 9,
    "inherited": 9,
    "overridden": 0,
    "aligned": 0,
    "attention": 0
  },
  "rows": 9,
  "states": [
    "inherited"
  ],
  "first": "PHP Binary"
}
```

Browser validation:

```text
php bin/w server:start -p 9980 -n ai-test-wls-php-inheritance-9980 -m 512M
php tests/e2e/framework/backend-session-bootstrap.php --mode=wls --username=admin --password=admin
Playwright Chromium using bundled Codex runtime:
  open /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/CNY/zh_Hans_CN/weline_phpmanager/backend/wls-php-manager?operation=php-profile&project_id=codex-inheritance&domain=codex.local&project_type=wls
  assert [data-wpm-inheritance-map] exists
  assert 9 inheritance rows render
  toggle dark -> light theme
  assert desktop 1440px and mobile 390px have no horizontal overflow
```

Result:

```json
{
  "ok": true,
  "desktop": {
    "title": "WLS PHP Manager",
    "inheritanceTitle": "PHP 配置继承地图",
    "rows": 9,
    "states": [
      "inherited"
    ],
    "summaries": [
      "9",
      "9",
      "0",
      "0"
    ],
    "themeBefore": "dark",
    "overflow": 0
  },
  "themeAfter": "light",
  "mobile": {
    "overflow": 0,
    "rowWidth": 320,
    "viewport": 390
  },
  "consoleErrors": []
}
```

Cleanup and scope check:

```text
php bin/w server:stop -n ai-test-wls-php-inheritance-9980
php bin/w server:list | rg "ai-test-wls-php-inheritance-9980|dev-docs-api-9524|总计"
```

Result:

```text
ai-test-wls-php-inheritance-9980 stopped on port 9980.
Existing dev-docs-api-9524 remained running.
```

Diff / graph checks:

```text
git diff --check -- <PhpManager inheritance files and WLS Panel plan docs>
gitnexus detect-changes --repo dev-workspace --scope all
```

Result:

```text
git diff --check passed.
GitNexus detect-changes reported the known broad dirty workspace:
134 files, 426 symbols, 6 affected processes, risk high. This includes
pre-existing WLS Panel/Deploy/FileManager/AppStore/framework work outside this
PhpManager inheritance slice; the local impact target for WlsPhpProfileService
and WlsPhpManager remains UNKNOWN because the new Weline_PhpManager module is
not present in the 2026-06-14 dev-workspace index.
```

## 2026-06-19 - DbManager Existing Slave Env Apply Guard

Scope:

- Weline_DbManager now supports applying project database config back to an
  existing `db.slaves.*` entry, in addition to the existing direct `db` and
  `db.master/default` paths.
- Slave writes are intentionally guarded: the apply path updates only an
  already configured `db.slaves.<key>` target and does not create, delete, or
  reorder slave entries.
- The WLS Database Manager panel now shows the write mode and a warning when
  the selected target is an existing slave profile.

GitNexus:

```json
{
  "Weline\\DbManager\\Service\\WlsDatabaseEnvApplyService": {
    "risk": "UNKNOWN",
    "reason": "new Weline_DbManager module is not present in the current dev-workspace index"
  },
  "Weline\\DbManager\\Controller\\Backend\\WlsDbManager": {
    "risk": "UNKNOWN",
    "reason": "new Weline_DbManager module is not present in the current dev-workspace index"
  }
}
```

Static validation:

```text
extend/server/php/php.exe -l app/code/Weline/DbManager/Service/WlsDatabaseEnvApplyService.php
extend/server/php/php.exe -l app/code/Weline/DbManager/view/templates/Backend/WlsDbManager/index.phtml

app/code/Weline/DbManager/i18n/en_US.csv OK 175 rows
app/code/Weline/DbManager/i18n/zh_Hans_CN.csv OK 175 rows

rg "sleep|usleep|die|exit|alert|confirm|prompt" on changed service/template: no matches
git diff --check -- app/code/Weline/DbManager app/code/Weline/Server/doc/wls-panel-plan: passed
```

Service validation:

```json
{
  "save_success": true,
  "target_mode": "slave",
  "target_label": "db.slaves.codex_slave",
  "change_count": 2,
  "backup_created": true,
  "runtime_action": "none",
  "env_updated": true
}
```

Cleanup after the service assertion:

```json
{
  "codex_slave_present": false,
  "temp_profile_count": 0
}
```

Route refresh:

```text
php bin/w setup:upgrade --stage=schema_diff,route_update -m Weline_DbManager
```

The full setup command exceeded the local 125s command window in this dirty
workspace and the matching setup process was stopped. A focused route refresh
was then executed through `RouteUpdateService::updateRoutes(['Weline_DbManager'])`.
Generated backend routes now include:

```text
weline_dbmanager/backend/wls-db-manager::GET
weline_dbmanager/backend/wls-db-manager/env-apply::POST
weline_dbmanager/backend/wls-db-manager/env-rollback::POST
weline_dbmanager/backend/wls-db-manager/profile-save::POST
weline_dbmanager/backend/wls-db-manager/test-connection::POST
```

HTTP probe:

```json
{
  "curl": "HTTP:200 SIZE:82323",
  "has_shell": true,
  "has_guard": true,
  "has_slave": true,
  "has_login": false,
  "has_404": false,
  "title_hint": "Weline_DbManager"
}
```

Browser validation:

```text
extend/server/php/php.exe bin/w server:start ai-test-wls-db-slave-9982 -p 9982 --no-ssl -c 2 --worker-memory-limit=512M
Playwright Chromium with PLAYWRIGHT_INSTANCE_NAME=ai-test-wls-db-slave-9982 and PLAYWRIGHT_DISABLE_PROXY=1:
  open weline_dbmanager/backend/wls-db-manager?operation=database-profile&project_id=codex-db-slave-browser&domain=codex-db-slave-browser.wls.test&project_type=wls&connection_key=codex_browser_slave
  assert [data-wls-db-manager-shell] visible
  assert [data-wdb-env-plan][data-wdb-env-can-apply="1"] visible
  assert [data-wdb-slave-write-guard] visible
  assert page contains db.slaves.codex_browser_slave
  assert write mode contains Existing slave / 已有 slave
  assert desktop 1440x900 and mobile 390x844 have no horizontal overflow
  toggle light -> dark -> light
```

Result:

```json
{
  "ok": true,
  "desktop": {
    "status": 200,
    "overflow": {
      "doc": 0,
      "body": 0
    },
    "beforeTheme": "light",
    "afterTheme": "dark",
    "restoredTheme": "light"
  },
  "mobile": {
    "status": 200,
    "overflow": {
      "doc": 0,
      "body": 0
    },
    "beforeTheme": "light",
    "afterTheme": "dark",
    "restoredTheme": "light"
  },
  "sidebarOverflow": 0,
  "errors": []
}
```

Screenshots:

```text
tests/e2e/artifacts/backend/wls-dbmanager-existing-slave-desktop.png
tests/e2e/artifacts/backend/wls-dbmanager-existing-slave-mobile.png
```

Runtime cleanup:

```json
{
  "server_instance": "ai-test-wls-db-slave-9982",
  "status": "stopped",
  "env_restored": true,
  "backup_exists": false,
  "codex_browser_slave_present": false,
  "temp_profile_count": 0,
  "maintenance": false
}
```

## 2026-06-19 - WLS Panel Plugin Refresh Result Summary

Scope:

- The independent WLS Panel marketplace now shows a structured result summary
  after plugin refresh, so module install/update reload is visible in the panel
  instead of being a notice-only redirect.
- The POST refresh redirect carries registry mode/count, route refresh/count,
  installed WLS plugin count, and panel menu contribution count.
- The summary is responsive and follows the existing light/dark theme shell.

Code touched:

```text
app/code/Weline/Server/Controller/Backend/WlsPanel.php
app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
app/code/Weline/Server/i18n/en_US.csv
app/code/Weline/Server/i18n/zh_Hans_CN.csv
app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md
app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md
```

Static validation:

```text
extend/server/php/php.exe -l app/code/Weline/Server/Controller/Backend/WlsPanel.php
extend/server/php/php.exe -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml

app/code/Weline/Server/i18n/en_US.csv OK 2489
app/code/Weline/Server/i18n/zh_Hans_CN.csv OK 2489

rg "\b(alert|confirm|prompt)\s*\(|\b(fetch|XMLHttpRequest|axios|\$\.ajax)\b" on changed controller/template:
  app/code/Weline/Server/Controller/Backend/WlsPanel.php: PHP fetch('index') only

git diff --check -- changed WLS Panel source/i18n files: passed
```

Runtime setup:

```text
extend/server/php/php.exe bin/w server:start ai-test-wls-plugin-refresh-9987 -p 9987 --no-ssl -c 2 --worker-memory-limit=512M
```

The first 9986 runtime probe hit the local maintenance page. For validation
only, `app/etc/env.php` was backed up under `var/tmp/codex-wls-plugin-refresh`,
`maintenance:disable` was run, and a fresh 9987 instance was started. The
original env file was restored after browser validation.

Browser validation:

```json
{
  "initialStatus": 200,
  "initialUrl": "http://p11005ce4.weline.test:9987/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace",
  "refreshUrl": "http://p11005ce4.weline.test:9987/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace?panel_plugin_refresh=1&panel_plugin_refresh_registry_mode=incremental&panel_plugin_refresh_registry_count=4&panel_plugin_refresh_routes=1&panel_plugin_refresh_route_count=4&panel_plugin_refresh_plugin_count=4&panel_plugin_refresh_contribution_count=4&panel_notice=plugins_refreshed#installed-plugins",
  "summaryText": "能力重载 面板插件刷新结果 路由已刷新 注册表模式 增量 注册表模块 4 路由模块 4 WLS 插件 4 面板菜单入口 4",
  "routeFlag": "1",
  "theme": {
    "before": "light",
    "after": "dark"
  },
  "desktopOverflow": {
    "width": 1440,
    "scrollWidth": 1440,
    "bodyScrollWidth": 1440
  },
  "mobileOverflow": {
    "width": 390,
    "scrollWidth": 390,
    "bodyScrollWidth": 390,
    "sidebarScrollWidth": 358,
    "sidebarClientWidth": 358
  }
}
```

Screenshots:

```text
tests/e2e/artifacts/backend/wls-panel-plugin-refresh-desktop.png
tests/e2e/artifacts/backend/wls-panel-plugin-refresh-mobile.png
```

Runtime cleanup:

```text
extend/server/php/php.exe bin/w server:stop ai-test-wls-plugin-refresh-9987

Get-CimInstance Win32_Process ... '*ai-test-wls-plugin-refresh-9987*': no output
var/tmp/codex-wls-plugin-refresh: no backup file remains
app/etc/env.php restored from the pre-validation backup
```

## 2026-06-19 - Gateway Direct Listen Unsupported Runtime Guard

Scope:

- The Gateway Settings form can save `direct_listen` as the desired traffic
  mode, but unsupported runtimes must not submit a panel-triggered restart that
  is already known to fail.
- Local validation ran on Windows, where `RuntimeCapabilityDetector` reports no
  SO_REUSEPORT support, so this slice is a negative capability proof and guard
  check. The remaining `direct_listen` work is shared-port routing and
  throughput validation on Linux/macOS or another supported runtime.

Code touched:

```text
app/code/Weline/Server/Service/WlsPanelGatewaySettingsService.php
app/code/Weline/Server/i18n/en_US.csv
app/code/Weline/Server/i18n/zh_Hans_CN.csv
app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md
```

Static validation:

```text
extend/server/php/php.exe -l app/code/Weline/Server/Service/WlsPanelGatewaySettingsService.php

app/code/Weline/Server/i18n/en_US.csv OK 2490
app/code/Weline/Server/i18n/zh_Hans_CN.csv OK 2490
```

Runtime capability probe:

```json
{
  "os_family": "Windows",
  "supports_reuse_port": false,
  "reuse_port_constant": false
}
```

Service-level save/restart guard validation:

```json
{
  "input": {
    "gateway_traffic_mode": "direct_listen",
    "runtime_action": "restart",
    "apply_routes": "0",
    "gateway_instance": "ai-test-wls-direct-listen-missing"
  },
  "assertions": {
    "saved": true,
    "traffic_mode_direct": true,
    "runtime_action_restart": true,
    "runtime_action_blocked": true,
    "runtime_action_not_success": true,
    "restart_required": true,
    "message_mentions_reuseport": true
  },
  "pass": true
}
```

Dedicated WLS negative start validation:

```text
extend/server/php/php.exe bin/w server:start ai-test-wls-direct-listen-9988 -p 9988 --no-ssl -c 2 --topology direct --worker-memory-limit=512M

Observed runtime error:
WLS direct topology requires SO_REUSEPORT on this OS/kernel.
```

Runtime cleanup:

```text
extend/server/php/php.exe bin/w server:stop ai-test-wls-direct-listen-9988
server:stop reported the instance did not exist after the failed direct start.
Get-CimInstance Win32_Process ... '*ai-test-wls-direct-listen-9988*': no output after a separate cleanup check.
app/etc/env.php restored from the pre-validation backup.
var/tmp/codex-wls-direct-listen-validation removed after restore.
```

## 2026-06-19 - Gateway Direct Listen Feasibility Refresh

Scope:

- Rechecked the `WLS-GW-PERF-001` direct-listen requirement in the current
  Windows workspace before attempting any shared-port throughput work.
- This is a negative capability confirmation only. It does not replace the
  required Linux/macOS direct-listen routing and throughput proof.

Source boundaries checked:

```text
app/code/Weline/Server/Service/Runtime/RuntimeCapabilityDetector.php
app/code/Weline/Server/Service/Runtime/RuntimeStrategyResolver.php
app/code/Weline/Server/Service/WlsPanelGatewaySettingsService.php
app/code/Weline/Server/Console/Server/Start.php
```

Runtime capability probe:

```json
{
    "php": "8.4.16",
    "os_family": "Windows",
    "os": "WINNT",
    "sockets": true,
    "so_reuseport_constant": false
}
```

Direct topology start probe:

```powershell
php bin/w server:start ai-test-wls-direct-feas-9994 -p 9994 --no-ssl -c 2 --topology direct --worker-memory-limit=512M
```

Observed result:

```text
WLS direct topology requires SO_REUSEPORT on this OS/kernel.
```

Cleanup evidence:

```powershell
php bin/w server:stop ai-test-wls-direct-feas-9994
Test-NetConnection -ComputerName 127.0.0.1 -Port 9994
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object { ($_.CommandLine -like '*ai-test-wls-direct-feas-9994*') }
rg -n "ai-test-wls-direct-feas-9994" app/etc var app/code/Weline/Server/doc/wls-panel-plan dev/ai/codex/tasks/2026-06-19/2026-06-19-0737-wls-gateway-direct-listen-feasibility
```

Result:

- `server:stop` reported the instance did not exist after the failed direct
  start, which matches the resolver blocking before process spawn.
- Port `9994` returned `TcpTestSucceeded=False`.
- The php.exe command-line filter returned no matching process.
- The repo/runtime state search returned no matches for the test instance name.
- `WLS-GW-PERF-001` remains open for a supported Linux/macOS runner where
  `RuntimeCapabilityDetector` reports `supports_reuse_port=true`.

## 2026-06-19 - FileManager Preview-To-Guarded-Edit Slice

Scope:

- `Weline_FileManager` can now promote an already previewed text file into the
  existing guarded `SAVE_TEXT` form when all write checks pass.
- The slice deliberately reuses the existing save endpoint, ACL, confirmation
  checkbox, `SAVE_TEXT` phrase, overwrite checkbox, 128 KB cap, and safe text
  extension allowlist.
- Source-code previews remain read-only: PHP/PHTML/JS/SQL/`.env` previews do
  not expose the edit action and render a blocker reason instead.

Code touched:

```text
app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
app/code/Weline/FileManager/i18n/en_US.csv
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv
app/code/Weline/FileManager/doc/README.md
app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md
app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
```

GitNexus impact:

```json
{
  "target": "Weline\\FileManager\\Controller\\Backend\\WlsFileManager",
  "repo": "dev-workspace",
  "risk": "UNKNOWN",
  "reason": "Target not found in the current GitNexus index; the controller/template files are part of the newer WLS FileManager slice."
}
```

Static validation:

```text
extend\server\php\php.exe -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
No syntax errors detected in app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php

extend\server\php\php.exe -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
No syntax errors detected in app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml

app/code/Weline/FileManager/i18n/en_US.csv OK 367
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv OK 366

git diff --check -- <changed FileManager/WLS Panel Plan files>
exit 0
```

Runtime setup:

```text
extend\server\php\php.exe bin\w server:start ai-test-wls-file-edit-9992 -p 9992 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher --log
```

The local `app/etc/env.php` originally had `system.maintenance=true`, so the
first browser probe correctly reached the maintenance page rather than the
panel. For validation only, `app/etc/env.php` was copied to this task's
artifacts directory, `system.maintenance` was temporarily set to `false`, the
test instance was restarted, and the backup was restored after the browser
smoke completed.

Focused browser validation:

```json
{
  "pass": true,
  "url": "http://127.0.0.1:9992/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?operation=files.write&project_id=e2e-file-edit&domain=file-edit.e2e.wls.test&project_type=wls&root=var&path=wls-file-edit-e2e-1781842557616&preview=wls-file-edit-e2e-1781842557616%2Feditable.txt",
  "assertions": [
    "preview panel rendered editable.txt and its existing content",
    "Edit action was visible for a writable var text file",
    "save-text form prefilled root=var, path=test directory, file_name=editable.txt, content, and overwrite checkbox",
    "SAVE_TEXT submission persisted updated file content and appended operation-log evidence",
    "app_code/register.php preview rendered without an edit action and showed a direct-edit blocker",
    "desktop and 390px mobile widths had no horizontal overflow"
  ],
  "desktopMetrics": {
    "width": 1440,
    "htmlScrollWidth": 1440,
    "htmlClientWidth": 1440,
    "bodyScrollWidth": 1440,
    "bodyClientWidth": 1440
  },
  "mobileMetrics": {
    "width": 390,
    "htmlScrollWidth": 390,
    "htmlClientWidth": 390,
    "bodyScrollWidth": 390,
    "bodyClientWidth": 390
  }
}
```

Screenshots:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-0348-wls-file-manager-text-edit-slice/artifacts/wls-file-manager-preview-edit-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0348-wls-file-manager-text-edit-slice/artifacts/wls-file-manager-preview-edit-mobile.png
```

Runtime cleanup:

```text
app/etc/env.php restored from dev/ai/codex/tasks/2026-06-19/2026-06-19-0348-wls-file-manager-text-edit-slice/artifacts/env-before-file-edit.php
maintenance=true verified after restore
extend\server\php\php.exe bin\w server:stop ai-test-wls-file-edit-9992
Port 9992 only had transient TIME_WAIT entries after stop
No non-PowerShell process command line contained ai-test-wls-file-edit-9992
No var/wls-file-edit-e2e-* temporary directory remained
```

## 2026-06-19 - Project Config Center Scoped Editor Entries

Slice:

- `WlsPanelProjectConfigCenterService` now emits per-project safe context
  labels, security-policy availability, scoped-editor target labels, and
  operation action labels for PHP, database, files, and deploy.
- The dashboard Project Config Center now separates `Attack Logs` from
  `Security Policy`, keeps the existing Gateway jump, and renders each
  operation with `data-wls-config-operation`, `data-wls-config-status`,
  `data-wls-config-target`, and `data-wls-config-context`.
- Generated project operation URLs still pass only safe context
  (`operation`, `project_id`, `domain`, `project_type`) or the WLS marketplace
  tag filter. Raw `project_path` is not emitted into these links.

Static validation:

```text
php -l app/code/Weline/Server/Service/WlsPanelProjectConfigCenterService.php
No syntax errors detected in app/code/Weline/Server/Service/WlsPanelProjectConfigCenterService.php

php -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
No syntax errors detected in app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml

app/code/Weline/Server/i18n/en_US.csv: rows=2487 bad=0
app/code/Weline/Server/i18n/zh_Hans_CN.csv: rows=2487 bad=0

git diff --check -- app/code/Weline/Server/Service/WlsPanelProjectConfigCenterService.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/i18n/en_US.csv app/code/Weline/Server/i18n/zh_Hans_CN.csv
exit 0
```

GitNexus impact check:

```text
gitnexus impact -r dev-workspace WlsPanelProjectConfigCenterService
Target 'WlsPanelProjectConfigCenterService' not found; risk UNKNOWN.

gitnexus impact -r dev-workspace WlsPanel
Target 'WlsPanel' not found; risk UNKNOWN.
```

These WLS Panel files are still part of the active untracked panel work set, so
GitNexus could not map the new symbols yet.

Focused browser validation:

```json
{
  "ok": true,
  "url": "http://127.0.0.1:9996/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel",
  "cardCount": 1,
  "operationCount": 4,
  "desktopOverflow": {
    "htmlScrollWidth": 1440,
    "htmlClientWidth": 1440,
    "bodyScrollWidth": 1440,
    "bodyClientWidth": 1440
  },
  "mobileOverflow": {
    "htmlScrollWidth": 390,
    "htmlClientWidth": 390,
    "bodyScrollWidth": 390,
    "bodyClientWidth": 390
  }
}
```

Assertions covered:

- Standalone shell rendered on `server/backend/wls-panel` after backend login.
- Project Config Center rendered at least one project card.
- Each project card rendered a safe context block.
- Attack Logs, Security Policy, and Gateway actions were present.
- PHP, database, file-manager, and deploy operation links were present.
- Each operation exposed status, target label, and safe context data
  attributes.
- PHP/DB/file/deploy/security links did not contain `project_path`.
- Security Policy linked to `#project-security-policy`; Attack Logs linked to
  `#security-logs`.
- Dark theme toggle changed `data-wls-theme` to `dark`.
- Desktop 1440px and mobile 390px had no horizontal overflow.

Screenshots:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-0428-wls-project-config-center-scoped-editors/artifacts/wls-project-config-center-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0428-wls-project-config-center-scoped-editors/artifacts/wls-project-config-center-mobile-dark.png
```

Runtime cleanup:

```text
Dedicated validation used ai-test-wls-project-config-9996 on port 9996 with --no-ssl, 2 workers, --worker-memory-limit=512M, and --supervisor false.
An earlier ai-test-wls-project-config-9994 reload attempt left stale partial processes after a transient ACL fatal page; those PIDs were manually stopped before switching to the clean 9996 instance.
php bin/w server:stop ai-test-wls-project-config-9996 -f left residual test PIDs; all listed PIDs were manually stopped.
Final status: ai-test-wls-project-config-9996 全部停止 (0/0).
Final status: ai-test-wls-project-config-9994 全部停止 (0/0).
Ports 9994 and 9996 had no LISTEN entries after cleanup.
No non-PowerShell command line contained ai-test-wls-project-config-9994 or ai-test-wls-project-config-9996 after cleanup.
```

## 2026-06-19 - Plugin-Heavy WLS Panel 512M Smoke

Slice:

- Completed `WLS-PANEL-MEM-001` on a dedicated Windows Dispatcher-mode WLS
  instance with 512M worker memory.
- The standalone WLS Panel loaded dashboard, marketplace, security, Deploy,
  FileManager, PHP Manager, and Database Manager together.
- Browser evidence covered desktop 1440px, mobile 390px, and theme toggles.
- The route sweep found a real plugin UX issue: Deploy, PHP, DB, and
  FileManager sidebar-only `#anchor` links resolved to the backend root because
  of the backend layout base URL. The templates now generate absolute
  current-plugin URLs with safe context query parameters before appending the
  anchor.

Runtime:

```text
php bin/w server:start ai-test-wls-panel-plugin-heavy-9990 -p 9990 -c 2 --no-ssl --worker-memory-limit=512M --supervisor false

Backend entry:
http://p11005ce4.weline.test:9990/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin

server:status before cleanup:
Master PID 4556 running
Dispatcher PID 56848 on port 9990
HTTP Worker #1 PID 33516 on port 26442, memory 251.07 MB
HTTP Worker #2 PID 5016 on port 26443, memory 236.67 MB
```

Static validation:

```text
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
No syntax errors detected in app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml

php -l app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml
No syntax errors detected in app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml

php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
No syntax errors detected in app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml

php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
No syntax errors detected in app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
```

Browser route sweep:

```text
Dashboard: server/backend/wls-panel
Marketplace: server/backend/wls-panel/marketplace
Security: server/backend/wls-panel/security
Deploy: deploy/backend/wls-deploy
FileManager: weline_filemanager/backend/wls-file-manager
PHP Manager: weline_phpmanager/backend/wls-php-manager
Database Manager: weline_dbmanager/backend/wls-db-manager

Assertions:
- Each route rendered its standalone shell marker.
- No route contained Fatal error, Parse error, Warning:, 500, 404, or backend
  login redirect text.
- Desktop 1440px and mobile 390px checks had no horizontal overflow.
- Theme toggles changed the relevant shell theme attributes.
- WLS Panel showed 4 installed WLS plugins and the `module:wls` marketplace
  contract.
- Project Config Center exposed project backend, child panel, PHP, database,
  file, deploy, security-log, security-policy, and gateway jumps without raw
  project_path in URLs.
- Anchor regression report after the fix had no bad plugin sidebar anchor
  links.
```

Artifacts:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/route-sweep-report.json
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/anchor-fix-report.json
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/panel-dashboard-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/panel-marketplace-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/panel-security-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/deploy-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/filemanager-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/phpmanager-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/dbmanager-desktop.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/panel-dashboard-mobile.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/deploy-mobile.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-0710-wls-panel-plugin-heavy-smoke/artifacts/filemanager-mobile.png
```

Runtime cleanup:

```text
php bin/w server:stop ai-test-wls-panel-plugin-heavy-9990
All child processes drained and disconnected: Dispatcher(PID:56848), HTTP Worker(PID:33516), HTTP Worker(PID:5016).

php bin/w server:status ai-test-wls-panel-plugin-heavy-9990
Master 状态: 已停止
状态: 全部停止 (0/0)

Get-NetTCPConnection -LocalPort 9990
No LISTEN entries remained; only transient FinWait2 connections were observed.
```

## 2026-06-19 - Gateway Target Selector Service Guard

Slice:

- Advanced `WLS-GW-TARGET-002` with a focused service-level protection before
  the live dual-Gateway browser run.
- `WlsPanelGatewaySettingsService::buildActiveRoutes()` is now protected so a
  PHPUnit test can provide a deterministic route payload without touching the
  database.
- The service test covers two ready Gateway-enabled targets:
  - no `gateway_instance` returns a Gateway selection error and does not call
    `IpcControlGateway::proxyApply()`;
  - explicit `gateway_instance=gateway-b` calls `proxyApply()` exactly once for
    `gateway-b` with the expected normalized route payload.

Changed files:

```text
app/code/Weline/Server/Service/WlsPanelGatewaySettingsService.php
app/code/Weline/Server/Test/Unit/Service/WlsPanelGatewaySettingsServiceTest.php
dev/ai/codex/tasks/2026-06-19/2026-06-19-0920-wls-gateway-target-selector-service-guard/
```

Validation:

```text
php -l app\code\Weline\Server\Service\WlsPanelGatewaySettingsService.php
No syntax errors detected in app\code\Weline\Server\Service\WlsPanelGatewaySettingsService.php

php -l app\code\Weline\Server\Test\Unit\Service\WlsPanelGatewaySettingsServiceTest.php
No syntax errors detected in app\code\Weline\Server\Test\Unit\Service\WlsPanelGatewaySettingsServiceTest.php

extend\server\php\php.exe vendor\bin\phpunit --bootstrap app\bootstrap.php app\code\Weline\Server\Test\Unit\Service\WlsPanelGatewaySettingsServiceTest.php
OK (2 tests, 10 assertions)
```

Notes:

- The focused PHPUnit run emitted the existing duplicate-extension warnings and
  Windows `chcp` PATH warnings, but exited 0.
- `php bin\w phpunit:run --module=Weline_Server --filter=WlsPanelGatewaySettingsServiceTest`
  was stopped after hanging without output; it appeared to collect or run the
  wider module suite instead of this file-only target.
- No WLS runtime instance was started for this service-only slice, so there was
  no port/process cleanup requirement.
- Remaining `WLS-GW-TARGET-002` work is the true browser/runtime proof with two
  live Gateway-enabled non-9501 WLS instances, explicit target selection in the
  panel, selected-target-only `proxyApply`, and cleanup for both instances.

## 2026-06-19 - Gateway Target Selector Live Dual Gateway

Slice:

- Advanced `WLS-GW-TARGET-002` from service-only protection to a live
  dual-Gateway runtime/control-plane proof.
- Two Gateway-enabled WLS instances ran concurrently on non-9501 ports.
- A unique SNI route was applied only to the selected B instance.
- The selected B Gateway served the route through a TLS backend; the
  non-selected A Gateway did not.
- This proof did not click the browser form. It validates the service guard
  plus live runtime isolation. A future UI smoke can submit the same selector
  through the Gateway Settings form if strict browser-click evidence is needed.

Runtime setup:

```text
A:
WLS_GATEWAY_ENABLED=1
WLS_GATEWAY_LISTEN=127.0.0.1:9986
WLS_GATEWAY_TRAFFIC_MODE=passthrough
php bin\w server:start ai-test-wls-gw-target-a-9985 -p 9985 --no-ssl -c 1 --worker-memory-limit=512M --supervisor false

B:
WLS_GATEWAY_ENABLED=1
WLS_GATEWAY_LISTEN=127.0.0.1:9988
WLS_GATEWAY_TRAFFIC_MODE=passthrough
php bin\w server:start ai-test-wls-gw-target-b-9987 -p 9987 --no-ssl -c 1 --worker-memory-limit=512M --supervisor false
```

Status before apply:

```text
ai-test-wls-gw-target-a-9985:
Master PID 60156
Dispatcher PID 46936 on port 9985
HTTP Worker PID 46364 on port 26437
Gateway PID 57340 on port 9986

ai-test-wls-gw-target-b-9987:
Master PID 4612
Dispatcher PID 55012 on port 9987
HTTP Worker PID 15004 on port 26439
Gateway PID 7188 on port 9988

Get-NetTCPConnection:
127.0.0.1:9985 Listen 46936
127.0.0.1:9986 Listen 57340
127.0.0.1:9987 Listen 55012
127.0.0.1:9988 Listen 7188
```

Control-plane validation:

```text
php -r "require 'app/bootstrap.php'; $g=new \Weline\Server\Service\Control\IpcControlGateway(); ..."

IPC status confirmed both A and B were running and each had desired_state
including gateway=1 with a ready Gateway child.

B-only route apply:
IpcControlGateway::proxyApply(
  'ai-test-wls-gw-target-b-9987',
  [[
    'domain' => 'wls-gw-selected-target.local',
    'backend_host' => '127.0.0.1',
    'backend_port' => 9990,
    'backend_ssl' => true,
    'priority' => 100,
  ]],
  5.0
)

Result:
success=true
accepted=true
completed=true
instance=ai-test-wls-gw-target-b-9987
action=proxy_apply
data.routes=1
data.gateways=1
data.targets=["gateway(ipc:935)"]
```

Route probes:

```text
Positive selected-target probe:
curl.exe --noproxy "*" --max-time 10 -k -i --resolve wls-gw-selected-target.local:9988:127.0.0.1 https://wls-gw-selected-target.local:9988/

HTTP/1.1 200 OK
Content-Type: text/plain
Content-Length: 14
X-WLS-Mock: selected-b
Connection: close

selected-b-ok

Negative non-selected target probe:
curl.exe --noproxy "*" --max-time 10 -k -i --resolve wls-gw-selected-target.local:9986:127.0.0.1 https://wls-gw-selected-target.local:9986/

curl: (35) schannel: failed to receive handshake, SSL/TLS connection failed
```

Protocol note:

```text
WlsGateway currently extracts TLS ClientHello SNI and forwards the original
TLS stream to the matched backend. Plain HTTP Host-header probes are invalid,
and routing to a --no-ssl WLS dispatcher does not produce useful hit evidence.
For this proof, a one-request local TLS mock backend was used on 127.0.0.1:9990
with the repository development certificate, then removed after the run.
```

Cleanup:

```text
php bin\w server:stop ai-test-wls-gw-target-b-9987
All child processes drained and disconnected:
Dispatcher(PID:55012), Gateway(PID:7188), HTTP Worker(PID:15004)

php bin\w server:stop ai-test-wls-gw-target-a-9985
All child processes drained and disconnected:
Dispatcher(PID:46936), Gateway(PID:57340), HTTP Worker(PID:46364)

php bin\w server:status ai-test-wls-gw-target-a-9985
Master PID: -
Master status: stopped
All stopped (0/0)

php bin\w server:status ai-test-wls-gw-target-b-9987
Master PID: -
Master status: stopped
All stopped (0/0)

Get-NetTCPConnection -LocalPort 9985,9986,9987,9988,9990
No Listen entries remained; only transient TimeWait entries were observed on
9986 and 9990.

Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" ... target names ...
No matching PHP command line remained.
```

## 2026-06-19 - Gateway Target Selector Browser Form Proof

Purpose:

- Close the browser-form gap for `WLS-GATEWAY-011` / `WLS-GW-TARGET-002`.
- Prove the standalone WLS Panel can choose one Gateway target from multiple
  running Gateway-enabled WLS instances and submit the native Gateway Settings
  `Apply Routes Now` form.
- Prove the selected target receives the route while a non-selected Gateway does
  not.

Instances:

```text
Panel host:
ai-test-wls-gw-ui-host-9991
main/dispatcher port: 9991
Gateway: disabled

Gateway A:
ai-test-wls-gw-ui-a-9992
main/dispatcher port: 9992
Gateway listen: 127.0.0.1:9993
WLS_GATEWAY_ENABLED=1
WLS_GATEWAY_TRAFFIC_MODE=passthrough

Gateway B:
ai-test-wls-gw-ui-b-9994
main/dispatcher port: 9994
Gateway listen: 127.0.0.1:9995
WLS_GATEWAY_ENABLED=1
WLS_GATEWAY_TRAFFIC_MODE=passthrough
```

Temporary route:

```text
ReverseProxy:
wls-gw-ui-selected.local -> https://127.0.0.1:9996
backend_ssl=1
priority=100000
status=active

The temporary rule was deleted after validation.
```

Browser proof:

```text
Browser URL:
http://pf9938bb3.weline.test:9991/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel#gateway-settings

The in-app browser performed a real backend login using the local development
admin account documented in dev/ai/AI-开发与测试指南.md, then opened the
standalone WLS Panel.

The native `.wls-gateway-apply-form` was present:
action=http://pf9938bb3.weline.test:9991/.../server/backend/wls-panel/gateway-apply
method=post
select[name="gateway_instance"] required=true
button=立即应用路由

Selector options included:
- 自动选择 Gateway
- ai-test-wls-gw-ui-a-9992 - 127.0.0.1:9993
- ai-test-wls-gw-ui-b-9994 - 127.0.0.1:9995
- ai-test-wls-gw-ui-host-9991
- dev-docs-api-9524

Selected value before submit:
ai-test-wls-gw-ui-b-9994

After submit:
URL remained in the standalone WLS Panel at #gateway-settings
Visible page text included: 网关路由已应用。
Selected value after submit:
ai-test-wls-gw-ui-b-9994
```

Selected-target route probe:

```text
curl.exe --noproxy "*" --max-time 10 -k -i --resolve wls-gw-ui-selected.local:9995:127.0.0.1 https://wls-gw-ui-selected.local:9995/

HTTP/1.1 200 OK
Content-Type: text/plain
Content-Length: 16
X-WLS-Mock: ui-selected-b
Connection: close

ui-selected-b-ok

The one-request TLS mock printed:
mock_served GET / HTTP/1.1
```

Non-selected target probe:

```text
A second one-request TLS mock was started on 127.0.0.1:9996 before the A probe.

curl.exe --noproxy "*" --max-time 10 -k -i --resolve wls-gw-ui-selected.local:9993:127.0.0.1 https://wls-gw-ui-selected.local:9993/

curl: (35) schannel: failed to receive handshake, SSL/TLS connection failed

The second TLS mock stayed waiting and had to be stopped manually, proving A did
not forward the SNI route to the backend.
```

Cleanup:

```text
Temporary ReverseProxy rule:
deleted for wls-gw-ui-selected.local

Temporary scripts:
var/codex-tmp/wls_gateway_ui_route.php
var/codex-tmp/wls_gateway_ui_tls_mock.php
var/codex-tmp/wls_backend_login_cookie.php
deleted

Temporary session file:
var/session/f83c7f02cf9ee916ff338537666e828f
deleted and Test-Path returned False

Stopped:
php bin\w server:stop ai-test-wls-gw-ui-b-9994
php bin\w server:stop ai-test-wls-gw-ui-a-9992
php bin\w server:stop ai-test-wls-gw-ui-host-9991

Status:
ai-test-wls-gw-ui-host-9991 Master PID=-, all stopped (0/0)
ai-test-wls-gw-ui-a-9992 Master PID=-, all stopped (0/0)
ai-test-wls-gw-ui-b-9994 Master PID=-, all stopped (0/0)

Ports 9991-9996:
No Listen entries remained. Only transient TimeWait entries were observed.

PHP process filter:
No matching command line remained for the three AI instances or the TLS mock.
```

## 2026-06-19 - FileManager Editor Ergonomics Slice

Scope:

- Added an operations-style safe-text editor shell to the existing guarded
  `SAVE_TEXT` form in `Weline_FileManager`.
- The slice adds wrap and font-size controls, dirty-state display, safe revert,
  line/character/byte counters, cursor position, byte-limit state, and
  mobile-safe toolbar wrapping.
- The server-side write policy did not change: ACL, allowlisted root checks,
  safe extension whitelist, confirmation checkbox, `SAVE_TEXT` phrase, and
  operation logging remain the enforcement points.
- Source-code write enablement and broader source-tree write policy remain
  future work.

Changed files:

```text
app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
app/code/Weline/FileManager/i18n/en_US.csv
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv
app/code/Weline/FileManager/doc/README.md
app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
dev/ai/codex/tasks/2026-06-19/2026-06-19-1020-wls-file-manager-editor-ergonomics/
```

Static validation:

```text
php -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
No syntax errors detected in app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml

php -r "foreach (array('app/code/Weline/FileManager/i18n/en_US.csv','app/code/Weline/FileManager/i18n/zh_Hans_CN.csv') as $file) { ... }"
app/code/Weline/FileManager/i18n/en_US.csv OK 367
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv OK 367

rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
No matches.
```

Browser validation:

```text
Instance:
php bin/w server:start ai-test-wls-file-editor-9999 -p 9999 -c 2 --no-ssl --worker-memory-limit=512M --supervisor false

Editor URL:
/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager?root=var&path=wls-file-manager-editor-smoke&preview=wls-file-manager-editor-smoke%2Fnote.txt
```

Observed editor behavior:

```text
before:
dirty=未修改
lines=4
chars=177
bytes=177
position=光标 1:1
revertDisabled=true
overflow=0

after typing:
dirty=已修改
lines=5
chars=209
bytes=209
revertDisabled=false
overflow=0

toolbar:
wrapPressed=false
nowrap=true
fontSize=16px
overflow=0

revert:
dirty=未修改
revertDisabled=true
restored=true
overflow=0

save:
success=true
wfm_notice=text_saved
editorVisible=true
overflow=0
```

Layout evidence:

```text
Desktop 1440x900:
editorVisible=true
gridAlignItems=start
oldFlexRule=false
overflow=0

Mobile 390x844 with cache-buster:
servedToolbarCss=true
oldFlexRule=false
toolsDisplay=grid
toolsGrid=256px
overflow=0
editorVisible=true
```

Screenshots:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-1020-wls-file-manager-editor-ergonomics/artifacts/desktop-editor-final-verified.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1020-wls-file-manager-editor-ergonomics/artifacts/mobile-editor-final-verified.png
```

Runtime cache note:

- `php bin/w cache:clear -f` and `php bin/w cache:flush` did not remove the
  stale `var/cache/template` and `var/cache/taglib` compiled view files.
- The browser also retained the same URL response, so the final visual proof
  used a cache-busted query parameter after clearing the compiled view cache and
  restarting the AI WLS instance.

Cleanup:

```text
php bin/w server:stop ai-test-wls-file-editor-9999
Get-NetTCPConnection -LocalPort 9999 -State Listen
No listening entry.

Temporary smoke directory:
var/wls-file-manager-editor-smoke
deleted; Test-Path returned False.
```

## 2026-06-19 - WLS Panel plan remaining-map sync

Status: passed for documentation/prototype consistency after the FileManager
safe-text editor ergonomics slice.

Updated docs:

- `00-INDEX.md` now lists the active remaining work as direct-listen
  SO_REUSEPORT runtime proof, real PHP extension adapters, real DbManager
  mysql/pgsql lifecycle/backup/restore/migration adapters plus slave
  create/remove flows, and FileManager source-code/source-tree write policy.
- `10-prototype.md` now treats safe-text editor ergonomics as implemented and
  keeps only executable-source/source-code writes plus broader project-root
  editing as future policy slices.
- `30-atomic-task-plan.md` Stage 3 summary rows now match the later detailed
  operations rows for PHP Manager, DbManager, and FileManager.

Validation commands:

```text
rg -n "FileManager rich-editor|rich-editor ergonomics|richer editing|optional richer editing|Executable-source writes|source-code editing|broader source-tree write|remain future|future slices" app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/FileManager/doc/README.md
rg -n "[ \t]+$" app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md dev/ai/codex/tasks/2026-06-19/2026-06-19-1431-wls-panel-plan-remaining-map-sync
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md dev/ai/codex/tasks/2026-06-19/2026-06-19-1431-wls-panel-plan-remaining-map-sync
```

Validation result:

- The precise obsolete wording scan for FileManager rich-editor future-work
  terms returned no output.
- The broad remaining-work scan now only finds expected future items:
  source-code/source-tree policy rows and database lifecycle future slices.
- The trailing whitespace scan returned no output.
- `git diff --check` returned no output.

No WLS/browser run was required for this slice because no runtime code, route,
template, CSS, JS, or generated artifact changed.

## 2026-06-20 - FileManager Source-Tree Policy Split

Status: passed for documentation and task decomposition.

Scope:

- Rechecked the current FileManager source-edit boundary.
- `SAVE_SOURCE` remains limited to existing, writable, non-protected, small
  source files under explicitly enabled source roots.
- Source-root create, upload, rename, delete, recursive delete, and queue
  operations remain disabled in runtime code.
- The WLS Panel Plan now splits broader source-tree writes into future layers:
  `SOURCE_CREATE_FILE`, `SOURCE_RENAME`, `SOURCE_TRASH`, and a separately
  reviewed source queue policy.

Changed docs:

- `00-INDEX.md`
- `10-prototype.md`
- `30-atomic-task-plan.md`
- `dev/ai/codex/tasks/2026-06-20/2026-06-20-0346-wls-file-manager-source-tree-policy-split/*`

Validation:

```text
rg -n "FileManager Source-Tree Policy Split|WLS-FILE-SOURCE-002|SOURCE_CREATE_FILE|SOURCE_RENAME|SOURCE_TRASH|Source-tree writes must keep" app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md dev/ai/codex/tasks/2026-06-20/2026-06-20-0346-wls-file-manager-source-tree-policy-split
rg -n "[ \t]+$" app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md dev/ai/codex/tasks/2026-06-20/2026-06-20-0346-wls-file-manager-source-tree-policy-split
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md dev/ai/codex/tasks/2026-06-20/2026-06-20-0346-wls-file-manager-source-tree-policy-split
```

- Targeted wording scan found the new prototype/evidence/backlog rows.
- Trailing whitespace scan returned no output.
- `git diff --check` returned no output.
- No WLS/browser run was required because no runtime code, route, template,
  CSS, JS, or generated artifact changed.

## 2026-06-19 - FileManager Source Edit Policy Slice

Status: passed for the first opt-in FileManager source-edit policy slice.

Scope:

- Added a separate source-edit path policy to `Weline_FileManager`.
- Ordinary controlled writes remain limited to `var`, `pub`, `project_var`,
  and `project_pub`.
- Source roots (`project`, `local_project`, `app_code`) stay read-only for
  create/upload/rename/delete/compress/trash operations.
- When a saved path policy explicitly enables source editing for a source root,
  existing small source files can enter the guarded `SAVE_SOURCE` editor.
- Source editing rejects protected paths and segments such as `.env`,
  `app/etc/env.php`, lock files, `.git`, `generated`, `vendor`,
  `node_modules`, and `var`; it also rejects symlinks, non-existing files,
  unreadable/unwritable files, binary-null content, and content above 128 KB.
- Operation logs now distinguish `save_source` from ordinary `save_text`.

Initial validation:

```text
php -l app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
No syntax errors detected in app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php

php -l app/code/Weline/FileManager/Service/WlsFileManagerPathPolicyService.php
No syntax errors detected in app/code/Weline/FileManager/Service/WlsFileManagerPathPolicyService.php

php -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
No syntax errors detected in app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
```

Environment note:

- The local PHP runtime still prints duplicate-extension warnings before lint
  results; the target files themselves pass syntax validation.

Static validation:

```text
php -d error_reporting=22527 -r "foreach (array('app/code/Weline/FileManager/i18n/en_US.csv', 'app/code/Weline/FileManager/i18n/zh_Hans_CN.csv') as $file) { ... }"
app/code/Weline/FileManager/i18n/en_US.csv OK 392
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv OK 392

rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/FileManager/Service/WlsFileManagerPathPolicyService.php app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
No output.

rg -n "[ \t]+$" <changed FileManager/WLS Panel Plan/task files>
No output.

git diff --check -- <changed FileManager/WLS Panel Plan/task files>
No whitespace errors. Git reported LF/CRLF working-copy warnings for existing FileManager doc/i18n files.
```

GitNexus impact check:

```text
gitnexus impact "Weline\\FileManager\\Controller\\Backend\\WlsFileManager::postSaveText" --repo dev-workspace --direction upstream --depth 2
Target not found; risk UNKNOWN.

gitnexus impact "Weline\\FileManager\\Service\\WlsFileManagerPathPolicyService::saveFromPanel" --repo dev-workspace --direction upstream --depth 2
Target not found; risk UNKNOWN.
```

The FileManager source-edit files are still newer than the current GitNexus
index, so the risk was managed by targeted source review, linting, static
scans, and runtime smoke instead of graph impact proof.

Runtime smoke:

```text
php bin/w server:start ai-test-wls-file-source-9998 -p 9998 -c 2 --no-ssl --worker-memory-limit=512M --supervisor false

curl GET /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_filemanager/backend/wls-file-manager
HTTP/1.1 302 Found to admin/login when unauthenticated.

curl GET /admin/login
HTTP/1.1 200 OK

curl POST /CNY/zh_Hans_CN/admin/login/post with admin/admin and form_key
HTTP/1.1 302 Found to admin.

curl GET /weline_filemanager/backend/wls-file-manager?operation=files.write#path-policy
HTTP/1.1 200 OK; shell rendered with source policy controls.

curl POST /weline_filemanager/backend/wls-file-manager/path-policy-save
HTTP/1.1 302 Found with wfm_notice=path_policy_saved.

curl GET /weline_filemanager/backend/wls-file-manager?operation=files.write&root=project&preview=README.md#wfm-save-text-card
HTTP/1.1 200 OK; preview rendered as `保存源码`, hidden `source_edit=1`, and `SAVE_SOURCE`.

curl POST /weline_filemanager/backend/wls-file-manager/path-policy-reset
HTTP/1.1 302 Found with wfm_notice=path_policy_reset.

curl GET /weline_filemanager/backend/wls-file-manager?operation=files.write&root=project#path-policy
HTTP/1.1 200 OK; source-edit policy returned to default off.
```

Runtime cleanup:

```text
php bin/w server:stop ai-test-wls-file-source-9998
server:status ai-test-wls-file-source-9998
Master PID=-, state stopped, all stopped (0/0).

Get-NetTCPConnection -LocalPort 9998 -State Listen
No matching MSFT_NetTCPConnection object.

rg -n "codex-source-edit-smoke|source_edit_enabled|source_edit_roots" var/wls-panel/file-manager-path-policy.json
The policy file did not exist after reset.
```

Artifacts:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-1440-wls-file-manager-source-edit-policy/artifacts/filemanager-source-policy.html
dev/ai/codex/tasks/2026-06-19/2026-06-19-1440-wls-file-manager-source-edit-policy/artifacts/filemanager-source-preview.html
dev/ai/codex/tasks/2026-06-19/2026-06-19-1440-wls-file-manager-source-edit-policy/artifacts/filemanager-source-reset.html
```

The temporary backend cookie jar was deleted after validation.

## 2026-06-19 - WLS Panel Responsive Theme Shell Hardening

Status: passed for source-template hardening plus real WLS HTTP render smoke.

Scope:

- Hardened the independent WLS Panel shell after the plugin-heavy dashboard and
  marketplace grew beyond the original Stage 1 prototype.
- Moved the sidebar-to-top-nav collapse to the 1100px breakpoint so shell
  structure changes when the content grids also start narrowing.
- Made plugin capability nav dividers span all mobile nav columns.
- Made header actions left-align and fill the available width on narrow
  layouts.
- Allowed long translated button labels to wrap inside buttons instead of
  forcing horizontal overflow.
- Kept phone layouts single-column for shell actions.
- Added dark-mode sidebar variables so the sidebar participates in the panel
  theme rather than staying visually fixed.
- Kept the WLS Panel independent from the ordinary framework backend shell:
  the framework backend remains only the authorized entry point, while the WLS
  Panel owns navigation, theme, project/admin links, marketplace, security,
  gateway, and plugin capability pages.

Target assertions:

```text
Desktop 1440:
- sidebar column and main column do not overlap.
- plugin grids auto-fit without card overflow.

Tablet 1100/1024:
- sidebar becomes top navigation before card grids become cramped.
- plugin divider spans all nav columns.
- header actions align left below the title.

Mobile 390:
- shell actions become single-column buttons.
- translated labels wrap inside buttons.
- no horizontal page overflow.

Theme:
- toggling dark mode sets data-wls-theme on the shell.
- document/body receive data-wls-panel-theme for diagnostics.
- main, cards, forms, status chips, and sidebar use dark variables.
```

Validation:

```text
php -l app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
No syntax errors detected in app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml

rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
No output.

rg -n "[ \t]+$" <edited WLS Panel template/docs/task files>
No output.

rg -n "@media \(max-width: 1100px\)|grid-column: 1 / -1|--wls-sidebar-bg|data-wls-panel-theme|wls-plugin-grid|white-space: normal|grid-template-columns: minmax\(0, 1fr\)" app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
Matched the expected shell, theme, and responsive hooks.
```

Runtime smoke:

```text
php bin/w server:start ai-test-wls-panel-theme-9997 -p 9997 -c 2 --no-ssl --worker-memory-limit=512M --supervisor false
Started successfully on http://p11005ce4.weline.test:9997 with 2 workers.

curl -I http://p11005ce4.weline.test:9997/
HTTP/1.1 200 OK
X-Wls-Instance: ai-test-wls-panel-theme-9997

GET /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin/login
HTTP 200; login form rendered with form_key, username, and password fields.

POST /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/CNY/zh_Hans_CN/admin/login/post
HTTP flow completed with the test admin credentials used by prior WLS smoke runs.

GET /U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace
Rendered standalone WLS marketplace HTML.

rg -n 'data-wls-shell="standalone"|data-wls-theme-toggle|data-wls-panel-theme|@media \(max-width: 1100px\)|grid-column: 1 / -1|white-space: normal|WLS Panel|Marketplace|module:wls|wls-plugin-grid' panel-marketplace.html
Matched the independent shell, theme toggle, responsive CSS, marketplace grid, and typed WLS tag output.

rg -n 'login|weline-admin-login-form|Login|admin' panel-marketplace.html
No output, confirming the authenticated WLS Panel response did not fall back to the login form.
```

Artifacts:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-1520-wls-panel-responsive-theme-shell/artifacts/panel-marketplace.html
```

The temporary login and login-post captures were removed because login POST
responses can contain session headers. The retained marketplace HTML was saved
without response headers and the cookie jar was deleted after validation.

Browser limitation:

- `tool_search` did not expose an in-app browser control or screenshot tool in
  this thread.
- `npx playwright --version` failed with `'playwright' is not recognized as an
  internal or external command`.
- This slice therefore has real WLS HTTP render evidence and static CSS/DOM
  assertions, but no screenshot-level browser evidence.

Cleanup:

```text
php bin/w server:stop ai-test-wls-panel-theme-9997
Instance stopped.

php bin/w server:status ai-test-wls-panel-theme-9997
Master PID=-, Master state stopped, all stopped (0/0).

Get-NetTCPConnection -LocalPort 9997 -State Listen
No output.
```

## 2026-06-19 - WLS Panel Headless Visual Smoke

Status: passed for screenshot-level standalone shell UI smoke.

Scope:

- Added a task-local CDP smoke script under
  `dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts`.
- The script launches headless Microsoft Edge through Chrome DevTools Protocol,
  performs a real backend login, opens the WLS Panel marketplace route, toggles
  dark mode, captures screenshots, and writes JSON layout assertions.
- No production code, generated template, persistent E2E spec, or production
  dependency was added for this proof.

Validation commands:

```text
node --check dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/wls-panel-cdp-smoke.mjs
Passed.

php bin/w server:start ai-test-wls-panel-visual-9996 -p 9996 -c 2 --no-ssl --worker-memory-limit=512M --supervisor false
Started successfully on http://p11005ce4.weline.test:9996.

node dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/wls-panel-cdp-smoke.mjs
Exit code 0.
```

Assertions:

```text
cdp-smoke-result.json:
passed=true

desktop-light-1440:
theme=light, documentTheme=light, shell=true, loginFallback=false,
overflow=0, desktopSeparated=true, headerNoOverlap=true, buttonsFit=true,
pluginCards=4, marketplaceTag=module:wls, failures=[]

desktop-dark-1440:
theme=dark, documentTheme=dark, overflow=0, sidebarBg=rgb(12, 21, 25),
failures=[]

tablet-dark-1024:
overflow=0, compactStacked=true, dividerSpans=true, buttonsFit=true,
failures=[]

tablet-dark-768:
overflow=0, compactStacked=true, dividerSpans=true, buttonsFit=true,
failures=[]

phone-dark-390:
overflow=0, compactStacked=true, dividerSpans=true, headerNoOverlap=true,
buttonsFit=true, failures=[]
```

Screenshots:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/desktop-light-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/tablet-dark-1024.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/tablet-dark-768.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts/cdp-smoke-result.json
```

Visual review notes:

- Desktop light screenshot shows the WLS Panel as an independent full-screen
  server panel, with the framework backend chrome absent and the WLS sidebar
  owning navigation.
- Phone dark screenshot shows the sidebar collapsed into a top single-column
  navigation stack; shell actions are full-width buttons; marketplace and
  installed-plugin cards remain readable with no horizontal overflow.

Sensitive artifact check:

```text
rg -n "Set-Cookie|Cookie:|PHPSESSID|WELINE_BACKEND|form_key" dev/ai/codex/tasks/2026-06-19/2026-06-19-1542-wls-panel-headless-visual-smoke/artifacts
No output.
```

Cleanup:

```text
php bin/w server:stop ai-test-wls-panel-visual-9996
Instance stopped.

php bin/w server:status ai-test-wls-panel-visual-9996
Master PID=-, Master state stopped, all stopped (0/0).

Get-NetTCPConnection -LocalPort 9996 -State Listen
No output.

Get-NetTCPConnection -LocalPort 9336 -State Listen
No output.
```

## 2026-06-20 - WLS Panel Multi-page Visual Smoke

Status: passed for screenshot-level coverage of the independent WLS Panel and
the installed WLS plugin panel surfaces.

Scope:

- Covered Dashboard, Marketplace, Security, PHP Manager, DB Manager, File
  Manager, and Deploy.
- Each surface was opened through the standalone WLS shell after a real backend
  login.
- Each surface was captured at desktop `1440x900` and phone `390x844`.
- Assertions checked independent shell presence, no login fallback, no visible
  fatal/exception/SQL text, no horizontal overflow, expected text presence, and
  button text containment.

Route recovery:

```text
php bin/w setup:upgrade --route --sync
```

The full route command was interrupted because the local Windows process stayed
stuck in `composer dump-autoload`. Before interruption it had left
`generated/routers/*.php` as empty arrays. A temporary diagnostic probe verified
that controller scanning and `registerRoute()` still produced route data in
memory. A temporary recovery script then committed routes through the framework
`RouteUpdateStage` rather than hand-editing generated files.

Recovered generated route counts:

```text
generated/routers/backend_pc.php: 3068
generated/routers/backend_rest_api.php: 190
generated/routers/frontend_pc.php: 381
generated/routers/frontend_rest_api.php: 248
```

Route evidence:

- `generated/routers/backend_pc.php` contains `admin/login`.
- `generated/routers/backend_pc.php` contains `server/backend/wls-panel::GET`.
- `generated/routers/backend_pc.php` contains
  `server/backend/wls-panel/marketplace::GET`.
- `generated/routers/backend_pc.php` contains
  `server/backend/wls-panel/security::GET`.
- `generated/routers/backend_pc.php` contains
  `weline_dbmanager/backend/wls-db-manager`.
- `generated/routers/backend_pc.php` contains
  `weline_phpmanager/backend/wls-php-manager`.
- `generated/routers/backend_pc.php` contains `deploy/backend/wls-deploy::GET`.

Runtime:

```text
php bin/w server:start ai-test-wls-panel-pages-9995 --host=127.0.0.1 --port=9995 --workers=2 --no-ssl
```

The WLS instance ran on `http://p11005ce4.weline.test:9995`. The local env
resolved the worker count to `8`, and all workers warmed successfully.

HTTP route smoke:

```text
curl.exe --max-time 10 -I http://p11005ce4.weline.test:9995/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/admin
curl.exe --max-time 10 -I http://p11005ce4.weline.test:9995/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel
```

Both requests returned backend login redirects instead of 404, proving the WLS
workers had loaded the restored route files.

Validation command:

```text
node dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/wls-panel-multi-page-cdp-smoke.mjs
```

Result:

```text
passed=true
pages=dashboard, marketplace, security, php-manager, db-manager, file-manager, deploy
viewports=desktop-dark-1440, phone-dark-390
cases=14
```

Assertions:

- Every case had `shell=true`.
- Every case had `loginFallback=false`.
- Every case had `overflow=0`.
- Every case had `fatalHits=[]`.
- Every case had `buttonsFit=true`.
- Every expected text entry matched.

Screenshots:

```text
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/dashboard-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/dashboard-phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/marketplace-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/marketplace-phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/security-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/security-phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/php-manager-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/php-manager-phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/db-manager-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/db-manager-phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/file-manager-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/file-manager-phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/deploy-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/deploy-phone-dark-390.png
dev/ai/codex/tasks/2026-06-19/2026-06-19-1551-wls-panel-multi-page-visual-smoke/artifacts/multi-page-cdp-smoke-result.json
```

Visual review notes:

- Dashboard desktop presents the independent WLS server panel shell, without
  the ordinary framework backend chrome.
- Marketplace phone and FileManager phone stack navigation/actions without
  horizontal overflow.
- Deploy desktop keeps plugin navigation, project context, Tag Push default
  strategy, and Webhook/manual-plan controls inside the standalone plugin shell.

Cleanup:

```text
Temporary diagnostic/rebuild scripts under var/codex-tmp were removed.
Sensitive retained-artifact scan returned no output for cookie/session/form-key patterns.
php bin/w server:stop ai-test-wls-panel-pages-9995
Instance stopped.
Get-NetTCPConnection -LocalPort 9995 -State Listen
No output.
Get-NetTCPConnection -LocalPort 9337 -State Listen
No output.
Test-Path var/process/setup_upgrade.lock
False.
```

## 2026-06-20 - FileManager SOURCE_TRASH Source-Policy Slice

Scope:

- Add a dedicated WLS FileManager source-policy `SOURCE_TRASH` action.
- Move one existing small source file from an allowed source root into the same
  project `.wls-trash` directory.
- Keep source upload, hard delete, recursive delete, purge, and source queue
  operations disabled for this slice.

Changed files:

```text
app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
app/code/Weline/FileManager/i18n/en_US.csv
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv
app/code/Weline/FileManager/doc/README.md
app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md
app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
dev/ai/codex/tasks/2026-06-20/2026-06-20-0451-wls-file-manager-source-trash
```

Route refresh:

```text
php bin/w setup:upgrade --route -m Weline_FileManager --skip-classmap --skip-composer-dump --skip-env-check
```

Generated route evidence:

```text
generated/routers/backend_pc.php: weline_filemanager/backend/wls-file-manager/source-trash::POST -> postSourceTrash
generated/routers/backend_pc.php: weline_filemanager/backend/wls-file-manager/post-source-trash::POST -> postSourceTrash
```

Static validation:

```text
php -l app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php
No syntax errors detected in app\code\Weline\FileManager\Controller\Backend\WlsFileManager.php

php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
No syntax errors detected in app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml

i18n CSV parse:
app/code/Weline/FileManager/i18n/en_US.csv ok, rows=423, sourceTrashText=true
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv ok, rows=423, sourceTrashText=true

git diff --check -- <changed FileManager/WLS Panel Plan files>
No whitespace errors. Git reported LF/CRLF working-copy warnings for existing
FileManager doc/i18n files.
```

GitNexus impact:

```text
gitnexus impact WlsFileManager
gitnexus impact "Weline\FileManager\Controller\Backend\WlsFileManager"
gitnexus impact postSourceRename
gitnexus query "WlsFileManager source rename"
```

Result: current GitNexus index did not resolve the FileManager controller
symbols for this new/dirty module slice. Impact was kept scoped through
controller/template/service/route scans.

Runtime topology notes:

```text
9972 dispatcher, 2 workers:
- database recovery initially blocked backend requests
- after DB recovered, WLS startup timed out with worker READY 1/2 and Master exit

9974 dispatcher, 1 worker:
- Dispatcher returned maintenance page
- error log reported Worker IPC control channel connection failure

9975 direct:
- WLS rejected direct topology on Windows because SO_REUSEPORT is unavailable

9976 --no-dispatcher, 1 worker:
- served backend requests and completed the functional SOURCE_TRASH smoke
```

Functional smoke:

```text
php bin/w server:start ai-test-wls-file-source-trash-9976 -p 9976 -c 1 --no-ssl --no-dispatcher --no-daemon
```

Result file:

```text
dev/ai/codex/tasks/2026-06-20/2026-06-20-0451-wls-file-manager-source-trash/artifacts/filemanager-source-trash-smoke-result.json
```

Result summary:

```text
status=partial
functional_http=passed
browser_visual=blocked_by_url_policy
topology=single (--no-dispatcher)
domain=codex-source-trash-9976.local
availableWriteRoots=var,pub
login-page HTTP 200
login-post HTTP 302
path-policy-save HTTP 302
source-create HTTP 302
filesystem-created-source ok bytes=102
source-trash HTTP 302
filesystem-trashed-source ok bytes=102
operation-log HTTP 200 containsFileName=true
path-policy-reset HTTP 302
```

Browser visual status:

- Earlier in-app browser attempts reached the FileManager page and confirmed the
  source-policy forms rendered.
- Final desktop/mobile screenshot capture was not completed because the in-app
  browser rejected the local backend URL by URL policy.
- Per browser tool policy, no alternate browser surface or raw browser command
  workaround was used.

Cleanup:

```text
php bin/w server:stop ai-test-wls-file-source-trash-9976
Instance stopped.

netstat -ano | Select-String -Pattern ':9976'
Only TIME_WAIT entries remained; no LISTEN entry.

Artifacts directory:
dev/ai/codex/tasks/2026-06-20/2026-06-20-0451-wls-file-manager-source-trash/artifacts/filemanager-source-trash-smoke-result.json
```

## 2026-06-20 - FileManager SOURCE_RENAME Slice

Status: passed for implementation, route refresh, focused browser smoke, visual
review, and WLS cleanup.

Note: this follow-up slice supersedes the older SOURCE_CREATE_FILE evidence
below where `SOURCE_RENAME` was still recorded as a future layer.

Scope:

- Added the next executable source-tree write layer to `Weline_FileManager`.
- Source roots still do not become ordinary writable roots. The action requires
  the existing project/domain source-edit policy for `project`, `local_project`,
  or `app_code`.
- `SOURCE_RENAME` renames one existing allowlisted source file inside the same
  enabled source directory. It refuses missing confirmation, same-name rename,
  invalid source extensions, overwrites, path escapes, protected paths,
  symlinks, unreadable files, and unwritable files/directories.
- Source upload, delete, recursive delete, source trash, and source queue
  operations remain unavailable for source roots.
- The WLS Panel Plan docs now mark source-tree layer 3 as implemented while
  leaving `SOURCE_TRASH` and source queue flows as future slices.

Changed files:

```text
app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
app/code/Weline/FileManager/i18n/en_US.csv
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv
app/code/Weline/FileManager/doc/README.md
app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md
app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md
app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
app/code/Weline/Server/doc/wls-panel-plan/75-stage-1-panel-shell-e2e-evidence.md
dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename
```

Generated by framework route refresh, not edited manually:

```text
generated/routers/backend_pc.php
generated/extends.php
generated/hooks.php
generated/events.php
generated/taglibs.php
```

GitNexus impact:

```text
list_mcp_resources(server="gitnexus")
npx gitnexus status
npx gitnexus --help
```

Result: the GitNexus MCP server failed to start in this environment and the
CLI package was not locally runnable without install, so this slice records
impact as `UNKNOWN` and stays bounded by targeted source scan plus runtime and
browser validation.

Static validation:

```text
extend/server/php/php.exe -l app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
No syntax errors detected in app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php

extend/server/php/php.exe -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
No syntax errors detected in app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml

extend/server/php/php.exe -d error_reporting=22527 -r "<csv parse probe>"
app/code/Weline/FileManager/i18n/en_US.csv OK 414
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv OK 414

rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
No output.

node --check dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename/artifacts/filemanager-source-rename-smoke.mjs
No output.

git diff --check -- <changed FileManager/WLS Panel Plan files>
No whitespace errors. Git reported LF/CRLF working-copy warnings for existing FileManager doc/i18n files.
```

Route refresh:

```text
extend/server/php/php.exe bin/w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
```

The module route refresh completed and wrote backend routes. Existing Windows
PATH warnings for `chcp`/`schtasks` appeared during cache/cron maintenance, but
the command exited `0` and `setup_upgrade.lock` was absent afterward.

```text
generated/routers/backend_pc.php: weline_filemanager/backend/wls-file-manager/source-rename::POST -> postSourceRename
generated/routers/backend_pc.php: weline_filemanager/backend/wls-file-manager/post-source-rename::POST -> postSourceRename
Test-Path var/process/setup_upgrade.lock
False.
```

Runtime/browser validation:

```text
extend/server/php/php.exe bin/w server:start ai-test-wls-file-rename-9996 -p 9996 -c 2 --no-ssl --worker-memory-limit=512M --supervisor false
node dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename/artifacts/filemanager-source-rename-smoke.mjs
```

Result:

```text
passed=true
baseUrl=http://p11005ce4.weline.test:9996
domain=codex-source-rename-9996.local
before=dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename/artifacts/source-rename-before-1781930361753.md
after=dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename/artifacts/source-rename-after-1781930361753.md
policySave=wfm_notice=path_policy_saved
sourceRename=wfm_notice=source_renamed
policyReset=wfm_notice=path_policy_reset
desktop overflow=0 shell=true loginFallback=false sourceCreateForm=true sourceRenameForm=true sourceRenameButtonDisabled=false
phone overflow=0 shell=true loginFallback=false sourceCreateForm=true sourceRenameForm=true sourceRenameButtonDisabled=false
```

Screenshots:

```text
dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename/artifacts/filemanager-source-rename-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename/artifacts/filemanager-source-rename-phone-dark-390.png
dev/ai/codex/tasks/2026-06-20/2026-06-20-0428-wls-file-manager-source-rename/artifacts/filemanager-source-rename-smoke-result.json
```

Visual review notes:

- Desktop FileManager renders the independent WLS plugin shell, project context,
  source-renamed notice, path cards, and dark/light control without visual
  overlap.
- Phone width `390` stacks navigation and content without horizontal overflow;
  primary actions remain visible and the source-renamed notice remains readable.
- The isolated domain policy profile was reset after the rename proof.

Cleanup:

```text
extend/server/php/php.exe bin/w server:stop ai-test-wls-file-rename-9996
Instance stopped.
Get-NetTCPConnection -LocalPort 9996 -State Listen
No matching listener.
Get-CimInstance Win32_Process -Filter "name='php.exe'" | Where-Object { $_.CommandLine -like '*ai-test-wls-file-rename-9996*' }
No output.
Test-Path var/process/setup_upgrade.lock
False.
```

## 2026-06-20 - FileManager SOURCE_CREATE_FILE Slice

Status: passed for implementation, route refresh, focused browser smoke, and
WLS cleanup.

Scope:

- Added the first executable source-create layer to `Weline_FileManager`.
- Source roots still do not become ordinary writable roots. The action requires
  the existing project/domain source-edit policy for `project`, `local_project`,
  or `app_code`.
- `SOURCE_CREATE_FILE` creates one new allowlisted small source file inside an
  existing enabled source directory. It does not create directories, overwrite
  existing files, or unlock source upload, rename, delete, recursive delete, or
  queue operations.
- The WLS Panel Plan docs now mark source-tree layer 2 as implemented while
  leaving `SOURCE_RENAME`, `SOURCE_TRASH`, and source queue flows as future
  slices.

Changed files:

```text
app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
app/code/Weline/FileManager/i18n/en_US.csv
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv
app/code/Weline/FileManager/doc/README.md
app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md
app/code/Weline/Server/doc/wls-panel-plan/10-prototype.md
app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md
dev/ai/codex/tasks/2026-06-20/2026-06-20-0354-wls-file-manager-source-create-file
```

Generated by framework route refresh, not edited manually:

```text
generated/routers/backend_pc.php
generated/extends.php
generated/hooks.php
generated/events.php
generated/taglibs.php
```

GitNexus impact:

```text
gitnexus impact "Weline\\FileManager\\Controller\\Backend\\WlsFileManager::postSaveText" --repo dev-workspace --direction upstream --depth 2
gitnexus impact "Weline\\FileManager\\Controller\\Backend\\WlsFileManager::resolveSourceEditableFile" --repo dev-workspace --direction upstream --depth 2
gitnexus context WlsFileManager --repo dev-workspace
```

Result: the FileManager/WLS Panel symbols are still not present in the current
GitNexus index, so the impact risk is recorded as `UNKNOWN` and the slice stays
isolated.

Static validation:

```text
extend/server/php/php.exe -l app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php
No syntax errors detected in app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php

extend/server/php/php.exe -l app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
No syntax errors detected in app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml

extend/server/php/php.exe -d error_reporting=22527 -r "<csv parse probe>"
app/code/Weline/FileManager/i18n/en_US.csv OK 403
app/code/Weline/FileManager/i18n/zh_Hans_CN.csv OK 403

rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/FileManager/Controller/Backend/WlsFileManager.php app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
No output.

git diff --check -- <changed FileManager/WLS Panel Plan files>
No whitespace errors. Git reported LF/CRLF working-copy warnings for existing FileManager doc/i18n files.
```

Route refresh:

```text
php bin/w setup:upgrade --route
```

The full route refresh completed registry generation and composer dump-autoload,
then exited non-zero during environment self-repair because this Windows PATH
could not resolve `chcp` or `php`.

```text
extend/server/php/php.exe bin/w setup:upgrade --route -m Weline_FileManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump
```

The narrower module route refresh completed and wrote backend routes:

```text
generated/routers/backend_pc.php: weline_filemanager/backend/wls-file-manager/source-create::POST -> postSourceCreate
generated/routers/backend_pc.php: weline_filemanager/backend/wls-file-manager/post-source-create::POST -> postSourceCreate
```

Runtime/browser validation:

```text
extend/server/php/php.exe bin/w server:start ai-test-wls-file-create-9997 -p 9997 -c 2 --no-ssl --worker-memory-limit=512M --supervisor false
node dev/ai/codex/tasks/2026-06-20/2026-06-20-0354-wls-file-manager-source-create-file/artifacts/filemanager-source-create-smoke.mjs
```

Result:

```text
passed=true
baseUrl=http://p11005ce4.weline.test:9997
domain=codex-source-create-9997.local
created=dev/ai/codex/tasks/2026-06-20/2026-06-20-0354-wls-file-manager-source-create-file/artifacts/source-create-smoke-1781929186185.md
policySave=wfm_notice=path_policy_saved
sourceCreate=wfm_notice=source_created
policyReset=wfm_notice=path_policy_reset
desktop overflow=0 shell=true loginFallback=false sourceCreateForm=true sourceCreateButtonDisabled=false
phone overflow=0 shell=true loginFallback=false sourceCreateForm=true sourceCreateButtonDisabled=false
```

Screenshots:

```text
dev/ai/codex/tasks/2026-06-20/2026-06-20-0354-wls-file-manager-source-create-file/artifacts/filemanager-source-create-desktop-dark-1440.png
dev/ai/codex/tasks/2026-06-20/2026-06-20-0354-wls-file-manager-source-create-file/artifacts/filemanager-source-create-phone-dark-390.png
dev/ai/codex/tasks/2026-06-20/2026-06-20-0354-wls-file-manager-source-create-file/artifacts/filemanager-source-create-smoke-result.json
```

Visual review notes:

- Desktop FileManager renders the independent WLS plugin shell, path summary,
  source-created notice, and project context without login fallback.
- Phone width `390` stacks the plugin navigation and content without horizontal
  overflow; the source-created notice remains visible.
- The isolated domain policy profile was reset after the create proof.

Cleanup:

```text
extend/server/php/php.exe bin/w server:stop ai-test-wls-file-create-9997
Instance stopped.
Get-NetTCPConnection -LocalPort 9997 -State Listen
No output.
Get-CimInstance Win32_Process -Filter "name='php.exe'" | Where-Object { $_.CommandLine -like '*ai-test-wls-file-create-9997*' }
No output.
Test-Path var/process/setup_upgrade.lock
False.
```
