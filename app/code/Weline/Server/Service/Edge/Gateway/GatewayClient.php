<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Authenticated single-request client for WLS Edge Protocol 2.
 */
final class GatewayClient
{
    private const LONG_ADMIN_RESPONSE_TIMEOUT_SECONDS = 90.0;

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly float $timeoutSeconds = 2.0,
        private readonly GatewayCredentialStore $credentials = new GatewayCredentialStore(),
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(string $operation, array $payload = []): array
    {
        return $this->requestWithChannel('admin', $operation, $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function projectRequest(string $operation, array $payload = []): array
    {
        return $this->requestWithChannel('project', $operation, $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function requestWithChannel(string $channel, string $operation, array $payload): array
    {
        if ($channel === 'admin') {
            $hostId = $this->trustedHostId();
            $secret = \strtolower(\trim((string)@\file_get_contents($this->paths->adminTokenFile())));
            if (!\is_file($this->paths->adminTokenFile())
                || \is_link($this->paths->adminTokenFile())
                || \preg_match('/\A[a-f0-9]{64}\z/D', $secret) !== 1
            ) {
                throw new \RuntimeException('Trusted WLS Gateway administrator credential is unavailable.');
            }
            $credentialId = 'admin';
        } else {
            $credential = $this->credentials->load(
                isset($payload['project_uuid']) ? (string)$payload['project_uuid'] : null,
            );
            $hostId = (string)$credential['host_id'];
            $secret = (string)$credential['secret'];
            $credentialId = (string)$credential['credential_id'];
            $payload['project_uuid'] ??= (string)$credential['project_uuid'];
        }
        $request = [
            'protocol' => GatewayPaths::PROTOCOL,
            'channel' => $channel,
            'host_id' => $hostId,
            'credential_id' => $credentialId,
            'operation' => \strtolower(\trim($operation)),
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'monotonic_timestamp' => \hrtime(true) / 1_000_000_000,
            'nonce' => \bin2hex(\random_bytes(16)),
            'payload' => $payload,
        ];
        $request['request_digest'] = \hash('sha256', self::canonicalJson([
            'operation' => $request['operation'],
            'payload' => $payload,
        ]));
        $request['signature'] = \hash_hmac('sha256', self::canonicalJson($request), $secret);

        $endpoint = $this->paths->endpoint($channel);
        $errno = 0;
        $error = '';
        $socket = $endpoint['transport'] === 'pipe'
            ? @\fopen($endpoint['address'], 'r+b')
            : @\stream_socket_client(
                $endpoint['address'],
                $errno,
                $error,
                $this->timeoutSeconds,
                \STREAM_CLIENT_CONNECT,
            );
        if (!\is_resource($socket)) {
            throw new \RuntimeException(
                'WLS Gateway ' . $channel . ' endpoint unavailable: '
                . ($error !== '' ? $error : (string)$errno)
            );
        }
        try {
            \stream_set_timeout(
                $socket,
                (int)\ceil($this->responseTimeoutSeconds($channel, $request['operation'])),
            );
            $encoded = \json_encode(
                $request,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            );
            if (!\is_string($encoded) || @\fwrite($socket, $encoded . "\n") !== \strlen($encoded) + 1) {
                throw new \RuntimeException('Unable to send WLS Gateway request.');
            }
            $line = @\fgets($socket, 4 * 1024 * 1024);
            if (!\is_string($line) || \trim($line) === '') {
                throw new \RuntimeException('WLS Gateway returned an empty response.');
            }
            $response = \json_decode($line, true);
            if (!\is_array($response)
                || (string)($response['protocol'] ?? '') !== GatewayPaths::PROTOCOL
                || !\hash_equals((string)$request['request_id'], (string)($response['request_id'] ?? ''))
            ) {
                throw new \RuntimeException('WLS Gateway returned an invalid protocol response.');
            }
            $signature = \strtolower((string)($response['signature'] ?? ''));
            unset($response['signature']);
            $expected = \hash_hmac('sha256', self::canonicalJson($response), $secret);
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
                || !\hash_equals($expected, $signature)
            ) {
                throw new \RuntimeException('WLS Gateway response authentication failed.');
            }
            $response['signature'] = $signature;
            return $response;
        } finally {
            @\fclose($socket);
        }
    }

    private function responseTimeoutSeconds(string $channel, string $operation): float
    {
        if ($channel === 'admin'
            && \in_array($operation, ['repair', 'revoke', 'transfer', 'upgrade'], true)
        ) {
            // These administrator mutations may synchronously validate a
            // candidate, run the full activation probe window, and publish or
            // roll back before replying. Keep endpoint connection failures
            // bounded by timeoutSeconds, but preserve the authenticated result
            // across the complete publication transaction.
            return \max($this->timeoutSeconds, self::LONG_ADMIN_RESPONSE_TIMEOUT_SECONDS);
        }

        return $this->timeoutSeconds;
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return $this->projectRequest('own-status');
    }

    /**
     * @return array<string,mixed>
     */
    public function administratorStatus(): array
    {
        return $this->request('status');
    }

    /**
     * Recursively key-sort JSON objects so the client and standalone
     * controller sign exactly the same bytes.
     */
    public static function canonicalJson(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!\is_array($item)) {
                return $item;
            }
            if (!\array_is_list($item)) {
                \ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        $encoded = \json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to canonicalize WLS Gateway request.');
        }
        return $encoded;
    }

    private function trustedHostId(): string
    {
        $file = $this->paths->hostIdFile();
        if (!\is_file($file) || \is_link($file)) {
            throw new \RuntimeException('Trusted WLS Gateway host identity is unavailable.');
        }
        $hostId = \strtolower(\trim((string)@\file_get_contents($file)));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $hostId) !== 1) {
            throw new \RuntimeException('Trusted WLS Gateway host identity is invalid.');
        }
        return $hostId;
    }
}
