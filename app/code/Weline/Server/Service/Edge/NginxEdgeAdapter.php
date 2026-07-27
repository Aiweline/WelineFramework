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
        $managed = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv();
        if (!$managed->isEdgeNginxManaged() || !$managed->paths()->isInstalled()) {
            throw new \RuntimeException(
                'Certificate reload requires the installed project-managed Nginx.'
            );
        }
        $result = $managed->reload();
        if (!($result['ok'] ?? false)
            && (string)($result['message'] ?? '') === 'managed nginx is not running'
        ) {
            // Cold start persists certificate material before the edge exists.
            // prepareAndStart() will publish a config bound to these files, so
            // only the explicit, identity-safe stopped state may skip reload.
            return;
        }
        if (!($result['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($result['message'] ?? 'Project-managed Nginx certificate reload failed.')
            );
        }
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
