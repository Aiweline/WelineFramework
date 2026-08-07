<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * POSIX-only, instance-scoped TLS ticket-ring control-plane store.
 *
 * Publishes key material atomically for the native HTTP/3 TLS consumer while
 * exposing only epoch/digest metadata to the Master and Worker argv.
 */
final class TlsTicketRingStore
{
    private const MAGIC = "WLSTKR01";
    private const VERSION = 1;
    private const SECRET_BYTES = 32;
    private const DIGEST_BYTES = 32;
    private const PREFIX_BYTES = 32;
    private const RECORD_BYTES = 128;
    private const MIN_ROTATION_SECONDS = 300;
    private const MAX_ROTATION_SECONDS = 604800;
    private const MAX_RAW_DIRECTORY_ENTRIES = 1024;
    private const MAX_TEMPORARY_FILES = 64;
    private const MAX_RING_FILES = 256;
    private const LOCK_LEAF = '.store.lock';
    private const DEFAULT_LOCK_WAIT_SECONDS = 5.0;
    private const MAX_LOCK_WAIT_SECONDS = 30.0;
    private const LOCK_RETRY_USEC = 10_000;
    public const DEFAULT_ROTATION_SECONDS = 21600;

    private readonly string $directory;
    private readonly float $lockWaitSeconds;

    public function __construct(
        ?string $directory = null,
        float $lockWaitSeconds = self::DEFAULT_LOCK_WAIT_SECONDS,
    ) {
        if (!\is_finite($lockWaitSeconds)
            || $lockWaitSeconds <= 0.0
            || $lockWaitSeconds > self::MAX_LOCK_WAIT_SECONDS
        ) {
            throw new \InvalidArgumentException(
                'TLS ticket-ring lock wait must be within (0, 30] seconds.',
            );
        }
        $root = \defined('BP')
            ? \rtrim((string)\constant('BP'), '/\\')
            : \dirname(__DIR__, 6);
        $this->directory = $directory !== null && \trim($directory) !== ''
            ? \rtrim($directory, '/\\')
            : $root . DIRECTORY_SEPARATOR . 'var'
                . DIRECTORY_SEPARATOR . 'server'
                . DIRECTORY_SEPARATOR . 'tls-ticket-rings';
        $this->lockWaitSeconds = $lockWaitSeconds;
    }

    /**
     * Ensure the instance has one current/previous key snapshot.
     *
     * @return array{epoch:int,digest:string,rotated:bool}
     */
    public function ensure(
        string $instanceName,
        int $rotationSeconds = self::DEFAULT_ROTATION_SECONDS,
        ?int $now = null,
    ): array {
        $this->assertPosix();
        $instanceName = $this->normalizeInstanceName($instanceName);
        $rotationSeconds = \max(
            self::MIN_ROTATION_SECONDS,
            \min(self::MAX_ROTATION_SECONDS, $rotationSeconds)
        );
        $now ??= \time();
        if ($now <= 0) {
            throw new \RuntimeException('TLS ticket-ring clock is invalid.');
        }

        $this->ensureDirectory();
        $lock = $this->openLock();
        try {
            $this->acquireStoreLock($lock);
        } catch (\Throwable $throwable) {
            \fclose($lock);
            throw $throwable;
        }

        $snapshot = null;
        $verified = null;
        $current = '';
        $previous = '';
        $payload = '';
        try {
            $path = $this->pathForInstance($instanceName);
            $this->assertStoreLockHandle($lock);
            $recovery = $this->recoverInterruptedWrites($path, $lock);
            $pathStat = $recovery['target_status'];
            $this->assertStoreLockHandle($lock);

            if (\is_array($pathStat)) {
                $this->assertSecureRegularPath(
                    $path,
                    $pathStat,
                    'snapshot',
                    self::RECORD_BYTES,
                    self::RECORD_BYTES,
                );
                $snapshot = $this->readSnapshotFromPath($path, $pathStat);
                $expiresAt = $snapshot['created_at'] + $rotationSeconds;
                if ($now < $expiresAt) {
                    return [
                        'epoch' => $snapshot['epoch'],
                        'digest' => $snapshot['digest'],
                        'rotated' => false,
                    ];
                }
                $previous = $snapshot['current'];
            } else {
                if (\file_exists($path) || \is_link($path)) {
                    throw new \RuntimeException(
                        'TLS ticket-ring snapshot must be a regular file.',
                    );
                }
                $previous = \random_bytes(self::SECRET_BYTES);
            }

            $this->assertPublicationCapacity(
                $recovery['raw_entries'],
                $recovery['ring_count'],
                \is_array($pathStat),
            );
            $current = \random_bytes(self::SECRET_BYTES);
            $epoch = ($snapshot['epoch'] ?? 0) + 1;
            $payload = $this->encode(
                $epoch,
                $now,
                $rotationSeconds,
                $current,
                $previous
            );
            $verified = $this->atomicWrite(
                $path,
                $payload,
                \is_array($pathStat) ? $pathStat : null,
                $lock,
            );
            return [
                'epoch' => $verified['epoch'],
                'digest' => $verified['digest'],
                'rotated' => true,
            ];
        } finally {
            if (\is_array($snapshot)) {
                self::wipeSnapshot($snapshot);
            }
            if (\is_array($verified)) {
                self::wipeSnapshot($verified);
            }
            self::wipeString($current);
            self::wipeString($previous);
            self::wipeString($payload);
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
    }

    /** @param resource $lock */
    private function acquireStoreLock($lock): void
    {
        $startedAt = $this->monotonicSeconds();
        $deadline = $startedAt + $this->lockWaitSeconds;
        do {
            $this->assertStoreLockHandle($lock);
            if (@\flock($lock, LOCK_EX | LOCK_NB)) {
                $this->assertStoreLockHandle($lock);
                return;
            }
            $now = $this->monotonicSeconds();
            if ($now >= $deadline) {
                break;
            }
            $remainingUsec = (int)\max(
                1,
                \min(
                    self::LOCK_RETRY_USEC,
                    \ceil(($deadline - $now) * 1_000_000),
                ),
            );
            \usleep($remainingUsec);
        } while (true);

        throw new \RuntimeException('TLS ticket-ring store lock acquisition timed out.');
    }

    private function monotonicSeconds(): float
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('TLS ticket-ring monotonic clock is invalid.');
        }

