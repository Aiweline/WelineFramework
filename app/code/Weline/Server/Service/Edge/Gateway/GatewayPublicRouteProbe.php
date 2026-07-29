<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-side end-to-end proof for a shared gateway route.
 *
 * The probe validates SNI, the project certificate, Host routing, the
 * gateway-injected tenant/instance/generation markers and the authenticated
 * backend's echoed nonce. A listening TCP port alone is never healthy.
 */
final class GatewayPublicRouteProbe
{
    public function __construct(
        private readonly GatewayRegistrationBuilder $registrationBuilder
            = new GatewayRegistrationBuilder(),
    ) {
    }

    /**
     * @param array<string,mixed> $registration
     */
    public function registrationIsHealthy(array $registration, int $httpsPort): bool
    {
        if ($httpsPort < 1 || $httpsPort > 65535) {
            return false;
        }
        $routes = \is_array($registration['routes'] ?? null) ? $registration['routes'] : [];
        if ($routes === []) {
            return false;
        }
        foreach ($routes as $route) {
            try {
                $healthy = \is_array($route)
                    && $this->routeIsHealthy(
                        $route,
                        (string)($registration['project_uuid'] ?? ''),
                        (string)($registration['instance_id'] ?? ''),
                        $httpsPort,
                    );
            } catch (\Throwable) {
                $healthy = false;
            }
            if (!$healthy) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $route
     */
    private function routeIsHealthy(
        array $route,
        string $projectUuid,
        string $instanceId,
        int $httpsPort,
    ): bool {
        $domain = \strtolower(\trim((string)($route['domain'] ?? '')));
        if (\str_starts_with($domain, '*.')) {
            $domain = 'wls-probe.' . \substr($domain, 2);
        }
        $identity = \is_array($route['backend_identity'] ?? null)
            ? $route['backend_identity']
            : [];
        $certificate = \is_array($route['certificate'] ?? null) ? $route['certificate'] : [];
        $certificateReference = \is_array($certificate['cert'] ?? null)
            ? $certificate['cert']
            : [];
        $certificatePath = $this->registrationBuilder->resolveCertificateSourceReference(
            $certificateReference,
        );
        if ($domain === ''
            || $projectUuid === ''
            || $instanceId === ''
            || $certificatePath === null
        ) {
            return false;
        }
        $certificatePem = @\file_get_contents($certificatePath);
        if (!\is_string($certificatePem) || $certificatePem === '') {
            return false;
        }
        $expectedCertificate = @\openssl_x509_read($certificatePem);
        if ($expectedCertificate === false) {
            return false;
        }
        $context = \stream_context_create([
            'ssl' => [
                'peer_name' => $domain,
                'SNI_enabled' => true,
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'disable_compression' => true,
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
        $socket = @\stream_socket_client(
            'tls://127.0.0.1:' . $httpsPort,
            $errno,
            $error,
            1.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!\is_resource($socket)) {
            return false;
        }
        $params = \stream_context_get_params($socket);
        $peerCertificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $expectedFingerprint = (string)@\openssl_x509_fingerprint(
            $expectedCertificate,
            'sha256',
        );
        $peerFingerprint = ($peerCertificate instanceof \OpenSSLCertificate
            || \is_resource($peerCertificate))
                ? (string)@\openssl_x509_fingerprint($peerCertificate, 'sha256')
                : '';
        if ($expectedFingerprint === ''
            || $peerFingerprint === ''
            || !\hash_equals(\strtolower($expectedFingerprint), \strtolower($peerFingerprint))
        ) {
            @\fclose($socket);
            return false;
        }
        $nonce = \bin2hex(\random_bytes(16));
        \stream_set_timeout($socket, 2);
        $request = "GET /__wls_gateway_sentinel?nonce={$nonce} HTTP/1.1\r\n"
            . "Host: {$domain}\r\nConnection: close\r\nCache-Control: no-store\r\n\r\n";
        if (!$this->writeAll($socket, $request)) {
            @\fclose($socket);
            return false;
        }
        $response = (string)@\stream_get_contents($socket, 262144);
        @\fclose($socket);
        [$headerBlock, $body] = \array_pad(\explode("\r\n\r\n", $response, 2), 2, '');
        if (!\str_starts_with($headerBlock, 'HTTP/1.1 200 ')) {
            return false;
        }
        $headers = [];
        foreach (\array_slice(\explode("\r\n", $headerBlock), 1) as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = \explode(':', $line, 2);
            $headers[\strtolower(\trim($name))] = \trim($value);
        }
        $health = $body !== '' ? \json_decode($body, true) : null;
        return \is_array($health)
            && \hash_equals($projectUuid, (string)($headers['x-wls-project-uuid'] ?? ''))
            && \hash_equals($instanceId, (string)($headers['x-wls-instance-id'] ?? ''))
            && (int)($identity['generation'] ?? 0)
                === (int)($headers['x-wls-backend-generation'] ?? 0)
            && \hash_equals($nonce, (string)($headers['x-wls-probe-nonce'] ?? ''))
            && \hash_equals($instanceId, (string)($health['instance'] ?? ''))
            && \hash_equals(
                (string)($identity['launch_id'] ?? ''),
                (string)($health['launch_id'] ?? ''),
            )
            && (int)($identity['master_epoch'] ?? 0) === (int)($health['master_epoch'] ?? 0)
            && \hash_equals($nonce, (string)($health['nonce'] ?? ''));
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $payload): bool
    {
        $written = 0;
        $length = \strlen($payload);
        while ($written < $length) {
            $amount = @\fwrite($socket, \substr($payload, $written));
            if (!\is_int($amount) || $amount <= 0) {
                return false;
            }
            $written += $amount;
        }
        return true;
    }
}
