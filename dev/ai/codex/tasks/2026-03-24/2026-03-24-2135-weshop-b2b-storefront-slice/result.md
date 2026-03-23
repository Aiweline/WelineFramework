# Result - WeShop B2B storefront slice

## Outcome
- Introduced a dedicated `weshop/b2b` storefront route with `CompanyPageDataService`, summary computations, and a rich default-theme `b2b` page for company records linked to the signed-in account email.
- Injected a `WeShop_Customer::frontend::account::orders::cards` card that surfaces a B2B entry point without leaking the global company registry, provided hook documentation for both the card and the new page layout, and kept translations in sync for the revised copy.
- Added targeted PHPUnit coverage for the page data service and controller, then fixed the slice's hook names to satisfy the framework's stricter `[a-z-]+` validator.

## Changed Files
- All new/modified module files under `app/code/WeShop/B2B/` (controller, services, hooks, docs, router metadata, translations, tests, and default-theme view assets), including:
  - `Service/CompanyService.php`
  - `Service/CompanyPageDataService.php`
  - `Controller/Frontend/B2B/Index.php`
  - `hook.php`
  - `view/hooks/WeShop_Customer/frontend/account/orders/cards.phtml`
  - `Test/Unit/Service/CompanyPageDataServiceTest.php`
  - `Test/Unit/Controller/Frontend/B2B/IndexTest.php`
  - `doc/hook/frontend/b2b/page-before.md`
  - `doc/hook/frontend/b2b/company-list-after.md`
  - `i18n/en_US.csv`
  - `i18n/zh_Hans_CN.csv`
- `app/design/WeShop/default/frontend/pages/b2b/index.phtml`
- The B2B task workspace files plus plan/progress updates under `dev/ai/codex/tasks/2026-03-24/2026-03-24-2135-weshop-b2b-storefront-slice/`.

## Verification
- `php -l app/code/WeShop/B2B/Service/CompanyService.php app/code/WeShop/B2B/Service/CompanyPageDataService.php app/code/WeShop/B2B/Controller/Frontend/B2B/Index.php app/code/WeShop/B2B/Test/Unit/Service/CompanyPageDataServiceTest.php app/code/WeShop/B2B/Test/Unit/Controller/Frontend/B2B/IndexTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/WeShop/B2B/Test/Unit/Service/CompanyPageDataServiceTest.php app/code/WeShop/B2B/Test/Unit/Controller/Frontend/B2B/IndexTest.php --colors=never` (passed: `4 tests`, `20 assertions`; one existing PHPUnit deprecation remains).
- `php bin/w setup:upgrade -m WeShop_B2B --yes`
  - failed first on a real slice issue: hook names containing `b2b` violated the strict hook-name validator.
  - passed B2B hook/registry scanning after renaming to compliant hook names.
  - still fails later in the global framework DB bootstrap stage due the unrelated SQLite adapter deprecation (`use Pgsql`).

## Remaining Risks
- Records are currently linked by company contact email because the module schema still lacks a first-class company-to-customer relationship table. A future B2B core slice should replace this with explicit membership/role mapping and approval metadata.

## Next Resume Step
- Build the real company-membership relationship model (company members, roles, approvals, credit lines) so the B2B storefront and backend can move from email matching to explicit corporate account ownership.
