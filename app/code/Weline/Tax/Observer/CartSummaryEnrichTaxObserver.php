<?php

declare(strict_types=1);

namespace Weline\Tax\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Tax\Api\CheckoutTaxAdvisorInterface;
use Weline\Tax\Service\TaxConflictException;
use Weline\Tax\Service\TaxScopeConfig;

/**
 * Attach estimated sales tax onto cart summary (no shipping/duty until checkout).
 */
final class CartSummaryEnrichTaxObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $summary = $event->getData('summary');
        if (!is_array($summary) || !empty($summary['is_empty'])) {
            return;
        }
        $items = $summary['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return;
        }

        try {
            /** @var CheckoutTaxAdvisorInterface $advisor */
            $advisor = ObjectManager::getInstance(CheckoutTaxAdvisorInterface::class);
        } catch (\Throwable) {
            return;
        }

        $scope = $event->getData('scope');
        if (!is_array($scope)) {
            $scope = [];
        }
        $websiteId = (int)($scope['website_id'] ?? RequestContext::getWelineWebsiteId() ?? 0);
        $storeId = (int)($scope['store_id'] ?? RequestContext::getWelineStoreId() ?? 0);
        $channelId = (int)($scope['channel_id'] ?? RequestContext::getWelineChannelId() ?? 0);
        $currency = strtoupper(trim((string)($summary['currency'] ?? 'CNY'))) ?: 'CNY';

        $orders = [[
            'items' => $this->taxLines($items),
        ]];
        if ($orders[0]['items'] === []) {
            return;
        }

        $address = $this->estimateAddress($scope);
        try {
            $tax = $advisor->quoteTax($orders, [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ], $address, $currency, []);
        } catch (TaxConflictException) {
            return;
        } catch (\Throwable) {
            return;
        }

        $taxMinor = max(0, (int)($tax['tax_amount_minor'] ?? 0));
        $salesMinor = max(0, (int)($tax['sales_tax_amount_minor'] ?? $taxMinor));
        $subtotalMinor = max(0, (int)($summary['subtotal_minor'] ?? 0));
        $summary['tax_amount_minor'] = $taxMinor;
        $summary['tax_amount'] = round($taxMinor / 100, 2);
        $summary['sales_tax_amount_minor'] = $salesMinor;
        $summary['duty_amount_minor'] = max(0, (int)($tax['duty_amount_minor'] ?? 0));
        $summary['tax_estimate_note'] = (string)($tax['note'] ?? '');
        $summary['tax_jurisdiction_key'] = (string)($tax['jurisdiction_key'] ?? '');
        $summary['grand_total_minor'] = $subtotalMinor + $taxMinor;
        $summary['grand_total'] = round(($subtotalMinor + $taxMinor) / 100, 2);
        $event->setData('summary', $summary);
    }

    /**
     * @param list<mixed> $items
     * @return list<array{line_uuid:string,tax_class_code:string,row_total_minor:int}>
     */
    private function taxLines(array $items): array
    {
        $lines = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $lineId = trim((string)($item['line_uuid'] ?? $item['item_id'] ?? ''));
            if ($lineId === '') {
                $lineId = 'cart-line-' . $index;
            }
            $lines[] = [
                'line_uuid' => $lineId,
                'tax_class_code' => trim((string)($item['tax_class_code'] ?? 'standard')) ?: 'standard',
                'row_total_minor' => max(0, (int)($item['row_total_minor'] ?? 0)),
            ];
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{country_code:string,region_code:string}
     */
    private function estimateAddress(array $scope): array
    {
        $country = strtoupper(trim((string)(
            $scope['country_code']
            ?? $scope['destination_country']
            ?? ''
        )));
        $region = strtoupper(trim((string)(
            $scope['region_code']
            ?? $scope['region']
            ?? ''
        )));
        if ($country === '') {
            $jurisdiction = $this->defaultJurisdiction(
                (int)($scope['website_id'] ?? 0),
                (int)($scope['store_id'] ?? 0),
            );
            [$country, $region] = array_pad(explode('|', $jurisdiction, 2), 2, '');
            $country = strtoupper(trim($country)) ?: 'CN';
            $region = strtoupper(trim($region));
        }

        return [
            'country_code' => $country,
            'region_code' => $region,
        ];
    }

    private function defaultJurisdiction(int $websiteId, int $storeId): string
    {
        try {
            $resolved = (new TaxScopeConfig())->resolve(max(0, $websiteId), max(0, $storeId));
            $key = strtoupper(trim((string)($resolved['default_jurisdiction'] ?? 'CN|')));

            return $key !== '' ? $key : 'CN|';
        } catch (\Throwable) {
            return 'CN|';
        }
    }
}
