<?php

declare(strict_types=1);

namespace Weline\Server\Session\Server;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Atomic fixed-path capability token used by the shared Session/Memory sidecars.
 *
 * The listen-port owner is the only authority allowed to publish a generation.
 * Every writer and compare-and-remove operation shares one persistent lock;
 * readers do not need that lock because the target is replaced atomically.
 */
final class SharedStateTokenStore
{
    private const ENVELOPE_SCHEMA = 'wls-shared-state-token/2';

    private const LEDGER_SCHEMA = 'wls-shared-state-token-ledger/1';

    private const MAX_TOKEN_BYTES = 8192;

    private const MAX_LEDGER_BYTES = 16_384;

    private const POSIX_FILE_MODE = 0600;

    private const MAX_RECOVERY_DIRECTORY_ENTRIES = 16_384;

    private const MAX_RECOVERY_ARTIFACTS_PER_KIND = 8;

    /** @var array{role:string,host:string,port:int,instance:string,token_path_sha256:string}|null */
    private readonly ?array $authority;

    public function __construct(
        private readonly string $targetPath,
        private readonly float $lockTimeoutSeconds = 0.25,
        ?array $authority = null,
    ) {
        if (!\is_finite($this->lockTimeoutSeconds)
            || $this->lockTimeoutSeconds <= 0.0
            || $this->lockTimeoutSeconds > 300.0
        ) {
            throw new \InvalidArgumentException(
                'Shared-state token lock timeout must be within (0, 300] seconds.'
            );
        }
        $leaf = \basename(\str_replace('\\', '/', $this->targetPath));
        if ($leaf === ''
            || $leaf === '.'
            || $leaf === '..'
            || !\str_ends_with(\strtolower($leaf), '.token')
            || \strlen($leaf) > 192
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\.token\z/D', $leaf) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid shared-state token target path.');
        }
        $this->authority = $authority !== null
            ? self::normalizeAuthority($authority, $this->targetPath)
            : null;
    }

    public function publicationLockPath(): string
    {
        return $this->targetPath . '.publication.lock';
    }

    public function generationLedgerPath(): string
    {
        return $this->targetPath . '.generation.json';
    }

    /** @return array{secret:string,version:int} */
    public function publish(string $secret, int $version): array
    {
        $authority = $this->requireAuthority();
        self::assertSecret($secret);
        if ($version < 1) {
            throw new \InvalidArgumentException(
                'Shared-state token generation must be positive.'
            );
        }

        return $this->withPublicationLock(function (?array $ledger) use (
            $secret,
            $version,
            $authority,
        ): array {
            $this->recoverRetainedArtifactsLocked(true, $ledger);
            $target = self::readStatePath($this->targetPath, null);
            $current = $this->resolveCurrentFence($target, $ledger);
            $candidate = self::activeEnvelope($secret, $version, $authority);
            $this->assertPublishTransition($current, $candidate);
            $this->publishEnvelopeLocked($candidate, $target);

            return ['secret' => $secret, 'version' => $version];
        });
    }

    /** @return array{secret:string,version:int} */
    public function publishNext(string $secret): array
    {
        $authority = $this->requireAuthority();
        self::assertSecret($secret);

        return $this->withPublicationLock(function (?array $ledger) use (
            $secret,
            $authority,
        ): array {
            $this->recoverRetainedArtifactsLocked(true, $ledger);
            $target = self::readStatePath($this->targetPath, null);
            $current = $this->resolveCurrentFence($target, $ledger);
            if (($current['active'] ?? false)
                && !self::sameAuthority($current['authority'], $authority)
            ) {
                throw new \RuntimeException(
                    'Active shared-state capability path belongs to a different endpoint authority.'
                );
            }
            $currentGeneration = (int)($current['generation'] ?? 0);
            if ($currentGeneration >= PHP_INT_MAX) {
                throw new \RuntimeException(
                    'Shared-state token generation is exhausted.'
                );
            }
            $generation = $currentGeneration + 1;
            $candidate = self::activeEnvelope($secret, $generation, $authority);
            $this->publishEnvelopeLocked($candidate, $target);

            return ['secret' => $secret, 'version' => $generation];
        });
    }

    /** @return array{secret:string,version:int}|null */
    public function read(): ?array
    {
        return self::readPath($this->targetPath, $this->authority);
    }

    /** @return array{secret:string,version:int}|null */
    public static function readPath(string $path, ?array $expectedAuthority = null): ?array
    {
        $state = self::readCapabilityStatePath($path, $expectedAuthority);
        if ($state === null || !$state['active']) {
            return null;
        }

        return [
            'secret' => $state['secret'],
            'version' => $state['version'],
        ];
    }

    /**
     * @return array{active:bool,secret:string,version:int,digest:string,authority:array}|null
     */
    public static function readCapabilityStatePath(
        string $path,
        ?array $expectedAuthority = null,
    ): ?array {
        $normalizedExpected = $expectedAuthority !== null
            ? self::normalizeAuthority($expectedAuthority, $path)
            : null;
        $state = self::readStatePath($path, $normalizedExpected);
        if ($state === null) {
            return null;
        }

        return [
            'active' => $state['active'],
            'secret' => $state['secret'],
            'version' => $state['generation'],
            'digest' => $state['digest'],
            'authority' => $state['authority'],
        ];
    }

    /**
     * @param array{role:string,host:string,port:int,instance:string,token_path_sha256:string}|null $expectedAuthority
     * @return array{active:bool,secret:string,generation:int,digest:string,authority:array}|null
     */
    private static function readStatePath(string $path, ?array $expectedAuthority): ?array
    {
        $before = @\lstat($path);
        if (!\is_array($before)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'Shared-state authentication token path is indeterminate or unsafe.'
                );
            }
            return null;
        }
        $contents = GatewayProjectStateFilesystem::readOptional(
            $path,
            self::MAX_TOKEN_BYTES,
            'shared-state authentication token',
        );
        if ($contents === null) {
            return null;
        }
        $after = @\lstat($path);
        if (!\is_array($after)
            || !self::sameFileState($before, $after)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$after['mode']) & 0777) !== self::POSIX_FILE_MODE))
        ) {
            throw new \RuntimeException(
                'Shared-state authentication token permissions or identity are unsafe.'
            );
        }

        return self::decodeEnvelope($contents, $path, $expectedAuthority);
    }

    public function removeIfMatches(string $secret, ?int $version = null): bool
    {
        self::assertSecret($secret);
        $authority = $this->requireAuthority();

        return $this->withPublicationLock(function (?array $ledger) use (
            $secret,
            $version,
            $authority,
        ): bool {
            $this->recoverRetainedArtifactsLocked(false, $ledger);
            $target = self::readStatePath($this->targetPath, null);
            $current = $this->resolveCurrentFence($target, $ledger);
            if ($target === null
                || !$target['active']
                || $current === null
                || $target['generation'] !== $current['generation']
                || !\hash_equals($target['digest'], $current['digest'])
                || !self::sameAuthority($target['authority'], $authority)
            ) {
                return false;
            }
            if (!\hash_equals($secret, $target['secret'])
                || ($version !== null && $version !== $target['generation'])
            ) {
                return false;
            }
            if ($target['generation'] >= PHP_INT_MAX) {
                throw new \RuntimeException(
                    'Shared-state token generation is exhausted.'
                );
            }
            $nextGeneration = $target['generation'] + 1;
            $tombstone = self::inactiveEnvelope($nextGeneration, $authority);
            $this->publishEnvelopeLocked($tombstone, $target);

            return true;
        });
    }

    /** @param array{active:bool,generation:int,digest:string,authority:array}|null $ledger */
    private function recoverRetainedArtifactsLocked(
        bool $publisherOwnsGeneration,
        ?array $ledger,
    ): void
    {
        if (!GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
            $this->targetPath,
            self::MAX_TOKEN_BYTES,
            'shared-state authentication token',
        )) {
            return;
        }
        $selected = $this->recoverySnapshot();
        $rechecked = $this->recoverySnapshot();
        $this->assertSameRecoverySnapshot($selected, $rechecked);
        foreach ($rechecked['artifacts'] as $artifact) {
            if ($artifact['kind'] === 'backup' && !$artifact['valid']) {
                throw new \RuntimeException(
                    'Shared-state token recovery contains an invalid committed backup.'
                );
            }
        }

        if (!$rechecked['target_valid']) {
            $backups = \array_values(\array_filter(
                $rechecked['artifacts'],
                static fn (array $artifact): bool => $artifact['kind'] === 'backup',
            ));
            if (\count($backups) === 1 && $backups[0]['valid'] === true) {
                $backup = $backups[0];
                $backupState = \is_array($backup['state'] ?? null)
                    ? $backup['state']
                    : null;
                if ($backupState !== null
                    && $ledger !== null
                    && $backupState['generation'] === $ledger['generation']
                    && ($backupState['active'] !== $ledger['active']
                        || !\hash_equals($backupState['digest'], $ledger['digest'])
                        || !self::sameAuthority(
                            $backupState['authority'],
                            $ledger['authority'],
                        ))
                ) {
                    throw new \RuntimeException(
                        'Shared-state token recovery backup forks the durable generation fence.'
                    );
                }
                if ($backupState !== null
                    && $ledger !== null
                    && $backupState['generation'] < $ledger['generation']
                ) {
                    if (!$ledger['active']) {
                        $this->reconstructInactiveFenceLocked(
                            $ledger,
                            $rechecked['artifacts'],
                            $rechecked['target_identity'],
                        );
                        return;
                    }
                    if ($publisherOwnsGeneration) {
                        $this->retireSupersededRecoveryNamespaceLocked(
                            $rechecked['artifacts'],
                            $rechecked['target_identity'],
                        );
                        return;
                    }
                    throw new \RuntimeException(
                        'Shared-state token recovery backup predates the durable active generation fence.'
                    );
                }
                GatewayProjectStateFilesystem::restoreVerifiedAtomicBackup(
                    $backup['path'],
                    $this->targetPath,
                    $backup['identity'],
                    $rechecked['target_identity'],
                    $backup['digest'],
                    $backup['size'],
                    self::POSIX_FILE_MODE,
                );
            } elseif ($backups !== []) {
                throw new \RuntimeException(
                    'Shared-state token recovery has no unique valid committed backup.'
                );
            } elseif ($publisherOwnsGeneration
                && $rechecked['target_identity'] === null
            ) {
                // A staging leaf is not committed state. Once the bound
                // listen owner has a fresh in-memory generation it may retire
                // that first-publication residue and publish its own token.
                foreach ($rechecked['artifacts'] as $artifact) {
                    if (!GatewayProjectStateFilesystem::removeRegular(
                        $artifact['path'],
                        'uncommitted shared-state token staging file',
                        $artifact['identity'],
                    )) {
                        throw new \RuntimeException(
                            'Unable to retire an uncommitted shared-state token staging file.'
                        );
                    }
                }
                return;
            } else {
                throw new \RuntimeException(
                    'Shared-state token recovery target is unavailable without a committed backup.'
                );
            }
        }

        GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
            $this->targetPath,
            self::MAX_TOKEN_BYTES,
            'shared-state authentication token',
            function (string $contents): void {
                self::decodeEnvelope($contents, $this->targetPath, null);
            },
        );
    }

    /**
     * @return array{
     *   target_identity:array<string|int,mixed>|null,
     *   target_valid:bool,
     *   artifacts:array<string,array{
     *     path:string,kind:string,identity:array<string|int,mixed>,
     *     valid:bool,digest:string,size:int,state:?array
     *   }>
     * }
     */
    private function recoverySnapshot(): array
    {
        $directory = \dirname($this->targetPath);
        $targetLeaf = \basename(\str_replace('\\', '/', $this->targetPath));
        $backupPrefix = $targetLeaf . '.wls-backup-';
        $stagingPrefix = $targetLeaf . '.tmp-';
        $backupPattern = '/\A' . \preg_quote($backupPrefix, '/') . '[a-f0-9]{16}\z/Du';
        $stagingPattern = '/\A' . \preg_quote($stagingPrefix, '/') . '[a-f0-9]{24}\z/Du';
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the shared-state token recovery directory.'
            );
        }
        $artifacts = [];
        $counts = ['backup' => 0, 'staging' => 0];
        $visited = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if (++$visited > self::MAX_RECOVERY_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'Shared-state token recovery directory exceeds its fixed entry quota.'
                    );
                }
                $folded = \strtolower($leaf);
                $isBackup = \str_starts_with($folded, \strtolower($backupPrefix));
                $isStaging = \str_starts_with($folded, \strtolower($stagingPrefix));
                if (!$isBackup && !$isStaging) {
                    continue;
                }
                if (($isBackup && !\str_starts_with($leaf, $backupPrefix))
                    || ($isStaging && !\str_starts_with($leaf, $stagingPrefix))
                ) {
                    throw new \RuntimeException(
                        'Shared-state token recovery contains a non-canonical case alias.'
                    );
                }
                $kind = $isBackup && \preg_match($backupPattern, $leaf) === 1
                    ? 'backup'
                    : ($isStaging && \preg_match($stagingPattern, $leaf) === 1
                        ? 'staging'
                        : '');
                if ($kind === '') {
                    throw new \RuntimeException(
                        'Shared-state token recovery contains a malformed reserved leaf.'
                    );
                }
                ++$counts[$kind];
                if ($counts[$kind] > self::MAX_RECOVERY_ARTIFACTS_PER_KIND) {
                    throw new \RuntimeException(
                        'Shared-state token recovery artifact quota is exhausted.'
                    );
                }
                $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                $before = @\lstat($path);
                if (!\is_array($before)) {
                    throw new \RuntimeException(
                        'Shared-state token recovery artifact is indeterminate.'
                    );
                }
                $contents = GatewayProjectStateFilesystem::read(
                    $path,
                    self::MAX_TOKEN_BYTES,
                    'shared-state token recovery artifact',
                );
                $after = @\lstat($path);
                if (!\is_array($after) || !self::sameFileState($before, $after)) {
                    throw new \RuntimeException(
                        'Shared-state token recovery artifact changed during inspection.'
                    );
                }
                if (\PHP_OS_FAMILY !== 'Windows'
                    && ((((int)$after['mode']) & 0777) !== self::POSIX_FILE_MODE)
                ) {
                    throw new \RuntimeException(
                        'Shared-state token recovery artifact permissions are unsafe.'
                    );
                }
                $valid = false;
                $state = null;
                if ($kind === 'backup') {
                    try {
                        $state = self::decodeEnvelope(
                            $contents,
                            $this->targetPath,
                            null,
                        );
                        $valid = true;
                    } catch (\Throwable) {
                        $valid = false;
                    }
                }
                $artifacts[$path] = [
                    'path' => $path,
                    'kind' => $kind,
                    'identity' => $after,
                    'valid' => $valid,
                    'digest' => \hash('sha256', $contents),
                    'size' => \strlen($contents),
                    'state' => $state,
                ];
            }
        } finally {
            @\closedir($handle);
        }
        \ksort($artifacts, SORT_STRING);

        $targetIdentity = @\lstat($this->targetPath);
        $targetValid = false;
        if (\is_array($targetIdentity)) {
            try {
                $targetValid = self::readStatePath($this->targetPath, null) !== null;
            } catch (\Throwable) {
                $targetValid = false;
            }
        } elseif (\file_exists($this->targetPath) || \is_link($this->targetPath)) {
            throw new \RuntimeException(
                'Shared-state token recovery target is indeterminate or unsafe.'
            );
        }

        return [
            'target_identity' => \is_array($targetIdentity) ? $targetIdentity : null,
            'target_valid' => $targetValid,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @param array{active:false,generation:int,digest:string,authority:array}|array{active:bool,generation:int,digest:string,authority:array} $ledger
     * @param array<string,array{path:string,kind:string,identity:array,valid:bool,digest:string,size:int,state:?array}> $artifacts
     * @param array<string|int,mixed>|null $targetIdentity
     */
    private function reconstructInactiveFenceLocked(
        array $ledger,
        array $artifacts,
        ?array $targetIdentity,
    ): void {
        if ($ledger['active']) {
            throw new \LogicException('Only an inactive capability fence can be reconstructed.');
        }
        $tombstone = self::inactiveEnvelope(
            $ledger['generation'],
            $ledger['authority'],
        );
        if (!\hash_equals($ledger['digest'], $tombstone['digest'])) {
            throw new \RuntimeException(
                'Shared-state token inactive generation fence cannot be reconstructed safely.'
            );
        }
        $this->retireSupersededRecoveryNamespaceLocked(
            $artifacts,
            $targetIdentity,
        );
        GatewayProjectStateFilesystem::atomicWrite(
            $this->targetPath,
            self::encodeEnvelope($tombstone),
            self::POSIX_FILE_MODE,
        );
        $published = self::readStatePath($this->targetPath, $ledger['authority']);
        if ($published === null
            || $published['active']
            || $published['generation'] !== $ledger['generation']
            || !\hash_equals($published['digest'], $ledger['digest'])
        ) {
            throw new \RuntimeException(
                'Shared-state token inactive generation fence reconstruction failed.'
            );
        }
    }

    /**
     * @param array<string,array{path:string,kind:string,identity:array,valid:bool,digest:string,size:int,state:?array}> $artifacts
     * @param array<string|int,mixed>|null $targetIdentity
     */
    private function retireSupersededRecoveryNamespaceLocked(
        array $artifacts,
        ?array $targetIdentity,
    ): void {
        if ($targetIdentity !== null) {
            if (\is_link($this->targetPath)
                || ((((int)($targetIdentity['mode'] ?? 0)) & 0170000) !== 0100000)
                || (int)($targetIdentity['nlink'] ?? 0) !== 1
            ) {
                throw new \RuntimeException(
                    'Shared-state token recovery target is unsafe for generation fence recovery.'
                );
            }
            if (!GatewayProjectStateFilesystem::removeRegular(
                $this->targetPath,
                'superseded shared-state token recovery target',
                $targetIdentity,
            )) {
                throw new \RuntimeException(
                    'Unable to retire a superseded shared-state token recovery target.'
                );
            }
        }
        foreach ($artifacts as $artifact) {
            if (!GatewayProjectStateFilesystem::removeRegular(
                $artifact['path'],
                'superseded shared-state token recovery artifact',
                $artifact['identity'],
            )) {
                throw new \RuntimeException(
                    'Unable to retire a superseded shared-state token recovery artifact.'
                );
            }
        }
    }

    /**
     * @param array{target_identity:?array,target_valid:bool,artifacts:array<string,array>} $before
     * @param array{target_identity:?array,target_valid:bool,artifacts:array<string,array>} $after
     */
    private function assertSameRecoverySnapshot(array $before, array $after): void
    {
        if ($before['target_valid'] !== $after['target_valid']
            || !self::sameOptionalFileState(
                $before['target_identity'],
                $after['target_identity'],
            )
            || \array_keys($before['artifacts']) !== \array_keys($after['artifacts'])
        ) {
            throw new \RuntimeException(
                'Shared-state token recovery namespace changed before mutation.'
            );
        }
        foreach ($before['artifacts'] as $path => $artifact) {
            $current = $after['artifacts'][$path] ?? null;
            if (!\is_array($current)
                || !\hash_equals($artifact['kind'], (string)($current['kind'] ?? ''))
                || !\hash_equals($artifact['digest'], (string)($current['digest'] ?? ''))
                || $artifact['valid'] !== ($current['valid'] ?? null)
                || !self::sameFileState(
                    $artifact['identity'],
                    \is_array($current['identity'] ?? null) ? $current['identity'] : [],
                )
            ) {
                throw new \RuntimeException(
                    'Shared-state token recovery artifact changed before mutation.'
                );
            }
        }
    }

    /** @param array<string|int,mixed> $before @param array<string|int,mixed> $after */
    private static function sameFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int) $before[$field] !== (int) $after[$field]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string|int,mixed>|null $before @param array<string|int,mixed>|null $after */
    private static function sameOptionalFileState(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null) {
            return $before === null && $after === null;
        }

        return self::sameFileState($before, $after);
    }

    private function ensureDirectory(): void
    {
        $directory = \dirname($this->targetPath);
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0700, true)
            && !\is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Unable to create the shared-state token directory.'
            );
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)$status['mode']) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Shared-state token directory is not a safe directory.'
            );
        }
        if (\PHP_OS_FAMILY !== 'Windows'
            && ((((int)$status['mode']) & 0777) !== 0700)
        ) {
            if (!@\chmod($directory, 0700)) {
                throw new \RuntimeException(
                    'Unable to seal shared-state token directory permissions.'
                );
            }
            \clearstatcache(true, $directory);
            $sealed = @\lstat($directory);
            if (!\is_array($sealed)
                || \is_link($directory)
                || ((((int)$sealed['mode']) & 0170000) !== 0040000)
                || ((((int)$sealed['mode']) & 0777) !== 0700)
                || (int)$sealed['dev'] !== (int)$status['dev']
                || (int)$sealed['ino'] !== (int)$status['ino']
            ) {
                throw new \RuntimeException(
                    'Shared-state token directory changed while sealing permissions.'
                );
            }
        }
    }

    /**
     * @template TResult
     * @param \Closure(?array):TResult $operation
     * @return TResult
     */
    private function withPublicationLock(\Closure $operation): mixed
    {
        $this->ensureDirectory();
        $lock = VerifiedPersistentFileLock::acquire(
            $this->publicationLockPath(),
            $this->lockTimeoutSeconds,
            static function (): array {
                return [
                    'pid' => (int) \getmypid(),
                    'purpose' => 'shared-state-token-publication',
                    'started_at' => \date('c'),
                ];
            },
        );
        if (!\is_resource($lock)) {
            throw new \RuntimeException(
                'Unable to acquire the verified shared-state token publication lock within '
                . \number_format($this->lockTimeoutSeconds, 3, '.', '')
                . ' seconds.'
            );
        }
        try {
            return $operation($this->readGenerationLedgerLocked());
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @param array{active:bool,generation:int,digest:string,authority:array} $state
     * @return array{schema:string,active:bool,generation:int,digest:string,authority:array,ledger_digest:string}
     */
    private function ledgerPayload(array $state): array
    {
        $payload = [
            'schema' => self::LEDGER_SCHEMA,
            'active' => $state['active'],
            'generation' => $state['generation'],
            'digest' => $state['digest'],
            'authority' => $state['authority'],
        ];

        return [
            ...$payload,
            'ledger_digest' => \hash('sha256', self::canonicalJson($payload)),
        ];
    }

    /** @return array{active:bool,generation:int,digest:string,authority:array}|null */
    private function readGenerationLedgerLocked(): ?array
    {
        $path = $this->generationLedgerPath();
        if (GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
            $path,
            self::MAX_LEDGER_BYTES,
            'shared-state token generation ledger',
        )) {
            $recovered = ServerInstanceManager::updateValidatedJsonFileAtomically(
                $path,
                static fn (array $current): array => $current,
                function (string $contents): void {
                    $this->decodeLedger($contents);
                },
                'shared-state token generation ledger',
                self::MAX_LEDGER_BYTES,
                $this->lockTimeoutSeconds,
            );
            if (!$recovered) {
                throw new \RuntimeException(
                    'Unable to recover the shared-state token generation ledger.'
                );
            }
        }
        $document = ServerInstanceManager::readValidatedJsonStatic(
            $path,
            function (string $contents): void {
                $this->decodeLedger($contents);
            },
            'shared-state token generation ledger',
            self::MAX_LEDGER_BYTES,
        );
        if ($document === null) {
            return null;
        }

        return $this->decodeLedger(self::canonicalJson($document));
    }

    /** @return array{active:bool,generation:int,digest:string,authority:array} */
    private function decodeLedger(string $contents): array
    {
        if ($contents === '' || \strlen($contents) > self::MAX_LEDGER_BYTES) {
            throw new \RuntimeException('Shared-state token generation ledger is oversized or empty.');
        }
        try {
            $state = \json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Shared-state token generation ledger is malformed.',
                0,
                $exception,
            );
        }
        if (!\is_array($state)
            || ($state['schema'] ?? null) !== self::LEDGER_SCHEMA
            || !\is_bool($state['active'] ?? null)
            || !\is_int($state['generation'] ?? null)
            || $state['generation'] < 1
            || !\is_string($state['digest'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $state['digest']) !== 1
            || !\is_array($state['authority'] ?? null)
            || !\is_string($state['ledger_digest'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $state['ledger_digest']) !== 1
        ) {
            throw new \RuntimeException('Shared-state token generation ledger is invalid.');
        }
        $actualKeys = \array_keys($state);
        \sort($actualKeys, SORT_STRING);
        if ($actualKeys !== [
            'active',
            'authority',
            'digest',
            'generation',
            'ledger_digest',
            'schema',
        ]) {
            throw new \RuntimeException(
                'Shared-state token generation ledger fields are non-canonical.'
            );
        }
        $authority = self::normalizeAuthority($state['authority'], $this->targetPath);
        if (!self::sameAuthority($authority, $state['authority'])) {
            throw new \RuntimeException(
                'Shared-state token generation ledger authority is non-canonical.'
            );
        }
        $unsigned = [
            'schema' => self::LEDGER_SCHEMA,
            'active' => $state['active'],
            'generation' => $state['generation'],
            'digest' => $state['digest'],
            'authority' => $authority,
        ];
        if (!\hash_equals(
            $state['ledger_digest'],
            \hash('sha256', self::canonicalJson($unsigned)),
        )) {
            throw new \RuntimeException(
                'Shared-state token generation ledger digest is invalid.'
            );
        }

        return [
            'active' => $state['active'],
            'generation' => $state['generation'],
            'digest' => $state['digest'],
            'authority' => $authority,
        ];
    }

    /**
     * @param array{active:bool,secret?:string,generation:int,digest:string,authority:array}|null $target
     * @param array{active:bool,generation:int,digest:string,authority:array}|null $ledger
     * @return array{active:bool,secret?:string,generation:int,digest:string,authority:array}|null
     */
    private function resolveCurrentFence(?array $target, ?array $ledger): ?array
    {
        if ($target === null) {
            return $ledger;
        }
        if ($ledger === null) {
            return $target;
        }
        if ($target['generation'] === $ledger['generation']) {
            if ($target['active'] !== $ledger['active']
                || !\hash_equals($target['digest'], $ledger['digest'])
                || !self::sameAuthority($target['authority'], $ledger['authority'])
            ) {
                throw new \RuntimeException(
                    'Shared-state token generation fence contains a same-generation fork.'
                );
            }

            return $target;
        }

        return $target['generation'] > $ledger['generation'] ? $target : $ledger;
    }

    /**
     * @param array{active:bool,generation:int,digest:string,authority:array}|null $current
     * @param array{active:bool,secret:string,generation:int,digest:string,authority:array} $candidate
     */
    private function assertPublishTransition(?array $current, array $candidate): void
    {
        if ($current === null) {
            return;
        }
        if ($candidate['generation'] < $current['generation']) {
            throw new \RuntimeException(
                'Shared-state token lower generation was rejected.'
            );
        }
        if ($candidate['generation'] === $current['generation']) {
            if ($current['active']
                && \hash_equals($candidate['digest'], $current['digest'])
                && self::sameAuthority($candidate['authority'], $current['authority'])
            ) {
                return;
            }
            throw new \RuntimeException(
                'Shared-state token same generation contains a different digest or authority.'
            );
        }
        if ($current['active']
            && !self::sameAuthority($candidate['authority'], $current['authority'])
        ) {
            throw new \RuntimeException(
                'Active shared-state capability path belongs to a different endpoint authority.'
            );
        }
    }

    /**
     * @param array{active:bool,secret?:string,generation:int,digest:string,authority:array} $candidate
     * @param array{active:bool,secret?:string,generation:int,digest:string,authority:array}|null $target
     */
    private function publishEnvelopeLocked(array $candidate, ?array $target): void
    {
        if ($target === null
            || $target['generation'] !== $candidate['generation']
            || !\hash_equals($target['digest'], $candidate['digest'])
        ) {
            GatewayProjectStateFilesystem::atomicWrite(
                $this->targetPath,
                self::encodeEnvelope($candidate),
                self::POSIX_FILE_MODE,
            );
        }
        $published = self::readStatePath($this->targetPath, $candidate['authority']);
        if ($published === null
            || $published['active'] !== $candidate['active']
            || $published['generation'] !== $candidate['generation']
            || !\hash_equals($published['digest'], $candidate['digest'])
        ) {
            throw new \RuntimeException(
                'Shared-state token publication verification failed.'
            );
        }
        $this->writeGenerationLedgerLocked($candidate);
    }

    /** @param array{active:bool,generation:int,digest:string,authority:array} $state */
    private function writeGenerationLedgerLocked(array $state): void
    {
        $published = ServerInstanceManager::atomicWriteValidatedJsonStatic(
            $this->generationLedgerPath(),
            $this->ledgerPayload($state),
            function (string $contents): void {
                $this->decodeLedger($contents);
            },
            'shared-state token generation ledger',
            self::MAX_LEDGER_BYTES,
            $this->lockTimeoutSeconds,
        );
        if (!$published) {
            throw new \RuntimeException(
                'Unable to persist the shared-state token generation fence.'
            );
        }
    }

    /**
     * @param array{role:string,host:string,port:int,instance:string,token_path_sha256:string} $authority
     * @return array{active:true,secret:string,generation:int,digest:string,authority:array}
     */
    private static function activeEnvelope(string $secret, int $generation, array $authority): array
    {
        self::assertSecret($secret);
        $base = [
            'schema' => self::ENVELOPE_SCHEMA,
            'state' => 'active',
            'generation' => $generation,
            'authority' => $authority,
            'secret' => $secret,
        ];

        return [
            'active' => true,
            'secret' => $secret,
            'generation' => $generation,
            'digest' => \hash('sha256', self::canonicalJson($base)),
            'authority' => $authority,
        ];
    }

    /**
     * @param array{role:string,host:string,port:int,instance:string,token_path_sha256:string} $authority
     * @return array{active:false,generation:int,digest:string,authority:array}
     */
    private static function inactiveEnvelope(int $generation, array $authority): array
    {
        $base = [
            'schema' => self::ENVELOPE_SCHEMA,
            'state' => 'inactive',
            'generation' => $generation,
            'authority' => $authority,
        ];

        return [
            'active' => false,
            'generation' => $generation,
            'digest' => \hash('sha256', self::canonicalJson($base)),
            'authority' => $authority,
        ];
    }

    /** @param array{active:bool,secret?:string,generation:int,digest:string,authority:array} $state */
    private static function encodeEnvelope(array $state): string
    {
        $document = [
            'schema' => self::ENVELOPE_SCHEMA,
            'state' => $state['active'] ? 'active' : 'inactive',
            'generation' => $state['generation'],
            'authority' => $state['authority'],
            'digest' => $state['digest'],
        ];
        if ($state['active']) {
            $document['secret'] = $state['secret'];
        }
        $contents = self::canonicalJson($document);
        if (\strlen($contents) > self::MAX_TOKEN_BYTES) {
            throw new \RuntimeException('Shared-state token envelope exceeds its size limit.');
        }

        return $contents;
    }

    /**
     * @param array{role:string,host:string,port:int,instance:string,token_path_sha256:string}|null $expectedAuthority
     * @return array{active:bool,secret:string,generation:int,digest:string,authority:array}
     */
    private static function decodeEnvelope(
        string $contents,
        string $path,
        ?array $expectedAuthority,
    ): array {
        if ($contents === ''
            || \strlen($contents) > self::MAX_TOKEN_BYTES
            || !\hash_equals($contents, \trim($contents))
        ) {
            throw new \RuntimeException(
                'Shared-state authentication token is empty or non-canonical.'
            );
        }
        if (!\str_starts_with($contents, '{')) {
            if (\preg_match('/\A[^:\s]{32,4096}:[0-9]+\z/D', $contents) === 1) {
                throw new \RuntimeException(
                    'legacy shared-state token envelope is rejected; controlled migration is required.'
                );
            }
            throw new \RuntimeException('Shared-state token envelope is malformed.');
        }
        try {
            $document = \json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Shared-state token envelope is malformed.', 0, $exception);
        }
        if (!\is_array($document)
            || ($document['schema'] ?? null) !== self::ENVELOPE_SCHEMA
            || !\is_int($document['generation'] ?? null)
            || $document['generation'] < 1
            || !\is_string($document['state'] ?? null)
            || !\in_array($document['state'], ['active', 'inactive'], true)
            || !\is_string($document['digest'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $document['digest']) !== 1
            || !\is_array($document['authority'] ?? null)
        ) {
            throw new \RuntimeException('Shared-state token envelope fields are invalid.');
        }
        $active = $document['state'] === 'active';
        $expectedKeys = $active
            ? ['authority', 'digest', 'generation', 'schema', 'secret', 'state']
            : ['authority', 'digest', 'generation', 'schema', 'state'];
        $actualKeys = \array_keys($document);
        \sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \RuntimeException('Shared-state token envelope fields are non-canonical.');
        }
        $authority = self::normalizeAuthority($document['authority'], $path);
        if (!self::sameAuthority($authority, $document['authority'])) {
            throw new \RuntimeException('Shared-state token authority is non-canonical.');
        }
        if ($expectedAuthority !== null && !self::sameAuthority($authority, $expectedAuthority)) {
            throw new \RuntimeException(
                'Shared-state token endpoint authority does not match the requested service.'
            );
        }
        $secret = '';
        if ($active) {
            if (!\is_string($document['secret'] ?? null)) {
                throw new \RuntimeException('Shared-state token secret is missing.');
            }
            $secret = $document['secret'];
            self::assertSecret($secret);
        }
        $base = [
            'schema' => self::ENVELOPE_SCHEMA,
            'state' => $document['state'],
            'generation' => $document['generation'],
            'authority' => $authority,
        ];
        if ($active) {
            $base['secret'] = $secret;
        }
        $digest = \hash('sha256', self::canonicalJson($base));
        if (!\hash_equals($digest, $document['digest'])) {
            throw new \RuntimeException('Shared-state token envelope digest is invalid.');
        }
        $state = [
            'active' => $active,
            'secret' => $secret,
            'generation' => $document['generation'],
            'digest' => $digest,
            'authority' => $authority,
        ];
        if (!\hash_equals($contents, self::encodeEnvelope($state))) {
            throw new \RuntimeException('Shared-state token envelope encoding is non-canonical.');
        }

        return $state;
    }

    /** @param array<string,mixed> $authority @return array{role:string,host:string,port:int,instance:string,token_path_sha256:string} */
    private static function normalizeAuthority(array $authority, string $targetPath): array
    {
        $role = self::normalizeRole((string)($authority['role'] ?? ''));
        $host = self::normalizeHost((string)($authority['host'] ?? ''));
        $port = $authority['port'] ?? null;
        if (!\is_int($port) && !(\is_string($port) && \preg_match('/\A[0-9]+\z/D', $port) === 1)) {
            throw new \InvalidArgumentException('Shared-state token authority port is invalid.');
        }
        $port = (int)$port;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Shared-state token authority port is invalid.');
        }
        $instance = \strtolower(\trim((string)($authority['instance'] ?? '')));
        if ($instance === '') {
            $instance = self::defaultInstance($role, $host, $port);
        }
        if (\strlen($instance) > 192 || \preg_match('/[\x00-\x1f\x7f]/', $instance) === 1) {
            throw new \InvalidArgumentException('Shared-state token authority instance is invalid.');
        }

        return [
            'role' => $role,
            'host' => $host,
            'port' => $port,
            'instance' => $instance,
            'token_path_sha256' => \hash('sha256', self::canonicalTargetPath($targetPath)),
        ];
    }

    public static function defaultInstance(string $role, string $host, int $port): string
    {
        return self::normalizeRole($role) . '@' . self::normalizeHost($host) . ':' . $port;
    }

    private static function normalizeRole(string $role): string
    {
        $role = \strtolower(\trim($role));
        $role = match ($role) {
            'session', 'sessionserver', 'session_server' => 'session_server',
            'memory', 'memoryserver', 'memory_server' => 'memory_server',
            default => $role,
        };
        if ($role === ''
            || \strlen($role) > 64
            || \preg_match('/\A[a-z][a-z0-9_-]*\z/D', $role) !== 1
        ) {
            throw new \InvalidArgumentException('Shared-state token authority role is invalid.');
        }

        return $role;
    }

    private static function normalizeHost(string $host): string
    {
        $host = \strtolower(\trim($host));
        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }
        if (\in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return 'loopback';
        }
        if (\str_contains($host, '%')) {
            throw new \InvalidArgumentException('Shared-state token authority host is invalid.');
        }
        $packed = @\inet_pton($host);
        if (\is_string($packed)) {
            $canonical = @\inet_ntop($packed);
            if (\is_string($canonical) && $canonical !== '') {
                return \strtolower($canonical);
            }
        }
        $host = \rtrim($host, '.');
        if ($host === ''
            || \strlen($host) > 253
            || \preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host) !== 1
        ) {
            throw new \InvalidArgumentException('Shared-state token authority host is invalid.');
        }

        return $host;
    }

    private static function canonicalTargetPath(string $targetPath): string
    {
        if ($targetPath === '' || \str_contains($targetPath, "\0")) {
            throw new \InvalidArgumentException('Shared-state token authority path is invalid.');
        }
        $directory = \realpath(\dirname($targetPath));
        if (!\is_string($directory)) {
            throw new \InvalidArgumentException(
                'Shared-state token authority directory must exist before binding.'
            );
        }
        $path = \rtrim(\str_replace('\\', '/', $directory), '/')
            . '/'
            . \basename(\str_replace('\\', '/', $targetPath));

        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($path) : $path;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
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

        return \json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameAuthority(array $left, array $right): bool
    {
        return \hash_equals(self::canonicalJson($left), self::canonicalJson($right));
    }

    /** @return array{role:string,host:string,port:int,instance:string,token_path_sha256:string} */
    private function requireAuthority(): array
    {
        if ($this->authority === null) {
            throw new \RuntimeException(
                'Shared-state token publication requires an endpoint authority.'
            );
        }

        return $this->authority;
    }

    private static function assertSecret(string $secret): void
    {
        if (\strlen($secret) < 32
            || \strlen($secret) > 4096
            || \str_contains($secret, ':')
            || \preg_match('/[\x00-\x20\x7f]/', $secret) === 1
        ) {
            throw new \InvalidArgumentException(
                'Shared-state authentication token secret is invalid.'
            );
        }
    }
}
