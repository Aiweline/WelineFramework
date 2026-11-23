<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Shipping\Api\Data\Shipping\ShippingCoverageRow;
use Weline\Shipping\Api\ShippingCoverageSourceInterface;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingService;

/**
 * 配送覆盖读模型实现：服务 + 国家覆盖 + 费率模板 → 一国一行。
 *
 * 口径：`cost_minor` 是该服务费率模板的最小分档价（起运费），不是逐重量精算；
 * 精确金额仍由结账页实时计算，本模型只用于政策页展示与 GMC 出口。
 */
final class ShippingCoverageService implements ShippingCoverageSourceInterface
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return list<ShippingCoverageRow>
     */
    public function listCoverage(int $websiteId = -1, int $storeId = -1, string $currency = ''): array
    {
        $websiteId = $websiteId >= 0 ? $websiteId : max(0, (int)RequestContext::getWelineWebsiteId());
        $storeId = $storeId >= 0 ? $storeId : max(0, (int)RequestContext::getWelineStoreId());

        $services = $this->scopeServices($this->loadActiveServices(), $websiteId, $storeId);
        if ($services === []) {
            return [];
        }
        $regionsByService = $this->loadCountryRegions(array_keys($services));
        $templates = $this->loadTemplates();

        $rows = [];
        foreach ($services as $serviceId => $service) {
            $template = $templates[(int)($service[ShippingService::schema_fields_RATE_TEMPLATE_ID] ?? 0)] ?? null;
            if ($template === null) {
                continue;
            }
            $costMinor = self::minCostMinorFromTemplate($template);
            $templateCurrency = strtoupper(trim((string)($template[RateTemplate::schema_fields_CURRENCY_CODE] ?? '')));
            $serviceCurrency = strtoupper(trim($currency));
            $rowCurrency = $templateCurrency !== '' ? $templateCurrency : $serviceCurrency;
            if ($serviceCurrency !== '' && $templateCurrency !== '' && $serviceCurrency !== $templateCurrency) {
                // 币种不一致时不静默换算：交给上层按缺失处理，避免输出错价。
                continue;
            }
            $candidate = new ShippingCoverageRow(
                countryCode: '',
                daysMin: max(0, (int)($service[ShippingService::schema_fields_ESTIMATED_DAYS_MIN] ?? 0)),
                daysMax: max(0, (int)($service[ShippingService::schema_fields_ESTIMATED_DAYS_MAX] ?? 0)),
                costMinor: $costMinor,
                currency: $rowCurrency,
                serviceCode: (string)($service[ShippingService::schema_fields_SERVICE_CODE] ?? ''),
            );
            foreach ($regionsByService[$serviceId] ?? [] as $countryCode) {
                $row = new ShippingCoverageRow(
                    countryCode: $countryCode,
                    daysMin: $candidate->daysMin,
                    daysMax: $candidate->daysMax,
                    costMinor: $candidate->costMinor,
                    currency: $candidate->currency,
                    serviceCode: $candidate->serviceCode,
                );
                $rows[$countryCode] = self::preferredRow($rows[$countryCode] ?? null, $row);
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * 同一国家多条服务时的取舍：有时效优先 → 起运费低优先 → 时效短优先 → 服务码稳定排序。
     */
    public static function preferredRow(?ShippingCoverageRow $current, ShippingCoverageRow $candidate): ShippingCoverageRow
    {
        if ($current === null) {
            return $candidate;
        }
        $currentRank = [$current->hasTransitDays() ? 0 : 1, $current->hasCost() ? $current->costMinor : PHP_INT_MAX, $current->daysMin, $current->serviceCode];
        $candidateRank = [$candidate->hasTransitDays() ? 0 : 1, $candidate->hasCost() ? $candidate->costMinor : PHP_INT_MAX, $candidate->daysMin, $candidate->serviceCode];
        foreach ($currentRank as $index => $value) {
            if ($candidateRank[$index] === $value) {
                continue;
            }

            return $candidateRank[$index] < $value ? $candidate : $current;
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $template
     */
    public static function minCostMinorFromTemplate(array $template): int
    {
        $calculationType = strtolower(trim((string)($template[RateTemplate::schema_fields_CALCULATION_TYPE] ?? '')));
        $prices = [];
        $rawBrackets = $template[RateTemplate::schema_fields_RATE_BRACKETS] ?? null;
        if (is_string($rawBrackets) && trim($rawBrackets) !== '') {
            $decoded = json_decode($rawBrackets, true);
            if (is_array($decoded)) {
                foreach ($decoded as $bracket) {
                    if (!is_array($bracket)) {
                        continue;
                    }
                    $price = (float)($bracket['price'] ?? 0);
                    if ($price > 0) {
                        $prices[] = $price;
                    }
                }
            }
        }
        if ($prices === []) {
            $baseFee = (float)($template[RateTemplate::schema_fields_BASE_FEE] ?? 0);
            if ($baseFee > 0) {
                $prices[] = $baseFee;
            }
        }
        if ($prices === []) {
            return 0;
        }
        $min = min($prices);

        return (int)round($min * 100);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveServices(): array
    {
        /** @var ShippingService $model */
        $model = $this->objectManager->getInstance(ShippingService::class);
        $list = $model->clear()
            ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        $services = [];
        foreach ($list as $row) {
            $serviceId = (int)($row[ShippingService::schema_fields_ID] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }
            $services[$serviceId] = $row;
        }

        return $services;
    }

    /**
     * @param array<int, array<string, mixed>> $services
     * @return array<int, array<string, mixed>>
     */
    private function scopeServices(array $services, int $websiteId, int $storeId): array
    {
        $scoped = [];
        foreach ($services as $serviceId => $row) {
            $scopeType = (string)($row[ShippingService::schema_fields_SCOPE_TYPE] ?? '');
            $scopeId = (int)($row[ShippingService::schema_fields_SCOPE_ID] ?? 0);
            $matches = match ($scopeType) {
                ShippingService::SCOPE_WEBSITE => $scopeId === 0 || $scopeId === $websiteId,
                ShippingService::SCOPE_STORE => $storeId > 0 && $scopeId === $storeId,
                default => false,
            };
            if ($matches) {
                $scoped[$serviceId] = $row;
            }
        }

        return $scoped;
    }

    /**
     * @param list<int> $serviceIds
     * @return array<int, list<string>>
     */
    private function loadCountryRegions(array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }
        /** @var ServiceRegion $model */
        $model = $this->objectManager->getInstance(ServiceRegion::class);
        $list = $model->clear()
            ->where(ServiceRegion::schema_fields_IS_ACTIVE, 1)
            ->where(ServiceRegion::schema_fields_REGION_TYPE, ServiceRegion::TYPE_COUNTRY)
            ->select()
            ->fetchArray();
        $wanted = array_flip($serviceIds);
        $grouped = [];
        foreach ($list as $row) {
            $serviceId = (int)($row[ServiceRegion::schema_fields_SERVICE_ID] ?? 0);
            if (!isset($wanted[$serviceId])) {
                continue;
            }
            $countryCode = strtoupper(trim((string)($row[ServiceRegion::schema_fields_COUNTRY_CODE] ?? '')));
            if (strlen($countryCode) !== 2) {
                continue;
            }
            $grouped[$serviceId][$countryCode] = $countryCode;
        }
        $result = [];
        foreach ($grouped as $serviceId => $codes) {
            $result[$serviceId] = array_values($codes);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTemplates(): array
    {
        /** @var RateTemplate $model */
        $model = $this->objectManager->getInstance(RateTemplate::class);
        $list = $model->clear()
            ->where(RateTemplate::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();
        $templates = [];
        foreach ($list as $row) {
            $id = (int)($row[RateTemplate::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $templates[$id] = $row;
            }
        }

        return $templates;
    }
}
