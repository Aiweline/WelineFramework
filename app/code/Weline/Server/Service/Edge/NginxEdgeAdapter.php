<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

/**
 * The only supported public edge: Nginx owns TLS/HTTP; WLS serves loopback cleartext HTTP/1.1.
 */
final class NginxEdgeAdapter implements EdgeAdapterInterface
{

    public function name(): string
    {
        return self::NAME_NGINX;
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
        (new CertificateMaterialUpdateCoordinator())->notify($domain, $paths, self::NAME_NGINX);
    }

    public function doctorSnapshot(): array
    {
        $base = [
            'adapter' => self::NAME_NGINX,
            'native_http2' => 'retired',
            'native_http3' => 'retired',
            'expects_plaintext_backend' => true,
            'reload_command_configured' => false,
            'reload_command' => '',
            'last_reload' => null,
            'notes' => 'Nginx exclusively owns public TLS and HTTP negotiation. Managed Nginx always uses HTTP/2 with HTTP/1.1 fallback and enables QUIC/HTTP/3 only when nginx -V proves ngx_http_v3_module; Win32 and builds without that module never advertise Alt-Svc.',
        ];
        try {
            $base['managed_nginx'] = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv()->doctorSnapshot();
        } catch (\Throwable) {
            $base['managed_nginx'] = null;
        }
        return $base;
    }
}
