# aiweline/binquery-php

Official PHP client for Weline BinQuery.

```bash
composer require aiweline/binquery-php
```

```php
use Aiweline\BinQuery\BinQueryClient;

$client = BinQueryClient::connect([
    'domain' => 'example.com',
    'apiKey' => getenv('WELINE_BINQUERY_KEY'), // Weline_Api app access_token
]);

$result = $client->call('theme', 'list', [
    'page' => 1,
    'page_size' => 20,
]);
```

The client derives `https://{domain}/bin/query`, defaults to `area=frontend`, and uses `cache=auto`.

`apiKey` is a temporary `Weline_Api` third-party app `access_token`, not the permanent API user `api_key` and not `client_secret`. Create and authorize an app with the BinQuery scope, exchange the authorization code through `POST /api/rest/v1/apps/token`, then pass the returned `access_token` here. The default access token TTL is 3600 seconds; refresh it with `POST /api/rest/v1/apps/refresh`.
