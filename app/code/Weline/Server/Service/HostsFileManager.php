<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Service\Runtime\VerifiedPersistentFileLock;

/**
 * Manage the uniquely owned WLS block in a hosts file.
 *
 * This service never acquires operating-system authority itself. Direct
 * writes require a regular, single-link target owned by the effective POSIX
 * identity. The normal process is non-root; the fixed privileged editor may
 * call the same transaction as root only after sudo has authenticated it.
 */
class HostsFileManager
{
    public const LOOPBACK_IPV4 = '127.0.0.1';

    private const MARKER_START = '# Weline WLS Auto-Config Start';
    private const MARKER_END = '# Weline WLS Auto-Config End';
    private const OPERATION_UPSERT = 'upsert';
    private const OPERATION_REMOVE = 'remove';
    private const MAX_HOSTS_BYTES = 1_048_576;
    private const HOST_LOCK_TIMEOUT_SECONDS = 5.0;
    private const TARGET_LOCK_TIMEOUT_SECONDS = 1.0;
    private const METADATA_COPY_TIMEOUT_SECONDS = 3.0;

    public static function getHostsFilePath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return (string)\getenv('SystemRoot') . '\\System32\\drivers\\etc\\hosts';
        }

        return '/etc/hosts';
    }

    public static function hasPermission(): bool
    {
        $path = self::getHostsFilePath();
        $status = @\lstat($path);
        $effectiveUid = self::effectiveUid();

        return \is_array($status)
            && self::safeRegularFileStatus($status)
            && !\is_link($path)
            && \is_writable($path)
            && self::directWriterIdentityAllowed($status, PHP_OS_FAMILY, $effectiveUid);
    }

    /**
     * Managed local hosts domains always resolve to loopback. Callers cannot
     * accidentally publish a LAN or public address for those suffixes.
     */
    public static function resolveIpForDomain(
        string $domain,
        string $ip = self::LOOPBACK_IPV4,
    ): string {
        $domain = \strtolower(\trim($domain));
        if ($domain !== '' && LocalDomainPolicy::requiresHostsEntry($domain)) {
            return self::LOOPBACK_IPV4;
        }

        $ip = \trim($ip);

        return $ip !== '' ? $ip : self::LOOPBACK_IPV4;
    }

    /**
     * @return array{
     *     success:bool,
     *     message:string,
     *     needs_admin?:bool,
     *     already_exists?:bool,
     *     repaired?:bool,
     *     status?:string,
     *     error_code?:string,
     *     ip?:string
     * }
     */
    public static function addDomain(
        string $domain,
        string $ip = self::LOOPBACK_IPV4,
    ): array {
        $domain = \strtolower(\trim($domain));
        $ip = self::resolveIpForDomain($domain, $ip);
        if (!self::validDomain($domain) || !self::validIp($ip)) {
            return [
                'success' => false,
                'message' => 'Invalid hosts domain or IP address.',
                'needs_admin' => false,
                'ip' => $ip,
            ];
        }

        $hostsFile = self::getHostsFilePath();
        $status = @\lstat($hostsFile);
        if (!\is_array($status)
            || !self::safeRegularFileStatus($status)
            || \is_link($hostsFile)
        ) {
            return [
                'success' => false,
                'message' => "Hosts file not found or unsafe: {$hostsFile}",
                'needs_admin' => false,
                'ip' => $ip,
            ];
        }
        $satisfiedStatus = self::inspectSatisfiedAddStatus($hostsFile, $domain, $ip);
        if ($satisfiedStatus !== null) {
            return self::addDomainSuccessResult($domain, $ip, $satisfiedStatus);
        }
        if (!\is_writable($hostsFile)
            || !self::directWriterIdentityAllowed(
                $status,
                PHP_OS_FAMILY,
                self::effectiveUid(),
            )
        ) {
            return self::permissionDeniedResult($domain, $ip);
        }

        $mutation = self::mutateHostsFile(
            $hostsFile,
            self::OPERATION_UPSERT,
            $domain,
            $ip,
        );
        if (!($mutation['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string)($mutation['message'] ?? 'Unable to update the hosts file.'),
                'needs_admin' => false,
                'error_code' => isset($mutation['error_code'])
                    ? (string)$mutation['error_code']
                    : 'HOSTS_MUTATION_FAILED',
                'ip' => $ip,
            ];
        }

        $mutationStatus = (string)($mutation['status'] ?? 'added');

        return self::addDomainSuccessResult($domain, $ip, $mutationStatus);
    }

    /**
     * Read-only satisfaction is intentionally evaluated before writability.
     * A root-owned hosts file which already contains the exact mapping needs
     * neither mutation authority nor an administrator prompt.
     */
    private static function inspectSatisfiedAddStatus(
        string $hostsFile,
        string $domain,
        string $ip,
    ): ?string {
        $canonical = self::canonicalHostsTarget($hostsFile);
        if ($canonical === null) {
            return null;
        }

        $before = @\lstat($canonical);
        $handle = @\fopen($canonical, 'rb');
        if (!\is_array($before) || !\is_resource($handle)) {
            if (\is_resource($handle)) {
                @\fclose($handle);
            }
            return null;
        }

        try {
            $opened = @\fstat($handle);
            $pathStatus = @\lstat($canonical);
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || !self::safeRegularFileStatus($opened)
                || !self::safeRegularFileStatus($pathStatus)
                || !self::sameFileIdentity($before, $opened)
                || !self::sameFileIdentity($opened, $pathStatus)
            ) {
                return null;
            }
            $content = self::readHostsHandle($handle);
            if (!\is_string($content)
                || !self::targetSnapshotStillMatches($handle, $canonical, $opened, $content)
            ) {
                return null;
            }

            $plan = self::planMutation(
                $content,
                self::OPERATION_UPSERT,
                $domain,
                $ip,
            );
            if (!($plan['success'] ?? false)
                || !\hash_equals($content, (string)($plan['content'] ?? ''))
            ) {
                return null;
            }
            $status = (string)($plan['status'] ?? '');

            return \in_array($status, ['already_exists', 'external_satisfied'], true)
                ? $status
                : null;
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function addDomainSuccessResult(
        string $domain,
        string $ip,
        string $mutationStatus,
    ): array {
        return [
            'success' => true,
            'message' => match ($mutationStatus) {
                'already_exists' => "Domain {$domain} already exists in the WLS managed block",
                'external_satisfied' => "Domain {$domain} is already satisfied by an external hosts entry",
                'repaired' => "Repaired {$domain} WLS hosts entry to {$ip}",
                default => "Added {$domain} to the WLS managed hosts block",
            },
            'needs_admin' => false,
            'already_exists' => \in_array(
                $mutationStatus,
                ['already_exists', 'external_satisfied'],
                true,
            ),
            'repaired' => $mutationStatus === 'repaired',
            'status' => $mutationStatus,
            'ip' => $ip,
        ];
    }

    /**
     * @return array{
     *     success:bool,
     *     message:string,
     *     needs_admin?:bool,
     *     already_removed?:bool,
     *     status?:string,
     *     error_code?:string
     * }
     */
    public static function removeDomain(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        if (!self::validDomain($domain)) {
            return ['success' => false, 'message' => 'Invalid hosts domain.'];
        }

        $hostsFile = self::getHostsFilePath();
        $status = @\lstat($hostsFile);
        if (!\is_array($status)
            || !self::safeRegularFileStatus($status)
            || \is_link($hostsFile)
        ) {
            return [
                'success' => false,
                'message' => "Hosts file not found or unsafe: {$hostsFile}",
            ];
        }
        if (!\is_writable($hostsFile)
            || !self::directWriterIdentityAllowed(
                $status,
                PHP_OS_FAMILY,
                self::effectiveUid(),
            )
        ) {
            return self::permissionDeniedRemovalResult($domain);
        }

        $mutation = self::mutateHostsFile(
            $hostsFile,
            self::OPERATION_REMOVE,
            $domain,
            self::LOOPBACK_IPV4,
        );
        if (!($mutation['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string)($mutation['message'] ?? 'Unable to update the hosts file.'),
                'error_code' => isset($mutation['error_code'])
                    ? (string)$mutation['error_code']
                    : 'HOSTS_MUTATION_FAILED',
            ];
        }

        $mutationStatus = (string)($mutation['status'] ?? 'removed');
        if ($mutationStatus === 'already_removed') {
            return [
                'success' => true,
                'message' => "Domain {$domain} was not present in the WLS managed block",
                'already_removed' => true,
                'status' => $mutationStatus,
            ];
        }
        if ($mutationStatus === 'external_preserved') {
            return [
                'success' => true,
                'message' => "Domain {$domain} belongs to an external hosts entry and was preserved",
                'status' => $mutationStatus,
            ];
        }

        return [
            'success' => true,
            'message' => "Removed {$domain} from the WLS managed hosts block",
            'status' => $mutationStatus,
        ];
    }

    /**
     * @return array{success:false,message:string,needs_admin:true,ip:string}
     */
    private static function permissionDeniedResult(string $domain, string $ip): array
    {
        return [
            'success' => false,
            'message' => 'Administrator authorization is required to publish '
                . "{$ip} {$domain} through the bounded WLS hosts editor.",
            'needs_admin' => true,
            'ip' => $ip,
        ];
    }

    /** @return array{success:false,message:string,needs_admin:true} */
    private static function permissionDeniedRemovalResult(string $domain): array
    {
        return [
            'success' => false,
            'message' => "WLS did not modify the system hosts file. Remove {$domain} manually "
                . "with the operating system's trusted hosts editor.",
            'needs_admin' => true,
        ];
    }

    /**
     * Mutate one domain against the latest generation while holding one stable,
     * identity-scoped lock. The lock inode is intentionally never removed.
     *
     * @return array{success:bool,message:string,status?:string,error_code?:string,warning?:string}
     */
    private static function mutateHostsFile(
        string $hostsFile,
        string $operation,
        string $domain,
        string $ip,
    ): array {
        $domain = \strtolower(\trim($domain));
        $ip = \trim($ip);
        if (!\in_array($operation, [self::OPERATION_UPSERT, self::OPERATION_REMOVE], true)
            || !self::validDomain($domain)
            || !self::validIp($ip)
        ) {
            return self::mutationFailure('Invalid hosts mutation request.', 'INVALID_MUTATION');
        }

        $canonicalPath = self::canonicalHostsTarget($hostsFile);
        if ($canonicalPath === null) {
            return self::mutationFailure(
                'Hosts target is missing, linked, or unsafe.',
                'UNSAFE_HOSTS_TARGET',
            );
        }
        $beforeLock = @\lstat($canonicalPath);
        if (!\is_array($beforeLock)
            || !self::directWriterIdentityAllowed(
                $beforeLock,
                PHP_OS_FAMILY,
                self::effectiveUid(),
            )
        ) {
            return self::mutationFailure(
                'Direct hosts mutation is restricted to a file owned by the current non-root POSIX identity.',
                'DIRECT_WRITER_IDENTITY_REJECTED',
            );
        }

        $lockPath = self::hostMutationLockPath($canonicalPath);
        if ($lockPath === '') {
            return self::mutationFailure(
                'Unable to resolve the stable hosts mutation lock.',
                'HOST_LOCK_UNAVAILABLE',
            );
        }
        $lock = VerifiedPersistentFileLock::acquire(
            $lockPath,
            self::HOST_LOCK_TIMEOUT_SECONDS,
            static fn(): array => [
                'schema' => 'wls-hosts-mutation-lock/3',
                'operation' => $operation,
                'domain' => $domain,
                'uid' => self::effectiveUid(),
                'pid' => \getmypid(),
            ],
        );
        if (!\is_resource($lock)) {
            return self::mutationFailure(
                'Timed out acquiring the stable hosts mutation lock.',
                'HOST_LOCK_TIMEOUT',
            );
        }

        try {
            $lockIdentity = @\fstat($lock);
            if (!\is_array($lockIdentity)
                || !self::stableLockIdentity($lock, $lockPath, $lockIdentity)
            ) {
                return self::mutationFailure(
                    'The stable hosts mutation lock changed identity.',
                    'HOST_LOCK_REPLACED',
                );
            }
            $lockedCanonicalPath = self::canonicalHostsTarget($hostsFile);
            if (!\is_string($lockedCanonicalPath)
                || !self::samePath($canonicalPath, $lockedCanonicalPath)
            ) {
                return self::mutationFailure(
                    'Hosts target changed while acquiring its mutation lock.',
                    'HOSTS_TARGET_REPLACED',
                );
            }

            return self::mutateHostsFileLocked(
                $lockedCanonicalPath,
                $operation,
                $domain,
                $ip,
                $lock,
                $lockPath,
                $lockIdentity,
            );
        } catch (\Throwable $throwable) {
            return self::mutationFailure(
                'Hosts mutation failed: ' . $throwable->getMessage(),
                'HOSTS_MUTATION_EXCEPTION',
            );
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @param resource $hostLock
     * @param array<string|int,mixed> $hostLockIdentity
     * @return array{success:bool,message:string,status?:string,error_code?:string,warning?:string}
     */
    private static function mutateHostsFileLocked(
        string $canonicalPath,
        string $operation,
        string $domain,
        string $ip,
        $hostLock,
        string $hostLockPath,
        array $hostLockIdentity,
    ): array {
        $before = @\lstat($canonicalPath);
        if (!\is_array($before)
            || !self::safeRegularFileStatus($before)
            || !self::directWriterIdentityAllowed(
                $before,
                PHP_OS_FAMILY,
                self::effectiveUid(),
            )
        ) {
            return self::mutationFailure(
                'Hosts target is not one safe file owned by the current identity.',
                'UNSAFE_HOSTS_TARGET',
            );
        }

        $handle = @\fopen($canonicalPath, 'rb');
        if (!\is_resource($handle)) {
            return self::mutationFailure(
                'Unable to open the latest hosts generation.',
                'HOSTS_READ_FAILED',
            );
        }
        $targetLocked = false;
        try {
            $opened = @\fstat($handle);
            $pathStatus = @\lstat($canonicalPath);
            if (!\is_array($opened)
                || !\is_array($pathStatus)
                || !self::safeRegularFileStatus($opened)
                || !self::safeRegularFileStatus($pathStatus)
                || !self::sameFileIdentity($before, $opened)
                || !self::sameFileIdentity($opened, $pathStatus)
                || !self::stableLockIdentity($hostLock, $hostLockPath, $hostLockIdentity)
            ) {
                return self::mutationFailure(
                    'Hosts target changed while it was being opened.',
                    'HOSTS_TARGET_REPLACED',
                );
            }
            $targetLocked = self::acquireTargetFileLock($handle);
            if (!$targetLocked) {
                return self::mutationFailure(
                    'Timed out acquiring the hosts file lock.',
                    'HOSTS_FILE_LOCK_TIMEOUT',
                );
            }

            $content = self::readHostsHandle($handle);
            if ($content === null) {
                return self::mutationFailure(
                    'Hosts file is unreadable or exceeds the fixed size limit.',
                    'HOSTS_CONTENT_INVALID',
                );
            }
            if (!self::targetSnapshotStillMatches(
                $handle,
                $canonicalPath,
                $opened,
                $content,
            )) {
                return self::mutationFailure(
                    'Hosts target changed while reading the latest generation.',
                    'HOSTS_TARGET_REPLACED',
                );
            }

            $plan = self::planMutation($content, $operation, $domain, $ip);
            if (!($plan['success'] ?? false)) {
                return $plan;
            }
            $newContent = (string)($plan['content'] ?? $content);
            $mutationStatus = (string)($plan['status'] ?? 'already_exists');
            if ($newContent === $content) {
                return [
                    'success' => true,
                    'message' => 'Hosts mutation is already satisfied without taking ownership of external entries.',
                    'status' => $mutationStatus,
                ];
            }
            if (\strlen($newContent) > self::MAX_HOSTS_BYTES) {
                return self::mutationFailure(
                    'The resulting hosts file exceeds the fixed size limit.',
                    'HOSTS_CONTENT_TOO_LARGE',
                );
            }
            if (!self::stableLockIdentity($hostLock, $hostLockPath, $hostLockIdentity)
                || !self::targetSnapshotStillMatches(
                    $handle,
                    $canonicalPath,
                    $opened,
                    $content,
                )
            ) {
                return self::mutationFailure(
                    'Hosts target changed before atomic publication.',
                    'HOSTS_TARGET_REPLACED',
                );
            }

            $publication = self::publishAtomically(
                $canonicalPath,
                $handle,
                $opened,
                $content,
                $newContent,
                $hostLock,
                $hostLockPath,
                $hostLockIdentity,
            );
            if (!($publication['success'] ?? false)) {
                return $publication;
            }

            $result = [
                'success' => true,
                'message' => 'Hosts domain mutation was published atomically.',
                'status' => $mutationStatus,
            ];
            if (isset($publication['warning'])) {
                $result['warning'] = (string)$publication['warning'];
            }

            return $result;
        } finally {
            if ($targetLocked) {
                @\flock($handle, LOCK_UN);
            }
            @\fclose($handle);
        }
    }

    /**
     * @param resource $targetHandle
     * @param array<string|int,mixed> $targetIdentity
     * @param resource $hostLock
     * @param array<string|int,mixed> $hostLockIdentity
     * @return array{success:bool,message:string,error_code?:string,warning?:string}
     */
    private static function publishAtomically(
        string $target,
        $targetHandle,
        array $targetIdentity,
        string $oldContent,
        string $newContent,
        $hostLock,
        string $hostLockPath,
        array $hostLockIdentity,
    ): array {
        $directory = \dirname($target);
        $directoryIdentity = @\lstat($directory);
        $effectiveUid = self::effectiveUid();
        if (!\is_array($directoryIdentity)
            || !self::safePrivateDirectoryStatus($directoryIdentity, $effectiveUid)
            || \is_link($directory)
            || !\is_writable($directory)
            || !\function_exists('fsync')
        ) {
            return self::mutationFailure(
                'The hosts parent directory cannot provide a safe same-directory staging file.',
                'ATOMIC_REPLACE_UNAVAILABLE',
            );
        }

        $directoryHandle = @\fopen($directory, 'rb');
        if (!\is_resource($directoryHandle)) {
            return self::mutationFailure(
                'Unable to open the hosts parent directory for durable publication.',
                'ATOMIC_REPLACE_UNAVAILABLE',
            );
        }
        if (!@\fsync($directoryHandle)) {
            @\fclose($directoryHandle);

            return self::mutationFailure(
                'The hosts parent directory does not support durable atomic publication.',
                'ATOMIC_REPLACE_UNAVAILABLE',
            );
        }

        $stage = '';
        $reservedHandle = false;
        $stageIdentity = null;
        $renamed = false;
        try {
            $stage = $target . '.wls-hosts-txn-' . \bin2hex(\random_bytes(16));
            $reservedHandle = @\fopen($stage, 'x+b');
            if (!\is_resource($reservedHandle)) {
                return self::mutationFailure(
                    'Unable to reserve a same-directory hosts staging file.',
                    'ATOMIC_REPLACE_UNAVAILABLE',
                );
            }
            $reservedIdentity = @\fstat($reservedHandle);
            $reservedPathIdentity = @\lstat($stage);
            if (!\is_array($reservedIdentity)
                || !\is_array($reservedPathIdentity)
                || !self::safeRegularFileStatus($reservedIdentity)
                || !self::safeRegularFileStatus($reservedPathIdentity)
                || !self::sameFileIdentity($reservedIdentity, $reservedPathIdentity)
            ) {
                return self::mutationFailure(
                    'The hosts staging reservation changed identity.',
                    'ATOMIC_REPLACE_UNAVAILABLE',
                );
            }
            @\fclose($reservedHandle);
            $reservedHandle = false;

            if (!self::cloneMetadataToStage($target, $stage)) {
                return self::mutationFailure(
                    'Unable to clone hosts permissions and extended metadata to the staging file.',
                    'ATOMIC_REPLACE_UNAVAILABLE',
                );
            }
            $observedStageIdentity = @\lstat($stage);
            $stageIdentity = \is_array($observedStageIdentity)
                ? $observedStageIdentity
                : null;
            $targetPathIdentity = @\lstat($target);
            $directoryAfterClone = @\lstat($directory);
            if ($stageIdentity === null
                || !\is_array($targetPathIdentity)
                || !\is_array($directoryAfterClone)
                || !self::safeRegularFileStatus($stageIdentity)
                || !self::sameFileIdentity($targetIdentity, $targetPathIdentity)
                || !self::sameDirectoryIdentity($directoryIdentity, $directoryAfterClone)
                || !self::safePrivateDirectoryStatus($directoryAfterClone, $effectiveUid)
                || !self::sameReplacementMetadata($targetIdentity, $stageIdentity)
                || !self::fileDigestMatches($stage, $oldContent)
            ) {
                return self::mutationFailure(
                    'The staged hosts metadata or source snapshot failed verification.',
                    'ATOMIC_REPLACE_UNAVAILABLE',
                );
            }

            $stageHandle = @\fopen($stage, 'r+b');
            if (!\is_resource($stageHandle)) {
                return self::mutationFailure(
                    'Unable to open the hosts staging file.',
                    'ATOMIC_REPLACE_UNAVAILABLE',
                );
            }
            try {
                $openedStage = @\fstat($stageHandle);
                if (!\is_array($openedStage)
                    || !self::sameFileIdentity($stageIdentity, $openedStage)
                    || !self::overwriteStageHandle($stageHandle, $newContent)
                ) {
                    return self::mutationFailure(
                        'Unable to write and synchronize the hosts staging file.',
                        'ATOMIC_REPLACE_UNAVAILABLE',
                    );
                }
                $writtenStage = @\fstat($stageHandle);
                $stagePathAfterWrite = @\lstat($stage);
                if (!\is_array($writtenStage)
                    || !\is_array($stagePathAfterWrite)
                    || !self::sameFileIdentity($stageIdentity, $writtenStage)
                    || !self::sameFileIdentity($writtenStage, $stagePathAfterWrite)
                    || !self::sameReplacementMetadata($targetIdentity, $writtenStage)
                    || (int)($writtenStage['size'] ?? -1) !== \strlen($newContent)
                ) {
                    return self::mutationFailure(
                        'The written hosts staging file failed verification.',
                        'ATOMIC_REPLACE_UNAVAILABLE',
                    );
                }
            } finally {
                @\fclose($stageHandle);
            }

            if (!self::fileDigestMatches($stage, $newContent)
                || !self::targetSnapshotStillMatches(
                    $targetHandle,
                    $target,
                    $targetIdentity,
                    $oldContent,
                )
                || !self::stableLockIdentity($hostLock, $hostLockPath, $hostLockIdentity)
            ) {
                return self::mutationFailure(
                    'Hosts target changed before the final atomic replace.',
                    'HOSTS_TARGET_REPLACED',
                );
            }
            $directoryBeforeRename = @\lstat($directory);
            if (!\is_array($directoryBeforeRename)
                || !self::sameDirectoryIdentity($directoryIdentity, $directoryBeforeRename)
                || !self::safePrivateDirectoryStatus($directoryBeforeRename, $effectiveUid)
                || \is_link($directory)
                || !@\rename($stage, $target)
            ) {
                return self::mutationFailure(
                    'The same-directory hosts atomic replace failed.',
                    'ATOMIC_REPLACE_UNAVAILABLE',
                );
            }
            $renamed = true;

            $published = @\lstat($target);
            if (!\is_array($published)
                || !self::sameFileIdentity($stageIdentity, $published)
                || !self::sameReplacementMetadata($targetIdentity, $published)
                || !self::fileDigestMatches($target, $newContent)
            ) {
                return self::mutationFailure(
                    'The atomically replaced hosts file failed verification.',
                    'ATOMIC_PUBLICATION_VERIFICATION_FAILED',
                );
            }

            if (!@\fsync($directoryHandle)) {
                return [
                    'success' => true,
                    'message' => 'Hosts file was replaced atomically, but directory durability could not be confirmed.',
                    'warning' => 'DIRECTORY_SYNC_UNCONFIRMED',
                ];
            }

            return [
                'success' => true,
                'message' => 'Hosts file was replaced atomically.',
            ];
        } finally {
            if (\is_resource($reservedHandle)) {
                @\fclose($reservedHandle);
            }
            @\fclose($directoryHandle);
            if (!$renamed && $stage !== '') {
                self::removeStageIfOwned($stage, $stageIdentity);
            }
        }
    }

    /**
     * Clone the original file into the reserved stage using the platform copy
     * primitive that preserves access rules and extended metadata. The process
     * is bounded; it never receives elevated authority and can only write the
     * already-reserved same-directory stage.
     */
    private static function cloneMetadataToStage(string $source, string $stage): bool
    {
        if (!\function_exists('proc_open')
            || !\function_exists('proc_get_status')
            || !\function_exists('proc_terminate')
            || !\function_exists('proc_close')
        ) {
            return false;
        }
        $copy = \is_executable('/bin/cp')
            ? '/bin/cp'
            : (\is_executable('/usr/bin/cp') ? '/usr/bin/cp' : '');
        if ($copy === '') {
            return false;
        }
        $command = PHP_OS_FAMILY === 'Linux'
            ? [$copy, '--preserve=all', '--no-target-directory', $source, $stage]
            : [$copy, '-p', $source, $stage];
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @\proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => ['file', $nullDevice, 'w'],
            ],
            $pipes,
            \dirname($stage),
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return false;
        }

        $deadline = self::monotonicSeconds() + self::METADATA_COPY_TIMEOUT_SECONDS;
        $exitCode = -1;
        $mustTerminate = false;
        do {
            $status = @\proc_get_status($process);
            if (!\is_array($status)) {
                $mustTerminate = true;
                break;
            }
            if (!(bool)($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (self::monotonicSeconds() >= $deadline) {
                $mustTerminate = true;
                break;
            }
            \usleep(10_000);
        } while (true);

        if ($mustTerminate && !self::terminateMetadataCopyProcess($process)) {
            return false;
        }

        $closedCode = @\proc_close($process);
        if ($exitCode < 0 && \is_int($closedCode)) {
            $exitCode = $closedCode;
        }

        return $exitCode === 0;
    }

    /** @param resource $process */
    private static function terminateMetadataCopyProcess($process): bool
    {
        @\proc_terminate($process);
        $graceDeadline = self::monotonicSeconds() + 0.25;
        do {
            $status = @\proc_get_status($process);
            if (!\is_array($status) || !(bool)($status['running'] ?? false)) {
                return true;
            }
            \usleep(10_000);
        } while (self::monotonicSeconds() < $graceDeadline);

        @\proc_terminate($process, 9);
        $killDeadline = self::monotonicSeconds() + 1.0;
        do {
            $status = @\proc_get_status($process);
            if (!\is_array($status) || !(bool)($status['running'] ?? false)) {
                return true;
            }
            \usleep(10_000);
        } while (self::monotonicSeconds() < $killDeadline);

        return false;
    }

    /**
     * @return array{success:bool,message:string,status?:string,error_code?:string,content?:string}
     */
    private static function planMutation(
        string $content,
        string $operation,
        string $domain,
        string $ip,
    ): array {
        $document = self::parseDocument($content);
        if (!($document['success'] ?? false)) {
            return self::mutationFailure(
                (string)($document['message'] ?? 'The WLS managed hosts block is invalid.'),
                (string)($document['error_code'] ?? 'MANAGED_BLOCK_INVALID'),
            );
        }

        /** @var array<string,array{line:int,ip:string}> $managed */
        $managed = $document['managed'];
        /** @var array<string,list<string>> $external */
        $external = $document['external'];
        /** @var array<string,true> $ambiguousDomains */
        $ambiguousDomains = $document['ambiguous_domains'];
        $managedEntry = $managed[$domain] ?? null;
        $externalIps = $external[$domain] ?? [];

        if (isset($ambiguousDomains[$domain])) {
            return self::mutationFailure(
                "Domain {$domain} appears in malformed external hosts content.",
                'EXTERNAL_DOMAIN_AMBIGUOUS',
            );
        }

        if ($managedEntry !== null && $externalIps !== []) {
            return self::mutationFailure(
                "Domain {$domain} exists in both the WLS managed block and external hosts content.",
                'MANAGED_EXTERNAL_DOMAIN_CONFLICT',
            );
        }
        if ($operation === self::OPERATION_REMOVE) {
            if ($managedEntry === null && $externalIps !== []) {
                return [
                    'success' => true,
                    'message' => 'The external hosts entry is not owned by WLS and was preserved.',
                    'status' => 'external_preserved',
                    'content' => $content,
                ];
            }
            if ($managedEntry === null) {
                return [
                    'success' => true,
                    'message' => 'The domain is not present in the WLS managed block.',
                    'status' => 'already_removed',
                    'content' => $content,
                ];
            }
            /** @var list<array{text:string,eol:string}> $lines */
            $lines = $document['lines'];
            \array_splice($lines, $managedEntry['line'], 1);

            return [
                'success' => true,
                'message' => 'The WLS managed entry will be removed.',
                'status' => 'removed',
                'content' => self::joinLines($lines),
            ];
        }

        if ($externalIps !== []) {
            foreach ($externalIps as $externalIp) {
                if (!self::sameIp($externalIp, $ip)) {
                    return self::mutationFailure(
                        "External hosts content already owns {$domain} with another address.",
                        'EXTERNAL_DOMAIN_CONFLICT',
                    );
                }
            }

            return [
                'success' => true,
                'message' => 'The external hosts entry already satisfies the requested address.',
                'status' => 'external_satisfied',
                'content' => $content,
            ];
        }

        /** @var list<array{text:string,eol:string}> $lines */
        $lines = $document['lines'];
        if ($managedEntry !== null) {
            if (self::sameIp($managedEntry['ip'], $ip)) {
                return [
                    'success' => true,
                    'message' => 'The WLS managed entry already satisfies the request.',
                    'status' => 'already_exists',
                    'content' => $content,
                ];
            }
            $lines[$managedEntry['line']]['text'] = "{$ip} {$domain}";

            return [
                'success' => true,
                'message' => 'The WLS managed entry address will be repaired.',
                'status' => 'repaired',
                'content' => self::joinLines($lines),
            ];
        }

        $newline = (string)$document['newline'];
        $endLine = $document['end_line'];
        if (\is_int($endLine)) {
            \array_splice(
                $lines,
                $endLine,
                0,
                [['text' => "{$ip} {$domain}", 'eol' => $newline]],
            );
            $newContent = self::joinLines($lines);
        } else {
            $separator = $content === ''
                || \str_ends_with($content, "\n")
                || \str_ends_with($content, "\r")
                    ? ''
                    : $newline;
            $newContent = $content
                . $separator
                . self::MARKER_START . $newline
                . "{$ip} {$domain}" . $newline
                . self::MARKER_END . $newline;
        }

        return [
            'success' => true,
            'message' => 'A WLS managed hosts entry will be added.',
            'status' => 'added',
            'content' => $newContent,
        ];
    }

    /**
     * @return array{
     *     success:bool,
     *     message?:string,
     *     error_code?:string,
     *     lines?:list<array{text:string,eol:string}>,
     *     managed?:array<string,array{line:int,ip:string}>,
     *     external?:array<string,list<string>>,
     *     ambiguous_domains?:array<string,true>,
     *     end_line?:int|null,
     *     newline?:string
     * }
     */
    private static function parseDocument(string $content): array
    {
        $lines = self::splitLines($content);
        $starts = [];
        $ends = [];
        foreach ($lines as $index => $line) {
            if ($line['text'] === self::MARKER_START) {
                $starts[] = $index;
            } elseif ($line['text'] === self::MARKER_END) {
                $ends[] = $index;
            }
        }
        if (($starts === []) !== ($ends === [])
            || \count($starts) > 1
            || \count($ends) > 1
            || ($starts !== [] && $starts[0] >= $ends[0])
        ) {
            return [
                'success' => false,
                'message' => 'The WLS managed hosts block has duplicate, nested, or unpaired markers.',
                'error_code' => 'MANAGED_BLOCK_INVALID',
            ];
        }

        $startLine = $starts[0] ?? null;
        $endLine = $ends[0] ?? null;
        $managed = [];
        if (\is_int($startLine) && \is_int($endLine)) {
            for ($index = $startLine + 1; $index < $endLine; ++$index) {
                $text = $lines[$index]['text'];
                if ($text === '') {
                    continue;
                }
                if (\preg_match('/\A(\S+)[ \t]+(\S+)\z/D', $text, $matches) !== 1) {
                    return [
                        'success' => false,
                        'message' => 'The WLS managed hosts block contains a line it does not exclusively own.',
                        'error_code' => 'MANAGED_BLOCK_INVALID',
                    ];
                }
                $managedIp = (string)$matches[1];
                $managedDomain = \strtolower((string)$matches[2]);
                if (!self::validIp($managedIp) || !self::validDomain($managedDomain)) {
                    return [
                        'success' => false,
                        'message' => 'The WLS managed hosts block contains an invalid entry.',
                        'error_code' => 'MANAGED_BLOCK_INVALID',
                    ];
                }
                if (isset($managed[$managedDomain])) {
                    return [
                        'success' => false,
                        'message' => "The WLS managed block contains duplicate domain {$managedDomain}.",
                        'error_code' => 'MANAGED_DOMAIN_DUPLICATE',
                    ];
                }
                $managed[$managedDomain] = ['line' => $index, 'ip' => $managedIp];
            }
        }

        $external = [];
        $ambiguousDomains = [];
        foreach ($lines as $index => $line) {
            if (\is_int($startLine)
                && \is_int($endLine)
                && $index >= $startLine
                && $index <= $endLine
            ) {
                continue;
            }
            $parts = self::hostsLineParts($line['text']);
            if ($parts === null) {
                foreach (self::ambiguousExternalDomainTokens($line['text']) as $token) {
                    $ambiguousDomains[$token] = true;
                }
                continue;
            }
            foreach ($parts['hosts'] as $externalDomain) {
                $externalDomain = \strtolower($externalDomain);
                if (!isset($external[$externalDomain])) {
                    $external[$externalDomain] = [];
                }
                $external[$externalDomain][] = $parts['ip'];
            }
        }

        foreach ($managed as $managedDomain => $_entry) {
            if (isset($external[$managedDomain])) {
                return [
                    'success' => false,
                    'message' => "Domain {$managedDomain} exists in both managed and external hosts content.",
                    'error_code' => 'MANAGED_EXTERNAL_DOMAIN_CONFLICT',
                ];
            }
        }

        return [
            'success' => true,
            'lines' => $lines,
            'managed' => $managed,
            'external' => $external,
            'ambiguous_domains' => $ambiguousDomains,
            'end_line' => $endLine,
            'newline' => self::detectNewline($content),
        ];
    }

    /** @return list<array{text:string,eol:string}> */
    private static function splitLines(string $content): array
    {
        $segments = \preg_split(
            '/(\r\n|\n|\r)/',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if (!\is_array($segments)) {
            return [['text' => $content, 'eol' => '']];
        }
        $lines = [];
        $count = \count($segments);
        for ($index = 0; $index < $count; $index += 2) {
            $lines[] = [
                'text' => (string)($segments[$index] ?? ''),
                'eol' => (string)($segments[$index + 1] ?? ''),
            ];
        }

        return $lines;
    }

    /** @param list<array{text:string,eol:string}> $lines */
    private static function joinLines(array $lines): string
    {
        $content = '';
        foreach ($lines as $line) {
            $content .= $line['text'] . $line['eol'];
        }

        return $content;
    }

    /**
     * Compatibility helper used by the existing focused unit surface. It
     * follows the same ownership parser as real transactions.
     */
    private static function addDomainToContent(
        string $content,
        string $domain,
        string $ip,
    ): string {
        $plan = self::planMutation(
            $content,
            self::OPERATION_UPSERT,
            \strtolower(\trim($domain)),
            \trim($ip),
        );

        return ($plan['success'] ?? false)
            ? (string)($plan['content'] ?? $content)
            : $content;
    }

    private static function rewriteDomainIpInContent(
        string $content,
        string $domain,
        string $ip,
    ): string {
        return self::addDomainToContent($content, $domain, $ip);
    }

    private static function removeDomainFromContent(string $content, string $domain): string
    {
        $plan = self::planMutation(
            $content,
            self::OPERATION_REMOVE,
            \strtolower(\trim($domain)),
            self::LOOPBACK_IPV4,
        );

        return ($plan['success'] ?? false)
            ? (string)($plan['content'] ?? $content)
            : $content;
    }

    /**
     * Return a project-independent, UID-scoped lock path. TMPDIR is never used,
     * so sibling projects and child processes cannot silently split the lock.
     */
    private static function hostMutationLockPath(string $hostsFile): string
    {
        if ($hostsFile === '' || \str_contains($hostsFile, "\0")) {
            return '';
        }
        $canonical = @\realpath($hostsFile);
        $identity = \is_string($canonical) && $canonical !== '' ? $canonical : $hostsFile;
        $directory = self::stableHostLockDirectory();
        if ($directory === '') {
            return '';
        }

        return $directory . DIRECTORY_SEPARATOR . \hash('sha256', $identity) . '.lock';
    }

    private static function stableHostLockDirectory(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return '';
        }
        $effectiveUid = self::effectiveUid();
        if ($effectiveUid < 0) {
            return '';
        }
        $base = @\realpath('/var/tmp');
        if (!\is_string($base) || $base === '' || !\is_dir($base)) {
            return '';
        }
        $directory = $base . DIRECTORY_SEPARATOR
            . 'weline-wls-host-locks-v3-uid' . $effectiveUid;
        if (!\is_dir($directory)
            && !@\mkdir($directory, 0700, false)
            && !\is_dir($directory)
        ) {
            return '';
        }
        $canonical = @\realpath($directory);
        $status = @\lstat($directory);
        if (!\is_string($canonical)
            || $canonical === ''
            || !self::samePath($directory, $canonical)
            || !\is_array($status)
            || \is_link($directory)
            || !self::safeDirectoryStatus($status)
            || (int)($status['uid'] ?? -1) !== $effectiveUid
        ) {
            return '';
        }
        if ((((int)$status['mode']) & 07777) !== 0700 && !@\chmod($directory, 0700)) {
            return '';
        }
        $sealed = @\lstat($directory);
        if (!\is_array($sealed)
            || !self::sameDirectoryIdentity($status, $sealed)
            || (int)($sealed['uid'] ?? -1) !== $effectiveUid
            || ((((int)$sealed['mode']) & 07777) !== 0700)
        ) {
            return '';
        }

        return $canonical;
    }

    private static function canonicalHostsTarget(string $hostsFile): ?string
    {
        if ($hostsFile === ''
            || \str_contains($hostsFile, "\0")
            || \is_link($hostsFile)
        ) {
            return null;
        }
        $pathStatus = @\lstat($hostsFile);
        $canonical = @\realpath($hostsFile);
        if (!\is_array($pathStatus)
            || !self::safeRegularFileStatus($pathStatus)
            || !\is_string($canonical)
            || $canonical === ''
            || \is_link($canonical)
        ) {
            return null;
        }
        $canonicalStatus = @\lstat($canonical);
        if (!\is_array($canonicalStatus)
            || !self::safeRegularFileStatus($canonicalStatus)
            || !self::sameFileIdentity($pathStatus, $canonicalStatus)
        ) {
            return null;
        }

        return $canonical;
    }

    /** @param array<string|int,mixed> $status */
    private static function directWriterIdentityAllowed(
        array $status,
        string $osFamily,
        int $effectiveUid,
    ): bool {
        return $osFamily !== 'Windows'
            && $effectiveUid >= 0
            && (int)($status['uid'] ?? -1) === $effectiveUid;
    }

    private static function effectiveUid(): int
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('posix_geteuid')) {
            return -1;
        }
        $uid = @\posix_geteuid();

        return \is_int($uid) ? $uid : -1;
    }

    /** @param resource $handle */
    private static function acquireTargetFileLock($handle): bool
    {
        $deadline = self::monotonicSeconds() + self::TARGET_LOCK_TIMEOUT_SECONDS;
        do {
            if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }
            if (self::monotonicSeconds() >= $deadline) {
                return false;
            }
            \usleep(20_000);
        } while (true);
    }

    /** @param resource $handle */
    private static function readHostsHandle($handle): ?string
    {
        $status = @\fstat($handle);
        if (!\is_array($status)
            || (int)($status['size'] ?? -1) < 0
            || (int)($status['size'] ?? -1) > self::MAX_HOSTS_BYTES
            || @\fseek($handle, 0, SEEK_SET) !== 0
        ) {
            return null;
        }
        $content = @\stream_get_contents($handle, self::MAX_HOSTS_BYTES + 1);
        if (!\is_string($content)
            || \strlen($content) > self::MAX_HOSTS_BYTES
            || \str_contains($content, "\0")
        ) {
            return null;
        }

        return $content;
    }

    /**
     * @param resource $handle
     * @param array<string|int,mixed> $expectedIdentity
     */
    private static function targetSnapshotStillMatches(
        $handle,
        string $path,
        array $expectedIdentity,
        string $expectedContent,
    ): bool {
        $opened = @\fstat($handle);
        $pathStatus = @\lstat($path);
        if (!\is_array($opened)
            || !\is_array($pathStatus)
            || !self::sameFileIdentity($expectedIdentity, $opened)
            || !self::sameFileIdentity($opened, $pathStatus)
            || !self::sameReplacementMetadata($expectedIdentity, $opened)
            || !self::sameReplacementMetadata($opened, $pathStatus)
        ) {
            return false;
        }
        $current = self::readHostsHandle($handle);

        return \is_string($current) && \hash_equals($expectedContent, $current);
    }

    /** @param resource $handle */
    private static function overwriteStageHandle($handle, string $content): bool
    {
        return @\fseek($handle, 0, SEEK_SET) === 0
            && @\ftruncate($handle, 0)
            && self::writeAll($handle, $content)
            && @\fflush($handle)
            && @\fsync($handle);
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $content): bool
    {
        $length = \strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = @\fwrite($handle, \substr($content, $offset));
            if (!\is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    private static function fileDigestMatches(string $path, string $expected): bool
    {
        $status = @\lstat($path);
        if (!\is_array($status)
            || !self::safeRegularFileStatus($status)
            || (int)($status['size'] ?? -1) !== \strlen($expected)
            || \strlen($expected) > self::MAX_HOSTS_BYTES
        ) {
            return false;
        }
        $content = @\file_get_contents($path, false, null, 0, self::MAX_HOSTS_BYTES + 1);

        return \is_string($content) && \hash_equals($expected, $content);
    }

    /** @param null|array<string|int,mixed> $expectedIdentity */
    private static function removeStageIfOwned(string $stage, ?array $expectedIdentity): void
    {
        $current = @\lstat($stage);
        if (!\is_array($current)) {
            return;
        }
        if (!self::safeRegularFileStatus($current)
            || ($expectedIdentity !== null
                && !self::sameFileIdentity($expectedIdentity, $current))
        ) {
            return;
        }
        @\unlink($stage);
    }

    /**
     * @param resource $handle
     * @param array<string|int,mixed> $expected
     */
    private static function stableLockIdentity(
        $handle,
        string $path,
        array $expected,
    ): bool {
        $opened = @\fstat($handle);
        $pathStatus = @\lstat($path);

        return \is_array($opened)
            && \is_array($pathStatus)
            && self::safeRegularFileStatus($opened)
            && self::safeRegularFileStatus($pathStatus)
            && self::sameFileIdentity($expected, $opened)
            && self::sameFileIdentity($opened, $pathStatus)
            && self::sameReplacementMetadata($expected, $opened)
            && self::sameReplacementMetadata($opened, $pathStatus)
            && !\is_link($path);
    }

    /** @param array<string|int,mixed> $status */
    private static function safeRegularFileStatus(array $status): bool
    {
        return ((((int)($status['mode'] ?? 0)) & 0170000) === 0100000)
            && (int)($status['nlink'] ?? 0) === 1;
    }

    /** @param array<string|int,mixed> $status */
    private static function safeDirectoryStatus(array $status): bool
    {
        return ((((int)($status['mode'] ?? 0)) & 0170000) === 0040000);
    }

    /** @param array<string|int,mixed> $status */
    private static function safePrivateDirectoryStatus(array $status, int $effectiveUid): bool
    {
        return self::safeDirectoryStatus($status)
            && $effectiveUid >= 0
            && (int)($status['uid'] ?? -1) === $effectiveUid
            && ((((int)($status['mode'] ?? 0)) & 0022) === 0);
    }

    /**
     * @param array<string|int,mixed> $left
     * @param array<string|int,mixed> $right
     */
    private static function sameFileIdentity(array $left, array $right): bool
    {
        return (int)($left['dev'] ?? -1) === (int)($right['dev'] ?? -2)
            && (int)($left['ino'] ?? -1) === (int)($right['ino'] ?? -2)
            && (int)($left['nlink'] ?? -1) === (int)($right['nlink'] ?? -2);
    }

    /**
     * @param array<string|int,mixed> $left
     * @param array<string|int,mixed> $right
     */
    private static function sameDirectoryIdentity(array $left, array $right): bool
    {
        return self::safeDirectoryStatus($left)
            && self::safeDirectoryStatus($right)
            && (int)($left['dev'] ?? -1) === (int)($right['dev'] ?? -2)
            && (int)($left['ino'] ?? -1) === (int)($right['ino'] ?? -2);
    }

    /**
     * @param array<string|int,mixed> $target
     * @param array<string|int,mixed> $stage
     */
    private static function sameReplacementMetadata(array $target, array $stage): bool
    {
        return (((int)($target['mode'] ?? 0)) & 07777)
                === (((int)($stage['mode'] ?? 0)) & 07777)
            && (int)($target['uid'] ?? -1) === (int)($stage['uid'] ?? -2)
            && (int)($target['gid'] ?? -1) === (int)($stage['gid'] ?? -2);
    }

    private static function samePath(string $left, string $right): bool
    {
        return $left === $right;
    }

    private static function sameIp(string $left, string $right): bool
    {
        $leftBinary = @\inet_pton($left);
        $rightBinary = @\inet_pton($right);

        return \is_string($leftBinary)
            && \is_string($rightBinary)
            && \hash_equals($leftBinary, $rightBinary);
    }

    /** @return list<string> */
    private static function ambiguousExternalDomainTokens(string $line): array
    {
        $trimmed = \ltrim($line);
        if ($trimmed === '' || \str_starts_with($trimmed, '#')) {
            return [];
        }
        $commentOffset = \strpos($line, '#');
        $body = $commentOffset === false ? $line : \substr($line, 0, $commentOffset);
        $tokens = \preg_split('/\s+/', \trim($body));
        if (!\is_array($tokens)) {
            return [];
        }

        $domains = [];
        foreach ($tokens as $token) {
            $token = \strtolower($token);
            if (self::validDomain($token)) {
                $domains[] = $token;
            }
        }

        return $domains;
    }

    /**
     * @return null|array{ip:string,hosts:list<string>}
     */
    private static function hostsLineParts(string $line): ?array
    {
        $trimmed = \ltrim($line);
        if ($trimmed === '' || \str_starts_with($trimmed, '#')) {
            return null;
        }
        $commentOffset = \strpos($line, '#');
        $body = $commentOffset === false ? $line : \substr($line, 0, $commentOffset);
        if (\preg_match('/\A\s*(\S+)\s+(.*?)\s*\z/D', $body, $matches) !== 1) {
            return null;
        }
        $hosts = \preg_split('/\s+/', \trim((string)$matches[2]));
        if (!\is_array($hosts) || $hosts === [] || $hosts[0] === '') {
            return null;
        }

        return ['ip' => (string)$matches[1], 'hosts' => \array_values($hosts)];
    }

    private static function detectNewline(string $content): string
    {
        if (\str_contains($content, "\r\n")) {
            return "\r\n";
        }
        if (\str_contains($content, "\r")) {
            return "\r";
        }

        return "\n";
    }

    private static function validDomain(string $domain): bool
    {
        return \preg_match(
            '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
                . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
            $domain,
        ) === 1;
    }

    private static function validIp(string $ip): bool
    {
        return \filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** @return array{success:false,message:string,error_code?:string} */
    private static function mutationFailure(string $message, ?string $errorCode = null): array
    {
        $result = ['success' => false, 'message' => $message];
        if ($errorCode !== null && $errorCode !== '') {
            $result['error_code'] = $errorCode;
        }

        return $result;
    }

    private static function monotonicSeconds(): float
    {
        $seconds = \hrtime(true) / 1_000_000_000;
        if (!\is_finite($seconds) || $seconds <= 0.0) {
            throw new \RuntimeException('The hosts mutation monotonic clock is invalid.');
        }

        return $seconds;
    }
}
