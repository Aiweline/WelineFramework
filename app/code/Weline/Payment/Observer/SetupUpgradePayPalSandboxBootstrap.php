<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PayPalSandboxBootstrapService;

final class SetupUpgradePayPalSandboxBootstrap implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var PayPalSandboxBootstrapService $bootstrap */
        $bootstrap = ObjectManager::getInstance(PayPalSandboxBootstrapService::class);
        $bootstrap->bootstrapIfNeeded();
    }
}
