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
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\Runtime;

class SingleFlightCoordinator implements SingleFlightInterface, ProcessSharedInterface
{
    private const POOL_IDENTITY = 'single_flight';
    private const HASH_ALG = 'xxh3';

    /**
     * 进程内持有标记（key => token），用于CLI锁以及WLS同Worker重复请求的快速短路。
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
    private bool $preferFileLock = false;

    public function __construct(?AtomicCacheAdapterInterface $atomicAdapter = null, bool $preferFileLock = false)
    {
        $this->preferFileLock = $preferFileLock;
        if ($preferFileLock) {
            return;
        }
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
        // Non-blocking (2026-11-04, user decision: no request-path locks or waits).
        //
        // This used to poll tryAcquire() every 20ms until $timeoutMs elapsed, and
        // storefront policies passed budgets up to 20000ms (StorefrontCatalogCache
        // Coordinator), plus 5000/3000/2500ms elsewhere. Under crawl load every
        // follower burned a Worker fibre asleep on a lock it usually never won, and
        // the site parked: measured on changanhanfu with active_requests=4 on all
        // four Workers while clients hit 15s timeouts.
        //
        // A caller that misses the lock now proceeds immediately and does the work
        // itself. Duplicate work beats a parked Worker — the Worker is the scarce
        // resource, not the cache entry. $timeoutMs stays in the signature so call
        // sites and CachePolicy keep working; it is deliberately ignored.
        unset($timeoutMs);

        // TTL 秒数向上取整：亚秒级预算（如 200ms）若截断为 0，在带滑动过期
        // 的共享存储上会退化成默认长 TTL 的永不过期锁占位。
        $ttlSeconds = (int)\max(1, \ceil($ttlSeconds));
        $token = $this->generateToken();
        if ($this->tryAcquire($key, $token, $ttlSeconds)) {
            return $token;
        }

        return null;
    }

    public function release(string $key, string $token): void
    {
        if ($this->atomicAdapter !== null) {
            try {
                $this->atomicAdapter->compareAndSet($key, $token, null, 1);
            } finally {
                if (\array_key_exists($key, self::$localLocks) && self::$localLocks[$key] === $token) {
                    unset(self::$localLocks[$key]);
                }
            }
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
            // A persistent worker can have several Fibers contend for the same
            // key while the first CAS is waiting on WLS. Keep a process-local
            // holder marker so duplicates fail immediately; the atomic CAS
            // remains authoritative across workers.
            if (\array_key_exists($key, self::$localLocks)) {
                return false;
            }
            self::$localLocks[$key] = $token;
            try {
                $acquired = $this->atomicAdapter->compareAndSet($key, null, $token, $ttlSeconds);
            } catch (\Throwable $throwable) {
                if ((self::$localLocks[$key] ?? null) === $token) {
                    unset(self::$localLocks[$key]);
                }
                throw $throwable;
            }
            if (!$acquired && (self::$localLocks[$key] ?? null) === $token) {
                unset(self::$localLocks[$key]);
            }
            return $acquired;
        }

        if ($this->preferFileLock || Runtime::isFpm()) {
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
