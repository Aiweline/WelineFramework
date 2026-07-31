<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;

/**
 * Master 运行期身份租约。
 *
 * 子进程用它判断“当前 Master 是否仍是我的 Master”，不能把 IPC socket
 * connected 当作 Master 存活依据。
 */
class MasterLeaseManager
{
    public const STATE_RUNNING = 'running';
    public const STATE_STOPPING = 'stopping';
    public const HEARTBEAT_STALE_SEC = 15;
    private const TEMPORARY_STALE_SEC = 30;
    private const MAX_PROTECTED_LEASE_BYTES = 16384;

    public static function pathForInstance(string $instance): string
    {
        $safeInstance = self::safeInstance($instance);
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR
            . $safeInstance . DIRECTORY_SEPARATOR
            . 'master_lease.json';
    }

    public function writeRunning(
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token
    ): string {
        $path = self::pathForInstance($instance);
        $existing = $this->readProtected($path);
        if ($this->isFreshForeignRunningLease(
            $existing,
            $instance,
            $masterPid,
            $controlPort,
            $epoch,
            $token,
        )) {
            throw new MasterLeaseOwnershipLostException(
                'WLS Master lease is already owned by another live generation.'
            );
        }
        $this->writeLease($path, [
            'instance' => $instance,
            'master_pid' => $masterPid,
            'control_port' => $controlPort,
            'master_epoch' => $epoch,
            'master_token' => $token,
            'state' => self::STATE_RUNNING,
            'updated_at' => \microtime(true),
        ]);

        return $path;
    }

    public function touchRunning(
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token
    ): void
    {
        $path = self::pathForInstance($instance);
        $data = $this->readProtected($path);
        $this->assertRunningIdentity(
            $data,
            $instance,
            $masterPid,
            $controlPort,
            $epoch,
            $token,
        );
        $data['updated_at'] = \microtime(true);

        $this->writeLease($path, $data);
    }

    /**
     * Advance the live Master identity exactly once before launching a new
     * infrastructure generation. This is a compare-and-swap contract: a
     * stale caller must never overwrite a newer or foreign protected lease.
     */
    public function advanceRunningEpoch(
        string $instance,
        int $masterPid,
        int $controlPort,
        int $expectedEpoch,
        int $nextEpoch,
        string $token
    ): string {
        if ($expectedEpoch <= 0 || $nextEpoch !== $expectedEpoch + 1) {
            throw new \RuntimeException('WLS Master lease epoch transition is invalid.');
        }

        $path = self::pathForInstance($instance);
        $data = $this->readProtected($path);
        $this->assertRunningIdentity(
            $data,
            $instance,
            $masterPid,
            $controlPort,
            $expectedEpoch,
            $token,
        );

        $data['master_epoch'] = $nextEpoch;
        $data['updated_at'] = \microtime(true);
        $this->writeLease($path, $data);

        $published = $this->readProtected($path);
        $this->assertRunningIdentity(
            $published,
            $instance,
            $masterPid,
            $controlPort,
            $nextEpoch,
            $token,
        );

        return $path;
    }

    public function markStopping(string $instance, int $masterPid, string $token): void
    {
        $path = self::pathForInstance($instance);
        $data = $this->read($path);
        if ($data === null) {
            return;
        }

        $existingToken = (string)($data['master_token'] ?? '');
        if ($existingToken !== '' && !\hash_equals($existingToken, $token)) {
            return;
        }

        $data['instance'] = (string)($data['instance'] ?? $instance);
        $data['master_pid'] = $masterPid;
        $data['master_token'] = $token;
        $data['state'] = self::STATE_STOPPING;
        $data['updated_at'] = \microtime(true);

        $this->writeLease($path, $data);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function read(string $path): ?array
    {
        if ($path === '' || !\is_file($path)) {
            return null;
        }

        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || \trim($raw) === '') {
            return null;
        }

        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Read a security-sensitive lease without accepting symlink traversal,
     * world-readable files, identity swaps, or oversized payloads.
     *
     * @return array<string,mixed>|null
     */
    public function readProtected(string $path): ?array
    {
        if ($path === '' || \is_link($path) || !\is_file($path)) {
            return null;
        }
        $before = @\lstat($path);
        if (!\is_array($before)) {
            return null;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $mode = (int)($before['mode'] ?? 0);
            if (($mode & 0170000) !== 0100000 || ($mode & 0037) !== 0) {
                return null;
            }
        }

        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return null;
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !self::sameLeaseIdentity($before, $opened)) {
                return null;
            }
            $raw = @\stream_get_contents($handle, self::MAX_PROTECTED_LEASE_BYTES + 1);
            if (!\is_string($raw)
                || \strlen($raw) > self::MAX_PROTECTED_LEASE_BYTES
                || \trim($raw) === ''
            ) {
                return null;
            }
        } finally {
            @\fclose($handle);
        }

