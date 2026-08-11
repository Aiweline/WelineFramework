<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Bounded, no-follow traversal for host-derived WLS trees.
 *
 * The complete walk is collected before callers mutate ownership or remove a
 * tree, so a late depth/entry/link violation cannot leave a partially changed
 * active or rollback slot. Directory handles are compared with their path
 * identity before and after enumeration to narrow name-swap races.
 */
final class GatewayBoundedTreeWalker
{
    public const MAX_ENTRIES = 8192;
    public const MAX_DEPTH = 64;
    private const HARD_MAX_ENTRIES = 65_536;
    private const MAX_PATH_BYTES = 32768;

    /**
     * @return list<array{
     *   path:string,
     *   depth:int,
     *   directory:bool,
     *   executable:bool,
     *   device:string,
     *   inode:string
     * }>
     */
    public static function collect(
        string $root,
        bool $includeRoot = false,
        bool $childFirst = false,
        int $maximumEntries = self::MAX_ENTRIES,
        int $maximumDepth = self::MAX_DEPTH,
        ?\Closure $progress = null,
    ): array {
        if ($maximumEntries < 1
            || $maximumEntries > self::HARD_MAX_ENTRIES
            || $maximumDepth < 1
            || $maximumDepth > self::MAX_DEPTH
        ) {
            throw new \InvalidArgumentException(
                'Gateway bounded tree limits are outside the supported envelope.'
            );
        }
        $root = \rtrim($root, '/\\');
        if ($root === '' || \strlen($root) > self::MAX_PATH_BYTES) {
            throw new \RuntimeException('Gateway bounded tree root is invalid.');
        }
        $rootStatus = @\lstat($root);
        if (!self::isDirectoryStatus($rootStatus) || \is_link($root)) {
            throw new \RuntimeException(
                'Gateway bounded tree root is missing, linked, or special: ' . $root
            );
        }

        $rootRecord = self::record($root, 0, $rootStatus);
        $records = [];
        if ($includeRoot) {
            $records[] = $rootRecord;
        }
        $stack = [[
            'path' => $root,
            'depth' => 0,
            'status' => $rootStatus,
            'record' => $rootRecord,
        ]];
        $visited = 0;
        while ($stack !== []) {
            /** @var array{
             *   path:string,
             *   depth:int,
             *   status:array<string|int,mixed>,
             *   record:array<string,mixed>
             * } $node
             */
            $node = \array_pop($stack);
            $directory = $node['path'];
            $handle = @\opendir($directory);
            if (!\is_resource($handle)) {
                throw new \RuntimeException(
                    'Gateway bounded tree directory cannot be opened: ' . $directory
                );
            }
            try {
                $current = @\lstat($directory);
                $currentRecord = \is_array($current)
                    ? self::record($directory, $node['depth'], $current)
                    : null;
                $directoryChanged = !\is_array($currentRecord)
                    || !self::sameRecordIdentity(
                        $node['record'],
                        $currentRecord,
                    )
                    || \is_link($directory);
                if (\PHP_OS_FAMILY !== 'Windows') {
                    // macOS/BSD: DIR* from opendir() is not a usable fstat(2)
                    // target in PHP (fstat returns false). Keep the lstat fence
                    // above and only add the opened-handle check when fstat works.
                    $opened = @\fstat($handle);
                    if (\is_array($opened)) {
                        $directoryChanged = $directoryChanged
                            || !self::sameDirectoryIdentity(
                                $node['status'],
                                $opened,
                            );
                    }
                }
                if ($directoryChanged) {
                    throw new \RuntimeException(
                        'Gateway bounded tree directory identity changed: ' . $directory
                    );
                }
                while (($leaf = @\readdir($handle)) !== false) {
                    if ($leaf === '.' || $leaf === '..') {
                        continue;
                    }
                    if (($visited & 255) === 0 && $progress !== null) {
                        $progress();
                    }
                    if (++$visited > $maximumEntries) {
                        throw new \RuntimeException(
                            'Gateway bounded tree exceeds its fixed entry safety limit.'
                        );
                    }
                    $depth = $node['depth'] + 1;
                    if ($depth > $maximumDepth) {
                        throw new \RuntimeException(
                            'Gateway bounded tree exceeds its fixed depth safety limit.'
                        );
                    }
                    $path = $directory . DIRECTORY_SEPARATOR . $leaf;
                    if (\strlen($path) > self::MAX_PATH_BYTES) {
                        throw new \RuntimeException(
                            'Gateway bounded tree path exceeds the fixed path limit.'
                        );
                    }
                    $status = @\lstat($path);
                    if (!\is_array($status)
                        || \is_link($path)
                        || (!self::isDirectoryStatus($status)
                            && !self::isRegularStatus($status))
                        || (self::isRegularStatus($status)
                            && (int)($status['nlink'] ?? 0) !== 1)
                    ) {
                        throw new \RuntimeException(
                            'Gateway bounded tree contains a link or special file: ' . $path
                        );
                    }
                    $record = self::record($path, $depth, $status);
                    $records[] = $record;
                    if ($record['directory']) {
                        $stack[] = [
                            'path' => $path,
                            'depth' => $depth,
                            'status' => $status,
                            'record' => $record,
                        ];
                    }
                }
                $after = @\lstat($directory);
                $afterRecord = \is_array($after)
                    ? self::record($directory, $node['depth'], $after)
                    : null;
                if (!\is_array($afterRecord)
                    || !self::sameRecordIdentity(
                        $node['record'],
                        $afterRecord,
                    )
                    || \is_link($directory)
                ) {
                    throw new \RuntimeException(
                        'Gateway bounded tree directory changed during enumeration: '
                            . $directory
                    );
                }
            } finally {
                @\closedir($handle);
            }
        }

        if ($childFirst) {
            \usort(
                $records,
                static fn (array $left, array $right): int =>
                    $right['depth'] <=> $left['depth'],
            );
        }
        return $records;
    }

