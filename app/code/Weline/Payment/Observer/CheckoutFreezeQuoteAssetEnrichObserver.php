<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\AssetCheckoutDiscountQuote;

/**
 * Checkout freeze_quote::enrich — Payment 贡献资产折扣额度数据；B2B 只提供类型策略 SPI。
 */
final class CheckoutFreezeQuoteAssetEnrichObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!(bool)$event->getData('discounts_banned')) {
            return;
        }

        $payload = $event->getData('payload');
        if (!is_array($payload)) {
            return;
        }

        $depositAmountMinor = max(0, (int)$event->getData('deposit_amount_minor'));
        $customerId = $event->getData('customer_id');
        $customerIdInt = is_numeric($customerId) ? (int)$customerId : 0;
        $scope = $event->getData('scope');
        $websiteId = is_array($scope) ? (int)($scope['website_id'] ?? 0) : 0;
        $currency = (string)($event->getData('currency') ?: 'CNY');

        if ($customerIdInt <= 0) {
            $payload['b2b_credit'] = AssetCheckoutDiscountQuote::unavailableStub(
                'not_logged_in',
                $depositAmountMinor,
            );
            $event->setData('payload', $payload);

            return;
        }
        if ($depositAmountMinor <= 0) {
            $payload['b2b_credit'] = AssetCheckoutDiscountQuote::unavailableStub(
                'not_applicable',
                $depositAmountMinor,
            );
            $event->setData('payload', $payload);

            return;
        }

        try {
            $quote = ObjectManager::getInstance(AssetCheckoutDiscountQuote::class);
            if ($quote instanceof AssetCheckoutDiscountQuote) {
                $payload['b2b_credit'] = $quote->quote(
                    (string)$customerIdInt,
                    $websiteId,
                    $currency,
                    $depositAmountMinor,
                );
            } else {
                $payload['b2b_credit'] = AssetCheckoutDiscountQuote::unavailableStub(
                    'quote_failed',
                    $depositAmountMinor,
                );
            }
        } catch (\Throwable) {
            $payload['b2b_credit'] = AssetCheckoutDiscountQuote::unavailableStub(
                'quote_failed',
                $depositAmountMinor,
            );
        }

        $event->setData('payload', $payload);
    }
}
