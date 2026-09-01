<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Cache\Service\CacheWarmerRegistry;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\StorefrontFpcWarmer;

/**
 * Registers Theme storefront FPC warmer before Framework warm_on_server_start.
 */
final class RegisterStorefrontFpcWarmer implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            /** @var CacheWarmerRegistry $registry */
            $registry = ObjectManager::getInstance(CacheWarmerRegistry::class);
            if (!$registry->has('theme.storefront_fpc')) {
                $registry->register(ObjectManager::getInstance(StorefrontFpcWarmer::class));
            }
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[Theme] register storefront FPC warmer failed: ' . $e->getMessage());
            }
        }
    }
}
