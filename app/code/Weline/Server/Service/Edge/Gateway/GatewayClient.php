<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Authenticated single-request client for WLS Edge Protocol 2.
 */
final class GatewayClient
{
    private const LONG_ADMIN_RESPONSE_TIMEOUT_SECONDS = 90.0;
    private const LONG_PROJECT_MUTATION_RESPONSE_TIMEOUT_SECONDS = 90.0;

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
            $authenticated = \preg_match('/\A[a-f0-9]{64}\z/D', $signature) === 1
                && \hash_equals($expected, $signature);
            if (!$authenticated && \preg_match('/\A[a-f0-9]{64}\z/D', $signature) === 1) {
                try {
                    // A host slot and a project can legitimately run adjacent
                    // PHP patch versions. Re-decoding a signed JSON float and
                    // encoding it with the other runtime can change its
                    // shortest decimal representation, even though the value
                    // is unchanged. Preserve numeric lexemes from the wire for
                    // this compatibility verification; all object keys are
                    // still recursively sorted and the HMAC remains mandatory.
                    $wireExpected = \hash_hmac(
                        'sha256',
                        self::canonicalResponseFromWire($line, $signature),
                        $secret,
                    );
                    $authenticated = \hash_equals($wireExpected, $signature);
                } catch (\Throwable) {
                    $authenticated = false;
                }
            }
            if (!$authenticated) {
                throw new \RuntimeException('WLS Gateway response authentication failed.');
            }
            $response['signature'] = $signature;
            return self::sanitizeAuthenticatedResponse($response, $channel, $request);
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
        if ($channel === 'project'
            && \in_array($operation, ['register', 'renew', 'drain', 'unregister'], true)
        ) {
            // Project mutations may synchronously publish and validate a new
            // Nginx generation. A two-second read timeout caused the caller to
            // replay the same envelope while the first transaction was still
            // running, filling the Broker handler pool and starving heartbeat
            // and subsequent registration requests. Keep connect failures
            // short, but wait for one authoritative authenticated result.
            return \max(
                $this->timeoutSeconds,
                self::LONG_PROJECT_MUTATION_RESPONSE_TIMEOUT_SECONDS,
            );
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

    /**
     * Canonicalize a controller response without changing JSON number
     * spellings. This is a verification-only compatibility path for signed
     * responses produced by another PHP patch version.
     */
    private static function canonicalResponseFromWire(
        string $encodedResponse,
        string $expectedSignature,
    ): string {
        $marker = '';
        do {
            $marker = '__wls_edge_number_' . \bin2hex(\random_bytes(12)) . '_';
        } while (\str_contains($encodedResponse, $marker));

        $masked = '';
        $replacements = [];
        $length = \strlen($encodedResponse);
        $insideString = false;
        $escaped = false;
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $encodedResponse[$offset];
            if ($insideString) {
                $masked .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $insideString = false;
                }
                continue;
            }
            if ($character === '"') {
                $insideString = true;
                $masked .= $character;
                continue;
            }
            if ($character !== '-' && ($character < '0' || $character > '9')) {
                $masked .= $character;
                continue;
            }
            if (\preg_match(
                '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+\-]?[0-9]+)?/',
                \substr($encodedResponse, $offset),
                $match,
            ) !== 1) {
                throw new \RuntimeException('WLS Gateway response contains an invalid number.');
            }
            $number = (string)$match[0];
            $placeholder = $marker . \count($replacements);
            $encodedPlaceholder = '"' . $placeholder . '"';
            $masked .= $encodedPlaceholder;
            $replacements[$encodedPlaceholder] = $number;
            $offset += \strlen($number) - 1;
        }

        $document = \json_decode($masked, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($document)
            || !\hash_equals($expectedSignature, (string)($document['signature'] ?? ''))
        ) {
            throw new \RuntimeException('WLS Gateway wire response signature is invalid.');
        }
        unset($document['signature']);
        return \strtr(self::canonicalJson($document), $replacements);
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private static function sanitizeAuthenticatedResponse(
        array $response,
        string $channel,
        array $request,
    ): array {
        $enrollmentCredential = null;
        $operation = (string)($request['operation'] ?? '');
        $requestPayload = \is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $responsePayload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
        $credential = \is_array($responsePayload['credential'] ?? null)
            ? $responsePayload['credential']
            : [];
        $projectUuid = \strtolower(\trim((string)($requestPayload['project_uuid'] ?? '')));
        if ($channel === 'admin'
            && $operation === 'enroll'
            && ($response['ok'] ?? false) === true
            && (int)($credential['schema_version'] ?? 0) === 1
            && \hash_equals(GatewayPaths::PROTOCOL, (string)($credential['protocol'] ?? ''))
            && \hash_equals((string)($request['host_id'] ?? ''), (string)($credential['host_id'] ?? ''))
            && \preg_match('/\A[a-f0-9-]{36}\z/D', $projectUuid) === 1
            && \hash_equals($projectUuid, (string)($credential['project_uuid'] ?? ''))
            && \preg_match('/\A[a-f0-9]{32}\z/D', (string)($credential['credential_id'] ?? '')) === 1
            && \preg_match('/\A[a-f0-9]{64}\z/D', (string)($credential['secret'] ?? '')) === 1
        ) {
            // Preserve only the exact one-time structure required by
            // GatewayCredentialStore. Unknown fields from an older slot never
            // cross the authenticated client boundary.
            $enrollmentCredential = [
                'schema_version' => 1,
                'protocol' => GatewayPaths::PROTOCOL,
                'host_id' => (string)$credential['host_id'],
                'project_uuid' => $projectUuid,
                'credential_id' => (string)$credential['credential_id'],
                'secret' => (string)$credential['secret'],
                'issued_at' => (string)($credential['issued_at'] ?? ''),
            ];
        }

        $sanitized = GatewaySensitivePayloadSanitizer::sanitize($response);
        if (!\is_array($sanitized)) {
            throw new \RuntimeException('WLS Gateway response sanitization failed.');
        }
        if ($enrollmentCredential !== null) {
            $sanitized['payload'] = \is_array($sanitized['payload'] ?? null)
                ? $sanitized['payload']
                : [];
            $sanitized['payload']['credential'] = $enrollmentCredential;
        }
        return $sanitized;
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
