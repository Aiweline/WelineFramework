<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Durable, root-only forward journal for the one-time host Gateway install.
 * It carries no runtime dependency on the project: the project path is only a
 * replay fingerprint and every executable is copied into the host A/B slots.
 */
final class GatewayInitialBootstrapJournal
{
    private const SCHEMA_VERSION = 2;
    private const MAX_BYTES = 16_384;
    private const PROFILES = ['default', 'ipv4-only'];
    private const PHASES = [
        'PREPARING' => 0,
        'STAGED' => 1,
        'DEFINITION_INSTALLED' => 2,
        'ACTIVATED' => 3,
        'STARTED' => 4,
        'VERIFIED' => 5,
        'ROLLING_BACK' => 6,
    ];
    private const FIELDS = [
        'schema_version',
        'operation',
        'fingerprint',
        'package_path',
        'package_digest',
        'manifest_digest',
        'signature_digest',
        'platform',
        'arch',
        'profile',
        'host_id',
        'phase',
        'slot',
        'runtime_generation',
        'previous_active_slot',
        'service_kind',
        'created_at',
        'updated_at',
        'record_digest',
    ];

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
    ) {
    }

    /** @return array<string,mixed>|null */
    public function load(): ?array
    {
        $raw = GatewayProjectStateFilesystem::readOptional(
            $this->paths->initialBootstrapJournalFile(),
            self::MAX_BYTES,
            'Gateway initial bootstrap journal',
        );
        if ($raw === null) {
            return null;
        }
        $journal = \json_decode($raw, true);
        if (!\is_array($journal)) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal is invalid JSON.',
            );
        }
        $this->assertValid($journal);
        return $journal;
    }

    /**
     * @param array<string,mixed> $verification
     * @return array<string,mixed>
     */
    public function beginOrResume(array $verification, string $profile): array
    {
        $identity = $this->identity($verification, $profile);
        $existing = $this->load();
        if ($existing !== null) {
            $expectedFingerprint = \hash_equals(
                'PREPARING',
                (string)$existing['phase'],
            )
                ? (string)$identity['fingerprint']
                : self::hostFingerprint($identity);
            if (!\hash_equals(
                $expectedFingerprint,
                (string)$existing['fingerprint'],
            )) {
                throw new \RuntimeException(
                    'REPAIR_REQUIRED: an incomplete Gateway bootstrap belongs to a different signed package fingerprint.',
                );
            }
            return $existing;
        }
        $now = \time();
        $journal = $identity + [
            'schema_version' => self::SCHEMA_VERSION,
            'operation' => 'initial-bootstrap',
            'phase' => 'PREPARING',
            'slot' => '',
            'runtime_generation' => '',
            'previous_active_slot' => '',
            'service_kind' => '',
            'host_id' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return $this->write($journal);
    }

    /**
     * @param array<string,mixed> $journal
     * @param array<string,string> $facts
     * @return array<string,mixed>
     */
    public function advance(
        array $journal,
        string $phase,
        array $facts = [],
    ): array {
        $this->assertValid($journal);
        $phase = \strtoupper(\trim($phase));
        if (!isset(self::PHASES[$phase])
            || self::PHASES[$phase] < self::PHASES[(string)$journal['phase']]
        ) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal phase transition is invalid.',
            );
        }
        foreach ($facts as $field => $value) {
            if (!\in_array($field, [
                'slot',
                'runtime_generation',
                'previous_active_slot',
                'service_kind',
                'host_id',
            ], true)) {
                throw new \RuntimeException(
                    'Gateway initial bootstrap journal fact is unsupported.',
                );
            }
            $journal[$field] = $value;
        }
        if (self::PHASES[$phase] >= self::PHASES['STAGED']) {
            // Once immutable bytes have reached the host slot, recovery is
            // bound only to signed host content and never to the bootstrap
            // project's location or lifetime.
            $journal['package_path'] = '';
            $journal['fingerprint'] = self::hostFingerprint($journal);
        }
        $journal['phase'] = $phase;
        $journal['updated_at'] = \time();
        return $this->write($journal);
    }

    /** @return array<string,mixed> */
    public function resumeHostStaged(string $profile): array
    {
        $journal = $this->load();
        $profile = \strtolower(\trim($profile));
        if ($journal === null
            || !\in_array($profile, self::PROFILES, true)
            || \hash_equals('PREPARING', (string)($journal['phase'] ?? ''))
            || !\hash_equals($profile, (string)($journal['profile'] ?? ''))
            || !\hash_equals(
                (string)$journal['fingerprint'],
                self::hostFingerprint($journal),
            )
        ) {
            throw new \RuntimeException(
                'REPAIR_REQUIRED: the host-staged Gateway bootstrap journal cannot be resumed.',
            );
        }
        return $journal;
    }

    public function remove(array $expected): void
    {
        $this->assertValid($expected);
        $current = $this->load();
        if ($current === null
            || !\hash_equals(
                (string)$expected['record_digest'],
                (string)$current['record_digest'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal changed before removal.',
            );
        }
        if (!GatewayProjectStateFilesystem::removeRegular(
            $this->paths->initialBootstrapJournalFile(),
            'Gateway initial bootstrap journal',
        )) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal could not be removed.',
            );
        }
    }

    /** @param array<string,mixed> $verification @return array<string,string> */
    private function identity(array $verification, string $profile): array
    {
        $path = (string)($verification['package_dir'] ?? '');
        $path = \rtrim(\str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
        }
        $manifest = \is_array($verification['manifest'] ?? null)
            ? $verification['manifest']
            : [];
        $identity = [
            'package_path' => $path,
            'package_digest' => self::digest($verification, 'package_digest'),
            'manifest_digest' => self::digest($verification, 'manifest_digest'),
            'signature_digest' => self::digest($verification, 'signature_digest'),
            'platform' => (string)($manifest['platform'] ?? ''),
            'arch' => \strtolower(\trim((string)($manifest['arch'] ?? ''))),
            'profile' => \strtolower(\trim($profile)),
        ];
        if ($path === ''
            || $identity['platform'] === ''
            || $identity['arch'] === ''
            || !\in_array($identity['profile'], self::PROFILES, true)
        ) {
            throw new \RuntimeException(
                'Gateway initial bootstrap package identity is incomplete.',
            );
        }
        return ['fingerprint' => self::sourceFingerprint($identity)] + $identity;
    }

    /** @param array<string,mixed> $verification */
    private static function digest(array $verification, string $field): string
    {
        $digest = \strtolower(\trim((string)($verification[$field] ?? '')));
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new \RuntimeException(
                'Gateway initial bootstrap ' . $field . ' is invalid.',
            );
        }
        return $digest;
    }

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    private function write(array $journal): array
    {
        unset($journal['record_digest']);
        $keys = \array_keys($journal);
        \sort($keys, SORT_STRING);
        $expected = self::FIELDS;
        $expected = \array_values(\array_filter(
            $expected,
            static fn (string $field): bool => $field !== 'record_digest',
        ));
        \sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal fields are incomplete.',
            );
        }
        $journal['record_digest'] = self::recordDigest($journal);
        $encoded = \json_encode(
            $journal,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (\strlen($encoded) > self::MAX_BYTES) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal exceeds its fixed limit.',
            );
        }
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->initialBootstrapJournalFile(),
            $encoded,
            0600,
        );
        return $journal;
    }

    /** @param array<string,mixed> $journal */
    private function assertValid(array $journal): void
    {
        $keys = \array_keys($journal);
        \sort($keys, SORT_STRING);
        $expected = self::FIELDS;
        \sort($expected, SORT_STRING);
        $phase = (string)($journal['phase'] ?? '');
        $recordDigest = (string)($journal['record_digest'] ?? '');
        $unsigned = $journal;
        unset($unsigned['record_digest']);
        if ($keys !== $expected
            || ($journal['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !\hash_equals('initial-bootstrap', (string)($journal['operation'] ?? ''))
            || !isset(self::PHASES[$phase])
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)($journal['fingerprint'] ?? '')) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $recordDigest) !== 1
            || !\hash_equals(self::recordDigest($unsigned), $recordDigest)
            || !\is_int($journal['created_at'] ?? null)
            || !\is_int($journal['updated_at'] ?? null)
            || !\in_array(
                (string)($journal['profile'] ?? ''),
                self::PROFILES,
                true,
            )
        ) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal contract or digest is invalid.',
            );
        }
        $expectedFingerprint = \hash_equals('PREPARING', $phase)
            ? self::sourceFingerprint($journal)
            : self::hostFingerprint($journal);
        if ((\hash_equals('PREPARING', $phase)
                && \trim((string)($journal['package_path'] ?? '')) === '')
            || (!\hash_equals('PREPARING', $phase)
                && (string)($journal['package_path'] ?? '') !== '')
            || !\hash_equals(
                $expectedFingerprint,
                (string)$journal['fingerprint'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal source/host authority binding is invalid.',
            );
        }
        foreach (['package_digest', 'manifest_digest', 'signature_digest'] as $field) {
            self::digest($journal, $field);
        }
        if (!\in_array((string)($journal['slot'] ?? ''), ['', 'A', 'B'], true)
            || !\in_array((string)($journal['previous_active_slot'] ?? ''), ['', 'A', 'B'], true)
            || ((string)($journal['host_id'] ?? '') !== ''
                && \preg_match('/\A[a-f0-9]{32}\z/D', (string)$journal['host_id']) !== 1)
        ) {
            throw new \RuntimeException(
                'Gateway initial bootstrap journal slot binding is invalid.',
            );
        }
    }

    /** @param array<string,mixed> $unsigned */
    private static function recordDigest(array $unsigned): string
    {
        \ksort($unsigned, SORT_STRING);
        return \hash('sha256', \json_encode(
            $unsigned,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string,mixed> $identity */
    private static function hostFingerprint(array $identity): string
    {
        $host = [];
        foreach ([
            'package_digest',
            'manifest_digest',
            'signature_digest',
            'platform',
            'arch',
            'profile',
            'host_id',
        ] as $field) {
            $host[$field] = (string)($identity[$field] ?? '');
        }
        return \hash('sha256', \json_encode(
            $host,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string,mixed> $identity */
    private static function sourceFingerprint(array $identity): string
    {
        $source = ['package_path' => (string)($identity['package_path'] ?? '')];
        foreach ([
            'package_digest',
            'manifest_digest',
            'signature_digest',
            'platform',
            'arch',
            'profile',
        ] as $field) {
            $source[$field] = (string)($identity[$field] ?? '');
        }
        return \hash('sha256', \json_encode(
            $source,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
