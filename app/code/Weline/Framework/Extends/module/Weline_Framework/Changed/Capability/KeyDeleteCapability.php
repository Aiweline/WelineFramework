<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * 承接原 CacheImpactObserver：afterCommit 删 impact.cache_ops 裸键。
 */
final class KeyDeleteCapability implements ChangedCapabilityInterface
{
    public function code(): string
    {
        return 'cache_ops';
    }

    public function description(): string
    {
        return '按 pool/keys 删除缓存键（原 CacheImpactObserver）';
    }

    public function supportedEffects(): array
    {
        return [InvalidationEffect::CODE_CACHE_OPS_DELETE];
    }

    public function execute(InvalidationEffect $effect, ResourceChange $change): void
    {
        if ($effect->code !== InvalidationEffect::CODE_CACHE_OPS_DELETE) {
            return;
        }
        $ops = $effect->payload['cache_ops'] ?? ($change->toArray()['impact']['cache_ops'] ?? []);
        if (!is_array($ops) || $ops === []) {
            return;
        }
        $byPool = [];
        foreach ($ops as $op) {
            if (!is_array($op)) {
                continue;
            }
            $pool = trim((string)($op['pool'] ?? ''));
            $keys = $op['keys'] ?? null;
            if ($pool === '' || !is_array($keys) || $keys === []) {
                continue;
            }
            foreach ($keys as $key) {
                $key = trim((string)$key);
                if ($key !== '') {
                    $byPool[$pool][$key] = $key;
                }
            }
        }
        foreach ($byPool as $pool => $keys) {
            $cache = w_cache($pool);
            foreach ($keys as $key) {
                $cache->delete($key);
            }
        }
    }
}
