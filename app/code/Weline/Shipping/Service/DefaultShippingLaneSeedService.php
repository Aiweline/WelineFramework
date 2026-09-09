<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\CarrierRegion;
use Weline\Shipping\Model\DestinationRegion;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingService;

/**
 * 默认可达市场种子：承运商全球覆盖 + 默认站 website 分档航线（幂等 upsert）。
 */
final class DefaultShippingLaneSeedService
{
    private const CARRIER_CODE = 'WLS_STD';
    private const MARKETS_TSV = 'app/code/Weline/Shipping/data/default-markets/countries.tsv';

    /** @var array<string, array{name:string,fee:float,days_min:int,days_max:int,sort:int}> */
    private const LANE_META = [
        'domestic' => ['name' => '国内标快', 'fee' => 12.00, 'days_min' => 1, 'days_max' => 3, 'sort' => 10],
        'greater_china' => ['name' => '港澳台', 'fee' => 35.00, 'days_min' => 2, 'days_max' => 5, 'sort' => 20],
        'asia_pacific' => ['name' => '亚太', 'fee' => 55.00, 'days_min' => 3, 'days_max' => 7, 'sort' => 30],
        'americas' => ['name' => '美洲', 'fee' => 85.00, 'days_min' => 5, 'days_max' => 12, 'sort' => 40],
        'europe' => ['name' => '欧洲', 'fee' => 75.00, 'days_min' => 5, 'days_max' => 12, 'sort' => 50],
        'oceania' => ['name' => '大洋洲', 'fee' => 80.00, 'days_min' => 5, 'days_max' => 12, 'sort' => 60],
        'latam' => ['name' => '拉美', 'fee' => 95.00, 'days_min' => 7, 'days_max' => 15, 'sort' => 70],
        'middle_east_africa' => ['name' => '中东非洲', 'fee' => 90.00, 'days_min' => 6, 'days_max' => 14, 'sort' => 80],
        'other' => ['name' => '其他可达市场', 'fee' => 100.00, 'days_min' => 7, 'days_max' => 18, 'sort' => 90],
    ];

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @return array{carrier_id:int,coverage:int,templates:int,services:int,regions:int}
     */
    public function seedDefaultWebsite(int $websiteId = 0): array
    {
        $markets = $this->loadMarkets();
        $this->clearConflictingMarketEmbargoes($markets);
        $carrierId = $this->ensureStandardCarrier();
        $coverage = $this->ensureCarrierWorldCoverage($carrierId, $markets);
        $destinations = $this->ensureWebsiteDestinations($websiteId, $markets);
        $scope = [
            'scope_type' => ShippingService::SCOPE_WEBSITE,
            'scope_id' => max(0, $websiteId),
        ];
        $templates = 0;
        $services = 0;
        $regions = 0;
        $freeRuleId = $this->ensureFreeRule($scope);

        foreach (self::LANE_META as $lane => $meta) {
            $countries = $markets[$lane] ?? [];
            if ($countries === []) {
                continue;
            }
            $tplCode = 'SEED_TPL_' . strtoupper($lane);
            $svcCode = 'SEED_LANE_' . strtoupper($lane);
            $templateId = $this->upsertTemplate($scope, $tplCode, $meta['name'] . '运费', (float)$meta['fee']);
            ++$templates;
            $serviceId = $this->upsertService(
                $scope,
                $svcCode,
                $meta['name'],
                $carrierId,
                $templateId,
                $freeRuleId > 0 && $lane === 'domestic' ? $freeRuleId : 0,
                (int)$meta['days_min'],
                (int)$meta['days_max'],
                (int)$meta['sort'],
            );
            ++$services;
            $regions += $this->replaceServiceCountries($serviceId, $countries);
        }

        return [
            'carrier_id' => $carrierId,
            'coverage' => $coverage,
            'destinations' => $destinations,
            'templates' => $templates,
            'services' => $services,
            'regions' => $regions,
        ];
    }

