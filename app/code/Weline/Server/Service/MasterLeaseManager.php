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

    public function touchRunning(string $instance, int $masterPid, string $token): void
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
        $data['state'] = self::STATE_RUNNING;
        $data['updated_at'] = \microtime(true);

        $this->writeLease($path, $data);
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
