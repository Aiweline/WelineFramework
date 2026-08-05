<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Controller-independent, host-guardian certificate revocation channel.
 *
 * The Native Broker accepts this deliberately narrow operation even while the
 * PHP Controller is unavailable. Authority is limited to the exact durable
 * project tombstone and can only remove admission / stop the shared data plane.
 */
final class GatewayEmergencyRevocationClient
{
    private const PROTOCOL = 'WLS-EDGE-EMERGENCY/1';
    private const MAX_FRAME_BYTES = 4096;

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly GatewayCredentialStore $credentials = new GatewayCredentialStore(),
        private readonly float $timeoutSeconds = 3.0,
    ) {
    }

    /**
     * @param array{domain:string,generation:int,source_digest:string} $tombstone
     * @return array<string,mixed>
     */
    public function revoke(array $tombstone): array
    {
        $credential = $this->credentials->load();
        $projectUuid = (string)$credential['project_uuid'];
        $credentialId = (string)$credential['credential_id'];
        $credentialGeneration = (int)$credential['credential_generation'];
        $secret = (string)$credential['secret'];
        $domain = \strtolower(\rtrim(\trim((string)($tombstone['domain'] ?? '')), '.'));
        $generation = $tombstone['generation'] ?? null;
        $sourceDigest = \strtolower(\trim((string)(
            $tombstone['source_digest'] ?? ''
        )));
        if ($domain === ''
            || \strlen($domain) > 253
            || \preg_match('/\A(?:\*\.)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $domain) !== 1
            || !\is_int($generation)
            || $generation < 1
            || !\hash_equals(
                \hash(
                    'sha256',
                    "wls-disabled-certificate\0" . $domain . "\0" . $generation,
                ),
                $sourceDigest,
            )
        ) {
            throw new \InvalidArgumentException(
                'Emergency gateway revocation requires an exact durable tombstone.',
            );
        }
        $operationId = \bin2hex(\random_bytes(16));
        $timestamp = \time();
        $nonce = \bin2hex(\random_bytes(16));
        $fields = [
            self::PROTOCOL,
            'REVOKE',
            $projectUuid,
            $credentialId,
            (string)$credentialGeneration,
            \bin2hex($domain),
            (string)$generation,
            $sourceDigest,
            $operationId,
            (string)$timestamp,
            $nonce,
        ];
        $canonical = \implode("\t", $fields);
        $fields[] = \hash_hmac('sha256', $canonical, $secret);
        $frame = \implode("\t", $fields) . "\n";
        if (\strlen($frame) > self::MAX_FRAME_BYTES) {
            throw new \RuntimeException('Emergency gateway revocation frame is too large.');
        }
        $endpoint = $this->paths->endpoint('project');
        $errno = 0;
        $error = '';
        $socket = $endpoint['transport'] === 'pipe'
            ? @\fopen($endpoint['address'], 'r+b')
            : @\stream_socket_client(
                $endpoint['address'],
                $errno,
                $error,
                \max(0.5, \min(10.0, $this->timeoutSeconds)),
                \STREAM_CLIENT_CONNECT,
            );
        if (!\is_resource($socket)) {
            throw new \RuntimeException(
                'Native gateway guardian endpoint is unavailable: '
                    . ($error !== '' ? $error : (string)$errno),
            );
        }
        try {
            \stream_set_timeout($socket, (int)\ceil(\max(1.0, $this->timeoutSeconds)));
            $offset = 0;
            while ($offset < \strlen($frame)) {
                $written = @\fwrite($socket, \substr($frame, $offset));
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException(
                        'Unable to send emergency gateway revocation.',
                    );
                }
                $offset += $written;
            }
            $line = @\fgets($socket, self::MAX_FRAME_BYTES + 1);
        } finally {
            @\fclose($socket);
        }
        if (!\is_string($line)
            || !\str_ends_with($line, "\n")
            || \strlen($line) > self::MAX_FRAME_BYTES
            || \str_contains(\rtrim($line, "\n"), "\n")
        ) {
            throw new \RuntimeException(
                'Native gateway guardian returned no complete revocation acknowledgement.',
            );
        }
        $parts = \explode("\t", \rtrim($line, "\n"));
        if (\count($parts) !== 13
            || !\hash_equals(self::PROTOCOL, (string)($parts[0] ?? ''))
            || !\hash_equals('OK', (string)($parts[1] ?? ''))
        ) {
            throw new \RuntimeException(
                'Native gateway guardian rejected the emergency revocation.',
            );
        }
        $signature = \strtolower((string)$parts[12]);
        $expectedSignature = \hash_hmac(
            'sha256',
            \implode("\t", \array_slice($parts, 0, 12)),
            $secret,
        );
        if (!\hash_equals($operationId, (string)$parts[2])
            || !\hash_equals($projectUuid, (string)$parts[3])
            || !\hash_equals(\bin2hex($domain), (string)$parts[4])
            || (int)$parts[5] !== $generation
            || !\hash_equals($sourceDigest, (string)$parts[6])
            || \preg_match('/\A[1-9][0-9]*\z/D', (string)$parts[7]) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$parts[8]) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$parts[9]) !== 1
            || !\hash_equals('1', (string)$parts[10])
            || !\hash_equals('1', (string)$parts[11])
            || \preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || !\hash_equals($expectedSignature, $signature)
        ) {
            throw new \RuntimeException(
                'Native gateway guardian revocation acknowledgement is not exact.',
            );
        }
        return [
            'operation_id' => $operationId,
            'project_uuid' => $projectUuid,
            'domain' => $domain,
            'generation' => $generation,
            'source_digest' => $sourceDigest,
            'ledger_sequence' => (int)$parts[7],
            'ledger_digest' => (string)$parts[8],
            'broker_epoch' => (string)$parts[9],
            'data_plane_stopped' => true,
            'controller_restart_requested' => true,
        ];
    }
}
