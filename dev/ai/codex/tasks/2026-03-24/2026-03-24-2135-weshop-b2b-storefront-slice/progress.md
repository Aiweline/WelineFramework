# Progress - WeShop B2B storefront slice

- 2026-03-24 21:35 Created the task workspace, reviewed the AGENTS guidance, and captured the scope/plan for the B2B storefront slice.
- 2026-03-24 21:40 Added the B2B storefront controller, page-data service, default theme page, router metadata, and hook documentation.
- 2026-03-24 21:55 Created unit tests for the B2B page data service and controller plus the account card, and ran the targeted PHPUnit suites (warning only: no code coverage driver).
- 2026-03-24 23:39 Resumed the slice after audit review identified a product-risk: the page and account card were presenting the public company table as if it belonged to the logged-in customer.
- 2026-03-24 23:45 Reworked the storefront slice to scope records by the signed-in account email:
  - `CompanyService` now exposes contact-email-specific list/summary methods.
  - `CompanyPageDataService` now returns an explicit empty state when no contact email is available instead of falling back to the global company table.
  - `B2B\Index` now passes both `customerId` and `contactEmail`.
- 2026-03-24 23:49 Updated the account discovery card and default-theme `b2b` page copy so they describe linked company records rather than global registry stats, and surface the current linked contact email in the page hero.
- 2026-03-24 23:53 Extended PHPUnit coverage to lock in the new email-linked behavior and the empty-state contract.
- 2026-03-24 23:58 Ran targeted validation:
  - `php -l` passed for touched B2B PHP files and the default-theme `pages/b2b/index.phtml`.
  - `php vendor/bin/phpunit --no-coverage app/code/WeShop/B2B/Test/Unit/Service/CompanyPageDataServiceTest.php app/code/WeShop/B2B/Test/Unit/Controller/Frontend/B2B/IndexTest.php --colors=never` passed (`4` tests, `20` assertions; one existing PHPUnit deprecation remains).
- 2026-03-25 00:03 `php bin/w setup:upgrade -m WeShop_B2B --yes` exposed a real framework-compatibility defect in the slice: hook names using `b2b` in the component segment violate the strict `[a-z-]+` rule because digits are not allowed.
- 2026-03-25 00:07 Renamed the B2B layout hook to the compliant `WeShop_B2B::frontend::layouts::business::page-before` and the list hook to `WeShop_B2B::frontend::partials::company::list-after`, then updated the host template and hook docs.
- 2026-03-25 00:10 Re-ran `php bin/w setup:upgrade -m WeShop_B2B --yes`; B2B module hook/registry scanning now passes, and the command only fails later at the known unrelated global SQLite adapter deprecation (`use Pgsql`).