    /**
     * Return the native no-follow identity of one regular file or directory
     * without traversing its children.
     *
     * @return array{path:string,depth:int,directory:bool,executable:bool,device:string,inode:string}
     */
    public static function identity(
        string $path,
        bool $allowHardlinkedRegular = false,
    ): array
    {
        $status = @\lstat($path);
        $directory = self::isDirectoryStatus($status);
        $regular = self::isRegularStatus($status);
        if (!\is_array($status)
            || \is_link($path)
            || (!$directory && !$regular)
            || ($regular
                && !$allowHardlinkedRegular
                && (int)($status['nlink'] ?? 0) !== 1)
        ) {
            throw new \RuntimeException(
                'Gateway bounded identity target is missing, linked, or special: '
                    . $path,
            );
        }
        return self::record(
            $path,
            0,
            $status,
            $allowHardlinkedRegular,
        );
    }

    /**
     * Revalidate a collected name immediately before a path-based mutation.
     *
     * PHP does not expose portable openat/fchmodat primitives. Retaining and
     * comparing the filesystem identity still prevents an already-observed
     * entry from being silently replaced between the bounded preflight and a
     * caller's ownership, mode, or removal operation.
     *
     * @param array{
     *   path:string,
     *   depth:int,
     *   directory:bool,
     *   executable:bool,
     *   device:string,
     *   inode:string
     * } $record
     * @return array<string|int,mixed>
     */
    public static function revalidate(array $record): array
    {
        $path = $record['path'];
        $status = @\lstat($path);
        $directory = self::isDirectoryStatus($status);
        $regular = self::isRegularStatus($status);
        $identity = \is_array($status)
            && ($directory || $regular)
            && !\is_link($path)
            ? self::filesystemIdentity($path, $status, $directory)
            : null;
        if (!\is_array($status)
            || \is_link($path)
            || (!$directory && !$regular)
            || ($regular && (int)($status['nlink'] ?? 0) !== 1)
            || $directory !== $record['directory']
            || !\is_array($identity)
            || !\hash_equals($record['device'], $identity['device'])
            || !\hash_equals($record['inode'], $identity['inode'])
        ) {
            throw new \RuntimeException(
                'Gateway bounded tree entry identity changed after preflight: ' . $path
            );
        }
        return $status;
    }

