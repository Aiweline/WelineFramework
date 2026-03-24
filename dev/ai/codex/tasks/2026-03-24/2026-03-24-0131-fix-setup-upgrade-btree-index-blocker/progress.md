# Progress - fix setup-upgrade btree index blocker

- 2026-03-24 01:31 Created the task workspace.
- Investigated the real `BTREE` blocker and fixed the concrete bad schema declaration in `WeShop_GiftCard`.
- Added compatibility normalization in `IndexDefinition` so misplaced `BTREE` / `HASH` values in `type` fall back to default index type and become index methods instead of hard-failing upgrade.
- Added regression coverage for schema normalization, SQL compiler memory growth, and duplicate admin-role creation during upgrade.
- Fixed follow-up upgrade regressions uncovered by rerunning the full chain:
  - `GuoLaiRen_Blog` migration now checks missing legacy columns before altering them.
  - `setup:upgrade` keeps the raised memory limit for the full upgrade window.
  - `WeShop_Shipping` hook registration no longer exposes the invalid legacy hook alias.
  - missing `WeShop_Customer` hook docs were added so strict hook-doc validation passes.
- Re-verified `php bin/w setup:upgrade --yes` until it completed with exit code `0`.
- Re-ran the AI site workbench E2E spec, investigated an intermittent first-run hub-anchor failure, then hardened the first test with an explicit hub readiness wait before asserting the provider lane anchor.
- Final focused Playwright rerun passed with `3 passed`.
