# Result - framework login page performance audit

## Outcome

- Completed a focused backend login performance pass with two framework fixes that materially reduced live WLS response time on port `9982`.
- The work was committed as `a6295041` (`perf: reduce login overhead and expose devtool cost`).
- The implemented changes targeted the two biggest framework-side wastes found in the live request trace:
- DEV template compilation now reuses compiled output until the source file changes.
- i18n request-end collection now records only words actually used in the request and skips no-op cache writes.
- Added a follow-up accounting slice so the DeveloperWorkspace DevTool panel is no longer a hidden cost bucket:
- WLS now exposes dedicated headers for pre-telemetry work, telemetry tail work, and DevTool-panel work.
- the injected request trace now carries explicit `dev_tool_panel` spans, so the panel cost is visible inside the page instead of only being inferred from `run_after`.
- Important commit-scope caveat: the final commit also captured some pre-existing staged Theme/runtime work that was already sitting in the Git index before the commit step, so `a6295041` is broader than only the eight performance-targeted files listed below.

## Changed Files

- `app/code/Weline/Framework/View/Template.php`
- `app/code/Weline/I18n/Observer/ParserWordsRegister.php`
- `app/code/Weline/DeveloperWorkspace/Observer/DevToolPanelObserver.php`
- `app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php`
- `app/code/Weline/Framework/Runtime/WlsRuntime.php`
- `app/code/Weline/Framework/Test/Unit/View/TemplateCompileDecisionTest.php`
- `app/code/Weline/I18n/test/Unit/Observer/ParserWordsRegisterTest.php`
- `app/code/Weline/Framework/Test/Unit/Runtime/RequestLifecycleTraceTest.php`

## Verification

- `php -l app/code/Weline/Framework/View/Template.php`
- `php -l app/code/Weline/I18n/Observer/ParserWordsRegister.php`
- `php -l app/code/Weline/DeveloperWorkspace/Observer/DevToolPanelObserver.php`
- `php -l app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php`
- `php -l app/code/Weline/Framework/Runtime/WlsRuntime.php`
- `php -l app/code/Weline/Framework/Test/Unit/View/TemplateCompileDecisionTest.php`
- `php -l app/code/Weline/I18n/test/Unit/Observer/ParserWordsRegisterTest.php`
- `php -l app/code/Weline/Framework/Test/Unit/Runtime/RequestLifecycleTraceTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/Unit/View/TemplateCompileDecisionTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/I18n/test/Unit/Observer/ParserWordsRegisterTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/Unit/Runtime/RequestLifecycleTraceTest.php --colors=never`
- `php bin/w server:reload --no-wait`
- `curl -k -s -D - "https://127.0.0.1:9982/.../admin/login?no_access_reason=not_logged_in" -o tmp_perf_login_after.html`
- `1..5 | % { curl -k -o NUL -s -w "run=$_ start=%{time_starttransfer} total=%{time_total}\n" "https://127.0.0.1:9982/.../admin/login?no_access_reason=not_logged_in" }`
- same-request Python HTTPS probe against the same login URL to read both response headers and injected `window.__WELINE_REQUEST_TRACE__`
- Live timing summary:
- before: `X-Wls-Performance-Total` about `370-423ms`, `router_start` about `314-359ms`
- after the first optimization pass: `X-Wls-Performance-Total` about `174-177ms`, `router_start` about `128ms`
- accounting follow-up sample on the same login page after the new headers/spans:
- headers: `Total 326.62ms`, `RunAfter 48.08ms`, `PreTelemetryTotal 325.84ms`, `Telemetry 0.77ms`, `DevTool 47.43ms`, `X-WLS-Process-Time 333.94ms`
- injected trace: `dev_tool_panel 47.43ms`, `dev_tool_panel::render_panel 47.4ms`, `dev_tool_panel::inject_html 0.01ms`
- post-cleanup verification after removing the stale unreachable branch:
- headers: `Total 4712.82ms`, `RunAfter 84.74ms`, `PreTelemetryTotal 4710.92ms`, `Telemetry 1.78ms`, `DevTool 83.43ms`, `X-WLS-Process-Time 4886.19ms`
- injected trace still contained `dev_tool_panel`, `dev_tool_panel::render_panel`, and `dev_tool_panel::inject_html`
- repeated hot-request TTFB during the follow-up pass was still noisy (`~0.47s-0.76s`) because the current login route/runtime state had drifted from the earlier 16:21 measurement, but the DevTool cost is now directly attributable instead of being hidden in a generic phase.

## Remaining Risks

