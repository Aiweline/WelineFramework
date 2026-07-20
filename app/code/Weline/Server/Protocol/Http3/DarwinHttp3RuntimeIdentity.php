<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Deterministic, generation-fenced identity for Darwin HTTP/3 datagram routing.
 *
 * Only paths and digests may cross process metadata. Raw keys are derived in
 * the Master and the exact Worker generation from the authenticated Master
 * lease secret already required by the child control channel.
 */
final class DarwinHttp3RuntimeIdentity
{
    private const CHANNEL_CONTEXT = 'wls-http3-datagram-channel-v2';
    private const RETRY_CONTEXT = 'wls-http3-router-retry-v2';

    public static function retrySecret(string $masterToken, string $instanceName, int $epoch): string
    {
        self::assertMasterIdentity($masterToken, $instanceName, $epoch);

        return \hash_hkdf(
            'sha256',
            $masterToken,
            32,
            self::RETRY_CONTEXT . '|' . $instanceName . '|' . $epoch,
            \hash('sha256', 'weline-server-http3-retry', true),
        );
    }

    public static function channelKey(
        string $masterToken,
        string $instanceName,
        int $epoch,
        int $workerId,
        string $slotId,
        string $leaseId,
        int $generation,
    ): string {
        self::assertMasterIdentity($masterToken, $instanceName, $epoch);
        if ($workerId <= 0 || $slotId === '' || $leaseId === '' || $generation <= 0) {
            throw new \InvalidArgumentException('Darwin HTTP/3 Worker generation identity is incomplete.');
        }

        return \hash_hkdf(
            'sha256',
            $masterToken,
            32,
            self::CHANNEL_CONTEXT . '|' . $instanceName . '|' . $epoch . '|'
                . $workerId . '|' . $slotId . '|' . $leaseId . '|' . $generation,
            \hash('sha256', 'weline-server-http3-datagram-channel', true),
        );
    }

    public static function workerChannelPath(
        string $instanceName,
        int $workerId,
        string $leaseId,
        int $generation,
    ): string {
        if ($instanceName === '' || $workerId <= 0 || $leaseId === '' || $generation <= 0) {
            throw new \InvalidArgumentException('Darwin HTTP/3 Worker channel identity is incomplete.');
        }
        $directory = self::ensureRuntimeDirectory($instanceName);
        $leaseDigest = \substr(\hash('sha256', $leaseId), 0, 10);
        $path = $directory . \DIRECTORY_SEPARATOR
            . 'w' . $workerId . '-g' . $generation . '-' . $leaseDigest . '.sock';
        if (\strlen($path) >= 100) {
            throw new \RuntimeException('Darwin HTTP/3 Worker channel path exceeds the safe sockaddr_un limit.');
        }

        return $path;
    }

    public static function ensureRuntimeDirectory(string $instanceName): string
    {
        if ($instanceName === '') {
            throw new \InvalidArgumentException('Darwin HTTP/3 instance name is required.');
        }
        $uid = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : (int)\getmyuid();
        $project = \realpath(\defined('BP') ? BP : \dirname(__DIR__, 7)) ?: '';
        $scope = \substr(\hash('sha256', $project . '|' . $instanceName), 0, 16);
        $root = '/tmp/wls-' . $uid;
        self::ensureOwnedDirectory($root);
        $directory = $root . \DIRECTORY_SEPARATOR . 'h3-' . $scope;
        self::ensureOwnedDirectory($directory);

        return $directory;
    }

    private static function ensureOwnedDirectory(string $directory): void
    {
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create Darwin HTTP/3 runtime directory: ' . $directory);
        }
        $stat = @\lstat($directory);
        $uid = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : (int)\getmyuid();
        if (!\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0040000
            || (int)($stat['uid'] ?? -1) !== $uid
        ) {
            throw new \RuntimeException('Unsafe Darwin HTTP/3 runtime directory: ' . $directory);
        }
        @\chmod($directory, 0700);
    }

    private static function assertMasterIdentity(string $masterToken, string $instanceName, int $epoch): void
    {
        if ($masterToken === '' || $instanceName === '' || $epoch <= 0) {
            throw new \InvalidArgumentException('HTTP/3 requires an authenticated Master generation identity.');
        }
    }
}
