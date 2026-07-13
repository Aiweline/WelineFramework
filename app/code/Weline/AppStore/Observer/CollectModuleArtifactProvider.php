<?php

declare(strict_types=1);

namespace Weline\AppStore\Observer;

use Weline\AppStore\Service\AppStoreModuleArtifactProvider;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

final class CollectModuleArtifactProvider implements ObserverInterface
{
    public function __construct(private readonly AppStoreModuleArtifactProvider $provider)
    {
    }

    public function execute(Event &$event): void
    {
        $providers = (array)$event->getData('providers');
        $providers[] = $this->provider;
        $event->setData('providers', $providers);
    }
}