- `ParserWordsRegister` is still non-trivial at about `57ms`; legacy collected-word cache size and the global “collect words on every request” design still deserve a second pass.
- the login page still ships the inline DevTool panel plus large JS/HTML payload. This pass makes that cost explicit; it does not remove it.
- in the current environment the panel is still rendered during the compat `run_after` path, so `Telemetry` stays tiny while `RunAfter` and `DevTool` track together. If we want the accounting phases themselves to be cleaner, the next step would be to move panel injection fully onto the telemetry event path after confirming event-registry compatibility expectations.
- URL generation / website detection observers are called repeatedly during login rendering; request-scoped memoization could shave another small but real chunk.

## Next Resume Step

- If the goal is pure measurement transparency first, keep the new headers and request-trace spans as the baseline and consider one more small slice that moves DevTool rendering off the compat `run_after` path.
- If the goal shifts back to absolute latency, then the highest-value next perf slice is still DeveloperWorkspace lazy injection plus request-scoped memoization for URL rewrite / website detection observers.

## Update 2026-03-24 20:55

- Continued the task with a second framework slice after `a6295041`.
- Added two more optimizations:
- `Weline_BackendActivity` now skips activity-log insert/update work for unauthenticated `GET admin/login`
- `Weline_SystemConfig` now supports request-scope plus persistent cache reuse for both single-key lookups and module-level bulk reads, including cached `null` misses
- `Weline\Backend\Model\Config` and backend login now use the new bulk config path so the login page no longer fans out into repeated `m_system_config` reads

### Additional Changed Files

- `app/code/Weline/BackendActivity/Observer/BackendControllerInit.php`
- `app/code/Weline/BackendActivity/Observer/BackendControllerRouteAfter.php`
- `app/code/Weline/SystemConfig/Model/SystemConfig.php`
- `app/code/Weline/SystemConfig/extends/module/Weline_Framework/Query/SystemConfigQueryProvider.php`
- `app/code/Weline/SystemConfig/Test/Unit/Model/SystemConfigTest.php`
- `app/code/Weline/Backend/Model/Config.php`
- `app/code/Weline/Admin/Controller/Login.php`
- `app/code/Weline/UrlManager/Test/Unit/Observer/SeoUrlGenerateRewriteTest.php`

### Additional Verification

- `php -l app/code/Weline/BackendActivity/Observer/BackendControllerInit.php`
- `php -l app/code/Weline/BackendActivity/Observer/BackendControllerRouteAfter.php`
- `php -l app/code/Weline/SystemConfig/Model/SystemConfig.php`
- `php -l app/code/Weline/SystemConfig/extends/module/Weline_Framework/Query/SystemConfigQueryProvider.php`
- `php -l app/code/Weline/SystemConfig/Test/Unit/Model/SystemConfigTest.php`
- `php -l app/code/Weline/Backend/Model/Config.php`
- `php -l app/code/Weline/Admin/Controller/Login.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/SystemConfig/Test/Unit/Model/SystemConfigTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/I18n/test/Unit/Observer/ParserWordsRegisterTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/UrlManager/Test/Unit/Observer/SeoUrlGenerateRewriteTest.php --colors=never`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Admin/Test/Unit/Service/BackendVerificationCodeGateTest.php --colors=never`
- `php bin/w server:reload --no-wait`
- repeated live `curl -k` warm probes against `https://127.0.0.1:9982/.../admin/login?no_access_reason=not_logged_in`

### Updated Live Result

- warm run samples after the config-cache slice: `710.24ms`, `336.33ms`, `51.22ms`, `80.65ms`, `33.35ms`
- best observed hot request: `Total 33.35ms`, `Routerstart 25.36ms`, `RunAfter 4.80ms`, `DevTool 4.42ms`, `X-WLS-Process-Time 36.51ms`
- best hot trace: `router_start 25.88ms`, `controller_chain::action_execute 19.37ms`, `dev_tool_panel 4.42ms`, `ProcessUrlBefore 2.18ms`, `m_guolairen_page_builder_page 1.55ms`
- important confirmation: the hot trace no longer contained repeated `m_system_config` DB spans after the `SystemConfig` cache changes

### Updated Remaining Risk

- steady-state hot requests are now in the target tens-of-milliseconds range, but post-reload cold spikes are still large before caches warm
- the main remaining visible backend DB hotspot on the best hot trace is the `PageBuilder` lookup under `ModuleRouter::ProcessUrlBefore`
- the login page still ships the inline DevTool panel plus large JS/HTML payload; this pass keeps it and makes its cost explicit rather than removing it
