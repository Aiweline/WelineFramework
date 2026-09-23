<?php

declare(strict_types=1);

namespace Weline\Shipping\Service\Provider;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Exception\ShippingRateUnavailableException;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\PackingSplitter;
use Weline\Shipping\Service\RateCalculationService;
use Weline\Shipping\Service\ShippingCheckoutAddonService;
use Weline\Shipping\Service\ShippingIncotermService;
use Weline\Shipping\Service\ShippingPackingPolicyService;
use Weline\Shipping\Service\ShippingSeasonalSurchargeService;
use Weline\Shipping\Service\ShippingSurchargeService;

/**
 * Local template + FX + free-shipping pricing used exclusively by LocalRateTemplateProvider.
 */
final class LocalTemplatePricingService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly RateCalculationService $rateCalculationService,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $matchedServices
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $destAddress
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @param array<string, mixed> $addons
     * @return array{rates: array<string, array<string, mixed>>, fx_skipped: list<string>, unavailable_reasons: list<string>}
     */
    public function priceMatchedServices(
        array $matchedServices,
        array $lines,
        array $destAddress,
        string $currency,
        int $currencyPrecision,
        ?int $freeShippingSubtotalMinor,
        ?array $context = null,
        array $addons = [],
    ): array {
        $currency = strtoupper(trim($currency));
        $subtotalMinor = $freeShippingSubtotalMinor !== null
            ? max(0, $freeShippingSubtotalMinor)
            : $this->subtotalMinor($lines);
        $rates = [];
        $fxSkipped = [];
        $unavailableReasons = [];
        /** @var ShippingSurchargeService $surchargeSvc */
        $surchargeSvc = $this->objectManager->getInstance(ShippingSurchargeService::class);
        /** @var ShippingSeasonalSurchargeService $seasonalSvc */
        $seasonalSvc = $this->objectManager->getInstance(ShippingSeasonalSurchargeService::class);
        /** @var ShippingCheckoutAddonService $addonSvc */
        $addonSvc = $this->objectManager->getInstance(ShippingCheckoutAddonService::class);
        /** @var ShippingPackingPolicyService $packingPolicy */
        $packingPolicy = $this->objectManager->getInstance(ShippingPackingPolicyService::class);
        /** @var PackingSplitter $packer */
        $packer = $this->objectManager->getInstance(PackingSplitter::class);
        $limits = $packingPolicy->resolveLimits($context);
        $boxes = $packer->splitBoxes($lines, $limits['max_weight_kg'], $limits['max_volume_cm3']);
        if ($boxes === []) {
            $boxes = [$lines];
        }

        foreach ($matchedServices as $summary) {
            $serviceId = (int)($summary['service_id'] ?? 0);
            $serviceCode = trim((string)($summary['service_code'] ?? ''));
            if ($serviceId <= 0 || $serviceCode === '') {
                continue;
            }
            /** @var ShippingService $service */
            $service = $this->objectManager->getInstance(ShippingService::class, [], false)->load($serviceId);
            if (!$service->getId()
                || !(bool)$service->getData(ShippingService::schema_fields_IS_ACTIVE)
            ) {
                continue;
            }
            $freeReason = $this->freeReason($service, $subtotalMinor, $currencyPrecision, $destAddress);
            $baseMinor = 0;
            $publicPolicy = null;
            $boxCount = count($boxes);
            if ($freeReason === null) {
                $templateId = (int)$service->getData(ShippingService::schema_fields_RATE_TEMPLATE_ID);
                if ($templateId <= 0) {
                    continue;
                }
                /** @var RateTemplate $template */
                $template = $this->objectManager->getInstance(RateTemplate::class, [], false)->load($templateId);
                if (!$template->getId()
                    || !(bool)$template->getData(RateTemplate::schema_fields_IS_ACTIVE)
                ) {
                    continue;
                }
                $templateCurrency = strtoupper(trim((string)$template->getData(
                    RateTemplate::schema_fields_CURRENCY_CODE,
                )));
                if ($templateCurrency === '') {
                    continue;
                }
                $publicPolicy = $template->getMixedConfig()['public_tariff']['surcharge_policy'] ?? null;
                $sourcePrecision = $publicPolicy !== null && $templateCurrency === 'CNY' ? 2 : $currencyPrecision;
                $sumMinor = 0;
                $unavailable = false;
                foreach ($boxes as $boxLines) {
                    try {
                        $sumMinor += $this->rateCalculationService->calculateTemplateMinor(
                            $template,
                            $boxLines,
                            $sourcePrecision,
                            $subtotalMinor,
                        );
                    } catch (ShippingRateUnavailableException $e) {
                        $unavailable = true;
                        $reason = trim((string)$e->getMessage());
                        if ($reason !== '') {
                            $unavailableReasons[] = $reason;
                        }
                        break;
                    }
                }
                if ($unavailable) {
                    continue;
                }
                $baseMinor = $sumMinor;
                if ($templateCurrency !== $currency) {
                    $converted = $this->convertShippingMinor(
                        $baseMinor,
                        $templateCurrency,
                        $currency,
                        $sourcePrecision,
                        $currencyPrecision,
                    );
                    if ($converted === null) {
                        $fxSkipped[] = $serviceCode;
                        continue;
                    }
                    $baseMinor = $converted;
                }
            }
            $surchargeHits = $surchargeSvc->matchRules(
                $destAddress,
                $baseMinor,
                $currencyPrecision,
                $context,
            );
            if ($publicPolicy !== null) {
                $surchargeHits = array_values(array_filter($surchargeHits, static fn(array $hit): bool =>
                    in_array((int)($hit['rule_id'] ?? 0), $publicPolicy['remote_rule_ids'] ?? [], true)));
            }
            $surchargeMinor = $surchargeSvc->sumMinor($surchargeHits);
            $afterRemote = $baseMinor + $surchargeMinor;
            $seasonalHits = $seasonalSvc->matchRules($afterRemote, $currencyPrecision, $context);
            if ($publicPolicy !== null) {
                $seasonalHits = array_values(array_filter($seasonalHits, static fn(array $hit): bool =>
                    in_array((int)($hit['rule_id'] ?? 0), $publicPolicy['seasonal_rule_ids'] ?? [], true)));
            }
            $seasonalMinor = $seasonalSvc->sumMinor($seasonalHits);
            $afterSeasonal = $afterRemote + $seasonalMinor;
            $addonHits = $addonSvc->matchRequested($addons, $afterSeasonal, $currencyPrecision, $context);
            $addonMinor = $addonSvc->sumMinor($addonHits);
            $amountMinor = $afterSeasonal + $addonMinor;
            $incotermSvc = new ShippingIncotermService();
            $incoterm = $incotermSvc->normalize(
                (string)$service->getData(ShippingService::schema_fields_INCOTERM),
            );
            $row = [
                'amount_minor' => $amountMinor,
                'label' => (string)$service->getData(ShippingService::schema_fields_SERVICE_NAME),
                'currencies' => [$currency],
                'lane_specificity' => (int)($summary['lane_specificity'] ?? 0),
                'carrier_id' => (int)($summary['carrier_id'] ?? $service->getData(ShippingService::schema_fields_CARRIER_ID) ?? 0),
                'package_count' => $boxCount,
                'incoterm' => $incoterm,
                'duty_notice' => $incotermSvc->dutyNotice($incoterm),
            ];
            if ($freeReason !== null) {
                $row['free_reason'] = $freeReason;
            }
            if ($surchargeHits !== []) {
                $row['surcharges'] = $surchargeHits;
            }
            if ($seasonalHits !== []) {
                $row['seasonal'] = $seasonalHits;
            }
            if ($addonHits !== []) {
                $row['addons'] = $addonHits;
            }
            $rates[$serviceCode] = $row;
        }

        return [
            'rates' => $rates,
            'fx_skipped' => array_values(array_unique($fxSkipped)),
            'unavailable_reasons' => array_values(array_unique($unavailableReasons)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    private function subtotalMinor(array $lines): int
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            $row = array_key_exists('row_total_minor', $line)
                ? (int)$line['row_total_minor']
                : (int)($line['qty_minor'] ?? 0) * (int)($line['unit_price_minor'] ?? 0);
            if ($row < 0 || $subtotal > PHP_INT_MAX - $row) {
                throw new \OverflowException(__('配送报价小计溢出'));
            }
            $subtotal += $row;
        }

        return $subtotal;
    }

    /**
     * @param array<string, mixed> $destAddress
     */
    private function freeReason(
        ShippingService $service,
        int $subtotalMinor,
        int $currencyPrecision,
        array $destAddress = [],
    ): ?string {
        if ((bool)$service->getData(ShippingService::schema_fields_IS_FREE_SHIPPING)) {
            return 'service_free_shipping';
        }
        $ruleId = (int)$service->getData(ShippingService::schema_fields_FREE_SHIPPING_RULE_ID);
        if ($ruleId <= 0) {
            return null;
        }
        /** @var FreeShippingRule $rule */
        $rule = $this->objectManager->getInstance(FreeShippingRule::class, [], false)->load($ruleId);
        if (!$rule->getId() || !(bool)$rule->getData(FreeShippingRule::schema_fields_IS_ACTIVE)) {
            return null;
        }
        $type = (string)$rule->getData(FreeShippingRule::schema_fields_CONDITION_TYPE);
        $amountOk = true;
        if (
            $type === FreeShippingRule::CONDITION_ORDER_AMOUNT
            || $type === FreeShippingRule::CONDITION_MIXED
        ) {
            $minimumMinor = $this->decimalToMinor(
                (string)$rule->getData(FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT),
                $currencyPrecision,
            );
            $amountOk = $subtotalMinor >= $minimumMinor;
        }
        $regionOk = true;
        if (
            $type === FreeShippingRule::CONDITION_REGION
            || $type === FreeShippingRule::CONDITION_MIXED
        ) {
            $regionOk = $this->destMatchesFreeShippingRegions($rule, $destAddress);
        }
        if ($type === FreeShippingRule::CONDITION_ORDER_AMOUNT) {
            return $amountOk ? 'free_shipping_rule' : null;
        }
        if ($type === FreeShippingRule::CONDITION_REGION) {
            return $regionOk ? 'free_shipping_rule' : null;
        }
        if ($type === FreeShippingRule::CONDITION_MIXED) {
            return ($amountOk && $regionOk) ? 'free_shipping_rule' : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $destAddress
     */
    private function destMatchesFreeShippingRegions(FreeShippingRule $rule, array $destAddress): bool
    {
        $regionIds = $rule->getRegionIds();
        if ($regionIds === []) {
            return true;
        }
        $candidates = array_filter([
            (int)($destAddress['district_region_id'] ?? 0),
            (int)($destAddress['city_region_id'] ?? 0),
            (int)($destAddress['province_region_id'] ?? 0),
        ]);
        foreach ($candidates as $rid) {
            if (\in_array($rid, $regionIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function decimalToMinor(string $decimal, int $precision): int
    {
        $precision = max(0, min(6, $precision));
        $normalized = trim($decimal);
        if ($normalized === '' || !is_numeric($normalized)) {
            return 0;
        }
        $scale = 10 ** $precision;

        return (int)round(((float)$normalized) * $scale);
    }

    private function convertShippingMinor(
        int $minor,
        string $fromCurrency,
        string $toCurrency,
        int $fromPrecision,
        int $toPrecision,
    ): ?int {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        if ($minor === 0 || $from === '' || $to === '' || $from === $to) {
            return max(0, $minor);
        }
        $fromPrecision = max(0, min(6, $fromPrecision));
        $toPrecision = max(0, min(6, $toPrecision));
        try {
            /** @var CurrencyRateService $rates */
            $rates = $this->objectManager->getInstance(CurrencyRateService::class);
            $scaleFrom = 10 ** $fromPrecision;
            $major = $minor / $scaleFrom;
            $convertedMajor = $rates->tryConvert($major, $from, $to);
            if ($convertedMajor === null) {
                return null;
            }

            return max(0, (int)round($convertedMajor * (10 ** $toPrecision)));
        } catch (\Throwable) {
            return null;
        }
    }
}
