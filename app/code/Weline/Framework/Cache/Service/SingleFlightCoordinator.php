<?php

declare(strict_types=1);

/**
 * Single-flight 协调器
 *
 * 自适应运行时：
 * - WLS 模式：使用已注册缓存适配器的原子能力做跨 Worker / 协程占位
 * - FPM 模式：基于 var/cache/single_flight/{hash}.lock 文件锁
 * - CLI 模式：使用进程内静态数组锁（仅同进程有效，足够支撑 CLI 场景）
 *
 * 失败语义：在指定超时内未能 acquire，会返回 null。调用方应回退为「直接执行回调」
 * 以保证可用性，避免死锁阻塞。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\AdapterFactory;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\SchedulerSystem;

class SingleFlightCoordinator implements SingleFlightInterface
{
    private const POOL_IDENTITY = 'single_flight';
    private const HASH_ALG = 'xxh3';
    private const WAIT_SLICE_US = 20_000;

    /**
     * CLI 模式进程内锁存储（key => token）。
     *
     * @var array<string, string>
     */
    private static array $localLocks = [];

    /**
     * 已打开的 FPM 文件锁句柄（key => array{handle:resource, path:string}）。
     *
     * @var array<string, array{handle:resource, path:string}>
     */
    private array $fileHandles = [];

    private ?AtomicCacheAdapterInterface $atomicAdapter = null;

    public function __construct(?AtomicCacheAdapterInterface $atomicAdapter = null)
    {
        if (Runtime::isPersistent()) {
            $adapter = $atomicAdapter ?? (new AdapterFactory())->create('wls_memory', self::POOL_IDENTITY);
            if (!$adapter instanceof AtomicCacheAdapterInterface) {
                throw new \RuntimeException(
                    'The persistent single-flight cache adapter must implement '
                    . AtomicCacheAdapterInterface::class,
                );
            }
            $this->atomicAdapter = $adapter;
        }
    }

    public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string
    {
        $token = $this->generateToken();
        $deadlineNs = $timeoutMs > 0 ? (\hrtime(true) + ($timeoutMs * 1_000_000)) : 0;

        do {
            if ($this->tryAcquire($key, $token, $ttlSeconds)) {
                return $token;
            }

            if ($timeoutMs <= 0) {
                return null;
            }

            $remainingNs = $deadlineNs - \hrtime(true);
            if ($remainingNs <= 0) {
                return null;
            }

            SchedulerSystem::usleep((int)\min(
                self::WAIT_SLICE_US,
                (int)\max(1, \intdiv($remainingNs, 1_000)),
            ));
        } while (\hrtime(true) < $deadlineNs);

        return null;
    }

    public function release(string $key, string $token): void
    {
        if ($this->atomicAdapter !== null) {
            $this->atomicAdapter->compareAndSet($key, $token, null, 1);
            return;
        }

        if (isset($this->fileHandles[$key])) {
            $handle = $this->fileHandles[$key]['handle'];
            $path = $this->fileHandles[$key]['path'];
            \flock($handle, LOCK_UN);
            \fclose($handle);
            @\unlink($path);
            unset($this->fileHandles[$key]);
            return;
        }

        if (\array_key_exists($key, self::$localLocks) && self::$localLocks[$key] === $token) {
            unset(self::$localLocks[$key]);
        }
    }

    /**
     * 尝试一次 acquire 操作。
     */
    private function tryAcquire(string $key, string $token, int $ttlSeconds): bool
    {
        if ($this->atomicAdapter !== null) {
            return $this->atomicAdapter->compareAndSet($key, null, $token, $ttlSeconds);
        }

        if (Runtime::isFpm()) {
            return $this->acquireFileLock($key, $token);
        }

        if (\array_key_exists($key, self::$localLocks)) {
            return false;
        }
        self::$localLocks[$key] = $token;
        return true;
    }

    private function acquireFileLock(string $key, string $token): bool
    {
        $dir = $this->getLockDir();
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        $hashed = \hash(self::HASH_ALG, $key);
        $path = $dir . DIRECTORY_SEPARATOR . $hashed . '.lock';
        $handle = @\fopen($path, 'cb+');
        if ($handle === false) {
            return false;
        }

        if (!@\flock($handle, LOCK_EX | LOCK_NB)) {
            @\fclose($handle);
            return false;
        }

        @\ftruncate($handle, 0);
        @\fwrite($handle, $token);
        @\fflush($handle);

        $this->fileHandles[$key] = ['handle' => $handle, 'path' => $path];
        return true;
    }

    private function generateToken(): string
    {
        try {
            return \bin2hex(\random_bytes(8));
        } catch (\Throwable) {
            return \uniqid('sf_', true);
        }
    }

    private function getLockDir(): string
    {
        $base = \defined('BP') ? \rtrim(BP, DIRECTORY_SEPARATOR) : \sys_get_temp_dir();
        return $base . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'single_flight';
    }
}
