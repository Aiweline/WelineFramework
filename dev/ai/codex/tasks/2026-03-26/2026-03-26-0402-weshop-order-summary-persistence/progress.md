# Progress - WeShop order summary persistence

- 2026-03-26 04:02 Created the task workspace.
- 2026-03-26 04:xx Audited the next post-tax gap and picked the smallest production-critical
  slice: checkout summary was computed correctly but not persisted into the order record, so
  success/retry flows could fall back to zero shipping/tax/discount.
- 2026-03-26 04:xx Added persistent order summary fields on `WeShop_Order`, passed them through
  checkout order creation, and rewired order retry/success fallbacks to read the persisted data.
- 2026-03-26 04:xx Expanded focused unit coverage across checkout, order retry context, and order
  success page fallback behavior, then ran a targeted `WeShop_Order` setup upgrade plus checkout
  clean-route smoke on `9982`.
