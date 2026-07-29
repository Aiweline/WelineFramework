<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;

/**
 * Coordinates fail-closed capability recovery across every project instance.
 */
final class GatewayBackendCapabilityStateStore
{
    private const SCHEMA_VERSION = 1;
    private const DEFAULT_RECOVERY_SECONDS = 30;

    public function __construct(
        private readonly ?string $stateFile = null,
        private readonly ?\Closure $clock = null,
        private readonly int $recoverySeconds = self::DEFAULT_RECOVERY_SECONDS,
    ) {
    }

    /**
     * @param array<string,mixed> $observation
     * @return array<string,mixed>
     */
    public function stabilize(array $observation): array
    {
        if (!$this->validObservation($observation)) {
            return $this->failClosed($observation, 'capability_observation_invalid');
        }
        if ((string)$observation['mode'] === 'stateless') {
            // Stateless is generation-bound per-instance proof. It has no
            // recovery window and must not mutate project-shared derived state.
            return $observation;
        }
        $file = $this->resolvedStateFile();
        $directory = \dirname($file);
        try {
            if (\is_link($directory)
                || (!\is_dir($directory)
                    && !@\mkdir($directory, 0700, true)
                    && !\is_dir($directory))
                || !\is_writable($directory)
            ) {
                return $this->failClosed($observation, 'capability_state_unavailable');
            }
            $this->assertSafeTarget($file);
            $lockFile = $file . '.lock';
            $this->assertSafeTarget($lockFile);
            $lock = @\fopen($lockFile, 'c+b');
            if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
                if (\is_resource($lock)) {
                    @\fclose($lock);
                }
                return $this->failClosed($observation, 'capability_state_unavailable');
            }
            @\chmod($lockFile, 0600);
            try {
                $state = $this->readState($file);
                [$next, $result] = $this->transition($state, $observation);
                if ($state !== $next || !\is_file($file)) {
                    $this->publishState($file, $next);
                }
                return $result;
            } finally {
                @\flock($lock, LOCK_UN);
                @\fclose($lock);
            }
        } catch (\Throwable) {
            return $this->failClosed($observation, 'capability_state_unavailable');
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $observation
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function transition(array $state, array $observation): array
    {
        $now = $this->now();
        $rawMode = (string)$observation['mode'];
        $rawDigest = (string)$observation['evidence_digest'];
        if ($rawMode !== 'shared_session') {
            if (($state['mode'] ?? '') === 'isolated'
                && (int)($state['healthy_since_unix'] ?? -1) === 0
                && \hash_equals(
                    (string)($state['raw_evidence_digest'] ?? ''),
                    $rawDigest,
                )
            ) {
                return [$state, $observation];
            }
            return [[
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => 'isolated',
                'raw_evidence_digest' => $rawDigest,
                'healthy_since_unix' => 0,
                'updated_unix' => $now,
            ], $observation];
        }

        $sameEvidence = \hash_equals(
            (string)($state['raw_evidence_digest'] ?? ''),
            $rawDigest,
        );
        if (($state['mode'] ?? '') === 'shared_session' && $sameEvidence) {
            return [$state, $observation];
        }
        $healthySince = $sameEvidence ? (int)($state['healthy_since_unix'] ?? 0) : 0;
        if ($healthySince <= 0 || $now < $healthySince) {
            $healthySince = $now;
        }
        $qualified = $now - $healthySince >= \max(1, $this->recoverySeconds);
        $next = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $qualified ? 'shared_session' : 'isolated',
            'raw_evidence_digest' => $rawDigest,
            'healthy_since_unix' => $healthySince,
            'updated_unix' => $qualified || !$sameEvidence
                ? $now
                : (int)($state['updated_unix'] ?? $now),
        ];
        return [
            $next,
            $qualified
                ? $observation
                : $this->failClosed($observation, 'shared_session_recovery_pending'),
        ];
    }

