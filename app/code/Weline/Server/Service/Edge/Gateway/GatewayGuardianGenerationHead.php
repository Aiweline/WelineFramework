<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Root-owned, two-copy generation head for the immutable Recovery Guardian.
 *
 * The record is deliberately line-canonical so the frozen native Guardian can
 * validate it without a JSON parser. Every writer performs a sequence CAS and
 * writes the older slot; a same-sequence fork is treated as an ABA attack and
 * fails closed.
 */
final class GatewayGuardianGenerationHead
{
    private const MAX_BYTES = 4096;
    private const ZERO_32 = '00000000000000000000000000000000';
    private const ZERO_64 = '0000000000000000000000000000000000000000000000000000000000000000';
    private const PHASES = [
        'STABLE',
        'PROBATIONARY_COMMITTED',
        'ROLLBACK_PENDING',
        'ROLLBACK_OBSERVING',
        'FAILED_CLOSED',
    ];

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
    ) {
    }

    /**
     * Publish the first stable head, or prove an existing head is the exact
     * same generation. This is safe to replay after a crash between Guardian
     * installation and first service start.
     *
     * @return array<string,mixed>
     */
    public function initializeStable(
        string $hostId,
        string $launcherSha256,
        string $caSha256,
        string $runtimeGeneration,
        string $hostBootId,
    ): array {
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function () use (
                $hostId,
                $launcherSha256,
                $caSha256,
                $runtimeGeneration,
                $hostBootId,
            ): array {
                $this->recoverAtomicArtifactsUnlocked();
                $activeGenerationId = self::generationId(
                    $launcherSha256,
                    $caSha256,
                    $runtimeGeneration,
                );
                $current = $this->readUnlocked();
                if ($current !== null) {
                    foreach ([
                        'host_id' => self::normalizeHex($hostId, 32, 'host identity'),
                        'phase' => 'STABLE',
                        'active_generation_id' => $activeGenerationId,
                        'active_launcher_sha256' => self::normalizeHex(
                            $launcherSha256,
                            64,
                            'launcher digest',
                        ),
                        'active_ca_sha256' => self::normalizeHex(
                            $caSha256,
                            64,
                            'CA digest',
                        ),
                        'active_runtime_generation' => self::normalizeHex(
                            $runtimeGeneration,
                            64,
                            'runtime generation',
                        ),
                    ] as $field => $expected) {
                        if (!\hash_equals($expected, (string)$current[$field])) {
                            throw new \RuntimeException(
                                'Existing Guardian generation head belongs to another active generation.',
                            );
                        }
                    }
                    return $this->publicRecord($current);
                }

                return $this->publishUnlocked(0, [
                    'host_id' => self::normalizeHex($hostId, 32, 'host identity'),
                    'phase' => 'STABLE',
                    'active_generation_id' => $activeGenerationId,
                    'active_launcher_sha256' => self::normalizeHex(
                        $launcherSha256,
                        64,
                        'launcher digest',
                    ),
                    'active_ca_sha256' => self::normalizeHex(
                        $caSha256,
                        64,
                        'CA digest',
                    ),
                    'active_runtime_generation' => self::normalizeHex(
                        $runtimeGeneration,
                        64,
                        'runtime generation',
                    ),
                    'recovery_generation_id' => self::ZERO_64,
                    'recovery_nonce' => self::ZERO_32,
                    'recovery_authorization_sha256' => self::ZERO_64,
                    'host_boot_id' => self::normalizeHex(
                        $hostBootId,
                        64,
                        'host boot identity',
                    ),
                    'probation_started_monotonic_ms' => 0,
                    'probation_deadline_monotonic_ms' => 0,
                ]);
            },
        );
    }

    /** @return array<string,mixed>|null */
    public function read(): ?array
    {
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function (): ?array {
                return $this->readWhileLocked();
            },
        );
    }

    /**
     * The caller must hold guardianGenerationHeadLockFile().
     *
     * @return array<string,mixed>|null
     */
    public function readWhileLocked(): ?array
    {
        $this->recoverAtomicArtifactsUnlocked();
        $record = $this->readUnlocked();
        return $record === null ? null : $this->publicRecord($record);
    }

    /**
     * Sequence-CAS publication primitive for the native/PHP transition
     * handshake. Callers must supply all semantic fields; this class owns the
     * canonical envelope, predecessor binding and HMAC.
     *
     * @param array<string,mixed> $next
     * @return array<string,mixed>
     */
    public function transition(int $expectedSequence, array $next): array
    {
        if ($expectedSequence < 0) {
            throw new \InvalidArgumentException(
                'Guardian expected head sequence is invalid.',
            );
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $this->paths->guardianGenerationHeadLockFile(),
            function () use ($expectedSequence, $next): array {
                $this->recoverAtomicArtifactsUnlocked();
                return $this->publishUnlocked($expectedSequence, $next);
            },
        );
    }

    private function recoverAtomicArtifactsUnlocked(): void
    {
        for ($slot = 0; $slot <= 1; ++$slot) {
            $file = $this->paths->guardianGenerationHeadFile($slot);
            if (!GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $file,
                self::MAX_BYTES,
                'WLS Guardian generation head ' . $slot,
            )) {
                continue;
            }
            if (!\file_exists($file) && !\is_link($file)) {
                GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
                    $file,
                    self::MAX_BYTES,
                    'WLS Guardian generation head ' . $slot,
                );
                continue;
            }
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $file,
                self::MAX_BYTES,
                'WLS Guardian generation head ' . $slot,
                function (string $raw): void {
                    $this->decode($raw);
                },
            );
        }
    }

    /** @param array<string,mixed> $next @return array<string,mixed> */
    private function publishUnlocked(int $expectedSequence, array $next): array
    {
        $current = $this->readUnlocked();
        $actualSequence = (int)($current['sequence'] ?? 0);
        if ($actualSequence !== $expectedSequence) {
            throw new \RuntimeException(
                'Guardian generation-head CAS rejected a stale expected sequence.',
            );
        }
        if ($actualSequence === PHP_INT_MAX) {
            throw new \RuntimeException(
                'Guardian generation-head sequence is exhausted.',
            );
        }
        $record = $this->normalizeRecord($next + [
            'sequence' => $actualSequence + 1,
            'previous_record_sha256' => $current === null
                ? self::ZERO_64
                : (string)$current['_sha256'],
            'signature' => '',
        ]);
        if ((int)$record['sequence'] !== $actualSequence + 1
            || !\hash_equals(
                $current === null ? self::ZERO_64 : (string)$current['_sha256'],
                (string)$record['previous_record_sha256'],
            )
        ) {
            throw new \InvalidArgumentException(
                'Guardian generation-head publication attempted to override sequence ownership.',
            );
        }
        $this->assertTransitionAllowed($current, $record);
        $unsigned = $this->encodeUnsigned($record);
        $key = $this->administratorKey();
        try {
            $record['signature'] = \hash_hmac('sha256', $unsigned, $key);
        } finally {
            \sodium_memzero($key);
        }
        $encoded = $unsigned . 'signature=' . $record['signature'] . "\n";
        $targetSlot = $current === null ? 0 : (1 - (int)$current['_slot']);
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianGenerationHeadFile($targetSlot),
            $encoded,
            0600,
        );
        $published = $this->readUnlocked();
        if ($published === null
            || (int)$published['sequence'] !== $actualSequence + 1
            || !\hash_equals(\hash('sha256', $encoded), (string)$published['_sha256'])
        ) {
            throw new \RuntimeException(
                'Guardian generation-head publication did not become authoritative.',
            );
        }
        return $this->publicRecord($published);
    }

    /** @return array<string,mixed>|null */
    private function readUnlocked(): ?array
    {
        $records = [];
        $invalid = [];
        for ($slot = 0; $slot <= 1; ++$slot) {
            $path = $this->paths->guardianGenerationHeadFile($slot);
            $raw = GatewayProjectStateFilesystem::readOptional(
                $path,
                self::MAX_BYTES,
                'WLS Guardian generation head ' . $slot,
            );
            if ($raw === null) {
                continue;
            }
            try {
                $record = $this->decode($raw);
                $record['_slot'] = $slot;
                $record['_raw'] = $raw;
                $record['_sha256'] = \hash('sha256', $raw);
                $records[] = $record;
            } catch (\Throwable $throwable) {
                $invalid[$slot] = $throwable;
            }
        }
        if ($records === []) {
            if ($invalid !== []) {
                throw new \RuntimeException(
                    'Every Guardian generation-head copy is invalid.',
                    0,
                    \reset($invalid),
                );
            }
            return null;
        }
        \usort(
            $records,
            static fn (array $left, array $right): int =>
                ((int)$right['sequence'] <=> (int)$left['sequence'])
                    ?: ((int)$left['_slot'] <=> (int)$right['_slot']),
        );
        $selected = $records[0];
        if (isset($records[1])) {
            $older = $records[1];
            if (!\hash_equals((string)$selected['host_id'], (string)$older['host_id'])) {
                throw new \RuntimeException(
                    'Guardian generation-head copies belong to different hosts.',
                );
            }
            if ((int)$selected['sequence'] === (int)$older['sequence']) {
                if (!\hash_equals((string)$selected['_sha256'], (string)$older['_sha256'])) {
                    throw new \RuntimeException(
                        'Guardian generation-head has an equal-sequence ABA fork.',
                    );
                }
            } elseif ((int)$selected['sequence'] !== (int)$older['sequence'] + 1
                || !\hash_equals(
                    (string)$older['_sha256'],
                    (string)$selected['previous_record_sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'Guardian generation-head predecessor chain is invalid.',
                );
            }
            if ((int)$selected['sequence'] !== (int)$older['sequence']) {
                $this->assertTransitionAllowed($older, $selected);
            }
        }
        return $selected;
    }

    /** @return array<string,mixed> */
    private function decode(string $raw): array
    {
        $matches = [];
        if (\preg_match(
            '/\AWLS-GUARDIAN-GENERATION-HEAD\/1\n'
                . 'host_id=([a-f0-9]{32})\n'
                . 'sequence=([0-9]{1,20})\n'
                . 'phase=(STABLE|PROBATIONARY_COMMITTED|ROLLBACK_PENDING|ROLLBACK_OBSERVING|FAILED_CLOSED)\n'
                . 'active_generation_id=([a-f0-9]{64})\n'
                . 'active_launcher_sha256=([a-f0-9]{64})\n'
                . 'active_ca_sha256=([a-f0-9]{64})\n'
                . 'active_runtime_generation=([a-f0-9]{64})\n'
                . 'recovery_generation_id=([a-f0-9]{64})\n'
                . 'recovery_nonce=([a-f0-9]{32})\n'
                . 'recovery_authorization_sha256=([a-f0-9]{64})\n'
                . 'host_boot_id=([a-f0-9]{64})\n'
                . 'probation_started_monotonic_ms=([0-9]{1,20})\n'
                . 'probation_deadline_monotonic_ms=([0-9]{1,20})\n'
                . 'previous_record_sha256=([a-f0-9]{64})\n'
                . 'signature=([a-f0-9]{64})\n\z/D',
            $raw,
            $matches,
        ) !== 1) {
            throw new \RuntimeException(
                'Guardian generation-head canonical record is malformed.',
            );
        }
        $record = $this->normalizeRecord([
            'host_id' => $matches[1],
            'sequence' => self::decimalToInt($matches[2], 'sequence'),
            'phase' => $matches[3],
            'active_generation_id' => $matches[4],
            'active_launcher_sha256' => $matches[5],
            'active_ca_sha256' => $matches[6],
            'active_runtime_generation' => $matches[7],
            'recovery_generation_id' => $matches[8],
            'recovery_nonce' => $matches[9],
            'recovery_authorization_sha256' => $matches[10],
            'host_boot_id' => $matches[11],
            'probation_started_monotonic_ms' => self::decimalToInt(
                $matches[12],
                'probation start',
            ),
            'probation_deadline_monotonic_ms' => self::decimalToInt(
                $matches[13],
                'probation deadline',
            ),
            'previous_record_sha256' => $matches[14],
            'signature' => $matches[15],
        ]);
        $key = $this->administratorKey();
        try {
            $expected = \hash_hmac('sha256', $this->encodeUnsigned($record), $key);
        } finally {
            \sodium_memzero($key);
        }
        if (!\hash_equals($expected, (string)$record['signature'])) {
            throw new \RuntimeException(
                'Guardian generation-head signature is invalid.',
            );
        }
        return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function normalizeRecord(array $record): array
    {
        $expected = [
            'host_id',
            'sequence',
            'phase',
            'active_generation_id',
            'active_launcher_sha256',
            'active_ca_sha256',
            'active_runtime_generation',
            'recovery_generation_id',
            'recovery_nonce',
            'recovery_authorization_sha256',
            'host_boot_id',
            'probation_started_monotonic_ms',
            'probation_deadline_monotonic_ms',
            'previous_record_sha256',
            'signature',
        ];
        $actual = \array_keys($record);
        \sort($expected, SORT_STRING);
        \sort($actual, SORT_STRING);
        if ($actual !== $expected
            || !\is_int($record['sequence'] ?? null)
            || (int)$record['sequence'] < 1
            || !\in_array((string)($record['phase'] ?? ''), self::PHASES, true)
            || !\is_int($record['probation_started_monotonic_ms'] ?? null)
            || !\is_int($record['probation_deadline_monotonic_ms'] ?? null)
            || (int)$record['probation_started_monotonic_ms'] < 0
            || (int)$record['probation_deadline_monotonic_ms'] < 0
            || !\is_string($record['signature'] ?? null)
            || ((string)$record['signature'] !== ''
                && \preg_match('/\A[a-f0-9]{64}\z/D', (string)$record['signature']) !== 1)
        ) {
            throw new \InvalidArgumentException(
                'Guardian generation-head record violates its fixed schema.',
            );
        }
        foreach ([
            'host_id' => 32,
            'active_generation_id' => 64,
            'active_launcher_sha256' => 64,
            'active_ca_sha256' => 64,
            'active_runtime_generation' => 64,
            'recovery_generation_id' => 64,
            'recovery_nonce' => 32,
            'recovery_authorization_sha256' => 64,
            'host_boot_id' => 64,
            'previous_record_sha256' => 64,
        ] as $field => $length) {
            $record[$field] = self::normalizeHex(
                (string)($record[$field] ?? ''),
                $length,
                $field,
            );
        }
        if (!\hash_equals(
            self::generationId(
                (string)$record['active_launcher_sha256'],
                (string)$record['active_ca_sha256'],
                (string)$record['active_runtime_generation'],
            ),
            (string)$record['active_generation_id'],
        )) {
            throw new \InvalidArgumentException(
                'Guardian active generation identity is inconsistent.',
            );
        }
        $phase = (string)$record['phase'];
        $timedObservation = \in_array($phase, [
            'PROBATIONARY_COMMITTED',
            'ROLLBACK_OBSERVING',
        ], true);
        $probationary = \hash_equals('PROBATIONARY_COMMITTED', $phase);
        $rollbackPending = \hash_equals('ROLLBACK_PENDING', $phase);
        $rollbackObserving = \hash_equals('ROLLBACK_OBSERVING', $phase);
        $failedClosed = \hash_equals(
            'FAILED_CLOSED',
            $phase,
        );
        $hasRecovery = !\hash_equals(
            self::ZERO_64,
            (string)$record['recovery_generation_id'],
        )
            && !\hash_equals(
                self::ZERO_32,
                (string)$record['recovery_nonce'],
            )
            && !\hash_equals(
                self::ZERO_64,
                (string)$record['recovery_authorization_sha256'],
            );
        if (($timedObservation
                && ((int)$record['probation_started_monotonic_ms'] < 1
                    || (int)$record['probation_deadline_monotonic_ms']
                        <= (int)$record['probation_started_monotonic_ms']
                    || !$hasRecovery))
            || ($probationary
                && \hash_equals(
                        (string)$record['active_generation_id'],
                        (string)$record['recovery_generation_id'],
                    ))
            || (!$timedObservation
                && ((int)$record['probation_started_monotonic_ms'] !== 0
                    || (int)$record['probation_deadline_monotonic_ms'] !== 0))
            || ($rollbackPending
                && (!$hasRecovery || \hash_equals(
                        (string)$record['active_generation_id'],
                        (string)$record['recovery_generation_id'],
                    )))
            || ($rollbackObserving
                && (!$hasRecovery || !\hash_equals(
                        (string)$record['active_generation_id'],
                        (string)$record['recovery_generation_id'],
                    )))
            || ($failedClosed && !$hasRecovery)
            || (\hash_equals('STABLE', $phase)
                && (!\hash_equals(self::ZERO_64, (string)$record['recovery_generation_id'])
                    || !\hash_equals(self::ZERO_32, (string)$record['recovery_nonce'])
                    || !\hash_equals(
                        self::ZERO_64,
                        (string)$record['recovery_authorization_sha256'],
                    )))
        ) {
            throw new \InvalidArgumentException(
                'Guardian generation-head phase evidence is inconsistent.',
            );
        }
        return $record;
    }

    /**
     * @param array<string,mixed>|null $current
     * @param array<string,mixed> $next
     */
    private function assertTransitionAllowed(?array $current, array $next): void
    {
        if ($current === null) {
            if (!\hash_equals('STABLE', (string)$next['phase'])) {
                throw new \InvalidArgumentException(
                    'Guardian generation head must initialize as STABLE.',
                );
            }
            return;
        }
        if (!\hash_equals(
            (string)$current['host_id'],
            (string)$next['host_id'],
        )) {
            throw new \InvalidArgumentException(
                'Guardian generation-head transition cannot change host identity.',
            );
        }
        $from = (string)$current['phase'];
        $to = (string)$next['phase'];
        $allowed = match ($from) {
            'STABLE' => ['PROBATIONARY_COMMITTED', 'ROLLBACK_PENDING'],
            'PROBATIONARY_COMMITTED' => [
                'PROBATIONARY_COMMITTED',
                'STABLE',
                'ROLLBACK_PENDING',
                'FAILED_CLOSED',
            ],
            'ROLLBACK_PENDING' => ['ROLLBACK_OBSERVING', 'FAILED_CLOSED'],
            'ROLLBACK_OBSERVING' => [
                'ROLLBACK_OBSERVING',
                'STABLE',
                'FAILED_CLOSED',
            ],
            'FAILED_CLOSED' => [],
            default => [],
        };
        if (!\in_array($to, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Guardian generation-head phase transition is not allowed: '
                    . $from . ' -> ' . $to . '.',
            );
        }

        $same = static function (
            array $left,
            array $right,
            array $fields,
        ): bool {
            foreach ($fields as $field) {
                if (!\hash_equals(
                    (string)$left[$field],
                    (string)$right[$field],
                )) {
                    return false;
                }
            }
            return true;
        };
        $activeFields = [
            'active_generation_id',
            'active_launcher_sha256',
            'active_ca_sha256',
            'active_runtime_generation',
        ];
        $recoveryFields = [
            'recovery_generation_id',
            'recovery_nonce',
            'recovery_authorization_sha256',
        ];

        if ($from === 'STABLE' && $to === 'PROBATIONARY_COMMITTED') {
            if (!\hash_equals(
                    (string)$current['active_generation_id'],
                    (string)$next['recovery_generation_id'],
                )
                || \hash_equals(
                    (string)$current['active_generation_id'],
                    (string)$next['active_generation_id'],
                )
            ) {
                throw new \InvalidArgumentException(
                    'Guardian probation does not bind the prior stable generation.',
                );
            }
            return;
        }
        if ($from === 'PROBATIONARY_COMMITTED'
            && $to === 'PROBATIONARY_COMMITTED'
        ) {
            if (!$same($current, $next, [...$activeFields, ...$recoveryFields])) {
                throw new \InvalidArgumentException(
                    'Guardian probation restart changed its generation binding.',
                );
            }
            return;
        }
        if ($from === 'PROBATIONARY_COMMITTED' && $to === 'STABLE') {
            if (!$same($current, $next, $activeFields)) {
                throw new \InvalidArgumentException(
                    'Guardian stable commit changed the probationary generation.',
                );
            }
            return;
        }
        if ($to === 'ROLLBACK_PENDING') {
            $bound = $from === 'STABLE'
                ? ((\hash_equals(
                            (string)$current['active_generation_id'],
                            (string)$next['active_generation_id'],
                        )
                        && !\hash_equals(
                            (string)$current['active_generation_id'],
                            (string)$next['recovery_generation_id'],
                        ))
                    || (!\hash_equals(
                            (string)$current['active_generation_id'],
                            (string)$next['active_generation_id'],
                        )
                        && \hash_equals(
                            (string)$current['active_generation_id'],
                            (string)$next['recovery_generation_id'],
                        )))
                : ($same($current, $next, $activeFields)
                    && $same($current, $next, $recoveryFields));
            if (!$bound) {
                throw new \InvalidArgumentException(
                    'Guardian rollback request changed its candidate or recovery binding.',
                );
            }
            return;
        }
        if ($from === 'ROLLBACK_PENDING' && $to === 'ROLLBACK_OBSERVING') {
            if (!$same($current, $next, $recoveryFields)
                || !\hash_equals(
                    (string)$current['recovery_generation_id'],
                    (string)$next['active_generation_id'],
                )
            ) {
                throw new \InvalidArgumentException(
                    'Guardian rollback observation is not the exact authorized recovery generation.',
                );
            }
            return;
        }
        if ($from === 'ROLLBACK_OBSERVING'
            && $to === 'ROLLBACK_OBSERVING'
        ) {
            if (!$same(
                $current,
                $next,
                [...$activeFields, ...$recoveryFields],
            )) {
                throw new \InvalidArgumentException(
                    'Guardian rollback observation restart changed its exact recovery generation.',
                );
            }
            return;
        }
        if ($from === 'ROLLBACK_OBSERVING' && $to === 'STABLE') {
            if (!$same($current, $next, $activeFields)) {
                throw new \InvalidArgumentException(
                    'Guardian rollback stable head is not the authorized recovery generation.',
                );
            }
            return;
        }
        if ($to === 'FAILED_CLOSED') {
            if (!$same(
                $current,
                $next,
                [...$activeFields, ...$recoveryFields],
            )) {
                throw new \InvalidArgumentException(
                    'Guardian fail-closed transition changed its exact generation evidence.',
                );
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function encodeUnsigned(array $record): string
    {
        return "WLS-GUARDIAN-GENERATION-HEAD/1\n"
            . 'host_id=' . $record['host_id'] . "\n"
            . 'sequence=' . $record['sequence'] . "\n"
            . 'phase=' . $record['phase'] . "\n"
            . 'active_generation_id=' . $record['active_generation_id'] . "\n"
            . 'active_launcher_sha256=' . $record['active_launcher_sha256'] . "\n"
            . 'active_ca_sha256=' . $record['active_ca_sha256'] . "\n"
            . 'active_runtime_generation=' . $record['active_runtime_generation'] . "\n"
            . 'recovery_generation_id=' . $record['recovery_generation_id'] . "\n"
            . 'recovery_nonce=' . $record['recovery_nonce'] . "\n"
            . 'recovery_authorization_sha256='
                . $record['recovery_authorization_sha256'] . "\n"
            . 'host_boot_id=' . $record['host_boot_id'] . "\n"
            . 'probation_started_monotonic_ms='
                . $record['probation_started_monotonic_ms'] . "\n"
            . 'probation_deadline_monotonic_ms='
                . $record['probation_deadline_monotonic_ms'] . "\n"
            . 'previous_record_sha256=' . $record['previous_record_sha256'] . "\n";
    }

    private function administratorKey(): string
    {
        $raw = GatewayProjectStateFilesystem::read(
            $this->paths->adminTokenFile(),
            65,
            'WLS Guardian administrator HMAC key',
        );
        $hex = \strtolower(\trim($raw));
        $key = \preg_match('/\A[a-f0-9]{64}\z/D', $hex) === 1
            ? \hex2bin($hex)
            : false;
        if (!\is_string($key) || \strlen($key) !== 32) {
            throw new \RuntimeException(
                'WLS Guardian administrator HMAC key is invalid.',
            );
        }
        return $key;
    }

    public static function generationId(
        string $launcherSha256,
        string $caSha256,
        string $runtimeGeneration,
    ): string {
        return \hash(
            'sha256',
            "wls-guardian-active-generation/1\0"
                . self::normalizeHex($launcherSha256, 64, 'launcher digest')
                . "\0" . self::normalizeHex($caSha256, 64, 'CA digest')
                . "\0" . self::normalizeHex(
                    $runtimeGeneration,
                    64,
                    'runtime generation',
                ),
        );
    }

    private static function normalizeHex(
        string $value,
        int $length,
        string $label,
    ): string {
        $value = \strtolower(\trim($value));
        if (\preg_match('/\A[a-f0-9]{' . $length . '}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'Guardian ' . $label . ' is invalid.',
            );
        }
        return $value;
    }

    private static function decimalToInt(string $value, string $label): int
    {
        $normalized = \ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string)PHP_INT_MAX;
        if (\strlen($normalized) > \strlen($maximum)
            || (\strlen($normalized) === \strlen($maximum)
                && \strcmp($normalized, $maximum) > 0)
        ) {
            throw new \RuntimeException(
                'Guardian ' . $label . ' exceeds this runtime.',
            );
        }
        return (int)$normalized;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function publicRecord(array $record): array
    {
        if (isset($record['_sha256'])) {
            $record['record_sha256'] = (string)$record['_sha256'];
        }
        unset($record['_raw'], $record['_sha256'], $record['_slot']);
        return $record;
    }
}
