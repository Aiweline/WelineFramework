<?php

declare(strict_types=1);

namespace Weline\Framework\Event\ResourceChange\Observer;

use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/** Critical synchronous observer that advances the DB namespace authority. */
final class CacheNamespaceObserver implements ObserverInterface
{
    public function __construct(private readonly NamespaceGenerationRepository $namespaces)
    {
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new \InvalidArgumentException(__('资源变更缓存 Observer 只接受 ResourceChange v1'));
        }

        $impact = $change->toArray()['impact'] ?? [];
        $paths = array_values(array_unique(array_merge(
            is_array($impact['namespaces'] ?? null) ? $impact['namespaces'] : [],
            is_array($impact['previous_namespaces'] ?? null) ? $impact['previous_namespaces'] : [],
        )));
        if ($paths === []) {
            return;
        }

        $this->namespaces->bumpMany($paths);
    }
}
