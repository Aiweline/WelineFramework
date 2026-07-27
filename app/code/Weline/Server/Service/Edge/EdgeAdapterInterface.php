<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

/**
 * Nginx public-edge contract. Legacy WLS-native sources remain inert for
 * compatibility archaeology and cannot be selected by runtime orchestration.
 */
interface EdgeAdapterInterface
{
    public const NAME_NGINX = 'nginx';

    public const NAME_WLS = 'wls'; // Retired compatibility identifier; never selectable.

    public function name(): string;

    public function allowsNativeHttp2(): bool;

    public function allowsNativeHttp3(): bool;

    public function expectsPlaintextBackend(): bool;

    /**
     * Called after certificate PEM material is written or the certificate map is regenerated.
     *
     * @param array<string, mixed> $paths
     */
    public function onCertificateMaterialUpdated(string $domain, array $paths = []): void;

    /**
     * @return array<string, mixed>
     */
    public function doctorSnapshot(): array;
}
