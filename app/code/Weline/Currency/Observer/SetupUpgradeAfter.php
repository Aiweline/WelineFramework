<?php

declare(strict_types=1);

namespace Weline\Currency\Observer;

use Weline\Currency\Setup\CurrencyLocalDescriptionSeed;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class SetupUpgradeAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if ((bool)($event->getData('route_only') ?? false)) {
            return;
        }

        try {
            ObjectManager::getInstance(CurrencyLocalDescriptionSeed::class)->seedDefaults();
        } catch (\Throwable $e) {
            w_log_error('Currency local description seed failed: ' . $e->getMessage(), [], 'currency_setup');
        }
    }
}