    /**
     * @return array<string, list<string>> lane => country codes
     */
    public function loadMarkets(): array
    {
        $path = BP . self::MARKETS_TSV;
        $out = [];
        if (!is_file($path)) {
            return $out;
        }
        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $i => $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || ($i === 0 && str_contains($line, 'country_code'))) {
                continue;
            }
            $parts = preg_split("/\t|,\s*/", $line) ?: [];
            $cc = strtoupper(trim((string)($parts[0] ?? '')));
            $lane = strtolower(trim((string)($parts[1] ?? 'other')));
            if ($cc === '' || !isset(self::LANE_META[$lane])) {
                continue;
            }
            $out[$lane][] = $cc;
        }
        foreach ($out as $lane => $codes) {
            $out[$lane] = array_values(array_unique($codes));
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function marketCoverageRows(): array
    {
        $rows = [];
        foreach ($this->loadMarkets() as $codes) {
            foreach ($codes as $cc) {
                $rows[] = [
                    'region_type' => CarrierRegion::TYPE_COUNTRY,
                    'country_code' => $cc,
                    'region_id' => null,
                    'region_code' => $cc,
                    'street_id' => null,
                ];
            }
        }

        return $rows;
    }

    private function ensureStandardCarrier(): int
    {
        /** @var Carrier $model */
        $model = $this->objectManager->getInstance(Carrier::class, [], false);
        $items = $model->reset()
            ->where(Carrier::schema_fields_CARRIER_CODE, self::CARRIER_CODE)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;
        if ($existing instanceof Carrier && (int)$existing->getId() > 0) {
            return (int)$existing->getId();
        }

        /** @var Carrier $create */
        $create = $this->objectManager->getInstance(Carrier::class, [], false);
        $create->setData([
            Carrier::schema_fields_CARRIER_CODE => self::CARRIER_CODE,
            Carrier::schema_fields_CARRIER_NAME => 'Weline Standard',
            Carrier::schema_fields_CARRIER_TYPE => Carrier::TYPE_MANUAL,
            Carrier::schema_fields_TRACKING_URL_TEMPLATE => 'https://track.example.com/?n={tracking_number}',
            Carrier::schema_fields_TRACKING_SUPPORT_STATUS => Carrier::TRACKING_SUPPORTED,
            Carrier::schema_fields_IS_ACTIVE => 1,
            Carrier::schema_fields_SORT_ORDER => 10,
        ])->save();

        return (int)$create->getId();
    }

    /**
     * 清除 website/store/channel 上与默认可达市场冲突的禁运（不碰 system 禁运）。
     *
     * @param array<string, list<string>> $markets
     */
    private function clearConflictingMarketEmbargoes(array $markets): void
    {
        $codes = [];
        foreach ($markets as $list) {
            foreach ($list as $cc) {
                $codes[$cc] = true;
            }
        }
        if ($codes === []) {
            return;
        }
        try {
            /** @var EmbargoRegion $model */
            $model = $this->objectManager->getInstance(EmbargoRegion::class, [], false);
            $items = $model->reset()
                ->where(EmbargoRegion::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($items as $item) {
                if (!$item instanceof EmbargoRegion) {
                    continue;
                }
                $scope = (string)$item->getData(EmbargoRegion::schema_fields_SCOPE_TYPE);
                if ($scope === EmbargoRegion::SCOPE_SYSTEM) {
                    continue;
                }
                $cc = strtoupper((string)$item->getData(EmbargoRegion::schema_fields_COUNTRY_CODE));
                if (!isset($codes[$cc])) {
                    continue;
                }
                $item->setData(EmbargoRegion::schema_fields_IS_ACTIVE, 0)->save();
            }
        } catch (\Throwable) {
            // Embargo table may be unavailable during early upgrade.
        }
    }

    /**
     * 商户已维护较完整白名单则不覆盖；仅 CN 等窄名单时扩到默认可达市场。
     *
     * @param array<string, list<string>> $markets
     */
    private function ensureWebsiteDestinations(int $websiteId, array $markets): int
    {
        /** @var DestinationAdminService $admin */
        $admin = $this->objectManager->getInstance(DestinationAdminService::class);
        $existing = $admin->listForScope(DestinationRegion::SCOPE_WEBSITE, max(0, $websiteId));
        // 商户已维护较完整白名单则不覆盖；仅 CN 等窄名单时扩到默认可达市场。
        if (count($existing) > 5) {
            return count($existing);
        }
        $rows = [];
        foreach ($markets as $codes) {
            foreach ($codes as $cc) {
                $rows[] = [
                    'region_type' => DestinationRegion::TYPE_COUNTRY,
                    'country_code' => $cc,
                    'region_id' => 0,
                    'region_code' => $cc,
                    'street_id' => 0,
                ];
            }
        }
        if ($rows === []) {
            return 0;
        }

        return $admin->replaceForScope(DestinationRegion::SCOPE_WEBSITE, max(0, $websiteId), $rows);
    }

    /**
     * @param array<string, list<string>> $markets
     */
    private function ensureCarrierWorldCoverage(int $carrierId, array $markets): int
    {
        if ($carrierId <= 0) {
            return 0;
        }
        /** @var CarrierCoverageAdminService $admin */
        $admin = $this->objectManager->getInstance(CarrierCoverageAdminService::class);
        $rows = $this->marketCoverageRows();
        if ($rows === []) {
            return 0;
        }
        // 仅当覆盖为空或仅有极少国家时扩展；商户已手改多国则跳过。
        $count = $admin->countForCarrier($carrierId);
        if ($count > 5) {
            return $count;
        }

        return $admin->replaceForCarrier($carrierId, $rows);
    }

    /**
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function ensureFreeRule(array $scope): int
    {
        $code = 'SEED_FREE_99';
        /** @var FreeShippingRule $model */
        $model = $this->objectManager->getInstance(FreeShippingRule::class, [], false);
        $items = $model->reset()
            ->where(FreeShippingRule::schema_fields_SCOPE_TYPE, $scope['scope_type'])
            ->where(FreeShippingRule::schema_fields_SCOPE_ID, $scope['scope_id'])
            ->where(FreeShippingRule::schema_fields_RULE_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;
        if ($existing instanceof FreeShippingRule && (int)$existing->getId() > 0) {
            return (int)$existing->getId();
        }

        /** @var FreeShippingRule $create */
        $create = $this->objectManager->getInstance(FreeShippingRule::class, [], false);
        $create->setData([
            FreeShippingRule::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            FreeShippingRule::schema_fields_SCOPE_ID => $scope['scope_id'],
            FreeShippingRule::schema_fields_RULE_NAME => '满99免邮（国内）',
            FreeShippingRule::schema_fields_RULE_CODE => $code,
            FreeShippingRule::schema_fields_CONDITION_TYPE => FreeShippingRule::CONDITION_ORDER_AMOUNT,
            FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT => 99.00,
            FreeShippingRule::schema_fields_IS_ACTIVE => 1,
            FreeShippingRule::schema_fields_PRIORITY => 10,
        ])->save();

        return (int)$create->getId();
    }

    /**
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function upsertTemplate(array $scope, string $code, string $name, float $fee): int
    {
        /** @var RateTemplate $model */
        $model = $this->objectManager->getInstance(RateTemplate::class, [], false);
        $items = $model->reset()
            ->where(RateTemplate::schema_fields_SCOPE_TYPE, $scope['scope_type'])
            ->where(RateTemplate::schema_fields_SCOPE_ID, $scope['scope_id'])
            ->where(RateTemplate::schema_fields_TEMPLATE_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;
        if ($existing instanceof RateTemplate && (int)$existing->getId() > 0) {
            $existing->setData([
                RateTemplate::schema_fields_TEMPLATE_NAME => $name,
                RateTemplate::schema_fields_BASE_FEE => $fee,
                RateTemplate::schema_fields_IS_ACTIVE => 1,
            ])->save();

            return (int)$existing->getId();
        }

        /** @var RateTemplate $create */
        $create = $this->objectManager->getInstance(RateTemplate::class, [], false);
        $create->setData([
            RateTemplate::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            RateTemplate::schema_fields_SCOPE_ID => $scope['scope_id'],
            RateTemplate::schema_fields_TEMPLATE_NAME => $name,
            RateTemplate::schema_fields_TEMPLATE_CODE => $code,
            RateTemplate::schema_fields_CALCULATION_TYPE => RateTemplate::CALC_TYPE_FIXED,
            RateTemplate::schema_fields_BASE_FEE => $fee,
            RateTemplate::schema_fields_CURRENCY_CODE => 'CNY',
            RateTemplate::schema_fields_IS_ACTIVE => 1,
        ])->save();

        return (int)$create->getId();
    }

    /**
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function upsertService(
        array $scope,
        string $code,
        string $name,
        int $carrierId,
        int $templateId,
        int $freeRuleId,
        int $daysMin,
        int $daysMax,
        int $sort,
    ): int {
        /** @var ShippingService $model */
        $model = $this->objectManager->getInstance(ShippingService::class, [], false);
        $items = $model->reset()
            ->where(ShippingService::schema_fields_SCOPE_TYPE, $scope['scope_type'])
            ->where(ShippingService::schema_fields_SCOPE_ID, $scope['scope_id'])
            ->where(ShippingService::schema_fields_SERVICE_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;
        $payload = [
            ShippingService::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            ShippingService::schema_fields_SCOPE_ID => $scope['scope_id'],
            ShippingService::schema_fields_SERVICE_NAME => $name,
            ShippingService::schema_fields_SERVICE_CODE => $code,
            ShippingService::schema_fields_CARRIER_ID => $carrierId,
            ShippingService::schema_fields_RATE_TEMPLATE_ID => $templateId > 0 ? $templateId : null,
            ShippingService::schema_fields_FREE_SHIPPING_RULE_ID => $freeRuleId > 0 ? $freeRuleId : null,
            ShippingService::schema_fields_ESTIMATED_DAYS_MIN => $daysMin,
            ShippingService::schema_fields_ESTIMATED_DAYS_MAX => $daysMax,
            ShippingService::schema_fields_IS_FREE_SHIPPING => 0,
            ShippingService::schema_fields_IS_ACTIVE => 1,
            ShippingService::schema_fields_SORT_ORDER => $sort,
        ];
        if ($existing instanceof ShippingService && (int)$existing->getId() > 0) {
            $existing->setData($payload)->save();

            return (int)$existing->getId();
        }

        /** @var ShippingService $create */
        $create = $this->objectManager->getInstance(ShippingService::class, [], false);
        $create->setData($payload)->save();

        return (int)$create->getId();
    }

    /**
     * @param list<string> $countries
     */
    private function replaceServiceCountries(int $serviceId, array $countries): int
    {
        if ($serviceId <= 0) {
            return 0;
        }
        /** @var ServiceLaneAdminService $laneAdmin */
        $laneAdmin = $this->objectManager->getInstance(ServiceLaneAdminService::class);
        $rows = [];
        foreach ($countries as $cc) {
            $rows[] = [
                'region_type' => ServiceRegion::TYPE_COUNTRY,
                'country_code' => $cc,
                'region_id' => 0,
                'region_code' => $cc,
            ];
        }

        return $laneAdmin->replaceForService($serviceId, $rows);
    }
}
