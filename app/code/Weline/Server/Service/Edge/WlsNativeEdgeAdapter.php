<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

/**
 * Pure-PHP WLS edge for TLS 1.3, HTTP/2 and HTTP/1.1 fallback.
 *
 * HTTP/3 remains owned by the optional managed Nginx edge.
 */
final class WlsNativeEdgeAdapter implements EdgeAdapterInterface
{
    public function name(): string
    {
        return self::NAME_WLS;
    }

    public function allowsNativeHttp2(): bool
    {
        return true;
    }

    public function allowsNativeHttp3(): bool
    {
        return false;
    }

    public function expectsPlaintextBackend(): bool
    {
        return false;
    }

    public function onCertificateMaterialUpdated(string $domain, array $paths = []): void
    {
        (new CertificateMaterialUpdateCoordinator())->notify($domain, $paths, self::NAME_WLS);
    }

    public function doctorSnapshot(): array
    {
        return [
            'adapter' => self::NAME_WLS,
            'native_http2' => 'active_when_verified',
            'native_http3' => 'nginx_only',
            'expects_plaintext_backend' => false,
            'reload_command_configured' => false,
            'reload_command' => '',
            'last_reload' => null,
            'notes' => 'Pure WLS owns TLS 1.3 and negotiates HTTP/2 with HTTP/1.1 fallback. HTTP/3 is available only through managed Nginx.',
        ];
    }
}
