<?php

declare(strict_types=1);

namespace Weline\StorageOss\Service;

use Weline\Framework\Http\Security\SecretRefCipher;
use Weline\Storage\Api\Data\StorageConfigSnapshot;

/**
 * Seals only the request-frozen OSS coordinates required to abort a multipart
 * upload. The resulting ref is durable, authenticated and safe across disk
 * configuration revisions; plaintext credentials never enter task columns.
 */
final class OssMultipartCleanupSnapshotCodec
{
    public const FORMAT_VERSION = 1;
    public const MAX_SEALED_REF_BYTES = 32768;

    private const CONFIG_KEYS = [
        'access_key_id',
        'access_key_secret',
        'endpoint',
        'bucket',
        'prefix',
        'use_ssl',
        'is_cname',
        'security_token',
        'connect_timeout_seconds',
        'request_timeout_seconds',
        'max_retries',
    ];

    public function seal(StorageConfigSnapshot $snapshot): string
    {
        if ($snapshot->code()->providerCode() !== 'oss::aliyun') {
            throw new \InvalidArgumentException('cleanup_snapshot_provider_mismatch');
        }
        $driverConfig = $snapshot->driverConfig();
        $config = [];
        foreach (self::CONFIG_KEYS as $key) {
            if (array_key_exists($key, $driverConfig)) {
                $config[$key] = $driverConfig[$key];
            }
        }
        $this->assertScalarConfig($config);
        $sealed = SecretRefCipher::sealJson([
            'version' => self::FORMAT_VERSION,
            'disk_code' => $snapshot->diskCode,
            'config_revision' => $snapshot->configRevision,
            'namespace_fingerprint' => $snapshot->objectNamespaceFingerprint(),
            'config' => $config,
        ]);
        if (strlen($sealed) > self::MAX_SEALED_REF_BYTES) {
            throw new \RuntimeException('cleanup_snapshot_ref_too_large');
        }
        return $sealed;
    }

    public function reveal(string $sealedRef, string $diskCode, int $configRevision): StorageConfigSnapshot
    {
        if (!SecretRefCipher::isRef($sealedRef) || strlen($sealedRef) > self::MAX_SEALED_REF_BYTES) {
            throw new \RuntimeException('cleanup_snapshot_ref_invalid');
        }
        $payload = SecretRefCipher::revealJson($sealedRef);
        if (
            (int)($payload['version'] ?? 0) !== self::FORMAT_VERSION
            || !hash_equals($diskCode, (string)($payload['disk_code'] ?? ''))
            || (int)($payload['config_revision'] ?? 0) !== $configRevision
            || !is_array($payload['config'] ?? null)
        ) {
            throw new \RuntimeException('cleanup_snapshot_binding_invalid');
        }
        $config = $payload['config'];
        if (array_diff(array_keys($config), self::CONFIG_KEYS) !== []) {
            throw new \RuntimeException('cleanup_snapshot_config_invalid');
        }
        $this->assertScalarConfig($config);
        $snapshot = new StorageConfigSnapshot(
            $diskCode,
            $configRevision,
            $config,
            (string)($payload['namespace_fingerprint'] ?? ''),
        );
        if ($snapshot->code()->providerCode() !== 'oss::aliyun') {
            throw new \RuntimeException('cleanup_snapshot_provider_mismatch');
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $config */
    private function assertScalarConfig(array $config): void
    {
        if (count($config) > count(self::CONFIG_KEYS)) {
            throw new \RuntimeException('cleanup_snapshot_config_invalid');
        }
        foreach ($config as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new \RuntimeException('cleanup_snapshot_config_invalid');
            }
        }
    }
}
