<?php

declare(strict_types=1);

namespace Weline\MediaManager\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class BackendWhitelistUrl implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        $whitelist = $data->getData('whitelist_url');
        if (!\is_array($whitelist)) {
            $whitelist = [];
        }
        $whitelist[] = 'media/backend/ai-draw/preview';
        $data->setData('whitelist_url', \array_values(\array_unique($whitelist)));
    }
}
