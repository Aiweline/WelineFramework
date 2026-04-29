<?php

declare(strict_types=1);

/**
 * URL Guard 溢出追踪 Observer
 *
 * 订阅 `Weline_Framework_Router::guard::overflow` 事件，把每条越界请求的
 * - guard_name
 * - 触发参数名
 * - 命中的 value（如 max id 越界时的实际值）
 * - 当前观测到的 min/max 边界
 * - 最近样本（环形数组，最多 N 条，避免无限增长）
 *
 * 写入 `url_guard` 缓存池。CDN/告警/队列模块可读取该缓存生成黑名单或阻断规则。
 *
 * 单一职责：只负责"记录"，不负责"拦截"（拦截在 UrlGuardObserver 完成）、
 * 也不负责"清理"（由后续 Cron / CLI 命令滚动），符合 SRP。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Router\Observer;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class UrlGuardOverflowTracker implements ObserverInterface
{
    public const CACHE_POOL = 'url_guard';
    public const KEY_PREFIX = 'overflow:';
    public const RECENT_SAMPLES_MAX = 50;

    /** @var CachePoolInterface|null 测试时可注入 */
    private static ?CachePoolInterface $injectedPool = null;

    public function execute(Event &$event): void
    {
        try {
            $payload = $this->resolvePayload($event);
            $guardName = (string)($payload['guard_name'] ?? '');
            if ($guardName === '') {
                return;
            }

            $pool = $this->resolvePool();
            $key = self::KEY_PREFIX . $guardName;

            $existing = $pool->get($key);
            if (!\is_array($existing)) {
                $existing = [
                    'guard_name' => $guardName,
                    'count' => 0,
                    'first_seen_at' => 0,
                    'last_seen_at' => 0,
                    'min_value' => null,
                    'max_value' => null,
                    'recent' => [],
                ];
            }

            $details = (array)($payload['details'] ?? []);
            $value = $details['value'] ?? null;
            $now = (int)($payload['timestamp'] ?? \time());

            $existing['count'] = (int)$existing['count'] + 1;
            $existing['last_seen_at'] = $now;
            if ((int)$existing['first_seen_at'] === 0) {
                $existing['first_seen_at'] = $now;
            }

            if (\is_numeric($value)) {
                $numeric = $value + 0;
                if ($existing['min_value'] === null || $numeric < $existing['min_value']) {
                    $existing['min_value'] = $numeric;
                }
                if ($existing['max_value'] === null || $numeric > $existing['max_value']) {
                    $existing['max_value'] = $numeric;
                }
            }

            $sample = [
                't' => $now,
                'uri' => (string)($payload['uri'] ?? ''),
                'param' => (string)($details['param'] ?? ''),
                'value' => $value,
            ];
            $recent = $existing['recent'];
            \array_unshift($recent, $sample);
            $existing['recent'] = \array_slice($recent, 0, self::RECENT_SAMPLES_MAX);

            $pool->set($key, $existing);

            if (\function_exists('agent_log')) {
                \agent_log(
                    'UrlGuardOverflowTracker',
                    'guard overflow recorded',
                    [
                        'guard' => $guardName,
                        'count' => $existing['count'],
                        'value' => $value,
                        'min' => $existing['min_value'],
                        'max' => $existing['max_value'],
                    ],
                    'url-guard'
                );
            }
        } catch (\Throwable $e) {
            // 追踪失败不应影响主拒绝流程
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[UrlGuardOverflowTracker] failed: ' . $e->getMessage());
            }
        }
    }

    public static function setPool(?CachePoolInterface $pool): void
    {
        self::$injectedPool = $pool;
    }

    /**
     * 读取某 guard 的最新统计（外部模块调用，例如 CDN RulesPushDefaults）。
     *
     * @return array<string, mixed>|null
     */
    public static function readSnapshot(string $guardName): ?array
    {
        try {
            $pool = self::$injectedPool ?: ObjectManager::getInstance(CacheManager::class)->pool(self::CACHE_POOL);
            $value = $pool->get(self::KEY_PREFIX . $guardName);
            return \is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePool(): CachePoolInterface
    {
        if (self::$injectedPool !== null) {
            return self::$injectedPool;
        }
        return ObjectManager::getInstance(CacheManager::class)->pool(self::CACHE_POOL);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(Event $event): array
    {
        $data = $event->getData();
        return \is_array($data) ? $data : [];
    }
}
