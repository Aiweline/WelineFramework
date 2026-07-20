<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

/**
 * Self-developed edge: WLS SSL Worker negotiates HTTP/2 and optional native HTTP/3.
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
        return true;
    }

    public function expectsPlaintextBackend(): bool
    {
        return false;
    }

    public function onCertificateMaterialUpdated(string $domain, array $paths = []): void
    {
        $domains = $domain !== '' ? [$domain] : [];
        ObjectManager::getInstance(BroadcastControlDispatchService::class)
            ->reloadSslCert($domains);
    }

    public function doctorSnapshot(): array
    {
        return [
            'adapter' => self::NAME_WLS,
            'native_http2' => 'active_when_verified',
            'native_http3' => 'active_when_verified',
            'expects_plaintext_backend' => false,
            'reload_command_configured' => false,
            'reload_command' => '',
            'last_reload' => null,
            'notes' => 'WLS owns TLS and native HTTP/2/HTTP/3 negotiation; certificate updates broadcast ssl_cert_reload.',
        ];
    }
}
