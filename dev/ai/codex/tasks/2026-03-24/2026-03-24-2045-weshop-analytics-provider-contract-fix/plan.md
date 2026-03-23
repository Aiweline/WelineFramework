# Plan - weshop-analytics-provider-contract-fix

## Outcome

- `WeShop_Analytics` providers, dispatcher, and observers share one working contract and have unit coverage.

## Steps

- [x] Audit the current provider/dispatcher mismatch
- [x] Implement contract-safe provider and dispatcher behavior
- [x] Add focused unit tests
- [x] Run validation commands
- [x] Record results

## Verification Targets

- [x] Unit / phpunit
- [x] Syntax / php -l
- [x] setup:upgrade module scan if practical
