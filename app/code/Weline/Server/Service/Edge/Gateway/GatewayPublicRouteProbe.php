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
    private const PROBE_BUDGET_SECONDS = 0.75;
    private const ROUTE_BUDGET_SECONDS = 0.12;
    private const OBSERVATION_FRESHNESS_SECONDS = 60.0;
    private const MAX_RESPONSE_HEADER_BYTES = 32_768;
    private const MAX_RESPONSE_BODY_BYTES = 4_096;

    private int $routeCursor = 0;

    private ?string $priorityRouteId = null;

    /** @var array<string,array{healthy:bool,observed_at:float}> */
    private array $observations = [];

    /**
     * @param array<string,mixed> $registration
     */
    public function registrationIsHealthy(
        array $registration,
        int $httpsPort,
        ?array $activeRouteIds = null,
        ?float $deadlineMonotonic = null,
    ): bool
    {
        if ($httpsPort < 1 || $httpsPort > 65535) {
            return false;
        }
        $routes = \is_array($registration['routes'] ?? null) ? $registration['routes'] : [];
        if ($routes === [] || !\array_is_list($routes)) {
            return false;
        }
        if (\count($routes) > 256) {
            return false;
        }
        $requestedActive = null;
        if ($activeRouteIds !== null) {
            if ($activeRouteIds === []
                || !\array_is_list($activeRouteIds)
                || \count($activeRouteIds) > 256
            ) {
                return false;
            }
            $requestedActive = [];
            foreach ($activeRouteIds as $routeId) {
                if (!\is_string($routeId)
                    || \preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                    || isset($requestedActive[$routeId])
                ) {
                    return false;
                }
                $requestedActive[$routeId] = true;
            }
        }
        $routeKeys = [];
        $seenRouteIds = [];
        $probeRoutes = [];
        foreach ($routes as $index => $route) {
            $rawRouteId = \is_array($route) && \is_string($route['route_id'] ?? null)
                ? (string)$route['route_id']
                : '';
            $routeId = \strtolower($rawRouteId);
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || !\hash_equals($rawRouteId, $routeId)
                || isset($seenRouteIds[$routeId])
            ) {
                return false;
            }
            $seenRouteIds[$routeId] = true;
            if ($requestedActive === null || isset($requestedActive[$routeId])) {
                $routeKeys[$routeId] = \count($probeRoutes);
                $probeRoutes[] = $route;
            }
        }
        if ($probeRoutes === []
            || ($requestedActive !== null
                && \count($probeRoutes) !== \count($requestedActive))
        ) {
            return false;
        }
        $this->observations = \array_intersect_key(
            $this->observations,
            $routeKeys,
        );
        $startedAt = $this->monotonicNow();
        $deadline = $startedAt + self::PROBE_BUDGET_SECONDS;
        if ($deadlineMonotonic !== null) {
            if (!\is_finite($deadlineMonotonic)) {
                return false;
            }
            $deadline = \min($deadline, $deadlineMonotonic);
        }
        if ($deadline <= $startedAt) {
            return false;
        }
        $routes = $probeRoutes;
        $count = \count($routes);
        $start = $this->routeCursor % $count;
        $priorityIndex = null;
        if ($this->priorityRouteId !== null
            && isset($routeKeys[$this->priorityRouteId])
        ) {
            $priorityIndex = (int)$routeKeys[$this->priorityRouteId];
            $start = $priorityIndex;
        }
        // A failed route receives one immediate next-tick retry. Clear the
        // priority before probing so a persistent failure cannot starve the
        // remaining routes beyond their freshness window.
        $this->priorityRouteId = null;
        $attempted = 0;
        while ($attempted < $count && $this->monotonicNow() < $deadline) {
            $index = ($start + $attempted) % $count;
            $route = $routes[$index];
            $routeId = (string)$route['route_id'];
            try {
                $routeDeadline = \min(
                    $deadline,
                    $this->monotonicNow() + self::ROUTE_BUDGET_SECONDS,
                );
                $healthy = $this->routeIsHealthy(
                    $route,
                    (string)($registration['project_uuid'] ?? ''),
                    (string)($registration['instance_id'] ?? ''),
                    $httpsPort,
                    $routeDeadline,
                );
            } catch (\Throwable) {
                $healthy = false;
            }
            $this->observations[$routeId] = [
                'healthy' => $healthy,
                'observed_at' => $this->monotonicNow(),
            ];
            if (!$healthy) {
                $this->routeCursor = ($index + 1) % $count;
                if ($this->priorityRouteId === null
                    && ($priorityIndex === null
                        || $attempted !== 0
                        || $index !== $priorityIndex)
                ) {
                    // Retry a newly failed route first on the next one-second
                    // Agent tick, but only once before normal rotation resumes.
                    $this->priorityRouteId = $routeId;
                }
                $attempted++;
                continue;
            }
            $this->routeCursor = ($index + 1) % $count;
            $attempted++;
        }
        $now = $this->monotonicNow();
        foreach (\array_keys($routeKeys) as $routeId) {
            $observation = $this->observations[$routeId] ?? null;
            if (!\is_array($observation)
                || $now - (float)($observation['observed_at'] ?? 0.0)
                    > self::OBSERVATION_FRESHNESS_SECONDS
            ) {
                return false;
            }
            if (($observation['healthy'] ?? false) !== true) {
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
        float $deadline,
    ): bool {
        if ($this->monotonicNow() >= $deadline) {
            return false;
        }
        $domain = \strtolower(\trim((string)($route['domain'] ?? '')));
        if (\str_starts_with($domain, '*.')) {
            $domain = 'wls-probe.' . \substr($domain, 2);
        }
        $certificate = \is_array($route['certificate'] ?? null) ? $route['certificate'] : [];
        $expectedFingerprint = \strtolower(\trim(
            (string)($certificate['leaf_fingerprint_sha256'] ?? ''),
        ));
        if ($domain === ''
            || $projectUuid === ''
            || $instanceId === ''
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint) !== 1
        ) {
            return false;
        }
        $context = \stream_context_create([
            'ssl' => [
                'peer_name' => $domain,
                'SNI_enabled' => true,
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => true,
                'allow_self_signed' => true,
                'disable_compression' => true,
                'crypto_method' => \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);
        $remaining = $deadline - $this->monotonicNow();
        if ($remaining < 0.001) {
            return false;
        }
        $socket = @\stream_socket_client(
            'tls://127.0.0.1:' . $httpsPort,
            $errno,
            $error,
            \min(0.25, $remaining),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!\is_resource($socket)) {
            return false;
        }
        $this->setSocketDeadline($socket, $deadline);
        $params = \stream_context_get_params($socket);
        $peerCertificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $peerFingerprint = ($peerCertificate instanceof \OpenSSLCertificate
            || \is_resource($peerCertificate))
                ? \strtolower(\str_replace(
                    ':',
                    '',
                    (string)@\openssl_x509_fingerprint($peerCertificate, 'sha256'),
                ))
                : '';
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $peerFingerprint) !== 1
            || !\hash_equals($expectedFingerprint, $peerFingerprint)
        ) {
            @\fclose($socket);
            return false;
        }
        $nonce = \bin2hex(\random_bytes(16));
        $request = "GET /__wls_gateway_sentinel?nonce={$nonce} HTTP/1.1\r\n"
            . "Host: {$domain}\r\nConnection: close\r\nCache-Control: no-store\r\n\r\n";
        if (!$this->writeAll($socket, $request, $deadline)) {
            @\fclose($socket);
            return false;
        }
        $parsedResponse = $this->readStrictResponse($socket, $deadline);
        @\fclose($socket);
        if ($parsedResponse === null) {
            return false;
        }
        $headers = $parsedResponse['headers'];
        $body = $parsedResponse['body'];
        try {
            $health = \json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }
        $healthKeys = \is_array($health) ? \array_keys($health) : [];
        \sort($healthKeys, SORT_STRING);
        $observedInstanceId = (string)($headers['x-wls-instance-id'] ?? '');
        $backendInstances = \is_array($route['backend_instances'] ?? null)
            ? $route['backend_instances']
            : [];
        $backendInstance = \is_array($backendInstances[$observedInstanceId] ?? null)
            ? $backendInstances[$observedInstanceId]
            : null;
        $identity = \is_array($backendInstance)
            ? (array)($backendInstance['backend_identity'] ?? [])
            : [];
        if ($identity === [] && \hash_equals($instanceId, $observedInstanceId)) {
            $identity = \is_array($route['backend_identity'] ?? null)
                ? $route['backend_identity']
                : [];
        }
        return \is_array($health)
            && !\array_is_list($health)
            && $healthKeys === [
                'instance',
                'launch_id',
                'master_epoch',
                'nonce',
                'status',
            ]
            && \hash_equals('healthy', (string)($health['status'] ?? ''))
            && $identity !== []
            && \hash_equals($projectUuid, (string)($headers['x-wls-project-uuid'] ?? ''))
            && (int)($identity['generation'] ?? 0)
                === (int)($headers['x-wls-backend-generation'] ?? 0)
            && \hash_equals($nonce, (string)($headers['x-wls-probe-nonce'] ?? ''))
            && \hash_equals($observedInstanceId, (string)($health['instance'] ?? ''))
            && \hash_equals(
                (string)($identity['launch_id'] ?? ''),
                (string)($health['launch_id'] ?? ''),
            )
            && (int)($identity['master_epoch'] ?? 0) === (int)($health['master_epoch'] ?? 0)
            && \hash_equals($nonce, (string)($health['nonce'] ?? ''));
    }

    /**
     * Parse exactly one bounded HTTP/1.1 response. The sentinel deliberately
     * requests Connection: close, so EOF is part of the framing proof: a
     * second response, duplicate framing header, or trailing byte is rejected.
     *
     * @param resource $socket
     * @return array{headers:array<string,string>,body:string}|null
     */
    private function readStrictResponse($socket, float $deadline): ?array
    {
        if (!@\stream_set_blocking($socket, false)) {
            return null;
        }
        $response = '';
        $headerBoundary = null;
        $expectedBytes = null;
        $headers = null;
        $eof = false;
        $maximumBytes = self::MAX_RESPONSE_HEADER_BYTES
            + 4
            + self::MAX_RESPONSE_BODY_BYTES;
        while ($this->monotonicNow() < $deadline) {
            if (\feof($socket)) {
                $eof = true;
                break;
            }
            $remaining = $deadline - $this->monotonicNow();
            if ($remaining <= 0.0) {
                break;
            }
            $read = [$socket];
            $write = [];
            $except = [];
            $waitMicros = (int)\max(
                1,
                \min(20_000, \ceil($remaining * 1_000_000)),
            );
            $selected = @\stream_select($read, $write, $except, 0, $waitMicros);
            if ($selected === false) {
                return null;
            }
            if ($selected === 0) {
                continue;
            }
            $chunk = @\fread($socket, 8192);
            if (!\is_string($chunk)) {
                return null;
            }
            if ($chunk === '') {
                if (\feof($socket)) {
                    $eof = true;
                    break;
                }
                $metadata = @\stream_get_meta_data($socket);
                if (\is_array($metadata)
                    && ($metadata['timed_out'] ?? false) === true
                ) {
                    return null;
                }
                continue;
            }
            $response .= $chunk;
            if (\strlen($response) > $maximumBytes) {
                return null;
            }
            if ($headerBoundary === null) {
                $boundary = \strpos($response, "\r\n\r\n");
                if ($boundary === false) {
                    if (\strlen($response) > self::MAX_RESPONSE_HEADER_BYTES) {
                        return null;
                    }
                    continue;
                }
                if ($boundary < 1 || $boundary > self::MAX_RESPONSE_HEADER_BYTES) {
                    return null;
                }
                $headerBoundary = $boundary;
                $headers = $this->parseStrictHeaders(
                    \substr($response, 0, $headerBoundary),
                );
                if ($headers === null) {
                    return null;
                }
                $contentLength = (int)$headers['content-length'];
                $expectedBytes = $headerBoundary + 4 + $contentLength;
            }
            if ($expectedBytes !== null && \strlen($response) > $expectedBytes) {
                return null;
            }
            // Do not return at the declared length. Continue until close so a
            // smuggled second response or trailing byte cannot be hidden.
        }
        $metadata = @\stream_get_meta_data($socket);
        if (!$eof
            || (\is_array($metadata) && ($metadata['timed_out'] ?? false) === true)
            || $this->monotonicNow() > $deadline
            || $headerBoundary === null
            || $expectedBytes === null
            || !\is_array($headers)
            || \strlen($response) !== $expectedBytes
        ) {
            return null;
        }
        $body = \substr($response, $headerBoundary + 4);
        if ($body === ''
            || \strlen($body) !== (int)$headers['content-length']
            || $body !== \trim($body)
            || !\str_starts_with($body, '{')
            || !\str_ends_with($body, '}')
        ) {
            return null;
        }
        return ['headers' => $headers, 'body' => $body];
    }

    /** @return array<string,string>|null */
    private function parseStrictHeaders(string $headerBlock): ?array
    {
        if ($headerBlock === ''
            || \str_contains($headerBlock, "\nHTTP/")
            || \preg_match('/(?<!\r)\n|\r(?!\n)/', $headerBlock) === 1
        ) {
            return null;
        }
        $lines = \explode("\r\n", $headerBlock);
        $statusLine = (string)\array_shift($lines);
        if (\preg_match(
            '/\AHTTP\/1\.1 200 [\x20-\x7e]{0,128}\z/D',
            $statusLine,
        ) !== 1
            || \count($lines) > 128
        ) {
            return null;
        }
        $headers = [];
        foreach ($lines as $line) {
            if ($line === ''
                || $line[0] === ' '
                || $line[0] === "\t"
                || !\str_contains($line, ':')
            ) {
                return null;
            }
            [$name, $value] = \explode(':', $line, 2);
            $name = \strtolower($name);
            $value = \trim($value, " \t");
            if (\preg_match('/\A[!#$%&\'*+.^_`|~0-9a-z-]+\z/D', $name) !== 1
                || \preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1
                || \array_key_exists($name, $headers)
            ) {
                return null;
            }
            $headers[$name] = $value;
        }
        $length = (string)($headers['content-length'] ?? '');
        if (\array_key_exists('transfer-encoding', $headers)
            || \preg_match('/\A(?:[1-9][0-9]{0,3})\z/D', $length) !== 1
            || (int)$length > self::MAX_RESPONSE_BODY_BYTES
            || !\hash_equals('close', \strtolower((string)($headers['connection'] ?? '')))
            || \preg_match(
                '/\A[0-9a-f]{32}\z/D',
                \strtolower((string)($headers['x-wls-probe-nonce'] ?? '')),
            ) !== 1
            || \preg_match(
                '/\A[0-9]+\z/D',
                (string)($headers['x-wls-backend-generation'] ?? ''),
            ) !== 1
            || \trim((string)($headers['x-wls-project-uuid'] ?? '')) === ''
            || \trim((string)($headers['x-wls-instance-id'] ?? '')) === ''
        ) {
            return null;
        }
        return $headers;
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $payload, float $deadline): bool
    {
        $written = 0;
        $length = \strlen($payload);
        while ($written < $length) {
            if (!$this->setSocketDeadline($socket, $deadline)) {
                return false;
            }
            $amount = @\fwrite($socket, \substr($payload, $written));
            if (!\is_int($amount) || $amount <= 0) {
                return false;
            }
            $written += $amount;
        }
        return true;
    }

    /** @param resource $socket */
    private function setSocketDeadline($socket, float $deadline): bool
    {
        $remaining = $deadline - $this->monotonicNow();
        if ($remaining <= 0.0) {
            return false;
        }
        $seconds = (int)\floor($remaining);
        $microseconds = (int)\ceil(
            ($remaining - $seconds) * 1_000_000,
        );
        if ($microseconds >= 1_000_000) {
            $seconds++;
            $microseconds = 0;
        } elseif ($seconds === 0 && $microseconds < 1) {
            $microseconds = 1;
        }
        return @\stream_set_timeout($socket, $seconds, $microseconds);
    }

    private function monotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
