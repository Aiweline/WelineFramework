# Result - weshop storefront module wave promotion-b2b-and-next

## Outcome

- In progress.
- Promotion storefront slice has been hardened and committed with coupon-flow runtime fixes and targeted controller coverage.
- B2B storefront slice has been hardened and is ready to commit with safer account-email-linked summaries plus compliant hook names.
- Audit results now point to `Order`, `Report`, `Address`, `Logistics`, `Promotion backend`, and `Inventory` as the next high-value completion slices, while `Product/Catalog/Cart` theme-host compatibility remains the main storefront injection gap.

## Changed Files

- See the dedicated per-slice task workspaces for Promotion and B2B:
  - `dev/ai/codex/tasks/2026-03-24/2026-03-24-2140-weshop-promotion-storefront-slice/`
  - `dev/ai/codex/tasks/2026-03-24/2026-03-24-2135-weshop-b2b-storefront-slice/`

## Verification

- Promotion:
  - targeted `php -l` passed
  - targeted PHPUnit passed
  - `setup:upgrade -m WeShop_Promotion --yes` reached module registry/hook validation successfully, then failed later on the known unrelated SQLite adapter issue
- B2B:
  - targeted `php -l` passed
  - targeted PHPUnit passed
  - `setup:upgrade -m WeShop_B2B --yes` first exposed and then cleared a real hook-name validator issue; rerun then reached module registry/hook validation successfully before the same unrelated SQLite adapter issue

## Remaining Risks

- Default-theme host slots are still inconsistent with newer module hook declarations in `Product`, `Catalog`, `Filters`, `Cart`, and parts of `Checkout`.
- Many remaining WeShop gaps are now backend/menu/API/test completeness rather than storefront rendering alone.
- Repo-wide environment noise remains: late `setup:upgrade` stages still fail because an unrelated module path tries to instantiate the deprecated SQLite adapter instead of Pgsql.

## Next Resume Step

- Commit the staged B2B slice, then start the next independent module completion pass from the audit queue, with special attention to default-theme hook-host normalization in `Product/Catalog/Cart`.
