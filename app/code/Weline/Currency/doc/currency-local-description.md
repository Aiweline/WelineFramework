# Currency LocalDescription

## Purpose

Currency display names need to follow the active language. `CurrencyData` is the shared read path used by the storefront currency switcher and other callers, so it returns a localized `name` when a matching local description exists.

## Data Model

- Main model: `Weline\Currency\Model\Currency`
- Local model: `Weline\Currency\Model\Currency\LocalDescription`
- Public localization base: `Weline\I18n\Api\Localization\LocalModel`
- Relation field: `currency_id`
- Locale field: `local_code`
- Localized name field: `name`

`CurrencyData::getCurrencies()` and `CurrencyData::getCurrency()` call `loadLocalDescription()` and replace the returned `name` with `local_name` when present. Cache keys include the active language to avoid mixing names across locales.

## Default Data

Install, upgrade, and the post-upgrade observer seed default local names for existing default currencies through `CurrencyLocalDescriptionSeed`. The seeder checks that both the main currency table and local-description table exist before writing.

- `CNY`: Chinese Yuan in `en_US`
- `USD`: US Dollar in `en_US`
- `EUR`: Euro in `en_US`
- `GBP`: British Pound in `en_US`

## Backend Editing

The backend currency add/edit form exposes one `local_names[locale_code]` input per installed active locale. On save, `CurrencyLocalDescriptionService` upserts non-empty values into `weline_currency_local_description`; an empty value deletes that locale row so reads fall back to the base `currency.name`.

The locale selector consumes immutable records from `Weline\I18n\Api\Localization\LocaleRepositoryInterface`.
It never receives I18n ORM models or query builders. Other modules that need the active currency list use
`Weline\Currency\Api\CurrencyCatalogInterface` and immutable `CurrencyRecord` values.

This lets administrators override seeded values such as `US Dollar` without changing the base currency record or translation CSV files.
