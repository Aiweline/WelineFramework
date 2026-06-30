# WLS Panel Plugin UI Normalization Evidence

Date: 2026-06-21

Status: passed for `WLS-PANEL-PLUGIN-UI-002`; this is a shared interaction
polish slice and does not change DB/File/PHP/Deploy backend behavior.

## Scope

- Extended `wls-panel-plugins.js` from the first DB-focused plugin interaction
  slice to all current WLS standalone plugin shells: DbManager, Deploy,
  PhpManager, and FileManager.
- Theme persistence now writes `wls_panel_theme`, `wls_db_manager_theme`,
  `wls_deploy_theme`, `wls_php_manager_theme`, and `wls_file_manager_theme`.
- The shared click delegate covers `[data-wls-theme-toggle]`,
  `[data-wdb-theme-toggle]`, `[data-wdep-theme-toggle]`,
  `[data-wpm-theme-toggle]`, and `[data-wfm-theme-toggle]`.
- Plugin shells receive synchronized `data-theme`, host `color-scheme`,
  toggle labels, PHP Manager icon state, and `aria-pressed`.
- Plugin sidebar links marked with `[data-wls-plugin-nav]` now keep
  `.is-active` and `aria-current="location"` aligned with same-page
  `location.hash`.

## Changed Files

```text
app/code/Weline/Server/view/statics/assets/js/wls-panel-plugins.js
app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml
app/code/Weline/PhpManager/view/templates/Backend/WlsPhpManager/index.phtml
app/code/Weline/DbManager/view/templates/Backend/WlsDbManager/index.phtml
app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml
dev/ai/codex/tasks/2026-06-21/2026-06-21-1050-wls-panel-plugin-ui-normalization
```

## Static Validation

```text
node --check app\code\Weline\Server\view\statics\assets\js\wls-panel-plugins.js
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
php -l app\code\Weline\PhpManager\view\templates\Backend\WlsPhpManager\index.phtml
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -l app\code\Weline\FileManager\view\templates\Backend\WlsFileManager\index.phtml
```

Result:

- JS syntax check exited 0.
- All four PHP lint commands returned `No syntax errors detected`; the local
  PHP binary still prints the known duplicate-extension warnings first.

## Selector Contract

Commands:

```text
Select-String -SimpleMatch 'data-wls-plugin-nav' ...
Select-String -SimpleMatch 'aria-current="location"' ...
```

Result:

- `data-wls-plugin-nav` is present on Deploy, PHP Manager, DbManager, and
  FileManager plugin navs.
- Default active links in all four plugin navs render
  `aria-current="location"`.
- A 27-point static contract assertion passed across the shared JS and four
  templates, covering all five storage keys, four plugin shell selectors,
  theme toggle selectors, hash listener, host `colorScheme`, nav markers,
  default `aria-current`, and theme label attributes.

## Dedicated WLS Render

Runtime:

```text
php bin/w server:start ai-test-wls-plugin-ui-10018 -p 10018 -c 1 --no-ssl --supervisor false --worker-memory-limit=512M --dispatcher-memory-limit=512M
php tests\e2e\framework\backend-session-bootstrap.php --mode=wls --username=admin --password=admin
```

The instance ran on port `10018` in Dispatcher mode with one worker. The
backend session bootstrap returned:

```json
{
  "mode": "wls",
  "session_name": "WELINE_SESSID",
  "cookie_path": "/"
}
```

Rendered routes:

```text
/deploy/backend/wls-deploy#manual-release-plan
/weline_phpmanager/backend/wls-php-manager#ini-apply
/weline_dbmanager/backend/wls-db-manager#env-apply
/weline_filemanager/backend/wls-file-manager#write-operations
```

HTTP assertions:

```json
{
  "deploy": {"status": 200, "hasLoginForm": false, "hasPluginNav": true, "hasAriaCurrent": true, "hasSharedScript": true, "hasThemeToggle": true, "shell": "data-wls-deploy-shell"},
  "php": {"status": 200, "hasLoginForm": false, "hasPluginNav": true, "hasAriaCurrent": true, "hasSharedScript": true, "hasThemeToggle": true, "shell": "data-wls-php-manager-shell"},
  "db": {"status": 200, "hasLoginForm": false, "hasPluginNav": true, "hasAriaCurrent": true, "hasSharedScript": true, "hasThemeToggle": true, "shell": "data-wls-db-manager-shell"},
  "file": {"status": 200, "hasLoginForm": false, "hasPluginNav": true, "hasAriaCurrent": true, "hasSharedScript": true, "hasThemeToggle": true, "shell": "data-wls-file-manager-shell"}
}
```

## Shared JS Behavior

The actual `wls-panel-plugins.js` file was executed in a minimal DOM VM.

Assertions:

- Initial `location.hash="#settings"` moved the active nav link and
  `aria-current` to the matching same-page anchor.
- A delegated Deploy theme-button click called `preventDefault()` and
  `stopPropagation()`.
- All four plugin shells switched to `data-theme="dark"`.
- All four plugin theme buttons changed to `aria-pressed="true"`.
- All five shared storage keys persisted `dark`.
- A later `hashchange` to `#summary` moved `.is-active` and
  `aria-current="location"` back to the summary link and removed
  `aria-current` from the old link.

## Safety Checks

```text
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" <touched files>
git diff --check -- <touched files>
```

Result:

- Forbidden runtime/browser-call scan returned no matches.
- `git diff --check` exited 0; Git only warned that several existing LF files
  will be normalized to CRLF on the next Git touch.

## Browser Automation Note

```text
npx playwright --version
'playwright' is not recognized as an internal or external command
```

The current shell does not have Playwright/browser automation available, so this
slice used real WLS HTTP rendering plus a VM DOM behavior assertion instead of
screenshot-level browser proof.

## Cleanup

```text
php bin/w server:stop ai-test-wls-plugin-ui-10018
php bin/w server:status ai-test-wls-plugin-ui-10018
```

`server:stop` completed the dispatcher/worker drain flow. Final status for
`ai-test-wls-plugin-ui-10018` reported `全部停止 (0/0)`.
