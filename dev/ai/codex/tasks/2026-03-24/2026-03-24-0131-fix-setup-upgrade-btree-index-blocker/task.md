# Task: fix setup-upgrade btree index blocker

- Task ID: 2026-03-24-0131-fix-setup-upgrade-btree-index-blocker
- Started: 2026-03-24 01:31
- Status: completed
- Owner: Codex
- Source: fix the real BTREE upgrade blocker, keep AI site workbench E2E green, and submit the verified fix set

## Goal

- Fix the real `未知的索引类型：BTREE` upgrade blocker instead of only routing around it.
- Keep the AI site workbench end-to-end flow green after the framework/schema fixes.
- Submit only the files related to this blocker-removal and E2E stabilization chain.

## Scope

- In scope:
  - database schema/index normalization and the concrete bad schema declaration that triggered the failure
  - `setup:upgrade` regressions uncovered while rerunning the full upgrade flow
  - AI site workbench E2E stability and preflight/runtime preparation
  - minimal hook/doc compatibility fixes required to make upgrade and E2E pass again
- Out of scope:
  - unrelated dirty worktree changes in WeShop/PageBuilder/WLS modules
  - purchasing a real domain or external provider-side execution

## Constraints

- Worktree is heavily dirty; avoid staging or reverting unrelated files.
- Use recoverable, minimal fixes and keep compatibility where reasonable.
- Validation must include real `php bin/w setup:upgrade --yes` and the AI site workbench Playwright spec.

## Related Plans

- See [plan.md](./plan.md).

## Related Files

- `app/code/WeShop/GiftCard/Model/GiftCard.php`
- `app/code/Weline/Framework/Database/Schema/IndexDefinition.php`
- `app/code/Weline/Framework/Test/Unit/Database/Schema/IndexDefinitionTest.php`
- `app/code/Weline/Framework/Database/Compiler/MysqlCompiler.php`
- `app/code/Weline/Framework/Database/Compiler/PgsqlCompiler.php`
- `app/code/Weline/Framework/Database/Compiler/SqliteCompiler.php`
- `app/code/Weline/Framework/Database/test/Unit/DatabaseAstCompilerRegressionTest.php`
- `app/code/Weline/Backend/Setup/EnsureAdmin.php`
- `app/code/Weline/Backend/Setup/Upgrade.php`
- `app/code/Weline/Backend/test/Unit/Setup/EnsureAdminTest.php`
- `app/code/GuoLaiRen/Blog/Setup/Db/Migration/blog_post_summary_source_keyword_text_20250318-v1.0.2.php`
- `app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php`
- `app/code/WeShop/Shipping/hook.php`
- `app/code/WeShop/Shipping/doc/hook/checkout/methods.md`
- `app/design/WeShop/default/frontend/pages/checkout/index.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_1.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_2.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_3.phtml`
- `app/design/WeShop/default/frontend/layouts/checkout/checkout_page_4.phtml`
- `app/code/WeShop/Base/etc/theme-compatibility.php`
- `app/code/WeShop/Base/Test/Unit/Service/ThemeCompatibilityServiceTest.php`
- `app/code/WeShop/Customer/doc/hook/frontend/account/quick-links/after.md`
- `app/code/WeShop/Customer/doc/hook/frontend/account/recommendations/before.md`
- `app/code/WeShop/Customer/doc/hook/frontend/account/recommendations/after.md`
- `tests/e2e/specs/backend/ai-site-workbench.spec.js`
- `tests/e2e/start.js`
- `tests/e2e/framework/preflight-refresh.php`
- `tests/e2e/framework/preflight-refresh.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
