# Result - weline bot ai guided config

## Outcome

- Completed a humanized configuration upgrade for `Weline_Bot` role management:
  - guided role setup flow (template + business context + AI suggestion)
  - guided list-page entry flow (profile quick-start + template quick-start for many-project users)
  - AI-assisted role draft endpoint with robust fallback strategy
  - profile-scale strategy layer for role draft generation (`single_project` / `multi_project` / `enterprise`)
  - advanced configuration controls for model/adapter/skills/permissions/model-config
  - non-AI quick path (`Apply Template Baseline`) for users who want one-click setup without model invocation
  - safer save parsing and normalization in backend controller
- Completed the follow-up backend stabilization for broader Bot module usability:
  - fixed Bot adapter parser/runtime blockers by rewriting `BotAgentAdapter`, `ITOpsAdapter`, and `SEOAdapter`
  - added missing backend surfaces (controller/templates) for Skill / Schedule / Session / Chat / Memory so menu-linked pages no longer hit missing-template/runtime failures
  - introduced `Controller/Backend/Memory.php` and memory listing page
  - rebuilt `etc/backend/menu.xml` with clean action labels and aligned routes
  - added dedicated Bot backend E2E smoke spec and verified pages render without fatal runtime patterns

## Changed Files

- app/code/Weline/Bot/Controller/Backend/Role.php
- app/code/Weline/Bot/Controller/Backend/Session.php
- app/code/Weline/Bot/Controller/Backend/Memory.php
- app/code/Weline/Bot/Adapter/BotAgentAdapter.php
- app/code/Weline/Bot/Adapter/ITOpsAdapter.php
- app/code/Weline/Bot/Adapter/SEOAdapter.php
- app/code/Weline/Bot/Service/RoleConfigAssistant.php (new)
- app/code/Weline/Bot/view/templates/Backend/Role/form.phtml
- app/code/Weline/Bot/view/templates/Backend/Role/listing.phtml
- app/code/Weline/Bot/view/templates/Backend/Chat/index.phtml
- app/code/Weline/Bot/view/templates/Backend/Session/listing.phtml
- app/code/Weline/Bot/view/templates/Backend/Session/view.phtml
- app/code/Weline/Bot/view/templates/Backend/Skill/listing.phtml
- app/code/Weline/Bot/view/templates/Backend/Skill/view.phtml
- app/code/Weline/Bot/view/templates/Backend/Schedule/listing.phtml
- app/code/Weline/Bot/view/templates/Backend/Schedule/form.phtml
- app/code/Weline/Bot/view/templates/Backend/Memory/listing.phtml
- app/code/Weline/Bot/etc/backend/menu.xml
- app/code/Weline/Bot/etc/env.php
- app/code/Weline/Bot/Test/Unit/Service/RoleConfigAssistantTest.php (new)
- tests/e2e/specs/backend/weline-bot-backend.spec.js (new)

## Verification

