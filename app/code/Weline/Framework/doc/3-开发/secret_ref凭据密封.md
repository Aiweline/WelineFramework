# secret_ref 凭据密封（TASK-P1D-002）

## 目的

CDN / Storage 等账户凭据在落库与跨 Scope 传递时使用 `secret_ref:v1:` 封装，API/日志响应永不回传明文。

## 格式

```
secret_ref:v1:{base64url(nonce||ciphertext)}
```

实现：`Weline\Framework\Http\Security\SecretRefCipher`（libsodium `crypto_secretbox`）。

## 配置

`app/etc/env.php`（参考 `env.sample.php`）：

```php
'security' => [
    'secret_ref_key' => '生产环境必须换成高强度随机串',
],
```

仅 `ENV_TEST` 或 `DEV` 可在未配置时使用开发占位密钥；生产缺键直接报
`secret_ref_key_missing`，不得用固定密钥继续写入。

## 读写约定

| 路径 | 行为 |
|---|---|
| 写 | `Account::setCredentialsArray()` / `Domain::setCredentialsArray()` → `sealJson` |
| 读（服务端） | `getCredentialsArray()` → 自动 `revealJson`；旧明文 JSON 仍可读 |
| API / 目录 | `toPublicArray()` / `StorageCatalog::all()` 仅 `has_credentials` / `has_secret`，无明文 |

## 禁止

- 响应体、日志、QueryProvider 输出 `credentials` / `secret_ref` 原文
- Observer / Adapter 调用方直接 `json_decode(getData('credentials'))`（应走 `getCredentialsArray()`）
