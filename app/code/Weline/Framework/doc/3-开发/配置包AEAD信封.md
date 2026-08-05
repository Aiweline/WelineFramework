# 跨实例配置包 AEAD（TASK-P1D-003 / DEC-021）

## 目的

配置跨实例导出/导入使用 recipient X25519 + XChaCha20-Poly1305 envelope；`package_uuid` 一次性消费账本防重放。libsodium 不可用或功能未启用时 **fail-closed**，禁止回退 `Encrypt`（MD5）或明文 JSON。

## 容器字段

| 字段 | 规则 |
|---|---|
| `schema_version` | `weline-config-envelope/1` |
| `package_uuid` | 每包随机 UUID；进 AAD；目标 CAS 账本拒绝重放 |
| `recipient_kid` | 目标已发布且未吊销的 X25519 key id |
| `wrapped_key` | `crypto_box_seal(data_key, recipient_pk)` |
| `nonce` | 每包随机 XChaCha20 nonce |
| `aad` | canonical JSON：uuid/版本/source_instance/kid/文件名/Scope/时间 |
| `ciphertext` | XChaCha20-Poly1305 配置 payload |
| `payload_hash` | 密文 sha256（诊断，不替代 AEAD） |

`source_instance` 默认仅审计；要求受信来源时需追加 Ed25519 `source_signature`（本任务未强制验签成功才导入）。

## 密钥状态

| status | 导出 | 解密 |
|---|---|---|
| `active` | 允许 | 允许 |
| `decrypt_only` | 拒绝 | 允许（轮换过渡） |
| `revoked` | 拒绝 | 拒绝 |

## Env

见 `app/etc/env.sample.php` → `security.config_envelope`：

```php
'config_envelope' => [
    'enabled' => false, // 生产显式开启
    'instance_id' => 'prod-a',
    'active_kid' => 'k2026q3',
    'keys' => [
        'k2026q3' => [
            'status' => 'active',
            'public_key_base64' => '...',
            'secret_key_base64' => '...', // 仅接收方实例
        ],
    ],
],
```

生成密钥：`RecipientKeyDirectory::generateKeyRecord('kid')`。

## 代码入口

| 类 | 职责 |
|---|---|
| `SodiumCryptoEnvelope` | seal/open/preview |
| `RecipientKeyDirectory` | kid 目录与轮换 |
| `ConfigEnvelopeService` | export / previewImport / import |
| `ConfigPackageConsumption` | UUID 消费账本表 |
| `Helper\ConfigExport/Import` | 明文入口永久 fail-closed |

## 导入顺序

1. 功能启用 + sodium 可用  
2. kid 可解密（非 revoked）  
3. AEAD open（UUID/文件名/AAD/过期/截断/篡改均先失败，且不占账本）  
4. **CAS claim** `package_uuid`（已消费 → `config_envelope_package_replayed`，零配置写入）  
5. applier 写入；失败 → `markFailed`，uuid 仍占用（须原实例重导新包）

## 回滚

关闭 `security.config_envelope.enabled`；禁止明文导入；仅原实例重新导出新 `package_uuid`。
