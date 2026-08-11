<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;

/**
 * Coordinates fail-closed capability recovery across every project instance.
 */
final class GatewayBackendCapabilityStateStore
{
    private const SCHEMA_VERSION = 2;
    private const DEFAULT_RECOVERY_SECONDS = 30;

    public function __construct(
        private readonly ?string $stateFile = null,
        private readonly ?\Closure $clock = null,
        private readonly int $recoverySeconds = self::DEFAULT_RECOVERY_SECONDS,
        private readonly ?string $hostBootId = null,
        private readonly ?\Closure $wallClock = null,
    ) {
    }

    /**
     * @param array<string,mixed> $observation
     * @return array<string,mixed>
     */
    public function stabilize(
        array $observation,
        ?float $deadlineMonotonic = null,
    ): array
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
                    && !@\mkdir($directory, 0700)
                    && !\is_dir($directory))
            ) {
                return $this->failClosed($observation, 'capability_state_unavailable');
            }
            $directoryStatus = @\lstat($directory);
            if (!\is_array($directoryStatus)
                || \is_link($directory)
                || ((((int)($directoryStatus['mode'] ?? 0)) & 0170000) !== 0040000)
                || !\is_writable($directory)
                || (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($directory, 0700))
            ) {
                return $this->failClosed($observation, 'capability_state_unavailable');
            }
            $lockFile = $file . '.lock';
            return GatewayProjectStateFilesystem::withExclusiveLock(
                $lockFile,
                function () use (
                    $file,
                    $observation,
                    $deadlineMonotonic,
                ): array {
                    self::deadlineRemaining($deadlineMonotonic);
                    $this->cleanupStateRecoveryBackups($file);
                    $state = $this->readState($file);
                    [$next, $result] = $this->transition($state, $observation);
                    if ($state !== $next) {
                        $this->publishState($file, $next);
                    }
                    return $result;
                },
                waitTimeoutSeconds: self::lockWaitTimeout($deadlineMonotonic),
            );
        } catch (\Throwable) {
            return $this->failClosed($observation, 'capability_state_unavailable');
        }
    }

    private static function lockWaitTimeout(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        return \min(0.25, self::deadlineRemaining($deadlineMonotonic));
    }

    private static function deadlineRemaining(?float $deadlineMonotonic): float
    {
        if ($deadlineMonotonic === null) {
            return 300.0;
        }
        if (!\is_finite($deadlineMonotonic)) {
            throw new \RuntimeException('Gateway backend capability deadline is invalid.');
        }
        $remaining = $deadlineMonotonic - (\hrtime(true) / 1_000_000_000);
        if ($remaining <= 0.0) {
            throw new \RuntimeException(
                'Gateway backend capability deadline was exhausted.',
            );
        }
        return $remaining;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $observation
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function transition(array $state, array $observation): array
    {
        $now = $this->monotonicNow();
        $wallNow = $this->wallNow();
        $bootId = $this->resolvedHostBootId();
        $rawMode = (string)$observation['mode'];
        $rawDigest = (string)$observation['evidence_digest'];
        if ($rawMode !== 'shared_session') {
            if (($state['mode'] ?? '') === 'isolated'
                && (float)($state['healthy_since_monotonic'] ?? -1.0) === 0.0
                && \hash_equals(
                    $bootId,
                    (string)($state['host_boot_id'] ?? ''),
                )
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
                'host_boot_id' => $bootId,
                'healthy_since_monotonic' => 0.0,
                'updated_monotonic' => $now,
                'updated_unix' => $wallNow,
            ], $observation];
        }

        $sameEvidence = \hash_equals(
            (string)($state['raw_evidence_digest'] ?? ''),
            $rawDigest,
        );
        $sameBoot = \hash_equals(
            $bootId,
            (string)($state['host_boot_id'] ?? ''),
        );
        $healthySince = $sameBoot && $sameEvidence
            ? (float)($state['healthy_since_monotonic'] ?? 0.0)
            : 0.0;
        $age = $now - $healthySince;
        if (($state['mode'] ?? '') === 'shared_session'
            && $sameBoot
            && $sameEvidence
            && $healthySince > 0.0
            && \is_finite($age)
            && $age >= \max(1, $this->recoverySeconds)
        ) {
            return [$state, $observation];
        }

        $windowReset = $healthySince <= 0.0 || !\is_finite($age) || $age < 0.0;
        if ($windowReset) {
            $healthySince = $now;
            $age = 0.0;
        }
        $qualified = $age >= \max(1, $this->recoverySeconds);
        $next = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $qualified ? 'shared_session' : 'isolated',
            'raw_evidence_digest' => $rawDigest,
            'host_boot_id' => $bootId,
            'healthy_since_monotonic' => $healthySince,
            'updated_monotonic' => $qualified || $windowReset || !$sameBoot || !$sameEvidence
                ? $now
                : (float)($state['updated_monotonic'] ?? $now),
            'updated_unix' => $qualified || $windowReset || !$sameBoot || !$sameEvidence
                ? $wallNow
                : (int)($state['updated_unix'] ?? $wallNow),
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
        $raw = GatewayProjectStateFilesystem::readOptional(
            $file,
            65_536,
            'gateway backend capability state',
        );
        if ($raw === null) {
            return [];
        }
        $state = $this->decodeValidState($raw);
        if ($state !== null) {
            return $state;
        }
        // The recovery path itself must remain bounded. A random quarantine
        // name would copy the same unreadable state on every retry when the
        // original cannot be removed, eventually turning one corrupt derived
        // file into a disk-exhaustion loop. The lock serializes replacement of
        // this diagnostic slot and keeps only the latest rejected payload.
        $quarantine = $file . '.corrupt-latest';
        GatewayProjectStateFilesystem::atomicWrite($quarantine, $raw, 0600);
        GatewayProjectStateFilesystem::removeRegular(
            $file,
            'corrupt gateway backend capability state',
        );
        return [];
    }

    /** @return array<string,mixed>|null */
    private function decodeValidState(string $raw): ?array
    {
        $state = \json_decode($raw, true);
        $expected = [
            'schema_version',
            'mode',
            'raw_evidence_digest',
            'host_boot_id',
            'healthy_since_monotonic',
            'updated_monotonic',
            'updated_unix',
        ];
        $actual = \is_array($state) ? \array_keys($state) : [];
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        return \is_array($state)
            && $actual === $expected
            && ($state['schema_version'] ?? null) === self::SCHEMA_VERSION
            && \in_array((string)($state['mode'] ?? ''), [
                'isolated',
                'stateless',
                'shared_session',
            ], true)
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($state['raw_evidence_digest'] ?? ''),
            ) === 1
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($state['host_boot_id'] ?? ''),
            ) === 1
            && \is_int($state['updated_unix'] ?? null)
            && (int)$state['updated_unix'] >= 0
            && (\is_int($state['healthy_since_monotonic'] ?? null)
                || \is_float($state['healthy_since_monotonic'] ?? null))
            && \is_finite((float)$state['healthy_since_monotonic'])
            && (float)$state['healthy_since_monotonic'] >= 0.0
            && (\is_int($state['updated_monotonic'] ?? null)
                || \is_float($state['updated_monotonic'] ?? null))
            && \is_finite((float)$state['updated_monotonic'])
            && (float)$state['updated_monotonic'] >= 0.0
                ? $state
                : null;
    }

    private function cleanupStateRecoveryBackups(string $file): void
    {
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $file,
            65_536,
            'gateway backend capability state',
            function (string $raw): void {
                if ($this->decodeValidState($raw) === null) {
                    throw new \RuntimeException(
                        'Gateway backend capability recovery target is invalid.'
                    );
                }
            },
        );
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $file . '.corrupt-latest',
            65_536,
            'gateway backend capability diagnostic',
            static function (string $raw): void {
                if ($raw === '') {
                    throw new \RuntimeException(
                        'Gateway backend capability diagnostic recovery target is empty.'
                    );
                }
            },
        );
    }

    /** @param array<string,mixed> $state */
    private function publishState(string $file, array $state): void
    {
        $payload = \json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        GatewayProjectStateFilesystem::atomicWrite($file, $payload, 0600);
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

    private function monotonicNow(): float
    {
        $now = $this->clock !== null
            ? (float)($this->clock)()
            : \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('Gateway capability monotonic clock is invalid.');
        }
        return $now;
    }

    private function wallNow(): int
    {
        $now = $this->wallClock !== null ? (int)($this->wallClock)() : \time();
        if ($now <= 0) {
            throw new \RuntimeException('Gateway capability wall clock is invalid.');
        }
        return $now;
    }

    private function resolvedHostBootId(): string
    {
        return GatewayHostBootIdentity::validate(
            $this->hostBootId ?? GatewayHostBootIdentity::current(),
        );
    }

}
