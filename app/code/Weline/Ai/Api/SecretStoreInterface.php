<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/** Stable cross-module boundary for encrypted configuration payloads. */
interface SecretStoreInterface
{
    /** @param array<string, mixed> $config */
    public function encryptConfig(array $config): string;

    /** @return array<string, mixed>|null */
    public function decryptConfig(string $encryptedConfig): ?array;
}