        $after = @\lstat($path);
        if (!\is_array($after)
            || \is_link($path)
            || !self::sameLeaseIdentity($before, $after)
        ) {
            return null;
        }
        try {
            $decoded = \json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolve the current managed child's credential from non-secret process
     * identity arguments. The credential itself remains in the protected
     * Master lease and is never required on argv.
     *
     * @param list<mixed> $arguments
     */
    public function resolveProtectedCredentialFromArguments(
        array $arguments,
        string $instance = '',
        int $masterPid = 0,
        int $masterEpoch = 0,
    ): string {
        $leaseFile = self::namedArgument($arguments, 'master-lease-file');
        if ($instance === '') {
            $instance = self::namedArgument($arguments, 'instance-name');
        }
        if ($instance === '' && $leaseFile !== '') {
            $instance = \basename(\dirname($leaseFile));
        }
        if ($masterPid <= 0) {
            $masterPid = (int)self::namedArgument($arguments, 'master-pid');
        }
        if ($masterEpoch <= 0) {
            $masterEpoch = (int)self::namedArgument($arguments, 'epoch');
        }

        return $this->resolveProtectedCredential(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            self::namedArgument($arguments, 'launch-id'),
            self::namedArgument($arguments, 'lease-id'),
            (int)self::namedArgument($arguments, 'slot-generation'),
        );
    }

    public function resolveProtectedCredential(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $childLaunchId,
        string $childLeaseId,
        int $childGeneration,
    ): string {
        if ($leaseFile === ''
            || $masterPid <= 0
            || $masterEpoch <= 0
            || $childGeneration <= 0
            || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $instance) !== 1
            || !self::validOpaqueIdentity($childLaunchId)
            || !self::validOpaqueIdentity($childLeaseId)
        ) {
            throw new \RuntimeException('WLS child Master lease identity is incomplete.');
        }

        $expectedPath = self::pathForInstance($instance);
        if (\is_link($leaseFile) || \is_link($expectedPath)) {
            throw new \RuntimeException('WLS child Master lease path is not trusted.');
        }
        $canonicalPath = \realpath($leaseFile);
        $canonicalExpectedPath = \realpath($expectedPath);
        if (!\is_string($canonicalPath)
            || !\is_string($canonicalExpectedPath)
            || !self::samePath($canonicalPath, $canonicalExpectedPath)
        ) {
            throw new \RuntimeException('WLS child Master lease path does not match the instance.');
        }

        $lease = $this->readProtected($canonicalPath);
        if (!\is_array($lease)) {
            throw new \RuntimeException('WLS child Master lease is missing or invalid.');
        }
        foreach ([
            'instance',
            'master_pid',
            'control_port',
            'master_epoch',
            'master_token',
            'state',
            'updated_at',
        ] as $field) {
            if (!\array_key_exists($field, $lease)) {
                throw new \RuntimeException('WLS child Master lease schema is incomplete.');
            }
        }

        $leaseInstance = $lease['instance'];
        $leasePid = $lease['master_pid'];
        $leasePort = $lease['control_port'];
        $leaseEpoch = $lease['master_epoch'];
        $leaseState = $lease['state'];
        $leaseToken = $lease['master_token'];
        $updatedAt = $lease['updated_at'];
        if (!\is_string($leaseInstance)
            || !\hash_equals($instance, $leaseInstance)
            || !\is_int($leasePid)
            || $leasePid !== $masterPid
            || !\is_int($leasePort)
            || $leasePort <= 0
            || !\is_int($leaseEpoch)
            || $leaseEpoch !== $masterEpoch
            || !\is_string($leaseState)
            || $leaseState !== self::STATE_RUNNING
            || !\is_string($leaseToken)
            || \preg_match('/\A[a-f0-9]{64}\z/Di', $leaseToken) !== 1
            || (!\is_int($updatedAt) && !\is_float($updatedAt))
        ) {
            throw new \RuntimeException('WLS child Master lease identity is invalid.');
        }

        $age = \microtime(true) - (float)$updatedAt;
        if ($age < -5.0 || $age > self::HEARTBEAT_STALE_SEC) {
            throw new \RuntimeException('WLS child Master lease heartbeat is stale.');
        }
        if (!\Weline\Framework\System\Process\Processer::isRunningByPid($masterPid)) {
            throw new \RuntimeException('WLS child Master process is not running.');
        }

        return $leaseToken;
    }

