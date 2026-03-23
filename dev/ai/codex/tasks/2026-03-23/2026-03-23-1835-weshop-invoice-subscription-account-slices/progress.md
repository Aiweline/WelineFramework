# Progress - weshop invoice subscription account slices

- 2026-03-23 18:35 Created the task workspace.
- 2026-03-24 02:36 Reviewed the completed `Invoice` and `Subscription` worker outputs and confirmed both slices stayed within their write boundaries (`Invoice` / `Subscription` modules, default-theme pages, and task logs only).
- 2026-03-24 02:41 Tightened the `Invoice` slice locally by correcting the storefront `layoutType` to `invoice` and switching invoice summary cards to use customer-wide issued/pending counts instead of only the current paginated page.
- 2026-03-24 02:46 Expanded `Invoice` and `Subscription` i18n coverage so the new default-theme pages and account cards do not fall back to raw English copy in the Chinese locale.
- 2026-03-24 02:49 Re-ran targeted syntax checks plus PHPUnit for `WeShop_Invoice` and `WeShop_Subscription`; all assertions passed, with the repo-wide no-coverage warning still causing non-zero exits.
- 2026-03-24 02:53 Re-ran `php bin/w setup:upgrade -m WeShop_Invoice -m WeShop_Subscription --yes`; scoped module route/hook refresh passed, but the upgrade still fails later because an unrelated environment/module path instantiates the removed SQLite adapter.