        return $now;
    }

    /**
     * Load a private snapshot for a process-local native HTTP/3 TLS adapter.
     *
     * The caller must invoke wipeSnapshot() in a finally block.
     *
     * @return array{epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}
     */
    public function loadSecretSnapshot(string $instanceName): array
    {
        $this->assertPosix();
        $this->ensureDirectory(false);

        return $this->readSnapshotFromPath(
            $this->pathForInstance($this->normalizeInstanceName($instanceName))
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public static function wipeSnapshot(array &$snapshot): void
    {
        foreach (['current', 'previous'] as $field) {
            if (isset($snapshot[$field]) && \is_string($snapshot[$field])) {
                self::wipeString($snapshot[$field]);
            }
            unset($snapshot[$field]);
        }
    }

    private function pathForInstance(string $instanceName): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . \hash('sha256', $instanceName)
            . '.ring';
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        if ($instanceName === '' || \strlen($instanceName) > 512) {
            throw new \InvalidArgumentException('TLS ticket-ring instance name is invalid.');
        }

        return $instanceName;
    }

    private function assertPosix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new \RuntimeException('TLS ticket-ring file store is POSIX-only.');
        }
    }

    private function ensureDirectory(bool $create = true): void
    {
        $created = false;
        $before = @\lstat($this->directory);
        if (!\is_array($before)) {
            if (\file_exists($this->directory) || \is_link($this->directory)) {
                throw new \RuntimeException(
                    'TLS ticket-ring directory is indeterminate or unsafe.',
                );
            }
            if (!$create) {
                throw new \RuntimeException('TLS ticket-ring directory is missing.');
            }
            $created = @\mkdir($this->directory, 0700, true);
            if (!$created && !\is_dir($this->directory)) {
                throw new \RuntimeException('Unable to create the TLS ticket-ring directory.');
            }
        }
        if ($created && !@\chmod($this->directory, 0700)) {
            throw new \RuntimeException('Unable to protect the TLS ticket-ring directory.');
        }

        $stat = @\lstat($this->directory);
        $canonical = @\realpath($this->directory);
        if (!\is_array($stat)
            || (((int)$stat['mode'] & 0170000) !== 0040000)
            || (((int)$stat['mode'] & 0777) !== 0700)
            || \is_link($this->directory)
            || !\is_string($canonical)
            || !\hash_equals(
                \rtrim($canonical, '/'),
                \rtrim($this->directory, '/'),
            )
        ) {
            throw new \RuntimeException('TLS ticket-ring directory must be an owned 0700 directory.');
        }
        $this->assertOwner($stat, 'directory');
        if ($created) {
            $this->syncDirectory();
        }
    }

    /**
     * @return resource
     */
    private function openLock()
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . self::LOCK_LEAF;
        $before = @\lstat($path);
        $created = false;
        if (!\is_array($before)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'TLS ticket-ring lock must be a regular file.',
                );
            }
            $handle = @\fopen($path, 'x+b');
            if (\is_resource($handle)) {
                $created = true;
            } else {
                $before = @\lstat($path);
                if (!\is_array($before)) {
                    throw new \RuntimeException(
                        'Unable to create or resolve the TLS ticket-ring lock.',
                    );
                }
                $this->assertSecureRegularPath($path, $before, 'lock', 0, 0);
                $handle = @\fopen($path, 'r+b');
            }
        } else {
            $this->assertSecureRegularPath($path, $before, 'lock', 0, 0);
            $handle = @\fopen($path, 'r+b');
        }
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open the TLS ticket-ring lock.');
        }

        try {
            if ($created) {
                $this->protectOpenedFile($handle, $path, 'lock');
                if (!@\fflush($handle)
                    || (\function_exists('fsync') && !@\fsync($handle))
                ) {
                    throw new \RuntimeException(
                        'Unable to synchronize the TLS ticket-ring lock.',
                    );
                }
            }
            $after = @\lstat($path);
            if (!\is_array($after)) {
                throw new \RuntimeException('TLS ticket-ring lock disappeared.');
            }
            $this->assertSecureRegularPath($path, $after, 'lock', 0, 0);
            $this->assertOpenedPath($handle, $after, 'lock');
            if ($created) {
                $this->syncDirectory();
            }
        } catch (\Throwable $throwable) {
            \fclose($handle);
            throw $throwable;
        }

        return $handle;
    }

    /**
     * @param array<int|string,int>|null $expectedStatus
     * @return array{epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}
     */
    private function readSnapshotFromPath(
        string $path,
        ?array $expectedStatus = null,
    ): array
    {
        $payload = $this->readPayloadFromPath($path, $expectedStatus);
        try {
            return $this->decode($payload);
        } finally {
            self::wipeString($payload);
        }
    }

    /** @param array<int|string,int>|null $expectedStatus */
    private function readPayloadFromPath(
        string $path,
        ?array $expectedStatus = null,
    ): string {
        $pathStat = @\lstat($path);
        if (!\is_array($pathStat)) {
            throw new \RuntimeException('TLS ticket-ring snapshot is missing.');
        }
        $this->assertSecureRegularPath(
            $path,
            $pathStat,
            'snapshot',
            self::RECORD_BYTES,
            self::RECORD_BYTES,
        );
        if (\is_array($expectedStatus)
            && !$this->sameFileState($expectedStatus, $pathStat)
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring snapshot changed before reading.',
            );
        }

        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open the TLS ticket-ring snapshot.');
        }

        $payload = '';
        try {
            $this->assertOpenedPath($handle, $pathStat, 'snapshot');
            $read = @\stream_get_contents($handle, self::RECORD_BYTES + 1);
            $payload = \is_string($read) ? $read : '';
            $openedAfter = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (\strlen($payload) !== self::RECORD_BYTES
                || !\is_array($openedAfter)
                || !\is_array($pathAfter)
                || !$this->sameFileState($pathStat, $openedAfter)
                || !$this->sameFileState($openedAfter, $pathAfter)
            ) {
                throw new \RuntimeException(
                    'TLS ticket-ring snapshot changed while reading or has an invalid length.',
                );
            }
            return $payload;
        } catch (\Throwable $throwable) {
            self::wipeString($payload);
            throw $throwable;
        } finally {
            @\fclose($handle);
        }
    }

    private function encode(
        int $epoch,
        int $createdAt,
        int $rotationSeconds,
        string $current,
        string $previous,
    ): string {
        if ($epoch <= 0
            || \strlen($current) !== self::SECRET_BYTES
            || \strlen($previous) !== self::SECRET_BYTES
        ) {
            throw new \RuntimeException('TLS ticket-ring key material is invalid.');
        }

        $unsigned = self::MAGIC
            . \pack('N', self::VERSION)
            . $this->packUint64($epoch)
            . $this->packUint64($createdAt)
            . \pack('N', $rotationSeconds)
            . $current
            . $previous;
        $digest = \hash('sha256', $unsigned, true);
        $payload = $unsigned . $digest;
        self::wipeString($unsigned);
        self::wipeString($digest);

        return $payload;
    }

    /**
     * @return array{epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}
     */
    private function decode(string $payload): array
    {
        if (\strlen($payload) !== self::RECORD_BYTES
            || !\hash_equals(self::MAGIC, \substr($payload, 0, 8))
        ) {
            throw new \RuntimeException('TLS ticket-ring magic or length is invalid.');
        }

        $version = \unpack('Nvalue', \substr($payload, 8, 4));
        if ((int)($version['value'] ?? 0) !== self::VERSION) {
            throw new \RuntimeException('TLS ticket-ring version is unsupported.');
        }

        $epoch = $this->unpackUint64(\substr($payload, 12, 8));
        $createdAt = $this->unpackUint64(\substr($payload, 20, 8));
        $rotation = \unpack('Nvalue', \substr($payload, 28, 4));
        $rotationSeconds = (int)($rotation['value'] ?? 0);
        $current = \substr($payload, self::PREFIX_BYTES, self::SECRET_BYTES);
        $previous = \substr(
            $payload,
            self::PREFIX_BYTES + self::SECRET_BYTES,
            self::SECRET_BYTES
        );
        $digest = \substr($payload, self::RECORD_BYTES - self::DIGEST_BYTES);
        $unsigned = \substr($payload, 0, self::RECORD_BYTES - self::DIGEST_BYTES);
        $expected = \hash('sha256', $unsigned, true);

        try {
            if ($epoch <= 0
                || $createdAt <= 0
                || $rotationSeconds < self::MIN_ROTATION_SECONDS
                || $rotationSeconds > self::MAX_ROTATION_SECONDS
                || \strlen($current) !== self::SECRET_BYTES
                || \strlen($previous) !== self::SECRET_BYTES
                || !\hash_equals($expected, $digest)
            ) {
                throw new \RuntimeException('TLS ticket-ring snapshot integrity validation failed.');
            }

            return [
                'epoch' => $epoch,
                'created_at' => $createdAt,
                'rotation_seconds' => $rotationSeconds,
                'digest' => \bin2hex($digest),
                'current' => $current,
                'previous' => $previous,
            ];
        } catch (\Throwable $throwable) {
            self::wipeString($current);
            self::wipeString($previous);
            throw $throwable;
        } finally {
            self::wipeString($digest);
            self::wipeString($unsigned);
            self::wipeString($expected);
        }
    }

    /**
     * @param resource $lock
     * @param array<int|string,int>|null $expectedTargetStatus
     * @return array{epoch:int,created_at:int,rotation_seconds:int,digest:string,current:string,previous:string}
     */
    private function atomicWrite(
        string $path,
        string $payload,
        ?array $expectedTargetStatus,
        $lock,
    ): array {
        if (\strlen($payload) !== self::RECORD_BYTES) {
            throw new \RuntimeException('TLS ticket-ring publication payload length is invalid.');
        }
        $this->assertStoreLockHandle($lock);

        $temporary = '';
        $handle = null;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $candidate = $this->directory
                . DIRECTORY_SEPARATOR
                . '.ring.'
                . \bin2hex(\random_bytes(12))
                . '.tmp';
            $candidateHandle = @\fopen($candidate, 'x+b');
            if (\is_resource($candidateHandle)) {
                $temporary = $candidate;
                $handle = $candidateHandle;
                break;
            }
        }
        if ($temporary === '' || !\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to allocate a TLS ticket-ring temporary file within the retry budget.',
            );
        }

        $temporaryIdentity = null;
        $published = false;
        $failure = null;
        try {
            $this->protectOpenedFile($handle, $temporary, 'temporary file');
            $createdPath = @\lstat($temporary);
            if (!\is_array($createdPath)) {
                throw new \RuntimeException('TLS ticket-ring temporary file disappeared.');
            }
            $this->assertSecureRegularPath(
                $temporary,
                $createdPath,
                'temporary file',
                0,
                0,
            );
            $this->assertOpenedPath($handle, $createdPath, 'temporary file');
            $temporaryIdentity = $createdPath;

            $offset = 0;
            while ($offset < self::RECORD_BYTES) {
                $written = @\fwrite($handle, \substr($payload, $offset));
                if (!\is_int($written) || $written <= 0) {
                    throw new \RuntimeException('Unable to write the TLS ticket-ring snapshot.');
                }
                $offset += $written;
            }
            if (!@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                throw new \RuntimeException('Unable to synchronize the TLS ticket-ring snapshot.');
            }
            $openedAfter = @\fstat($handle);
            $temporaryAfter = @\lstat($temporary);
            if (!\is_array($openedAfter) || !\is_array($temporaryAfter)) {
                throw new \RuntimeException('TLS ticket-ring temporary file disappeared.');
            }
            $this->assertSecureRegularPath(
                $temporary,
                $temporaryAfter,
                'temporary file',
                self::RECORD_BYTES,
                self::RECORD_BYTES,
            );
            if (!$this->sameFileState($openedAfter, $temporaryAfter)
                || !$this->sameObjectIdentity($temporaryIdentity, $temporaryAfter)
            ) {
                throw new \RuntimeException(
                    'TLS ticket-ring temporary file changed before publication.',
                );
            }
            $temporaryIdentity = $temporaryAfter;
            @\fclose($handle);
            $handle = null;

            $this->assertStoreLockHandle($lock);
            $this->assertExpectedTargetState($path, $expectedTargetStatus);
            $beforeRename = @\lstat($temporary);
            if (!\is_array($beforeRename)
                || !$this->sameFileState($temporaryAfter, $beforeRename)
            ) {
                throw new \RuntimeException(
                    'TLS ticket-ring temporary file changed immediately before publication.',
                );
            }
            if (!@\rename($temporary, $path)) {
                throw new \RuntimeException(
                    'Unable to atomically publish the TLS ticket-ring snapshot.',
                );
            }
            $published = true;
            $publishedStat = @\lstat($path);
            if (!\is_array($publishedStat)) {
                throw new \RuntimeException('Published TLS ticket-ring snapshot is missing.');
            }
            $this->assertSecureRegularPath(
                $path,
                $publishedStat,
                'snapshot',
                self::RECORD_BYTES,
                self::RECORD_BYTES,
            );
            if (!$this->sameObjectIdentity($temporaryAfter, $publishedStat)) {
                throw new \RuntimeException(
                    'Published TLS ticket-ring snapshot identity is invalid.',
                );
            }
            $this->syncDirectory();

            $publishedPayload = $this->readPayloadFromPath($path, $publishedStat);
            try {
                if (!\hash_equals($payload, $publishedPayload)) {
                    throw new \RuntimeException(
                        'Published TLS ticket-ring snapshot does not match the staged generation.',
                    );
                }
                return $this->decode($publishedPayload);
            } finally {
                self::wipeString($publishedPayload);
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
            throw $throwable;
        } finally {
            if (\is_resource($handle)) {
                @\fclose($handle);
            }
            if (!$published && \is_array($temporaryIdentity)) {
                try {
                    $this->removeExactTemporary($temporary, $temporaryIdentity);
                } catch (\Throwable $cleanupFailure) {
                    throw new \RuntimeException(
                        'TLS ticket-ring publication failed and its temporary evidence could not be safely collected: '
                            . $cleanupFailure->getMessage(),
                        0,
                        $failure,
                    );
                }
            }
        }
    }

    /**
     * @param resource $lock
     * @return array{
     *   target_status:array<int|string,int>|null,
     *   raw_entries:int,
     *   ring_count:int
     * }
     */
    private function recoverInterruptedWrites(string $target, $lock): array
    {
        $this->assertStoreLockHandle($lock);
        $inventory = $this->discoverStoreEntries($target);

        foreach ($inventory['rings'] as $ring) {
            $snapshot = $this->readSnapshotFromPath(
                $ring['path'],
                $ring['status'],
            );
            self::wipeSnapshot($snapshot);
        }

        $temporaries = [];
        foreach ($inventory['temporaries'] as $temporary) {
            $temporaries[] = [
                'path' => $temporary['path'],
                'status' => $this->validateRecoveryTemporary(
                    $temporary['path'],
                    $temporary['status'],
                ),
            ];
        }

        $directoryAfterValidation = @\lstat($this->directory);
        if (!\is_array($directoryAfterValidation)
            || !$this->sameDirectoryState(
                $inventory['directory'],
                $directoryAfterValidation,
            )
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring directory changed during recovery validation.',
            );
        }

        $removed = 0;
        try {
            foreach ($temporaries as $temporary) {
                $this->removeExactTemporary(
                    $temporary['path'],
                    $temporary['status'],
                    false,
                );
                ++$removed;
            }
        } finally {
            if ($removed > 0) {
                $this->syncDirectory();
            }
        }
        $this->assertStoreLockHandle($lock);
        return [
            'target_status' => $this->assertExpectedTargetState(
                $target,
                $inventory['target_status'],
            ),
            'raw_entries' => $inventory['raw_entries'] - $removed,
            'ring_count' => \count($inventory['rings']),
        ];
    }

    /**
     * @return array{
     *   directory:array<int|string,int>,
     *   raw_entries:int,
     *   target_status:array<int|string,int>|null,
     *   temporaries:list<array{path:string,status:array<int|string,int>}>,
     *   rings:list<array{path:string,status:array<int|string,int>}>
     * }
     */
    private function discoverStoreEntries(string $target): array
    {
        $directoryBefore = @\lstat($this->directory);
        if (!\is_array($directoryBefore)) {
            throw new \RuntimeException('TLS ticket-ring directory is missing.');
        }
        $this->assertSecureDirectoryStatus($directoryBefore);

        $handle = @\opendir($this->directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to enumerate the TLS ticket-ring directory.');
        }
        $rawEntries = 0;
        $temporaries = [];
        $rings = [];
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                if (++$rawEntries > self::MAX_RAW_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'TLS ticket-ring raw entry quota is exhausted.',
                    );
                }

                $path = $this->directory . DIRECTORY_SEPARATOR . $leaf;
                if ($leaf === self::LOCK_LEAF) {
                    $status = @\lstat($path);
                    if (!\is_array($status)) {
                        throw new \RuntimeException(
                            'TLS ticket-ring lock is indeterminate.',
                        );
                    }
                    $this->assertSecureRegularPath($path, $status, 'lock', 0, 0);
                    continue;
                }
                if (\preg_match('/\A\.ring\.[a-f0-9]{24}\.tmp\z/D', $leaf) === 1) {
                    $status = @\lstat($path);
                    if (!\is_array($status)) {
                        throw new \RuntimeException(
                            'TLS ticket-ring recovery temporary is indeterminate.',
                        );
                    }
                    $temporaries[] = ['path' => $path, 'status' => $status];
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{64}\.ring\z/D', $leaf) === 1) {
                    $status = @\lstat($path);
                    if (!\is_array($status)) {
                        throw new \RuntimeException(
                            'TLS ticket-ring committed snapshot is indeterminate.',
                        );
                    }
                    $rings[] = ['path' => $path, 'status' => $status];
                    continue;
                }
                if (\str_starts_with($leaf, '.ring.')
                    || \str_ends_with($leaf, '.ring')
                    || \str_starts_with($leaf, self::LOCK_LEAF)
                ) {
                    throw new \RuntimeException(
                        'TLS ticket-ring directory contains a malformed reserved leaf.',
                    );
                }
            }
        } finally {
            @\closedir($handle);
        }

        if (\count($temporaries) > self::MAX_TEMPORARY_FILES) {
            throw new \RuntimeException(
                'TLS ticket-ring temporary recovery quota is exhausted.',
            );
        }
        if (\count($rings) > self::MAX_RING_FILES) {
            throw new \RuntimeException('TLS ticket-ring ring quota is exhausted.');
        }
        $targetStatus = null;
        foreach ($rings as $ring) {
            if (\hash_equals($target, $ring['path'])) {
                $targetStatus = $ring['status'];
                break;
            }
        }
        $directoryAfter = @\lstat($this->directory);
        if (!\is_array($directoryAfter)
            || !$this->sameDirectoryState($directoryBefore, $directoryAfter)
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring directory changed during discovery.',
            );
        }
        \usort(
            $temporaries,
            static fn (array $left, array $right): int => $left['path'] <=> $right['path'],
        );
        \usort(
            $rings,
            static fn (array $left, array $right): int => $left['path'] <=> $right['path'],
        );
        return [
            'directory' => $directoryAfter,
            'raw_entries' => $rawEntries,
            'target_status' => $targetStatus,
            'temporaries' => $temporaries,
            'rings' => $rings,
        ];
    }

    /**
     * @param array<int|string,int> $expectedStatus
     * @return array<int|string,int>
     */
    private function validateRecoveryTemporary(
        string $path,
        array $expectedStatus,
    ): array {
        $status = @\lstat($path);
        if (!\is_array($status)
            || !$this->sameFileState($expectedStatus, $status)
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring recovery temporary changed before validation.',
            );
        }
        $this->assertSecureRegularPath(
            $path,
            $status,
            'recovery temporary',
            0,
            self::RECORD_BYTES,
        );
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to open the TLS ticket-ring recovery temporary.',
            );
        }
        try {
            $this->assertOpenedPath($handle, $status, 'recovery temporary');
            $openedAfter = @\fstat($handle);
            $pathAfter = @\lstat($path);
            if (!\is_array($openedAfter)
                || !\is_array($pathAfter)
                || !$this->sameFileState($status, $openedAfter)
                || !$this->sameFileState($openedAfter, $pathAfter)
            ) {
                throw new \RuntimeException(
                    'TLS ticket-ring recovery temporary changed during validation.',
                );
            }
            return $pathAfter;
        } finally {
            @\fclose($handle);
        }
    }

    /** @param array<int|string,int> $expectedStatus */
    private function removeExactTemporary(
        string $path,
        array $expectedStatus,
        bool $synchronize = true,
    ): void {
        $this->assertTemporaryPath($path);
        $status = @\lstat($path);
        if (!\is_array($status)
            || !$this->sameFileState($expectedStatus, $status)
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring recovery temporary changed before cleanup.',
            );
        }
        $this->assertSecureRegularPath(
            $path,
            $status,
            'recovery temporary',
            0,
            self::RECORD_BYTES,
        );
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to open the TLS ticket-ring recovery temporary for cleanup.',
            );
        }
        try {
            $this->assertOpenedPath($handle, $status, 'recovery temporary');
            $immediatelyBefore = @\lstat($path);
            if (!\is_array($immediatelyBefore)
                || !$this->sameFileState($status, $immediatelyBefore)
                || !@\unlink($path)
            ) {
                throw new \RuntimeException(
                    'Unable to safely collect the TLS ticket-ring recovery temporary.',
                );
            }
        } finally {
            @\fclose($handle);
        }
        if (\is_array(@\lstat($path)) || \file_exists($path) || \is_link($path)) {
            throw new \RuntimeException(
                'TLS ticket-ring recovery temporary reappeared during cleanup.',
            );
        }
        if ($synchronize) {
            $this->syncDirectory();
        }
    }

    private function assertPublicationCapacity(
        int $rawEntries,
        int $ringCount,
        bool $targetPresent,
    ): void {
        if ($rawEntries >= self::MAX_RAW_DIRECTORY_ENTRIES) {
            throw new \RuntimeException(
                'TLS ticket-ring raw entry quota cannot admit a publication temporary.',
            );
        }
        if (!$targetPresent && $ringCount >= self::MAX_RING_FILES) {
            throw new \RuntimeException(
                'TLS ticket-ring ring quota cannot admit another committed snapshot.',
            );
        }
    }

    private function assertTemporaryPath(string $path): void
    {
        if (!\hash_equals($this->directory, \dirname($path))
            || \preg_match(
                '/\A\.ring\.[a-f0-9]{24}\.tmp\z/D',
                \basename($path),
            ) !== 1
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring cleanup target is not a bounded temporary leaf.',
            );
        }
    }

    /**
     * @param array<int|string,int>|null $expectedStatus
     * @return array<int|string,int>|null
     */
    private function assertExpectedTargetState(
        string $path,
        ?array $expectedStatus,
    ): ?array {
        $current = @\lstat($path);
        if ($expectedStatus === null) {
            if (\is_array($current) || \file_exists($path) || \is_link($path)) {
                throw new \RuntimeException(
                    'TLS ticket-ring target appeared and is not the expected regular generation.',
                );
            }
            return null;
        }
        if (!\is_array($current)) {
            throw new \RuntimeException(
                'TLS ticket-ring target disappeared before publication.',
            );
        }
        $this->assertSecureRegularPath(
            $path,
            $current,
            'snapshot',
            self::RECORD_BYTES,
            self::RECORD_BYTES,
        );
        if (!$this->sameFileState($expectedStatus, $current)) {
            throw new \RuntimeException(
                'TLS ticket-ring target changed before publication.',
            );
        }
        return $current;
    }

    /**
     * @param array<int|string,int> $stat
     */
    private function assertSecureRegularPath(
        string $path,
        array $stat,
        string $label,
        int $minimumBytes,
        int $maximumBytes,
    ): void {
        if ((((int)($stat['mode'] ?? 0) & 0170000) !== 0100000)
            || \is_link($path)
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring ' . $label . ' must be a regular file.',
            );
        }
        if ((int)($stat['nlink'] ?? 0) !== 1) {
            throw new \RuntimeException(
                'TLS ticket-ring ' . $label . ' must be a single-link regular file.',
            );
        }
        if (((int)$stat['mode'] & 0777) !== 0600) {
            throw new \RuntimeException(
                'TLS ticket-ring ' . $label . ' must use mode 0600.',
            );
        }
        $this->assertOwner($stat, $label);
        $size = (int)($stat['size'] ?? -1);
        if ($minimumBytes < 0
            || $maximumBytes < $minimumBytes
            || $size < $minimumBytes
            || $size > $maximumBytes
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring ' . $label . ' size is invalid.',
            );
        }
    }

    /**
     * @param resource $handle
     * @param array<int|string,int> $pathStat
     */
    private function assertOpenedPath($handle, array $pathStat, string $label): void
    {
        $opened = @\fstat($handle);
        if (!\is_array($opened)
            || !$this->sameFileState($opened, $pathStat)
        ) {
            throw new \RuntimeException('TLS ticket-ring ' . $label . ' changed while opening.');
        }
    }

    /** @param resource $handle */
    private function protectOpenedFile($handle, string $path, string $label): void
    {
        $protected = \function_exists('fchmod')
            ? @\fchmod($handle, 0600)
            : @\chmod($path, 0600);
        if (!$protected) {
            throw new \RuntimeException(
                'Unable to protect the TLS ticket-ring ' . $label . '.',
            );
        }
        $pathStat = @\lstat($path);
        if (!\is_array($pathStat)) {
            throw new \RuntimeException(
                'TLS ticket-ring ' . $label . ' disappeared while being protected.',
            );
        }
        $this->assertSecureRegularPath($path, $pathStat, $label, 0, 0);
        $this->assertOpenedPath($handle, $pathStat, $label);
    }

    /** @param resource $lock */
    private function assertStoreLockHandle($lock): void
    {
        if (!\is_resource($lock)) {
            throw new \RuntimeException('TLS ticket-ring store lock handle is invalid.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . self::LOCK_LEAF;
        $status = @\lstat($path);
        if (!\is_array($status)) {
            throw new \RuntimeException('TLS ticket-ring store lock disappeared.');
        }
        $this->assertSecureRegularPath($path, $status, 'lock', 0, 0);
        $this->assertOpenedPath($lock, $status, 'lock');
    }

    /** @param array<int|string,int> $stat */
    private function assertSecureDirectoryStatus(array $stat): void
    {
        if ((((int)($stat['mode'] ?? 0) & 0170000) !== 0040000)
            || (((int)($stat['mode'] ?? 0) & 0777) !== 0700)
            || \is_link($this->directory)
        ) {
            throw new \RuntimeException(
                'TLS ticket-ring directory must remain an owned 0700 directory.',
            );
        }
        $this->assertOwner($stat, 'directory');
    }

    /**
     * @param array<int|string,int> $left
     * @param array<int|string,int> $right
     */
    private function sameFileState(array $left, array $right): bool
    {
        foreach ([
            'dev',
            'ino',
            'mode',
            'nlink',
            'uid',
            'gid',
            'size',
            'mtime',
            'ctime',
        ] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int|string,int> $left
     * @param array<int|string,int> $right
     */
    private function sameObjectIdentity(array $left, array $right): bool
    {
        return (int)($left['dev'] ?? -1) === (int)($right['dev'] ?? -2)
            && (int)($left['ino'] ?? -1) === (int)($right['ino'] ?? -2)
            && (int)($left['uid'] ?? -1) === (int)($right['uid'] ?? -2);
    }

    /**
     * @param array<int|string,int> $left
     * @param array<int|string,int> $right
     */
    private function sameDirectoryIdentity(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int|string,int> $left
     * @param array<int|string,int> $right
     */
    private function sameDirectoryState(array $left, array $right): bool
    {
        foreach ([
            'dev',
            'ino',
            'mode',
            'nlink',
            'uid',
            'gid',
            'size',
            'mtime',
            'ctime',
        ] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int|string,int> $stat
     */
    private function assertOwner(array $stat, string $label): void
    {
        $effectiveUid = \function_exists('posix_geteuid')
            ? (int)\posix_geteuid()
            : (int)\getmyuid();
        if ((int)($stat['uid'] ?? -1) !== $effectiveUid) {
            throw new \RuntimeException('TLS ticket-ring ' . $label . ' owner is invalid.');
        }
    }

    private function packUint64(int $value): string
    {
        if ($value < 0) {
            throw new \RuntimeException('TLS ticket-ring unsigned integer is invalid.');
        }

        return \pack('N2', ($value >> 32) & 0xffffffff, $value & 0xffffffff);
    }

    private function unpackUint64(string $value): int
    {
        $parts = \unpack('Nhigh/Nlow', $value);
        $high = (int)($parts['high'] ?? 0);
        $low = (int)($parts['low'] ?? 0);

        return ($high << 32) | $low;
    }

    private function syncDirectory(): void
    {
        if (!\function_exists('fsync')) {
            return;
        }
        $before = @\lstat($this->directory);
        if (!\is_array($before)) {
            throw new \RuntimeException(
                'TLS ticket-ring directory disappeared before synchronization.',
            );
        }
        $this->assertSecureDirectoryStatus($before);
        $directory = @\fopen($this->directory, 'rb');
        if (!\is_resource($directory)) {
            throw new \RuntimeException(
                'Unable to open the TLS ticket-ring directory for synchronization.',
            );
        }
        try {
            $opened = @\fstat($directory);
            if (!\is_array($opened)
                || !$this->sameDirectoryIdentity($before, $opened)
                || !@\fsync($directory)
            ) {
                throw new \RuntimeException(
                    'Unable to synchronize the TLS ticket-ring directory.',
                );
            }
            $after = @\lstat($this->directory);
            if (!\is_array($after)
                || !$this->sameDirectoryIdentity($opened, $after)
            ) {
                throw new \RuntimeException(
                    'TLS ticket-ring directory changed during synchronization.',
                );
            }
        } finally {
            @\fclose($directory);
        }
    }

    /** @param-out string $value */
    private static function wipeString(string &$value): void
    {
        if ($value === '') {
            return;
        }
        if (\function_exists('sodium_memzero')) {
            // sodium_memzero() deliberately nulls its by-reference argument;
            // the next statement restores this method's declared string output.
            // @phpstan-ignore paramOut.type
            \sodium_memzero($value);
            $value = '';
            return;
        }
        $value = \str_repeat("\0", \strlen($value));
        $value = '';
    }
}
