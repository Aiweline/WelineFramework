<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Forward-only, crash-recoverable migration from the schema-1 Linux unit
 * layout (a regular file in systemd's search path) to the dedicated mutable
 * target plus fixed canonical symlink layout.
 *
 * The journal is intentionally independent of the ordinary platform
 * definition transaction: replacing a regular canonical unit with a symlink
 * changes the target type and needs its own exact before/after proof.
 */
final class GatewayLinuxSystemdLayoutMigration
{
    private const JOURNAL_SCHEMA = 1;
    private const MAX_DEFINITION_BYTES = 1_048_576;
    private const MAX_METADATA_BYTES = 16_384;
    private const MAX_JOURNAL_BYTES = 3_000_000;

    public function __construct(
        private readonly GatewayPaths $paths,
    ) {
    }

    /**
     * @param \Closure():void $daemonReload
     */
    public function migrate(
        string $profile,
        string $legacyDefinition,
        string $legacyMetadata,
        string $newDefinition,
        string $newMetadata,
        \Closure $daemonReload,
    ): void {
        $candidate = $this->newJournal(
            $profile,
            $legacyDefinition,
            $legacyMetadata,
            $newDefinition,
            $newMetadata,
        );
        $active = $this->readJournal();
        if ($active === null) {
            $this->writeJournal($candidate);
            $active = $candidate;
        } elseif (!$this->sameJournalImage($active, $candidate)) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration belongs to another definition generation.',
            );
        }
        $this->recover($active, $daemonReload);
    }

    /**
     * @param \Closure():void $daemonReload
     */
    public function recoverPending(\Closure $daemonReload): bool
    {
        $active = $this->readJournal();
        if ($active === null) {
            return false;
        }
        $this->recover($active, $daemonReload);
        return true;
    }

    /**
     * @param array<string,mixed> $journal
     * @param \Closure():void $daemonReload
     */
    private function recover(array $journal, \Closure $daemonReload): void
    {
        $phase = (string)$journal['phase'];
        if ($phase === 'prepared') {
            $this->layout()->ensureLegacyTargetPublished(
                (string)$journal['old_definition'],
                (string)$journal['new_definition'],
            );
            $journal = $this->advance($journal, 'target_published');
            $phase = 'target_published';
        }
        if ($phase === 'target_published') {
            $this->layout()->ensureLegacyFixedLink(
                (string)$journal['old_definition'],
                (string)$journal['new_definition'],
            );
            $journal = $this->advance($journal, 'link_published');
            $phase = 'link_published';
        }
        if ($phase === 'link_published') {
            $this->ensureMetadata(
                (string)$journal['old_metadata'],
                (string)$journal['new_metadata'],
            );
            $journal = $this->advance($journal, 'metadata_published');
            $phase = 'metadata_published';
        }
        if ($phase !== 'metadata_published') {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration phase is invalid during recovery.',
            );
        }
        $this->layout()->assertCurrentDefinitionAndFixedLink(
            (string)$journal['new_definition'],
        );
        $this->assertExactMetadata(
            (string)$journal['new_metadata'],
            'WLS Gateway migrated platform metadata',
        );
        $daemonReload();
        $this->removeJournal($journal);
    }

    /** @return array<string,mixed> */
    private function newJournal(
        string $profile,
        string $oldDefinition,
        string $oldMetadata,
        string $newDefinition,
        string $newMetadata,
    ): array {
        if (!\in_array($profile, ['default', 'ipv4-only'], true)
            || $oldDefinition === ''
            || $newDefinition === ''
            || $oldMetadata === ''
            || $newMetadata === ''
            || \strlen($oldDefinition) > self::MAX_DEFINITION_BYTES
            || \strlen($newDefinition) > self::MAX_DEFINITION_BYTES
            || \strlen($oldMetadata) > self::MAX_METADATA_BYTES
            || \strlen($newMetadata) > self::MAX_METADATA_BYTES
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration image is invalid.',
            );
        }
        $journal = [
            'schema_version' => self::JOURNAL_SCHEMA,
            'phase' => 'prepared',
            'nonce' => \bin2hex(\random_bytes(16)),
            'profile' => $profile,
            'old_definition_sha256' => \hash('sha256', $oldDefinition),
            'new_definition_sha256' => \hash('sha256', $newDefinition),
            'old_metadata_sha256' => \hash('sha256', $oldMetadata),
            'new_metadata_sha256' => \hash('sha256', $newMetadata),
            'old_definition_base64' => \base64_encode($oldDefinition),
            'new_definition_base64' => \base64_encode($newDefinition),
            'old_metadata_base64' => \base64_encode($oldMetadata),
            'new_metadata_base64' => \base64_encode($newMetadata),
        ];
        return $this->decodeJournal($this->encodeJournal($journal));
    }

    /** @param array<string,mixed> $journal */
    private function encodeJournal(array $journal): string
    {
        return \json_encode([
            'schema_version' => $journal['schema_version'] ?? null,
            'phase' => $journal['phase'] ?? null,
            'nonce' => $journal['nonce'] ?? null,
            'profile' => $journal['profile'] ?? null,
            'old_definition_sha256' => $journal['old_definition_sha256'] ?? null,
            'new_definition_sha256' => $journal['new_definition_sha256'] ?? null,
            'old_metadata_sha256' => $journal['old_metadata_sha256'] ?? null,
            'new_metadata_sha256' => $journal['new_metadata_sha256'] ?? null,
            'old_definition_base64' => $journal['old_definition_base64'] ?? null,
            'new_definition_base64' => $journal['new_definition_base64'] ?? null,
            'old_metadata_base64' => $journal['old_metadata_base64'] ?? null,
            'new_metadata_base64' => $journal['new_metadata_base64'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string,mixed> */
    private function decodeJournal(string $raw): array
    {
        $decoded = \json_decode($raw, true);
        $expectedKeys = [
            'schema_version',
            'phase',
            'nonce',
            'profile',
            'old_definition_sha256',
            'new_definition_sha256',
            'old_metadata_sha256',
            'new_metadata_sha256',
            'old_definition_base64',
            'new_definition_base64',
            'old_metadata_base64',
            'new_metadata_base64',
        ];
        $actualKeys = \is_array($decoded) ? \array_keys($decoded) : [];
        \sort($expectedKeys, SORT_STRING);
        \sort($actualKeys, SORT_STRING);
        if (!\is_array($decoded)
            || $actualKeys !== $expectedKeys
            || ($decoded['schema_version'] ?? null) !== self::JOURNAL_SCHEMA
            || !\in_array((string)($decoded['phase'] ?? ''), [
                'prepared',
                'target_published',
                'link_published',
                'metadata_published',
            ], true)
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($decoded['nonce'] ?? '')) !== 1
            || !\in_array((string)($decoded['profile'] ?? ''), ['default', 'ipv4-only'], true)
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration journal is malformed.',
            );
        }
        foreach ([
            'old_definition_sha256',
            'new_definition_sha256',
            'old_metadata_sha256',
            'new_metadata_sha256',
        ] as $key) {
            if (\preg_match('/\A[a-f0-9]{64}\z/D', (string)($decoded[$key] ?? '')) !== 1) {
                throw new \RuntimeException(
                    'WLS Gateway systemd layout migration journal digest is invalid.',
                );
            }
        }
        foreach ([
            'old_definition' => self::MAX_DEFINITION_BYTES,
            'new_definition' => self::MAX_DEFINITION_BYTES,
            'old_metadata' => self::MAX_METADATA_BYTES,
            'new_metadata' => self::MAX_METADATA_BYTES,
        ] as $name => $maximum) {
            $value = \base64_decode((string)($decoded[$name . '_base64'] ?? ''), true);
            if (!\is_string($value)
                || $value === ''
                || \strlen($value) > $maximum
                || !\hash_equals(
                    (string)$decoded[$name . '_sha256'],
                    \hash('sha256', $value),
                )
            ) {
                throw new \RuntimeException(
                    'WLS Gateway systemd layout migration journal image is invalid.',
                );
            }
            $decoded[$name] = $value;
        }
        return $decoded;
    }

    /** @return array<string,mixed>|null */
    private function readJournal(): ?array
    {
        $path = $this->paths->systemdLayoutMigrationTransactionFile();
        $this->reconcileJournalArtifacts();
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'WLS Gateway systemd layout migration journal path is indeterminate.',
                );
            }
            return null;
        }
        $this->assertJournalStatus($status);
        return $this->decodeJournal(GatewayProjectStateFilesystem::read(
            $path,
            self::MAX_JOURNAL_BYTES,
            'WLS Gateway systemd layout migration journal',
        ));
    }

    /** @param array<string,mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $path = $this->paths->systemdLayoutMigrationTransactionFile();
        $this->reconcileJournalArtifacts();
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            $this->encodeJournal($journal),
            0600,
        );
        $current = $this->readJournal();
        if (!\is_array($current)
            || !\hash_equals((string)$journal['nonce'], (string)$current['nonce'])
            || !\hash_equals((string)$journal['phase'], (string)$current['phase'])
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration journal did not persist.',
            );
        }
    }

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    private function advance(array $journal, string $phase): array
    {
        $current = $this->readJournal();
        if (!\is_array($current)
            || !\hash_equals((string)$journal['nonce'], (string)$current['nonce'])
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration journal identity changed.',
            );
        }
        $current['phase'] = $phase;
        $this->writeJournal($current);
        return $current;
    }

    /** @param array<string,mixed> $journal */
    private function removeJournal(array $journal): void
    {
        $current = $this->readJournal();
        if (!\is_array($current)
            || !\hash_equals((string)$journal['nonce'], (string)$current['nonce'])
            || !\hash_equals('metadata_published', (string)$current['phase'])
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration journal cannot be removed.',
            );
        }
        $path = $this->paths->systemdLayoutMigrationTransactionFile();
        $status = @\lstat($path);
        if (!\is_array($status)
            || !GatewayProjectStateFilesystem::removeRegular(
                $path,
                'completed WLS Gateway systemd layout migration journal',
                $status,
            )
        ) {
            throw new \RuntimeException(
                'Unable to remove the completed WLS Gateway systemd layout migration journal.',
            );
        }
    }

    private function reconcileJournalArtifacts(): void
    {
        $path = $this->paths->systemdLayoutMigrationTransactionFile();
        $status = @\lstat($path);
        if (\is_array($status)) {
            $this->assertJournalStatus($status);
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $path,
                self::MAX_JOURNAL_BYTES,
                'WLS Gateway systemd layout migration journal',
                fn (string $raw): array => $this->decodeJournal($raw),
            );
            return;
        }
        if (\file_exists($path) || \is_link($path)) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration journal path is indeterminate.',
            );
        }
        GatewayProjectStateFilesystem::discardUnpairedFirstPublicationStaging(
            $path,
            self::MAX_JOURNAL_BYTES,
            'WLS Gateway systemd layout migration journal',
        );
    }

    private function ensureMetadata(string $oldMetadata, string $newMetadata): void
    {
        $path = $this->paths->platformServiceMetadataFile();
        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $path,
            self::MAX_METADATA_BYTES,
            'WLS Gateway platform metadata during systemd layout migration',
            function (string $raw) use ($oldMetadata, $newMetadata): void {
                if (!\hash_equals($oldMetadata, $raw)
                    && !\hash_equals($newMetadata, $raw)
                ) {
                    throw new \RuntimeException(
                        'WLS Gateway platform metadata recovery image is foreign.',
                    );
                }
            },
        );
        $current = $this->readExactMetadata(
            'WLS Gateway platform metadata during systemd layout migration',
        );
        if (\hash_equals($newMetadata, $current)) {
            return;
        }
        if (!\hash_equals($oldMetadata, $current)) {
            throw new \RuntimeException(
                'WLS Gateway platform metadata does not match the legacy migration before-image.',
            );
        }
        GatewayProjectStateFilesystem::atomicWrite($path, $newMetadata, 0600);
        $this->assertExactMetadata(
            $newMetadata,
            'WLS Gateway migrated platform metadata',
        );
    }

    private function assertExactMetadata(string $expected, string $label): void
    {
        $actual = $this->readExactMetadata($label);
        if (!\hash_equals($expected, $actual)) {
            throw new \RuntimeException($label . ' does not match its expected image.');
        }
    }

    private function readExactMetadata(string $label): string
    {
        $path = $this->paths->platformServiceMetadataFile();
        $status = @\lstat($path);
        if (!\is_array($status)
            || \is_link($path)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)($status['uid'] ?? -1) !== $this->expectedUid()
            || (int)($status['gid'] ?? -1) !== $this->expectedGid()
            || !\in_array(((int)($status['mode'] ?? 0)) & 0777, [0600, 0440], true)
        ) {
            throw new \RuntimeException($label . ' is not an exact regular metadata file.');
        }
        return GatewayProjectStateFilesystem::read(
            $path,
            self::MAX_METADATA_BYTES,
            $label,
        );
    }

    /** @param array<string|int,mixed> $status */
    private function assertJournalStatus(array $status): void
    {
        if ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($status['nlink'] ?? 0) !== 1
            || (int)($status['uid'] ?? -1) !== $this->expectedUid()
            || (int)($status['gid'] ?? -1) !== $this->expectedGid()
            || (((int)($status['mode'] ?? 0)) & 0777) !== 0600
        ) {
            throw new \RuntimeException(
                'WLS Gateway systemd layout migration journal authority is unsafe.',
            );
        }
    }

    private function expectedUid(): int
    {
        if (!$this->paths->isTestMode()) {
            return 0;
        }
        $home = @\lstat($this->paths->home());
        if (!\is_array($home)) {
            throw new \RuntimeException('WLS Gateway test home authority is unavailable.');
        }
        return (int)($home['uid'] ?? -1);
    }

    private function expectedGid(): int
    {
        if (!$this->paths->isTestMode()) {
            return 0;
        }
        $home = @\lstat($this->paths->home());
        if (!\is_array($home)) {
            throw new \RuntimeException('WLS Gateway test home authority is unavailable.');
        }
        return (int)($home['gid'] ?? -1);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameJournalImage(array $left, array $right): bool
    {
        foreach ([
            'profile',
            'old_definition_sha256',
            'new_definition_sha256',
            'old_metadata_sha256',
            'new_metadata_sha256',
            'old_definition_base64',
            'new_definition_base64',
            'old_metadata_base64',
            'new_metadata_base64',
        ] as $field) {
            if (!\hash_equals((string)$left[$field], (string)$right[$field])) {
                return false;
            }
        }
        return true;
    }

    private function layout(): GatewayLinuxSystemdLayout
    {
        // The default property construction cannot receive this instance's
        // test-mode path object, so construct the path-bound helper lazily.
        return new GatewayLinuxSystemdLayout($this->paths);
    }
}
