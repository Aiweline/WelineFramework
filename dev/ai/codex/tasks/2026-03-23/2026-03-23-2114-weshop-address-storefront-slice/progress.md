# Progress - weshop address storefront slice

- 2026-03-23 21:14 Created the task workspace.
- 2026-03-24 12:xx Re-read workspace memory/context, confirmed `ACTIVE.md` is deprecated, and reassigned two existing subagents to parallel audits because the session was already at the subagent thread limit.
- 2026-03-24 12:xx Audited the old `WeShop_Address` module: found legacy `ObjectManager` + session controllers, no clean route, no default-theme page, and a stale custom `Address` model that drifted from `Weline_Shipping` delivery-address fields.
- 2026-03-24 12:xx Rebuilt `WeShop_Address` around `Weline_Shipping\Service\DeliveryAddressService` via a new façade `AddressService`, plus `AddressPageDataService`, clean `address` router config, and thin storefront controllers (`Index`, `Save`, `Delete`, `DefaultAddress`, compatibility `AddressList`).
- 2026-03-24 12:xx Added `default` theme address page and account-center discovery card; normalized customer-account and checkout-success links to the clean `address` route.
- 2026-03-24 12:xx Hardened `DeliveryAddressService` delete execution and relaxed phone/postcode validation for international addresses.
- 2026-03-24 12:xx Added targeted unit coverage for the address service normalization, page-data builder, and storefront controllers.
- 2026-03-24 12:xx Parallel audit results:
  - theme compatibility backlog: `Catalog` canonical host alignment, `Filters` container host on category page, `Checkout` shipping host parity across layout variants
  - backend/API backlog: `Promotion backend` safest next admin slice; `Order` highest value but larger
