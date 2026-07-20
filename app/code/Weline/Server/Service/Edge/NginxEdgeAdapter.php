<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge;

/**
 * Default production edge: Nginx terminates TLS/HTTP2/HTTP3; WLS serves cleartext HTTP/1.1.
 */
final class NginxEdgeAdapter implements EdgeAdapterInterface
{
    public function __construct(
        private readonly EdgeCertificateReloadService $reloadService = new EdgeCertificateReloadService()
    ) {
    }

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
        if ($managed->isEdgeNginxManaged() && $managed->paths()->isInstalled()) {
            $result = $managed->reload();
            if ($result['ok'] ?? false) {
                return;
            }
            // Fall through to configured reload_command / warning path when managed reload fails.
        }
        $this->reloadService->reloadAfterCertificateUpdate($domain);
    }

    public function doctorSnapshot(): array
    {
        $base = [
            'adapter' => self::NAME_NGINX,
            'native_http2' => 'retained_inactive',
            'native_http3' => 'retained_inactive',
            'expects_plaintext_backend' => true,
            'reload_command_configured' => $this->reloadService->configuredCommand() !== '',
            'reload_command' => $this->reloadService->configuredCommand(),
            'last_reload' => $this->reloadService->readLastResult(),
            'notes' => 'Nginx terminates TLS/HTTP2/HTTP3; WLS native protocol stacks remain in-tree but are not negotiated.',
        ];
        try {
            $base['managed_nginx'] = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv()->doctorSnapshot();
        } catch (\Throwable) {
            $base['managed_nginx'] = null;
        }
        return $base;
    }
}
