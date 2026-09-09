<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * SESSION payload read path: process array → shared cache pool → caller DB.
 *
 * Under WLS, file pools are hijacked to wls_memory so other HTTP Workers share L2.
 * Authority remains the database credential store; this is cache-aside only.
 */
final class FrontendWorkerSessionPayloadCache
{
    public const POOL_IDENTITY = 'frontend_worker_credential';

    private const MAX_PROCESS_ENTRIES = 2048;
    private const ENVELOPE_VERSION = 1;

    /** @var array<string, array{v:int,expires_at:int,payload:array<string,mixed>}> */
    private static array $process = [];

    private ?CachePoolInterface $pool = null;
    private bool $poolResolved = false;

    public function __construct(
        private readonly ?CacheManager $cacheManager = null,
        private readonly ?CachePoolInterface $poolOverride = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null Decrypted SESSION payload when still fresh.
     */
    public function get(string $cacheKey, int $now): ?array
    {
        if ($cacheKey === '' || $now < 1) {
            return null;
        }

        $local = self::$process[$cacheKey] ?? null;
        if (\is_array($local)
            && ($local['v'] ?? 0) === self::ENVELOPE_VERSION
            && (int)($local['expires_at'] ?? 0) > $now
            && \is_array($local['payload'] ?? null)) {
            return $local['payload'];
        }
        if ($local !== null) {
            unset(self::$process[$cacheKey]);
        }

        $pool = $this->pool();
        if ($pool === null) {
            return null;
        }
        try {
            $shared = $pool->get($cacheKey);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($shared)
            || ($shared['v'] ?? 0) !== self::ENVELOPE_VERSION
            || (int)($shared['expires_at'] ?? 0) <= $now
            || !\is_array($shared['payload'] ?? null)) {
            return null;
        }

        $this->rememberProcess($cacheKey, $shared);
        return $shared['payload'];
    }

    /** @param array<string, mixed> $payload */
    public function put(string $cacheKey, array $payload, int $expiresAt, int $now): void
    {
        if ($cacheKey === '' || $expiresAt <= $now || $payload === []) {
            return;
        }
        $envelope = [
            'v' => self::ENVELOPE_VERSION,
            'expires_at' => $expiresAt,
            'payload' => $payload,
        ];
        $this->rememberProcess($cacheKey, $envelope);

        $pool = $this->pool();
        if ($pool === null) {
            return;
        }
        $ttl = \max(1, $expiresAt - $now);
        try {
            $pool->set($cacheKey, $envelope, $ttl);
        } catch (\Throwable) {
            // Shared cache is best-effort; DB remains authority.
        }
    }

    public function forget(string $cacheKey): void
    {
        if ($cacheKey === '') {
            return;
        }
        unset(self::$process[$cacheKey]);
        $pool = $this->pool();
        if ($pool === null) {
            return;
        }
        try {
            $pool->delete($cacheKey);
        } catch (\Throwable) {
            // ignore
        }
    }

    /** @param array{type:string,scope_hash:string,credential_hash:string} $identity */
    public static function keyForIdentity(array $identity): string
    {
        return 'sess.v1.' . \hash(
            'sha256',
            ($identity['type'] ?? '') . "\n"
            . ($identity['scope_hash'] ?? '') . "\n"
            . ($identity['credential_hash'] ?? ''),
        );
    }

    /** @internal tests */
    public static function resetProcessCache(): void
    {
        self::$process = [];
    }

    private function pool(): ?CachePoolInterface
    {
        if ($this->poolOverride instanceof CachePoolInterface) {
            return $this->poolOverride;
        }
        if ($this->poolResolved) {
            return $this->pool;
        }
        $this->poolResolved = true;
        try {
            $manager = $this->cacheManager
                ?? ObjectManager::getInstance(CacheManager::class);
            if (!$manager instanceof CacheManager) {
                return null;
            }
            $pool = $manager->pool(self::POOL_IDENTITY);
            $this->pool = $pool instanceof CachePoolInterface ? $pool : null;
        } catch (\Throwable) {
            $this->pool = null;
        }
        return $this->pool;
    }

    /** @param array{v:int,expires_at:int,payload:array<string,mixed>} $envelope */
    private function rememberProcess(string $cacheKey, array $envelope): void
    {
        if (\count(self::$process) >= self::MAX_PROCESS_ENTRIES
            && !\array_key_exists($cacheKey, self::$process)) {
            $first = \array_key_first(self::$process);
            if (\is_string($first)) {
                unset(self::$process[$first]);
            }
        }
        self::$process[$cacheKey] = $envelope;
    }
}
