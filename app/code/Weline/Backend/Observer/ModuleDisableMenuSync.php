<?php
declare(strict_types=1);

namespace Weline\Backend\Observer;

use Weline\Backend\Service\MenuCollector;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ModuleDisableMenuSync implements ObserverInterface
{
    public function __construct(
        private MenuCollector $menuCollector
    ) {
    }

    public function execute(Event &$event): void
    {
        $moduleNames = $event->getData('module_names');
        if (!is_array($moduleNames) || empty($moduleNames)) {
            return;
        }
        $moduleNames = array_values(array_filter(array_map('strval', $moduleNames)));
        if (empty($moduleNames)) {
            return;
        }
        $this->menuCollector->collect($moduleNames);
    }
}