    /**
     * @param array<string|int,mixed> $status
     * @return array{
     *   path:string,
     *   depth:int,
     *   directory:bool,
     *   executable:bool,
     *   device:string,
     *   inode:string
     * }
     */
    private static function record(
        string $path,
        int $depth,
        array $status,
        bool $allowHardlinkedRegular = false,
    ): array
    {
        $directory = self::isDirectoryStatus($status);
        $identity = self::filesystemIdentity(
            $path,
            $status,
            $directory,
            $allowHardlinkedRegular,
        );
        return [
            'path' => $path,
            'depth' => $depth,
            'directory' => $directory,
            'executable' => (((int)($status['mode'] ?? 0)) & 0111) !== 0,
            'device' => $identity['device'],
            'inode' => $identity['inode'],
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameRecordIdentity(array $left, array $right): bool
    {
        return $left['directory'] === $right['directory']
            && \hash_equals((string)$left['device'], (string)$right['device'])
            && \hash_equals((string)$left['inode'], (string)$right['inode']);
    }

    /**
     * @param array<string|int,mixed> $status
     * @return array{device:string,inode:string}
     */
    private static function filesystemIdentity(
        string $path,
        array $status,
        bool $directory,
        bool $allowHardlinkedRegular = false,
    ): array {
        if (\PHP_OS_FAMILY === 'Windows') {
            return self::windowsFilesystemIdentity(
                $path,
                $directory,
                $allowHardlinkedRegular,
            );
        }
        if (!\array_key_exists('dev', $status)
            || !\array_key_exists('ino', $status)
        ) {
            throw new \RuntimeException(
                'Gateway bounded tree entry has no stable filesystem identity: ' . $path
            );
        }
        return [
            'device' => (string)$status['dev'],
            'inode' => (string)$status['ino'],
        ];
    }

    /** @return array{device:string,inode:string} */
    private static function windowsFilesystemIdentity(
        string $path,
        bool $directory,
        bool $allowHardlinkedRegular = false,
    ): array {
        $ffi = self::windowsKernel32();
        $widePath = self::windowsWidePath($ffi, $path);
        $handle = $ffi->CreateFileW(
            $widePath,
            0x00000080,
            0x00000007,
            null,
            3,
            0x02200000,
            null,
        );
        $information = $ffi->new('BY_HANDLE_FILE_INFORMATION');
        $result = null;
        $failure = null;
        try {
            if ((int)$ffi->GetFileInformationByHandle(
                $handle,
                \FFI::addr($information),
            ) === 0) {
                throw new \RuntimeException(
                    'Gateway bounded Windows entry cannot be opened without following: '
                        . $path
                );
            }
            $attributes = (int)$information->dwFileAttributes;
            $isDirectory = ($attributes & 0x00000010) !== 0;
            $indexHigh = (int)$information->nFileIndexHigh;
            $indexLow = (int)$information->nFileIndexLow;
            if (($attributes & 0x00000400) !== 0
                || $directory !== $isDirectory
                || (!$directory
                    && !$allowHardlinkedRegular
                    && (int)$information->nNumberOfLinks !== 1)
                || ($indexHigh === 0 && $indexLow === 0)
            ) {
                throw new \RuntimeException(
                    'Gateway bounded Windows entry is linked, special, or has no stable file ID: '
                        . $path
                );
            }
            $result = [
                'device' => \sprintf(
                    '%08x',
                    (int)$information->dwVolumeSerialNumber,
                ),
                'inode' => \sprintf('%08x%08x', $indexHigh, $indexLow),
            ];
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }
        $closeFailure = null;
        try {
            if ((int)$ffi->CloseHandle($handle) === 0) {
                $closeFailure = new \RuntimeException(
                    'Gateway bounded Windows identity handle did not close cleanly.',
                );
            }
        } catch (\Throwable $throwable) {
            $closeFailure = new \RuntimeException(
                'Gateway bounded Windows identity handle close failed.',
                0,
                $throwable,
            );
        }
        if ($closeFailure !== null) {
            throw new \RuntimeException(
                $closeFailure->getMessage(),
                0,
                $failure ?? $closeFailure->getPrevious(),
            );
        }
        if ($failure !== null) {
            throw $failure;
        }
        if (!\is_array($result)) {
            throw new \RuntimeException(
                'Gateway bounded Windows identity result was not produced.',
            );
        }
        return $result;
    }

    private static function windowsKernel32(): \FFI
    {
        static $ffi = null;
        if ($ffi instanceof \FFI) {
            return $ffi;
        }
        if (!\class_exists(\FFI::class)) {
            throw new \RuntimeException(
                'Gateway bounded Windows traversal requires PHP FFI.'
            );
        }
        try {
            $ffi = \FFI::cdef(
                'typedef int BOOL; typedef unsigned long DWORD;'
                    . ' typedef unsigned short WCHAR; typedef void* HANDLE;'
                    . ' typedef struct {'
                    . ' DWORD dwLowDateTime; DWORD dwHighDateTime;'
                    . ' } FILETIME;'
                    . ' typedef struct {'
                    . ' DWORD dwFileAttributes; FILETIME ftCreationTime;'
                    . ' FILETIME ftLastAccessTime; FILETIME ftLastWriteTime;'
                    . ' DWORD dwVolumeSerialNumber; DWORD nFileSizeHigh;'
                    . ' DWORD nFileSizeLow; DWORD nNumberOfLinks;'
                    . ' DWORD nFileIndexHigh; DWORD nFileIndexLow;'
                    . ' } BY_HANDLE_FILE_INFORMATION;'
                    . ' HANDLE CreateFileW(const WCHAR*, DWORD, DWORD, void*,'
                    . ' DWORD, DWORD, HANDLE);'
                    . ' BOOL GetFileInformationByHandle('
                    . ' HANDLE, BY_HANDLE_FILE_INFORMATION*);'
                    . ' BOOL CloseHandle(HANDLE);',
                'kernel32.dll',
            );
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Gateway bounded Windows file-ID verifier is unavailable.',
                0,
                $throwable,
            );
        }
        return $ffi;
    }

    /** @return \FFI\CData */
    private static function windowsWidePath(\FFI $ffi, string $path): \FFI\CData
    {
        $encoded = @\iconv('UTF-8', 'UTF-16LE', $path . "\0");
        if (!\is_string($encoded)
            || $encoded === ''
            || (\strlen($encoded) % 2) !== 0
        ) {
            throw new \RuntimeException(
                'Gateway bounded Windows path cannot be encoded as UTF-16LE.'
            );
        }
        $buffer = $ffi->new('WCHAR[' . (int)(\strlen($encoded) / 2) . ']');
        \FFI::memcpy($buffer, $encoded, \strlen($encoded));
        return $buffer;
    }

    /** @param array<string|int,mixed>|false $status */
    private static function isDirectoryStatus(array|false $status): bool
    {
        return \is_array($status)
            && ((((int)($status['mode'] ?? 0)) & 0170000) === 0040000);
    }

    /** @param array<string|int,mixed>|false $status */
    private static function isRegularStatus(array|false $status): bool
    {
        return \is_array($status)
            && ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000);
    }

    /**
     * @param array<string|int,mixed>|false $expected
     * @param array<string|int,mixed>|false $actual
     */
    private static function sameDirectoryIdentity(
        array|false $expected,
        array|false $actual,
    ): bool {
        if (!self::isDirectoryStatus($expected) || !self::isDirectoryStatus($actual)) {
            return false;
        }
        foreach (['dev', 'ino'] as $field) {
            if (!\array_key_exists($field, $expected)
                || !\array_key_exists($field, $actual)
                || (string)$expected[$field] !== (string)$actual[$field]
            ) {
                return false;
            }
        }
        return true;
    }
}
