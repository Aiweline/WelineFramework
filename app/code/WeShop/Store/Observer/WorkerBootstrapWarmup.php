<?php

declare(strict_types=1);

namespace WeShop\Store\Observer;

use WeShop\Store\Service\StoreContextService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;

class WorkerBootstrapWarmup implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!Runtime::isPersistent()) {
            return;
        }

        try {
            /** @var StoreContextService $storeContext */
            $storeContext = ObjectManager::getInstance(StoreContextService::class);
            foreach (['CNY', 'USD'] as $currency) {
                $storeContext->getCurrentStore(null, 'zh_Hans_CN', $currency);
            }
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[StoreWorkerWarmup] ' . $e->getMessage());
            }
        }
    }
}
