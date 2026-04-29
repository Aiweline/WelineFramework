<?php

declare(strict_types=1);

/**
 * 服务器启动后预热缓存 Observer
 *
 * 订阅 `Weline_Server::start_after`：在 WLS 主进程启动后立即触发一次全量预热，
 * 让框架"常驻内存"时携带预热好的关键缓存（路由 / 配置 / 翻译 / 等）。
 *
 * 业务模块通过 `register.php` 等钩子向 CacheWarmerRegistry 注册自定义 Warmer。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Observer;

use Weline\Framework\Cache\Service\CachePoolHealthWarmer;
use Weline\Framework\Cache\Service\CacheWarmerRegistry;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class WarmCacheOnServerStart implements ObserverInterface
{
    private static ?CacheWarmerRegistry $injectedRegistry = null;

    public function execute(Event &$event): void
    {
        try {
            $registry = $this->resolveRegistry();
            $result = $registry->warmUp();

            if (\function_exists('agent_log')) {
                \agent_log(
                    'WarmCacheOnServerStart',
                    'cache warmup finished',
                    [
                        'duration_ms' => $result['duration_ms'],
                        'ran' => $result['ran'],
                        'skipped' => $result['skipped'],
                        'warmed' => $result['warmed'],
                        'errors' => \count($result['errors']),
                    ],
                    'cache-warmer'
                );
            }

            if (\function_exists('w_log_info')) {
                \w_log_info(\sprintf(
                    '[CacheWarmup] ran=%d skipped=%d warmed=%d duration_ms=%d errors=%d',
                    $result['ran'],
                    $result['skipped'],
                    $result['warmed'],
                    $result['duration_ms'],
                    \count($result['errors'])
                ));
            }
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[WarmCacheOnServerStart] failed: ' . $e->getMessage());
            }
        }
    }

    public static function setRegistry(?CacheWarmerRegistry $registry): void
    {
        self::$injectedRegistry = $registry;
    }

    private function resolveRegistry(): CacheWarmerRegistry
    {
        if (self::$injectedRegistry !== null) {
            return self::$injectedRegistry;
        }
        $registry = ObjectManager::getInstance(CacheWarmerRegistry::class);
        if (!$registry->has('framework.cache_pool_health')) {
            $registry->register(new CachePoolHealthWarmer());
        }
        return $registry;
    }
}