- `php -l app/code/Weline/Bot/Controller/Backend/Role.php` -> passed
- `php -l app/code/Weline/Bot/Service/RoleConfigAssistant.php` -> passed
- `php -l app/code/Weline/Bot/view/templates/Backend/Role/form.phtml` -> passed
- `php -l app/code/Weline/Bot/view/templates/Backend/Role/listing.phtml` -> passed
- `php -l app/code/Weline/Bot/etc/env.php` -> passed
- `php -l app/code/Weline/Bot/Test/Unit/Service/RoleConfigAssistantTest.php` -> passed
- recursive `php -l` for `app/code/Weline/Bot/**/*.php` -> passed (no syntax failures)
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Bot/Test/Unit/Service/RoleConfigAssistantTest.php --colors=never` -> passed (`4 tests / 26 assertions`, existing PHPUnit deprecation notice remains)
- `php tests/e2e/framework/preflight-refresh.php` -> passed
- `cd tests/e2e && node start.js specs/backend/weline-bot-backend.spec.js` -> passed (`7 passed`)
- `php bin/w setup:upgrade --route` -> fails in current workspace baseline due CLI arg validation inconsistency (`--route` flagged as unknown while help lists it as supported)

## Remaining Risks

- Live backend browser acceptance for role suggestion endpoint was not executed in this turn.
- Route-only upgrade command is currently blocked by baseline framework CLI issue in this environment.

## Next Resume Step

- Expand Bot backend E2E from smoke to interaction-level flows:
  - role create/save with template baseline + AI suggestion
  - schedule save and status toggle
  - session detail rendering with message fixtures

## Close-out Addendum (2026-03-25 14:55)

- Closed the last failing Bot backend E2E flow end-to-end.
- Root cause of the old session_id = 0 failure was not session persistence: the test called https://.../api123/bot/api/v1/chat/send, which returns 404 in this runtime. The generated frontend router and live browser probe confirmed the correct route is https://.../bot/api/v1/chat/send.
- Follow-up runtime gap fixed in backend controllers:
  - Controller/Backend/Session.php now explicitly renders iew.phtml for getView().
  - Controller/Backend/Skill.php now explicitly renders iew.phtml for getView().
- Final focused verification is green:
  - php tests/e2e/framework/preflight-refresh.php -> passed
  - php -l app/code/Weline/Bot/Controller/Backend/Skill.php -> passed
  - php -l app/code/Weline/Bot/Controller/Backend/Session.php -> passed
  - PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_E2E_TRANSPORT=direct node tests/e2e/start.js specs/backend/weline-bot-backend.spec.js -> passed (10 passed)
- Additional changed files in this close-out:
  - app/code/Weline/Bot/Controller/Backend/Skill.php
  - tests/e2e/specs/backend/weline-bot-backend.spec.js

## Updated Remaining Risks

- Bot chat still degrades with a runtime error message when the i query provider is unavailable; this no longer blocks backend E2E, but it is still a product/runtime readiness gap if chat execution must work without Weline_Ai being fully configured.
- php bin/w setup:upgrade --route remains blocked by the baseline CLI inconsistency in this workspace.

## Updated Next Resume Step

- Improve Bot chat runtime degradation when w_query('ai', ...) is unavailable so chat API responses stay human-readable and non-technical even without a configured AI provider.

## Close-out Addendum (2026-03-25 16:15)

- Closed the hidden Bot chat runtime contract drift that still sat behind the green backend page E2E:
  - `AgentEngine` had been calling a removed/invalid `AiService::executeAgent*()` shape and also depended on a non-existent `ai` query provider for default-model lookup.
  - `SkillPackageManager` was emitting pre-wrapped OpenAI tool objects while current providers expect flat `name` / `description` / `parameters` tool definitions.
- Implemented the runtime-safe fix instead of masking the symptom:
  - `AiService` now exposes `generateStructured()` so Bot can keep provider `tool_calls` and manage its own tool loop.
  - `AiService::mergeConfigToProviderConfig()` now filters empty overrides before merging, preserving the earlier missing-key hardening.
  - `AgentEngine` now:
    - resolves configured model ids directly through `AiModel`
    - sends full session history to `generateStructured()`
    - persists assistant tool-call messages before tool-result turns
    - degrades stream mode to one final chunk instead of relying on the broken old pseudo-stream path
    - stays backward-compatible with stale generated factories by accepting optional tail injection for `AiModel`
  - `SkillPackageManager` now returns flat tool definitions compatible with the current OpenAI/Anthropic providers.
  - The Bot backend E2E chat assertion now explicitly rejects leaked internal contract text (`QueryProviderInterface`, `executeAgent(`, etc.).
- Final focused verification after the contract fix is green:
  - `php -l app/code/Weline/Ai/Service/AiService.php` -> passed
  - `php -l app/code/Weline/Bot/Service/AgentEngine.php` -> passed
  - `php -l app/code/Weline/Bot/Service/SkillPackageManager.php` -> passed
  - `php tests/e2e/framework/preflight-refresh.php` -> passed
  - `PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_E2E_TRANSPORT=direct PLAYWRIGHT_SKIP_PREFLIGHT=1 node tests/e2e/start.js specs/backend/weline-bot-backend.spec.js --reporter=line` -> passed (`10 passed`)
- Additional changed files in this addendum:
  - app/code/Weline/Ai/Service/AiService.php
  - app/code/Weline/Bot/Service/AgentEngine.php
  - app/code/Weline/Bot/Service/SkillPackageManager.php
  - tests/e2e/specs/backend/weline-bot-backend.spec.js

## Updated Remaining Risks (2026-03-25 16:15)

- Bot chat no longer leaks the old missing-query-provider / invalid-contract errors in the verified backend flow, but full real-provider tool-calling behavior is still only indirectly covered; there is no dedicated Bot unit test for multi-step tool loops yet.
- `php bin/w setup:upgrade --route` remains blocked by the baseline CLI inconsistency in this workspace.

## Updated Next Resume Step (2026-03-25 16:15)

- Add focused Bot service/unit coverage for `AgentEngine` tool-loop persistence and then expand the backend/browser flow to cover a real configured provider/tool execution path.

## Performance Closure Addendum (2026-03-25)

- Closed the hidden fallback storefront regression that was still blocking broader e2e confidence even after Bot backend was green.
- Root cause was not `WeShop_Analytics` logic itself:
  - request tracing still showed `template::getHook::Weline_Theme::frontend::layouts::base::head-before` taking about `16.3s`
  - a direct CLI benchmark showed `QueryProviderRegistry::getProvider('analytics')` at about `15600ms`
  - follow-up provider warmup profiling showed the real cost came from eager loading unrelated query providers, especially `memory` and `server`
- Implemented the framework-level fix:
  - restored `app/code/Weline/Framework/View/Template.php` from `HEAD` after a prior accidental UTF-16 rewrite so framework autoloading returned to a safe baseline
  - refactored `app/code/Weline/Framework/Service/Query/QueryProviderRegistry.php` so `getProvider()` no longer instantiates every registered provider up front
  - provider discovery now parses literal `getProviderName()` returns from source files during the scan phase and only instantiates the requested provider on demand
  - added `app/code/Weline/Framework/Test/Unit/Service/Query/QueryProviderRegistryTest.php` to lock provider-name parsing and lazy single-provider instantiation
- Measured improvement after the fix:
  - query provider benchmark: `getProvider('analytics')` improved from about `15600.62ms` to about `15.07ms`
  - fallback storefront login probe improved from about `16.11s` to about `0.55s`
  - fallback `/compare` probe returned in about `0.397s`
- Verification after the performance fix is green:
  - `php -l app/code/Weline/Framework/View/Template.php`
  - `php -l app/code/Weline/Framework/Service/Query/QueryProviderRegistry.php`
  - `php -l app/code/Weline/Framework/Test/Unit/Service/Query/QueryProviderRegistryTest.php`
  - `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/Unit/Service/Query/QueryProviderRegistryTest.php --colors=never` (`2 tests / 7 assertions`)
  - `php vendor/bin/phpunit --no-coverage app/code/Weline/Bot/Test/Unit/Service/AgentEngineTest.php --colors=never` (`8 tests / 97 assertions`)
  - `node tests/e2e/start.js specs/frontend/weshop-compare.spec.js specs/frontend/weshop-invoice.spec.js specs/frontend/weshop-recently-viewed.spec.js specs/frontend/weshop-rma.spec.js specs/frontend/weshop-subscription.spec.js specs/frontend/weshop-wishlist.spec.js` (`7 passed`)
  - `PLAYWRIGHT_E2E_TRANSPORT=direct PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_REUSE_FALLBACK_RUNTIME=0 node tests/e2e/start.js` (full grouped suite passed)

## Updated Changed Files (Performance Closure)

- app/code/Weline/Framework/View/Template.php
- app/code/Weline/Framework/Service/Query/QueryProviderRegistry.php
- app/code/Weline/Framework/Test/Unit/Service/Query/QueryProviderRegistryTest.php
- app/code/Weline/Framework/Test/Unit/Service/Query/FrameworkQueryServiceTest.php
- dev/ai/codex/tasks/2026-03-25/2026-03-25-0302-weline-bot-ai-guided-config/plan.md
- dev/ai/codex/tasks/2026-03-25/2026-03-25-0302-weline-bot-ai-guided-config/progress.md
- dev/ai/codex/tasks/2026-03-25/2026-03-25-0302-weline-bot-ai-guided-config/result.md

## Updated Remaining Risks (Performance Closure)

- `QueryProviderRegistry` currently fast-paths providers whose `getProviderName()` is a literal return in source. Providers with dynamically computed names still fall back to deferred instantiation.
- `app/code/Weline/Framework/View/Template.php` is intentionally restored to `HEAD`; no further behavior change was made there in this turn.

## Updated Next Resume Step (Performance Closure)

- If future providers need computed names, extend registry metadata discovery without reintroducing eager provider instantiation.

## Query Contract Addendum (2026-03-26)

- Added one more framework-level test layer so the query-performance fix is harder to regress accidentally:
  - `QueryProviderRegistryTest` now covers a dynamic provider-name fallback path where source parsing cannot infer the name and registry must resolve it through deferred instantiation
  - `FrameworkQueryServiceTest` now covers:
    - normal provider execution with before/after event dispatch
    - deny-in-before-event short-circuiting
    - `framework/introspect` provider summarization
- Verification for this follow-up is green:
  - `php -l app/code/Weline/Framework/Test/Unit/Service/Query/QueryProviderRegistryTest.php`
  - `php -l app/code/Weline/Framework/Test/Unit/Service/Query/FrameworkQueryServiceTest.php`
  - `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/Unit/Service/Query/QueryProviderRegistryTest.php app/code/Weline/Framework/Test/Unit/Service/Query/FrameworkQueryServiceTest.php --colors=never` (`6 tests / 26 assertions`)

## Updated Next Resume Step (2026-03-26)

- If we continue on the framework side, the next useful step is a focused benchmark/assertion around registry scan cost itself, not just provider instantiation cost.
