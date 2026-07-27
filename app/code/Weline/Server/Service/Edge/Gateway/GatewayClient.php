<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Authenticated single-request client for WLS Edge Protocol 2.
 */
final class GatewayClient
{
    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly float $timeoutSeconds = 2.0,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(string $operation, array $payload = []): array
    {
        $token = \trim((string)@\file_get_contents($this->paths->tokenFile()));
        if (!\preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \RuntimeException('Trusted WLS Gateway token is missing or invalid.');
        }

        $request = [
            'protocol' => GatewayPaths::PROTOCOL,
            'operation' => \strtolower(\trim($operation)),
            'request_id' => \bin2hex(\random_bytes(16)),
            'timestamp' => \time(),
            'nonce' => \bin2hex(\random_bytes(16)),
            'payload' => $payload,
        ];
        $request['signature'] = \hash_hmac('sha256', self::canonicalJson($request), $token);

        $endpoint = $this->paths->endpoint();
        $errno = 0;
        $error = '';
        $socket = @\stream_socket_client(
            $endpoint['address'],
            $errno,
            $error,
            $this->timeoutSeconds,
            \STREAM_CLIENT_CONNECT,
        );
        if (!\is_resource($socket)) {
            throw new \RuntimeException(
                'WLS Gateway control endpoint unavailable: ' . ($error !== '' ? $error : (string)$errno)
            );
        }
        try {
            \stream_set_timeout($socket, (int)\ceil($this->timeoutSeconds));
            $encoded = \json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            return $response;
        } finally {
            @\fclose($socket);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
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
}
