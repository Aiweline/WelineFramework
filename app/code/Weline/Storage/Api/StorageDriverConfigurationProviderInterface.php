<?php

declare(strict_types=1);

namespace Weline\Storage\Api;

use Weline\Storage\Api\Data\StorageConfigField;

/** Optional administration contract implemented by configurable disk providers. */
interface StorageDriverConfigurationProviderInterface extends StorageDriverProviderInterface
{
    public function displayName(): string;

    /** @return list<StorageConfigField> */
    public function configurationFields(): array;

    /**
     * Normalize untrusted configuration input without performing network I/O.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $previous Decrypted previous snapshot; secrets are write-only in the UI.
     * @return array<string,mixed>
     */
    public function normalizeConfiguration(array $input, array $previous = []): array;

    /** @return list<string> Top-level configuration keys that must be encrypted at rest. */
    public function secretConfigurationKeys(): array;

    /**
     * Stable SHA-256 of fields that determine where an object key is stored and how it is exposed.
     * Credential and CDN-only rotations must not change this value.
     *
     * @param array<string,mixed> $config Normalized, decrypted configuration.
     */
    public function objectNamespaceFingerprint(array $config): string;
}
