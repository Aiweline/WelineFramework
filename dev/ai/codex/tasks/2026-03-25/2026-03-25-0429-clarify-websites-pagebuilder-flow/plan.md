# Plan - align websites pagebuilder handoff flow

## Outcome

- The real Websites workbench flow now matches the copy and architecture boundary.
- Confirming the PageBuilder lane in `prepare` hands off into the native PageBuilder workspace instead of keeping PageBuilder stages inside Websites.
- Background domain purchase keeps progressing in the Websites workspace while the PageBuilder handoff continues.

## Steps

- [x] Reconfirm the architecture boundary and inspect the actual Websites/PageBuilder control flow
- [x] Implement the real handoff path, native-entry resolution, and session scope seeding
- [x] Update the Websites workspace behavior so `prepare -> generate` redirects into the provider-native PageBuilder workspace
- [x] Add or update focused unit/e2e coverage for the handoff and background domain-purchase path
- [x] Run validation commands and record the verified outcome

## Verification Targets

- [x] `php -l` for the touched PHP/template files
- [x] `php vendor/bin/phpunit --no-coverage app/code/GuoLaiRen/PageBuilder/Test/Unit/Extends/Module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProviderTest.php --colors=never`
- [x] `php tests/e2e/framework/preflight-refresh.php`
- [x] `node tests/e2e/start.js specs/backend/ai-site-workbench.spec.js`
