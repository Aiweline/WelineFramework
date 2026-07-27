<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;


/**
 * Retired compatibility class. Nginx is the only supported public edge.
 */
final class WlsNativeEdgeAdapter implements EdgeAdapterInterface
{
    public function __construct()
    {
        throw new \RuntimeException('WLS native edge is retired; use Nginx.');
    }

    public function name(): string
    {
        return self::NAME_WLS;
    }

    public function allowsNativeHttp2(): bool
    {
        return false;
    }

    public function allowsNativeHttp3(): bool
    {
        return false;
    }

    public function expectsPlaintextBackend(): bool
    {
        return true;
    }

    public function onCertificateMaterialUpdated(string $domain, array $paths = []): void
    {
        throw new \RuntimeException('WLS native certificate reload is retired; use project-managed Nginx.');
    }

    public function doctorSnapshot(): array
    {
        return [
            'adapter' => self::NAME_WLS,
            'native_http2' => 'retired',
            'native_http3' => 'retired',
            'expects_plaintext_backend' => true,
            'reload_command_configured' => false,
            'reload_command' => '',
            'last_reload' => null,
            'notes' => 'Retired compatibility adapter; Nginx exclusively owns public TLS and HTTP negotiation.',
        ];
    }
}
