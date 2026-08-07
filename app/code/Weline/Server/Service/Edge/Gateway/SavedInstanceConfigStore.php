<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\App\Env;

/**
 * The single locked writer for remembered server instance configuration.
 *
 * @phpstan-type FieldState array{exists:bool,value:mixed}
 */
final class SavedInstanceConfigStore
{
    private const MAXIMUM_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly ?string $configDirectory = null)
    {
    }

    /**
     * Read one complete saved generation under the same lock used by writers.
     * Missing is the only condition represented as null; unsafe, torn, or
     * malformed files fail closed instead of silently discarding user intent.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $instanceName): ?array
    {
        $file = $this->file($instanceName);
        $directory = \dirname($file);
        if (!\file_exists($file)
            && !\is_link($file)
            && !\file_exists($directory)
            && !\is_link($directory)
        ) {
            return null;
        }
        $canonical = \realpath($directory);
        $directoryStatus = @\lstat($directory);
        if (!\is_string($canonical)
            || !\is_array($directoryStatus)
            || \is_link($directory)
            || !self::sameCanonicalDirectoryPath($canonical, $directory)
            || ((((int)($directoryStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Saved instance configuration directory is unsafe.');
        }
        $hasRecoveryBackup = false;
        if (!\file_exists($file) && !\is_link($file)) {
            $hasRecoveryBackup = GatewayProjectStateFilesystem::hasAtomicWriteRecoveryBackups(
                $file,
                self::MAXIMUM_BYTES,
                'Saved instance configuration',
            );
            $lock = $file . '.lock';
            if (!$hasRecoveryBackup && !\file_exists($lock) && !\is_link($lock)) {
                // Linearize before a future writer creates its lock. Creating
                // a lock for a never-saved instance would leave durable state
                // merely because server:start inspected defaults.
                return null;
            }
        }
        return $this->withLock($file, function () use ($file): ?array {
            if (!\file_exists($file) && !\is_link($file)) {
                return null;
            }
            [$config] = $this->read($file, true);
            return $config;
        });
    }

    /**
     * @template TResult
     * @param \Closure(array<string,mixed>):array{0:array<string,mixed>,1:TResult} $mutator
     * @return TResult
     */
    public function update(
        string $instanceName,
        \Closure $mutator,
        bool $requireExisting = false,
    ): mixed {
        $file = $this->file($instanceName);
        return $this->withLock(
            $file,
            function () use ($file, $mutator, $requireExisting): mixed {
                [$config, $metadata] = $this->read($file, $requireExisting);
                $mutation = $mutator($config);
                if (!\is_array($mutation)
                    || \array_keys($mutation) !== [0, 1]
                    || !\is_array($mutation[0])
                ) {
                    throw new \RuntimeException(
                        'Saved instance configuration mutator returned an invalid result.'
                    );
                }
                $encoded = \json_encode(
                    $mutation[0],
                    JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_THROW_ON_ERROR,
                );
                if (\strlen($encoded) + 1 > self::MAXIMUM_BYTES) {
                    throw new \RuntimeException(
                        'Saved instance configuration exceeds its fixed size limit.'
                    );
                }
                $this->write($file, $encoded . "\n", $metadata);
                return $mutation[1];
            },
        );
    }

    /**
     * Restore only fields still equal to this transaction's after-image.
     * Concurrently changed fields are retained and reported as conflicts.
     *
     * @param array<string,FieldState> $before
     * @param array<string,FieldState> $after
     * @return array{restored:list<string>,conflicts:list<string>}
     */
    public function restoreOwnedFields(
        string $instanceName,
        array $before,
        array $after,
    ): array {
        if ($before === [] || \array_keys($before) !== \array_keys($after)) {
            throw new \RuntimeException('Saved instance configuration CAS snapshot is invalid.');
        }
        return $this->update(
            $instanceName,
            static function (array $config) use ($before, $after): array {
                $restored = [];
                $conflicts = [];
                foreach ($before as $field => $beforeState) {
                    $afterState = $after[$field] ?? null;
                    self::assertFieldState($field, $beforeState);
                    self::assertFieldState($field, $afterState);
                    $currentExists = \array_key_exists($field, $config);
                    $currentValue = $config[$field] ?? null;
                    if ($currentExists !== $afterState['exists']
                        || ($currentExists && $currentValue !== $afterState['value'])
                    ) {
                        $conflicts[] = $field;
                        continue;
                    }
                    if ($beforeState['exists']) {
                        $config[$field] = $beforeState['value'];
                    } else {
                        unset($config[$field]);
                    }
                    $restored[] = $field;
                }
                return [$config, ['restored' => $restored, 'conflicts' => $conflicts]];
            },
            true,
        );
    }

    public function file(string $instanceName): string
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \RuntimeException('Saved instance configuration name is invalid.');
        }
        $directory = $this->configDirectory
            ?? Env::VAR_DIR . 'server' . DS . 'config';
        return \rtrim($directory, '/\\') . DIRECTORY_SEPARATOR
            . $instanceName . '.json';
    }

    /** @param mixed $state */
    private static function assertFieldState(string $field, mixed $state): void
    {
        if (!\is_string($field)
            || $field === ''
            || \strlen($field) > 128
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $field) !== 1
            || !\is_array($state)
            || \array_keys($state) !== ['exists', 'value']
            || !\is_bool($state['exists'] ?? null)
        ) {
            throw new \RuntimeException('Saved instance configuration CAS field is invalid.');
        }
    }

    /**
     * @return array{0:array<string,mixed>,1:array{mode:int,uid:int,gid:int}}
     */
    private function read(string $file, bool $requireExisting): array
    {
        if (!\file_exists($file) && !\is_link($file)) {
            if ($requireExisting) {
                throw new \RuntimeException('Saved instance configuration is missing.');
            }
            $directory = @\lstat(\dirname($file));
            if (!\is_array($directory)) {
                throw new \RuntimeException('Saved instance configuration directory is missing.');
            }
            return [[], [
                'mode' => 0644,
                'uid' => (int)($directory['uid'] ?? -1),
                'gid' => (int)($directory['gid'] ?? -1),
            ]];
        }
        $before = @\lstat($file);
        if (!\is_array($before)
            || \is_link($file)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)($before['size'] ?? -1) > self::MAXIMUM_BYTES
        ) {
            throw new \RuntimeException('Saved instance configuration is linked or special.');
        }
        $content = GatewayProjectStateFilesystem::read(
            $file,
            self::MAXIMUM_BYTES,
            'Saved instance configuration',
        );
        $after = @\lstat($file);
        if (!\is_array($after) || !$this->sameStatus($before, $after)) {
            throw new \RuntimeException('Saved instance configuration changed while being read.');
        }
        $document = \json_decode($content, false, 64, JSON_THROW_ON_ERROR);
        $config = \json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!$document instanceof \stdClass || !\is_array($config)) {
            throw new \RuntimeException('Saved instance configuration is invalid.');
        }
        return [$config, [
            'mode' => ((int)$after['mode']) & 0777,
            'uid' => (int)($after['uid'] ?? -1),
            'gid' => (int)($after['gid'] ?? -1),
        ]];
    }

    /** @param array{mode:int,uid:int,gid:int} $metadata */
    private function write(string $file, string $contents, array $metadata): void
    {
        $mode = $metadata['mode'] > 0 ? ($metadata['mode'] & 0777) : 0644;
        $uid = $metadata['uid'];
        $gid = $metadata['gid'];
        if ($contents === '' || (\PHP_OS_FAMILY !== 'Windows' && ($uid < 0 || $gid < 0))) {
            throw new \RuntimeException('Saved instance configuration metadata is invalid.');
        }
        $seal = null;
        if (\PHP_OS_FAMILY !== 'Windows') {
            $seal = static function ($handle, string $path) use ($uid, $gid): void {
                $ownerOk = \function_exists('fchown')
                    ? @\fchown($handle, $uid)
                    : @\chown($path, $uid);
                $groupOk = \function_exists('fchgrp')
                    ? @\fchgrp($handle, $gid)
                    : @\chgrp($path, $gid);
                if (!$ownerOk || !$groupOk) {
                    throw new \RuntimeException(
                        'Unable to preserve saved instance configuration ownership.'
                    );
                }
            };
        }
        GatewayProjectStateFilesystem::atomicWrite($file, $contents, $mode, $seal);
        $published = @\lstat($file);
        if (!\is_array($published)
            || \is_link($file)
            || ((((int)($published['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($published['nlink'] ?? 0) !== 1
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)$published['mode']) & 0777) !== $mode
                    || (int)($published['uid'] ?? -1) !== $uid
                    || (int)($published['gid'] ?? -1) !== $gid))
        ) {
            throw new \RuntimeException('Published saved instance configuration is unsafe.');
        }
    }

    private function withLock(string $file, \Closure $operation): mixed
    {
        $directory = \dirname($file);
        $canonical = \realpath($directory);
        if (!\is_string($canonical)
            || !\is_dir($canonical)
            || \is_link($directory)
            || !self::sameCanonicalDirectoryPath($canonical, $directory)
        ) {
            throw new \RuntimeException('Saved instance configuration directory is unsafe.');
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Saved instance configuration directory is special.');
        }
        $uid = (int)($status['uid'] ?? -1);
        $gid = (int)($status['gid'] ?? -1);
        $seal = null;
        if (\PHP_OS_FAMILY !== 'Windows') {
            if ($uid < 0 || $gid < 0) {
                throw new \RuntimeException(
                    'Saved instance configuration directory ownership is unavailable.'
                );
            }
            $seal = static function ($handle, string $path) use ($uid, $gid): void {
                $ownerOk = \function_exists('fchown')
                    ? @\fchown($handle, $uid)
                    : @\chown($path, $uid);
                $groupOk = \function_exists('fchgrp')
                    ? @\fchgrp($handle, $gid)
                    : @\chgrp($path, $gid);
                if (!$ownerOk || !$groupOk) {
                    throw new \RuntimeException('Unable to preserve saved instance lock ownership.');
                }
            };
        }
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $file . '.lock',
            function () use ($file, $operation): mixed {
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $file,
                    self::MAXIMUM_BYTES,
                    'Saved instance configuration',
                    function (string $contents) use ($file): void {
                        unset($contents);
                        $this->read($file, true);
                    },
                );
                return $operation();
            },
            $seal,
        );
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameStatus(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
            if (!\array_key_exists($key, $before)
                || !\array_key_exists($key, $after)
                || (int)$before[$key] !== (int)$after[$key]
            ) {
                return false;
            }
        }
        return true;
    }

    private static function sameCanonicalDirectoryPath(
        string $canonical,
        string $requested,
    ): bool {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return \hash_equals(
                \rtrim($canonical, '/'),
                \rtrim($requested, '/'),
            );
        }
        $normalize = static function (string $path): string {
            $path = \str_replace('/', '\\', \rtrim($path, '/\\'));
            if (\str_starts_with($path, '\\\\?\\UNC\\')) {
                $path = '\\\\' . \substr($path, 8);
            } elseif (\str_starts_with($path, '\\\\?\\')) {
                $path = \substr($path, 4);
            }
            // Windows drive letters and ordinary NTFS names are
            // case-insensitive. Unicode folding is used when available; an
            // unavailable fold can only reject an unusual casing, never make
            // a different path pass the no-follow directory fence.
            return \function_exists('mb_strtolower')
                ? \mb_strtolower($path, 'UTF-8')
                : \strtolower($path);
        };

        return \hash_equals($normalize($canonical), $normalize($requested));
    }
}
