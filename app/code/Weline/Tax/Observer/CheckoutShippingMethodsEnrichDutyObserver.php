<?php

declare(strict_types=1);

namespace Weline\Tax\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Tax\Service\DutyEstimateService;

/**
 * Attach Tax-owned duty estimates onto checkout shipping method rows.
 * Does not change shipping amount_minor (Shipping Incoterm contract).
 */
final class CheckoutShippingMethodsEnrichDutyObserver implements ObserverInterface
{
    public function __construct(
        private readonly DutyEstimateService $duty = new DutyEstimateService(),
    ) {
    }

    public function execute(Event &$event): void
    {
        $methods = $event->getData('methods');
        $lines = $event->getData('lines');
        if (!is_array($methods) || $methods === []) {
            return;
        }

        $address = $event->getData('address');
        $scope = $event->getData('scope');
        $currency = strtoupper(trim((string)($event->getData('currency') ?: 'CNY'))) ?: 'CNY';
        if (!is_array($address)) {
            $address = [];
        }
        if (!is_array($scope)) {
            $scope = [];
        }

        $goodsMinor = $this->goodsSubtotalMinor(is_array($lines) ? $lines : []);
        $dest = strtoupper(trim((string)(
            $address['country_code']
            ?? $address['country']
            ?? ''
        )));
        $origin = strtoupper(trim((string)(
            $scope['origin_country']
            ?? $scope['seller_country']
            ?? 'CN'
        ))) ?: 'CN';

        $out = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $shippingMinor = max(0, (int)($method['amount_minor'] ?? 0));
            if ($shippingMinor === 0 && isset($method['amount'])) {
                $shippingMinor = (int) round(((float)$method['amount']) * 100);
            }
            $estimate = $this->duty->estimate([
                'goods_subtotal_minor' => $goodsMinor,
                'shipping_amount_minor' => $shippingMinor,
                'destination_country' => $dest,
                'origin_country' => $origin,
                'duty_notice' => (string)($method['duty_notice'] ?? ''),
                'currency' => $currency,
            ]);
            $charged = (int)$estimate['charged_minor'];
            $method['duty_amount_minor'] = (int)$estimate['duty_amount_minor'];
            $method['import_tax_amount_minor'] = (int)$estimate['import_tax_amount_minor'];
            $method['tax_amount_minor'] = $charged;
            $method['tax_amount'] = $charged / 100;
            $method['duty_estimate_reason'] = (string)$estimate['reason'];
            $out[] = $method;
        }

        $event->setData('methods', $out);
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    private function goodsSubtotalMinor(array $lines): int
    {
        $sum = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (isset($line['row_total_minor'])) {
                $sum += max(0, (int)$line['row_total_minor']);
                continue;
            }
            $qty = (float)($line['qty'] ?? $line['quantity'] ?? 1);
            $price = (float)($line['price'] ?? 0);
            $row = (float)($line['row_total'] ?? ($qty * $price));
            $sum += (int) round($row * 100);
        }

        return max(0, $sum);
    }
}
