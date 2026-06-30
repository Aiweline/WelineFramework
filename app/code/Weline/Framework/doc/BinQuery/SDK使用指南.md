# BinQuery SDK 使用指南

BinQuery SDK 的连接参数保持简单：只填写 `domain` 和 `apiKey`。SDK 默认使用 `https://{domain}/bin/query`，默认 `area=frontend`，默认 `cache=auto`。

## apiKey 获取与有效期

SDK 里的 `apiKey` 填的是 `Weline_Api` 第三方应用的 `access_token`，不是老 API 用户表里的永久 `api_key`，也不是 `client_secret`。它是临时访问令牌，默认有效期为 `3600` 秒；`refresh_token` 默认有效期为 `2592000` 秒，也就是 30 天。

后台/服务端创建流程：

1. 在后台 API 集成中创建第三方应用，或调用 `POST /api/rest/v1/apps/create` 创建应用，得到 `client_id` 和 `client_secret`。`client_secret` 只在创建时返回一次，应长期安全保存；应用被禁用、删除或重置后失效。
2. 给该应用授权 BinQuery scope，至少包含 `Weline_Framework::binquery` 或 `Weline_Framework::binquery::post`。授权接口会返回一次性 `code`，这个 code 只是临时换 token 凭证，不写进 SDK。
3. 调用 `POST /api/rest/v1/apps/token`，传入 `client_id`、`client_secret`、`code`、`redirect_uri`，换取 `access_token` 和 `refresh_token`。
4. SDK 的 `apiKey` 填第 3 步返回的 `access_token`。如果返回 `auth_error` 或 token 过期，调用 `POST /api/rest/v1/apps/refresh` 用 `refresh_token` 换新的 token。

生命周期说明：

- `client_id`：应用标识，长期有效，直到应用被禁用或删除。
- `client_secret`：应用密钥，长期有效但只创建时展示一次，泄漏后应重新创建或重置应用。
- `code`：授权码，一次性临时凭证，只用于换 token。
- `access_token`：SDK `apiKey` 实际使用的值，临时有效，默认 `3600` 秒。
- `refresh_token`：刷新凭证，默认 `2592000` 秒；刷新成功后旧 refresh token 会被撤销。

不要把老接口 `/api/rest/v1/weline_api/auth/exchange` 的 API 用户 `api_key/api_secret` 当成 BinQuery SDK 的 `apiKey`。BinQuery 网关只校验第三方应用 `access_token`，并检查它是否拥有 BinQuery scope。

## PHP SDK

安装地址：

```bash
composer require aiweline/binquery-php
```

源码下载目录：

```text
sdk/binquery-php
```

最小使用：

```php
use Aiweline\BinQuery\BinQueryClient;

$client = BinQueryClient::connect([
    'domain' => 'example.com',
    'apiKey' => getenv('WELINE_BINQUERY_KEY'), // Weline_Api 应用 access_token
]);

if ($client->hasOperation('theme', 'list')) {
    $docs = $client->docs('theme', 'list');
    $result = $client->call('theme', 'list', [
        'page' => 1,
        'page_size' => 20,
    ]);
}
```

常用查询：

```php
$providers = $client->providers();
$theme = $client->provider('theme');
$operations = $client->operations('theme');
$docs = $client->docs('theme', 'list');
$exists = $client->exists('theme', 'list');
```

Graph：

```php
$result = $client->graph([
    [
        'as' => 'themes',
        'provider' => 'theme',
        'operation' => 'list',
        'params' => ['page' => 1, 'page_size' => 20],
    ],
]);
```

关闭自动缓存 marker：

```php
$client = BinQueryClient::connect([
    'domain' => 'example.com',
    'apiKey' => getenv('WELINE_BINQUERY_KEY'),
    'cache' => false,
]);
```

## JS SDK

安装地址：

```bash
npm install @aiweline/binquery
```

源码下载目录：

```text
sdk/binquery-js
```

最小使用：

```js
import { BinQueryClient } from '@aiweline/binquery';

const client = await BinQueryClient.connect({
  domain: 'example.com',
  apiKey: process.env.WELINE_BINQUERY_KEY, // Weline_Api 应用 access_token
});

if (await client.hasOperation('theme', 'list')) {
  const docs = await client.docs('theme', 'list');
  const result = await client.call('theme', 'list', {
    page: 1,
    page_size: 20,
  });
}
```

常用查询：

```js
const providers = await client.providers();
const theme = await client.provider('theme');
const operations = await client.operations('theme');
const docs = await client.docs('theme', 'list');
const exists = await client.exists('theme', 'list');
```

Graph：

```js
const result = await client.graph([
  {
    as: 'themes',
    provider: 'theme',
    operation: 'list',
    params: { page: 1, page_size: 20 },
  },
]);
```

## cache:auto

SDK 默认 `cache=auto`。当 operation docs 中包含公开 CDN cache descriptor 时，SDK 会自动追加 `__wq_cache` 查询参数。服务端会重新计算 marker：

- marker 正确：返回 `Cache-Control: public, max-age=..., s-maxage=...`
- marker 缺失或错误：返回 `Cache-Control: no-store`

SDK 不决定缓存头，只表达“这个请求希望走可缓存路径”。

## DeveloperWorkspace API 文档入口

PHP/JS SDK 下载与安装信息会进入 DeveloperWorkspace 的 API 文档管理界面：

- 页面：`/dev/tool/docs/api`
- 分组：`BinQuery SDK`
- PHP 下载位置：`sdk/binquery-php`
- JS 下载位置：`sdk/binquery-js`
- 协议对接说明：`app/code/Weline/Framework/doc/BinQuery/协议对接指南.md`

该入口通过 `Weline_DeveloperWorkspace::api_doc_collect_after` 事件贡献，不直接写入 `Weline_Api` 的 `ApiDocService`。
