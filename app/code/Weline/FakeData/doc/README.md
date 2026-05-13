# Weline_FakeData

`Weline_FakeData` is a development-only CLI module for importing fake data through providers registered with `extends`.

## Commands

```bash
php bin/w fake-data:import --dry-run
php bin/w fake-data:import --provider=weshop_catalog
php bin/w fake-data:import --module=WeShop_Product --reset --force
```

The command runs only in `dev` or `development` deploy mode. `--reset` requires `--force` and cleans only records tracked in the fake data ledger.

## Provider Contract

Providers live under:

```text
extends/module/Weline_FakeData/Provider/{Name}Provider.php
```

Each provider implements `Weline\FakeData\Api\FakeDataProviderInterface`. Providers must be idempotent and should record every created entity with `FakeDataContext::record()`.

