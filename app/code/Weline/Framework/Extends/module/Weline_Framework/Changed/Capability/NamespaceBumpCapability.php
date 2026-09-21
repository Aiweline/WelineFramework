<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * sync bump namespaces（原 CacheNamespaceObserver）。
 * 提交后可选 publish 到 WLS Worker（加速 process 快照；库代次仍是权威）。
 */
final class NamespaceBumpCapability implements ChangedCapabilityInterface
{
    public function code(): string
    {
        return 'namespace_bump';
    }

    public function description(): string
    {
        return '事务内 bump impact.namespaces';
    }

    public function supportedEffects(): array
    {
        return [InvalidationEffect::CODE_BUMP_NAMESPACES];
    }

    public function execute(InvalidationEffect $effect, ResourceChange $change): void
    {
        if ($effect->code !== InvalidationEffect::CODE_BUMP_NAMESPACES) {
            return;
        }
        $impact = $change->toArray()['impact'] ?? [];
        $paths = array_values(array_unique(array_merge(
            is_array($effect->payload['namespaces'] ?? null) ? $effect->payload['namespaces'] : [],
            is_array($impact['namespaces'] ?? null) ? $impact['namespaces'] : [],
            is_array($impact['previous_namespaces'] ?? null) ? $impact['previous_namespaces'] : [],
        )));
        if ($paths === []) {
            return;
        }
        /** @var NamespaceGenerationRepository $repo */
        $repo = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $requestId = (string)(RequestContext::getId() ?? '');
        $repo->bumpMany($paths, [
            'changed.namespace_bump.publish' => static function (int $clock, array $versions) use ($requestId): void {
                try {
                    $publisher = ObjectManager::getInstance(RuntimeProviderResolver::class)
                        ->resolve(RuntimeNamespaceInvalidationPublisherInterface::class);
                    if ($publisher instanceof RuntimeNamespaceInvalidationPublisherInterface) {
                        $publisher->publish($clock, $versions, null, $requestId);
                    }
                } catch (\Throwable) {
                    // 已提交代次仍是权威；运行时广播只加速其他 Worker 观察。
                }
            },
        ]);
        unset($change);
    }
}
