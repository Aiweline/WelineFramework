<?php

declare(strict_types=1);

namespace Weline\Dropship\Observer;

use Weline\Dropship\Service\DropshipCheckoutFreightService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * After Shipping quote: overlay provider freight.
 * Failure behavior follows each Provider's freightOnFailure() config.
 */
final class CheckoutShippingQuoteOverlayObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $lines = $event->getData('lines');
        if (!is_array($lines) || $lines === []) {
            return;
        }

        $address = $event->getData('address');
        $scope = $event->getData('scope');
        $currency = (string)($event->getData('currency') ?: 'CNY');
        $localAmount = (int)$event->getData('amount_minor');
        $packages = $event->getData('split_packages');
        if (!is_array($address)) {
            $address = [];
        }
        if (!is_array($scope)) {
            $scope = [];
        }
        if (!is_array($packages)) {
            $packages = [];
        }

        try {
            /** @var DropshipCheckoutFreightService $svc */
            $svc = ObjectManager::getInstance(DropshipCheckoutFreightService::class);
            $result = $svc->overlayAmount(
                $localAmount,
                $lines,
                $address,
                $scope,
                $currency,
                $packages,
            );
        } catch (\Throwable $e) {
            $event->setData('error', DropshipCheckoutFreightService::ERROR_FREIGHT_UNAVAILABLE);
            $event->setData('applied', true);
            $event->setData('amount_minor', $localAmount);
            if (\function_exists('w_log_warning')) {
                w_log_warning('dropship checkout freight overlay exception: ' . $e->getMessage());
            }

            return;
        }

        if (!empty($result['error'])) {
            $event->setData('error', (string)$result['error']);
            $event->setData('applied', true);
            $event->setData('failure_mode', (string)($result['failure_mode'] ?? ''));

            return;
        }
        if (!empty($result['degraded'])) {
            $event->setData('applied', false);
            $event->setData('degraded', true);
            $event->setData('degrade_reason', (string)($result['degrade_reason'] ?? DropshipCheckoutFreightService::ERROR_FREIGHT_UNAVAILABLE));
            $event->setData('amount_minor', $localAmount);
            $quoteArray = $event->getData('quote_array');
            if (is_array($quoteArray)) {
                $quoteArray['dropship_freight_degraded'] = true;
                $quoteArray['dropship_freight_degrade_reason'] = (string)($result['degrade_reason'] ?? '');
                $event->setData('quote_array', $quoteArray);
            }
            if (\function_exists('w_log_warning')) {
                w_log_warning('dropship checkout freight degraded to local shipping per provider policy');
            }

            return;
        }
        if (empty($result['applied'])) {
            return;
        }

        $event->setData('applied', true);
        $event->setData('degraded', false);
        $event->setData('amount_minor', (int)$result['amount_minor']);
        $event->setData('split_packages', $result['split_packages']);
        $event->setData('dropship_freight_segments', $result['segments']);
        $quoteArray = $event->getData('quote_array');
        if (is_array($quoteArray)) {
            $quoteArray['amount_minor'] = (int)$result['amount_minor'];
            $quoteArray['dropship_freight_segments'] = $result['segments'];
            $quoteArray['dropship_freight_degraded'] = false;
            $event->setData('quote_array', $quoteArray);
        }
    }
}
