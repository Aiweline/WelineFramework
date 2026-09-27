# secret_ref 凭据密封（TASK-P1D-002）

## 目的

CDN / Storage 等账户凭据在落库与跨 Scope 传递时使用 `secret_ref:v1:` 封装，API/日志响应永不回传明文。

## 格式

```
secret_ref:v1:{base64url(nonce||ciphertext)}   # libsodium secretbox（优先）
secret_ref:v1o:{base64url(iv||tag||ciphertext)} # OpenSSL AES-256-GCM（无 sodium 时）
```

实现：`Weline\Framework\Http\Security\SecretRefCipher`。
有 `ext-sodium` 时写 `v1`；否则写 `v1o`。读路径自动识别两种前缀。

## 配置

`app/etc/env.php`（参考 `env.sample.php`）：

```php
'security' => [
    'secret_ref_key' => '生产环境建议预置高强度随机串',
],
```

- 仅 `ENV_TEST` 或 `DEV` 可在未配置时使用开发占位密钥。
- **生产缺键**：首次 `seal`/`sealJson` 时自动生成 64 位 hex 写入 `security.secret_ref_key`（文件锁防并发双写）；`env.php` 不可写时仍抛 `secret_ref_key_missing`。
- **禁止**用固定字符串当生产密钥；密钥变更后旧 `secret_ref:v1:` 无法解密，须重新填写凭据。

## 读写约定

| 路径 | 行为 |
|---|---|
| 写 | `Account::setCredentialsArray()` / `Domain::setCredentialsArray()` → `sealJson` |
| 读（服务端） | `getCredentialsArray()` → 自动 `revealJson`；旧明文 JSON 仍可读 |
| API / 目录 | `toPublicArray()` / `StorageCatalog::all()` 仅 `has_credentials` / `has_secret`，无明文 |

## 禁止

- 响应体、日志、QueryProvider 输出 `credentials` / `secret_ref` 原文
- Observer / Adapter 调用方直接 `json_decode(getData('credentials'))`（应走 `getCredentialsArray()`）
