<?php

declare(strict_types=1);

namespace Weline\Dropship\Observer;

use Weline\Dropship\Service\DropshipCheckoutFreightService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Shipping methods enrich. block_checkout → empty list; fallback_local → keep local amounts.
 */
final class CheckoutShippingMethodsEnrichObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $methods = $event->getData('methods');
        $lines = $event->getData('lines');
        if (!is_array($methods) || !is_array($lines) || $lines === []) {
            return;
        }

        $address = $event->getData('address');
        $scope = $event->getData('scope');
        $currency = (string)($event->getData('currency') ?: 'CNY');
        if (!is_array($address)) {
            $address = [];
        }
        if (!is_array($scope)) {
            $scope = [];
        }

        try {
            /** @var DropshipCheckoutFreightService $svc */
            $svc = ObjectManager::getInstance(DropshipCheckoutFreightService::class);
            $hadDropship = $svc->resolveFreightLines($lines) !== [];
            $out = $svc->overlayMethods($methods, $lines, $address, $scope, $currency);
        } catch (\Throwable $e) {
            $event->setData('methods', []);
            $event->setData('error', DropshipCheckoutFreightService::ERROR_FREIGHT_UNAVAILABLE);
            if (\function_exists('w_log_warning')) {
                w_log_warning('dropship shipping methods enrich exception: ' . $e->getMessage());
            }

            return;
        }

        $event->setData('methods', $out);
        if ($hadDropship && $out === [] && $methods !== []) {
            $event->setData('error', DropshipCheckoutFreightService::ERROR_FREIGHT_UNAVAILABLE);
        }
    }
}
