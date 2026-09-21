<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Http\Fpc\FpcStoreAdapterRegistry;
use Weline\Framework\Manager\ObjectManager;

/**
 * FPC 失效：仅经活跃 FpcStoreAdapter，不直捅 CacheManager。
 */
final class FpcCapability implements ChangedCapabilityInterface
{
    public function code(): string
    {
        return 'fpc';
    }

    public function description(): string
    {
        return 'Full Page Cache 失效（urls / all）';
    }

    public function supportedEffects(): array
    {
        return [
            InvalidationEffect::CODE_PURGE_FPC_URLS,
            InvalidationEffect::CODE_PURGE_FPC_ALL,
        ];
    }

    public function execute(InvalidationEffect $effect, ResourceChange $change): void
    {
        try {
            /** @var FpcStoreAdapterRegistry $registry */
            $registry = ObjectManager::getInstance(FpcStoreAdapterRegistry::class);
            $adapter = $registry->active();
        } catch (\Throwable) {
            return;
        }
        if ($adapter === null) {
            return;
        }
        if ($effect->code === InvalidationEffect::CODE_PURGE_FPC_ALL) {
            $adapter->purgeAll((string)($effect->payload['reason'] ?? $change->resourceType()));

            return;
        }
        if ($effect->code !== InvalidationEffect::CODE_PURGE_FPC_URLS) {
            return;
        }
        $urls = \is_array($effect->payload['urls'] ?? null) ? $effect->payload['urls'] : [];
        if ($urls === []) {
            return;
        }
        $adapter->purgeUrls(\array_map('strval', $urls));
    }
}
