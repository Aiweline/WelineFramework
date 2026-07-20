<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

/**
 * Edge protocol termination adapter.
 *
 * Isolates who terminates TLS/HTTP2/HTTP3: Nginx (default) or WLS native stack.
 * Native Protocol/Http2 and Protocol/Http3 code remains in-tree; adapters only gate runtime use.
 */
interface EdgeAdapterInterface
{
    public const NAME_NGINX = 'nginx';

    public const NAME_WLS = 'wls';

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
