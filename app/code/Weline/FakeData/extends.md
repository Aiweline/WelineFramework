# Weline_FakeData Extends Contract

`Weline_FakeData` exposes one provider extension point:

```text
extends/module/Weline_FakeData/Provider
```

Modules may add PHP classes under that directory to provide development fake data. Each provider must implement:

```php
Weline\FakeData\Api\FakeDataProviderInterface
```

Providers are executed only through `php bin/w fake-data:import`. `setup:upgrade` may create the ledger table and rebuild registries, but it must not import business fake data.

Provider implementations should be idempotent. Use stable keys and the fake data ledger so `--reset` removes only rows created by the provider.