    /** @return array<string,mixed> */
    private function readState(string $file): array
    {
        if (!\is_file($file)) {
            return [];
        }
        $raw = @\file_get_contents($file);
        $state = \is_string($raw) ? \json_decode($raw, true) : null;
        if (\is_array($state)
            && (int)($state['schema_version'] ?? 0) === self::SCHEMA_VERSION
            && \in_array((string)($state['mode'] ?? ''), ['isolated', 'stateless', 'shared_session'], true)
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($state['raw_evidence_digest'] ?? ''),
            ) === 1
            && (int)($state['healthy_since_unix'] ?? -1) >= 0
            && (int)($state['updated_unix'] ?? -1) >= 0
        ) {
            return $state;
        }
        $quarantine = $file . '.corrupt-' . $this->now() . '-' . \bin2hex(\random_bytes(4));
        if (!@\rename($file, $quarantine) && \is_file($file)) {
            throw new \RuntimeException('Unable to quarantine corrupt gateway capability state.');
        }
        return [];
    }

    /** @param array<string,mixed> $state */
    private function publishState(string $file, array $state): void
    {
        $payload = \json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $temporary = $file . '.tmp.' . \bin2hex(\random_bytes(6));
        $handle = @\fopen($temporary, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to stage gateway capability state.');
        }
        try {
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @\fwrite($handle, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to write gateway capability state.');
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!@\fflush($handle) || (\function_exists('fsync') && !@\fsync($handle))) {
                throw new \RuntimeException('Unable to synchronize gateway capability state.');
            }
            if (\function_exists('fchmod')) {
                @\fchmod($handle, 0600);
            }
        } catch (\Throwable $throwable) {
            @\fclose($handle);
            @\unlink($temporary);
            throw $throwable;
        }
        @\fclose($handle);
        if (@\rename($temporary, $file)) {
            @\chmod($file, 0600);
            return;
        }

        $target = @\fopen($file, 'c+b');
        if (!\is_resource($target) || !@\flock($target, LOCK_EX)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to replace gateway capability state.');
        }
        try {
            if (!@\ftruncate($target, 0) || !@\rewind($target)) {
                throw new \RuntimeException('Unable to reset gateway capability state.');
            }
            $remaining = $payload;
            while ($remaining !== '') {
                $written = @\fwrite($target, $remaining);
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to replace gateway capability state.');
                }
                $remaining = (string)\substr($remaining, $written);
            }
            if (!@\fflush($target)) {
                throw new \RuntimeException('Unable to flush gateway capability state.');
            }
            @\chmod($file, 0600);
        } finally {
            @\flock($target, LOCK_UN);
            @\fclose($target);
            @\unlink($temporary);
        }
    }

    /** @param array<string,mixed> $observation */
    private function validObservation(array $observation): bool
    {
        $mode = (string)($observation['mode'] ?? '');
        $evidence = \is_array($observation['evidence'] ?? null)
            ? $observation['evidence']
            : [];
        $digest = (string)($observation['evidence_digest'] ?? '');
        return \in_array($mode, ['isolated', 'stateless', 'shared_session'], true)
            && $evidence !== []
            && \preg_match('/\A[a-f0-9]{64}\z/D', $digest) === 1
            && \hash_equals(
                $digest,
                \hash('sha256', GatewayClient::canonicalJson($evidence)),
            );
    }

    /**
     * @param array<string,mixed> $observation
     * @return array<string,mixed>
     */
    private function failClosed(array $observation, string $reason): array
    {
        $evidence = \is_array($observation['evidence'] ?? null)
            ? $observation['evidence']
            : [
                'schema' => 'wls-session-capability/1',
                'storage' => 'unknown',
                'runtime_source' => 'project_shared_state',
                'runtime_registered' => false,
                'runtime_shared_service' => false,
                'host' => '',
                'port' => 0,
                'token_scope_digest' => '',
                'probe' => 'not_attempted',
            ];
        $evidence['reason'] = $reason;
        return [
            'mode' => 'isolated',
            'evidence' => $evidence,
            'evidence_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($evidence),
            ),
        ];
    }

    private function resolvedStateFile(): string
    {
        return $this->stateFile ?? Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR
            . 'gateway-v2' . DIRECTORY_SEPARATOR . 'backend-capability.json';
    }

    private function now(): int
    {
        return $this->clock !== null ? (int)($this->clock)() : \time();
    }

    private function assertSafeTarget(string $file): void
    {
        if (\is_link($file) || (\file_exists($file) && !\is_file($file))) {
            throw new \RuntimeException(
                'Gateway capability state target must be a regular non-symlink file.'
            );
        }
    }
}