    /** @param list<mixed> $arguments */
    private static function namedArgument(array $arguments, string $name): string
    {
        $prefix = '--' . $name . '=';
        foreach ($arguments as $argument) {
            if (!\is_scalar($argument)) {
                continue;
            }
            $argument = (string)$argument;
            if (!\str_starts_with($argument, $prefix)) {
                continue;
            }

            return \trim((string)\substr($argument, \strlen($prefix)), " \t\n\r\0\x0B\"'");
        }

        return '';
    }

    private static function validOpaqueIdentity(string $value): bool
    {
        return \preg_match('/\A[A-Za-z0-9_.:-]{1,160}\z/D', $value) === 1;
    }

    private static function samePath(string $left, string $right): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return \strcasecmp($left, $right) === 0;
        }

        return \hash_equals($left, $right);
    }

    /**
     * @param array<string,mixed>|null $lease
     */
    private function assertRunningIdentity(
        ?array $lease,
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token
    ): void {
        if ($instance === ''
            || $masterPid <= 0
            || $controlPort <= 0
            || $epoch <= 0
            || \preg_match('/\A[a-f0-9]{64}\z/Di', $token) !== 1
            || !\is_array($lease)
        ) {
            throw new \RuntimeException('WLS Master lease running identity is incomplete.');
        }

        if ($this->isForeignRunningLease(
            $lease,
            $instance,
            $masterPid,
            $controlPort,
            $epoch,
            $token,
        )) {
            throw new MasterLeaseOwnershipLostException(
                'WLS Master lease running identity does not match.'
            );
        }

        $leaseInstance = $lease['instance'] ?? null;
        $leasePid = $lease['master_pid'] ?? null;
        $leasePort = $lease['control_port'] ?? null;
        $leaseEpoch = $lease['master_epoch'] ?? null;
        $leaseToken = $lease['master_token'] ?? null;
        $leaseState = $lease['state'] ?? null;
        $updatedAt = $lease['updated_at'] ?? null;
        if (!\is_string($leaseInstance)
            || !\hash_equals($instance, $leaseInstance)
            || !\is_int($leasePid)
            || $leasePid !== $masterPid
            || !\is_int($leasePort)
            || $leasePort !== $controlPort
            || !\is_int($leaseEpoch)
            || $leaseEpoch !== $epoch
            || !\is_string($leaseToken)
            || !\hash_equals($token, $leaseToken)
            || !\is_string($leaseState)
            || $leaseState !== self::STATE_RUNNING
            || (!\is_int($updatedAt) && !\is_float($updatedAt))
        ) {
            throw new \RuntimeException('WLS Master lease running identity does not match.');
        }
    }

    /**
     * A complete running lease with another identity is a definitive ownership
     * fence, not a transient read failure.
     *
     * @param array<string,mixed>|null $lease
     */
    private function isForeignRunningLease(
        ?array $lease,
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token,
    ): bool {
        if (!\is_array($lease)
            || !\is_string($lease['instance'] ?? null)
            || !\is_int($lease['master_pid'] ?? null)
            || !\is_int($lease['control_port'] ?? null)
            || !\is_int($lease['master_epoch'] ?? null)
            || !\is_string($lease['master_token'] ?? null)
            || !\is_string($lease['state'] ?? null)
            || (!\is_int($lease['updated_at'] ?? null) && !\is_float($lease['updated_at'] ?? null))
            || $lease['state'] !== self::STATE_RUNNING
        ) {
            return false;
        }

        return !\hash_equals($instance, $lease['instance'])
            || $masterPid !== $lease['master_pid']
            || $controlPort !== $lease['control_port']
            || $epoch !== $lease['master_epoch']
            || !\hash_equals($token, $lease['master_token']);
    }

    /**
     * @param array<string,mixed>|null $lease
     */
    private function isFreshForeignRunningLease(
        ?array $lease,
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token,
    ): bool {
        if (!$this->isForeignRunningLease(
            $lease,
            $instance,
            $masterPid,
            $controlPort,
            $epoch,
            $token,
        )) {
            return false;
        }

        $updatedAt = (float)($lease['updated_at'] ?? 0.0);

        return $updatedAt > 0.0
            && (\microtime(true) - $updatedAt) <= self::HEARTBEAT_STALE_SEC;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameLeaseIdentity(array $left, array $right): bool
    {
        $leftDevice = (int)($left['dev'] ?? 0);
        $rightDevice = (int)($right['dev'] ?? 0);
        $leftInode = (int)($left['ino'] ?? 0);
        $rightInode = (int)($right['ino'] ?? 0);
        if ($leftDevice > 0 && $rightDevice > 0 && $leftInode > 0 && $rightInode > 0) {
            return $leftDevice === $rightDevice && $leftInode === $rightInode;
        }

        return (int)($left['size'] ?? -1) === (int)($right['size'] ?? -2)
            && (int)($left['mtime'] ?? -1) === (int)($right['mtime'] ?? -2)
            && (int)($left['ctime'] ?? -1) === (int)($right['ctime'] ?? -2);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeLease(string $path, array $data): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Unable to create master lease directory: ' . $dir);
        }
        $this->cleanupStaleTemporaries($path);

        $json = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!\is_string($json)) {
            throw new \RuntimeException('Unable to encode master lease payload.');
        }

        $tmp = $path . '.' . \getmypid() . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        $payload = $json . PHP_EOL;
        $written = @\file_put_contents($tmp, $payload, LOCK_EX);
        if (!\is_int($written) || $written !== \strlen($payload)) {
            @\unlink($tmp);
            throw new \RuntimeException('Unable to write master lease temp file: ' . $tmp);
        }
        @\chmod($tmp, 0640);
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('Unable to publish master lease file: ' . $path);
        }
    }

    private function cleanupStaleTemporaries(string $path): void
    {
        $basename = \basename($path);
        $pattern = '/^' . \preg_quote($basename, '/') . '\.[0-9]+\.[a-f0-9]{8}\.tmp$/D';
        $now = \time();
        foreach (\glob($path . '.*.tmp') ?: [] as $candidate) {
            if (!\is_string($candidate)
                || \is_link($candidate)
                || !\is_file($candidate)
                || \preg_match($pattern, \basename($candidate)) !== 1
            ) {
                continue;
            }
            $modifiedAt = @\filemtime($candidate);
            if (!\is_int($modifiedAt) || $now - $modifiedAt < self::TEMPORARY_STALE_SEC) {
                continue;
            }
            @\unlink($candidate);
        }
    }

    private static function safeInstance(string $instance): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9_.-]+/', '_', $instance);
        $safe = \is_string($safe) ? \trim($safe, '._-') : '';
        return $safe !== '' ? $safe : 'default';
    }
}
