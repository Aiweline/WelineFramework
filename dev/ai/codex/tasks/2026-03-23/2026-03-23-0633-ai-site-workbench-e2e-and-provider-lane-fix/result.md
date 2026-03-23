# Result - ai site workbench e2e and provider lane fix

## Outcome

- Completed.
- The Websites AI workbench now keeps provider-lane navigation on the active hub route and supports a local fake quick-build path suitable for demo and E2E validation.
- The quick-build terminal once again shows the final `done` / SSE completion message instead of ending with only a disconnected state.
- Targeted Playwright coverage now exercises the unified AI site workbench end to end, including the PageBuilder workspace handoff and fake theme-generation flow.
- The repo-level Playwright flow now discovers both module-local `app/code/**/test/e2e` specs and shared `tests/e2e/specs` specs by default, so the AI site workbench spec no longer needs a config bypass.
- Module-local specs now resolve the shared `tests/e2e/node_modules` Playwright dependency correctly during standard runs.
- Follow-up validation is green again after local runtime drift:
  - `runtime.js` now reuses the real logged-in backend root instead of assuming the backend prefix alone is enough
  - the AI workbench helper now builds localized backend URLs correctly
  - direct-mode backend/API URL helpers now resolve to the real backend/API prefixes instead of proxy-only alias paths
  - the AI workbench spec now follows the workspace page's own auto-reload after provider-tool application instead of racing it with a second manual reload
- The wrapper entry is now green again with no extra env setup:
  - `tests/e2e/start.js` prefers a stable local PHP runtime target when the caller does not explicitly pin `PLAYWRIGHT_TARGET_ORIGIN`
  - the wrapped run defaults to direct mode for that local runtime, avoiding stale proxy/WLS drift during local verification
  - `cd tests/e2e && node start.js specs/backend/ai-site-workbench.spec.js` now passes as-is locally

## Changed Files

- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
- `tests/e2e/collect-tests.js`
- `tests/e2e/framework/runtime.js`
- `tests/e2e/playwright.config.js`
- `tests/e2e/start.js`
- `tests/e2e/specs/backend/helpers/ai-workbench.js`
- `tests/e2e/specs/backend/ai-site-workbench.spec.js`

## Verification

- `php -l app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `php -l app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
- `node --check tests/e2e/specs/backend/helpers/ai-workbench.js`
- `node --check tests/e2e/specs/backend/ai-site-workbench.spec.js`
- `curl.exe -k -I https://127.0.0.1:9982/`
- `node ./tests/e2e/node_modules/playwright/cli.js test tests/e2e/specs/backend/ai-site-workbench.spec.js`
- Result: `3 passed`
- `node tests/e2e/collect-tests.js`
- `node tests/e2e/node_modules/playwright/cli.js test --config tests/e2e/playwright.config.js --list`
- `cd tests/e2e && $env:MODULE_FILTER='Weline_Theme'; node .\node_modules\playwright\cli.js test --list`
- `cd tests/e2e && node .\node_modules\playwright\cli.js test specs/backend/ai-site-workbench.spec.js`
- `cd tests/e2e && node start.js specs/backend/ai-site-workbench.spec.js`
- Result: default config now lists shared + module-local specs, module filter lists Theme specs only, and the wrapped AI site workbench run still passes with `3 passed`
- `node --check tests/e2e/framework/runtime.js`
- `node --check tests/e2e/specs/backend/helpers/ai-workbench.js`
- `node --check tests/e2e/specs/backend/ai-site-workbench.spec.js`
- `$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9991'; $env:PLAYWRIGHT_DISABLE_PROXY='1'; cd tests/e2e; node .\node_modules\playwright\cli.js test specs/backend/ai-site-workbench.spec.js`
- Result: `3 passed`
- `$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9991'; $env:PLAYWRIGHT_DISABLE_PROXY='1'; cd tests/e2e; node start.js specs/backend/ai-site-workbench.spec.js`
- Result: `3 passed`
- `cd tests/e2e && node start.js specs/backend/ai-site-workbench.spec.js`
- Result: `3 passed`

## Remaining Risks

- Fake mode validates the local UX and orchestration path, but it intentionally does not prove real registrar / DNS / HTTPS integration.
- Playwright config/collection logs are still emitted once in the launcher and again in Playwright worker/config processes, so the console output is noisier than ideal even though discovery now works.
- The local wrapper now intentionally prefers a built-in PHP runtime for predictable local E2E runs. Raw unwrapped WLS/proxy execution is still more sensitive to local runtime drift if someone bypasses `tests/e2e/start.js`.

## Next Resume Step

- If we want a cleaner developer experience next, the remaining nice-to-have is deduplicating collection/debug logging across the main Playwright process and worker processes.
