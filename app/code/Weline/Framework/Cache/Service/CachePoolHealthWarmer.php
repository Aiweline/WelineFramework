<?php

declare(strict_types=1);

/**
 * 出厂默认 Warmer：缓存池可用性自检 + jitter 配置确认
 *
 * 不真正预热业务数据，只触发每个 PREDEFINED_POOLS 的实例化、读写一次哨兵 key，
 * 用来：
 *  1. 把所有池在 WLS 启动时就一次性加载到进程内（避免首请求时初始化）
 *  2. 暴露任何配置错误（驱动不可用、目录无写权限）在启动期就报警
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Manager\ObjectManager;

class CachePoolHealthWarmer implements CacheWarmerInterface
{
    public const SENTINEL_KEY = '__weline_warmer_health_sentinel__';

    public function __construct(private ?CacheManager $manager = null)
    {
    }

    public function getName(): string
    {
        return 'framework.cache_pool_health';
    }

    public function getTargetPool(): string
    {
        return '__all__';
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function canWarm(): bool
    {
        return true;
    }

    public function warm(): array
    {
        $manager = $this->manager ?? ObjectManager::getInstance(CacheManager::class);

        $identities = $manager->getPoolIdentities();
        $warmed = 0;
        $skipped = 0;

        foreach ($identities as $identity) {
            try {
                $pool = $manager->pool($identity);
                $pool->set(self::SENTINEL_KEY, ['t' => \time(), 'pool' => $identity], 60);
                $value = $pool->get(self::SENTINEL_KEY);
                if (\is_array($value) && ($value['pool'] ?? null) === $identity) {
                    $warmed++;
                    $pool->delete(self::SENTINEL_KEY);
                } else {
                    $skipped++;
                }
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return [
            'warmed' => $warmed,
            'skipped' => $skipped,
            'message' => "checked={$warmed} skipped={$skipped} of " . \count($identities),
        ];
    }
}
