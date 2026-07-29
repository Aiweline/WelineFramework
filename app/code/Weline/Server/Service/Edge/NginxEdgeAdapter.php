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
        $gateway = new \Weline\Server\Service\Edge\Gateway\GatewayHostManager();
        $gatewayStatus = $gateway->status();
        if (($gatewayStatus['ok'] ?? false)
            && ($gatewayStatus['ready'] ?? false)
            && ($gatewayStatus['protocol'] ?? '')
                === \Weline\Server\Service\Edge\Gateway\GatewayPaths::PROTOCOL
        ) {
            $instanceDir = \Weline\Framework\App\Env::VAR_DIR . 'server'
                . DIRECTORY_SEPARATOR . 'instances';
            foreach (\glob($instanceDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $endpointFile) {
                $raw = @\file_get_contents($endpointFile);
                $endpoint = \is_string($raw) ? \json_decode($raw, true) : null;
                if (!\is_array($endpoint)
                    || (string)($endpoint['gateway']['mode'] ?? '') !== 'gateway'
                    || (int)($endpoint['master_pid'] ?? 0) < 1
                    || !self::gatewayBackendMasterReachable($endpoint)
                ) {
                    continue;
                }
                $instanceName = \trim((string)($endpoint['instance_name'] ?? $endpoint['name'] ?? ''));
                if ($instanceName === '') {
                    continue;
                }
                $gateway->renew($instanceName);
                return;
            }
            // Cold certificate publication before a project runtime exists is
            // replayed by the next full register and must not mutate legacy
            // project Nginx merely because a host gateway is healthy.
            return;
        }

        $managed = \Weline\Server\Service\Edge\Nginx\ManagedNginxService::fromEnv();
        if (!self::managedNginxCanReload(
            $managed->isEdgeNginxManaged(),
            $managed->paths()->isInstalled(),
        )) {
            // Pure WLS and cold fallback own their TLS context directly. The
            // project certificate remains the fact source and no Nginx reload
            // is required until a managed edge is explicitly installed.
            return;
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

    /** Return whether the project-managed Nginx can receive a reload. */
    private static function managedNginxCanReload(bool $managed, bool $installed): bool
    {
        return $managed && $installed;
    }

    /**
     * A persisted gateway endpoint is only eligible for certificate renewal
     * while its project Backend Master control endpoint is reachable. This is
     * deliberately socket-based so the same check works on Unix and Windows.
     *
     * @param array<string,mixed> $endpoint
     */
    private static function gatewayBackendMasterReachable(array $endpoint): bool
    {
        $port = (int)($endpoint['control_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            return false;
        }

        $host = \trim((string)($endpoint['control_host'] ?? ''), "[] \t\n\r\0\x0B");
        if ($host === '' || $host === '0.0.0.0') {
            $host = '127.0.0.1';
        } elseif ($host === '::') {
            $host = '::1';
        }
        $authority = \str_contains($host, ':') ? '[' . $host . ']' : $host;
        $socket = @\stream_socket_client(
            'tcp://' . $authority . ':' . $port,
            $errorCode,
            $errorMessage,
            0.2,
            \STREAM_CLIENT_CONNECT,
        );
        if (!\is_resource($socket)) {
            return false;
        }
        \fclose($socket);
        return true;
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
