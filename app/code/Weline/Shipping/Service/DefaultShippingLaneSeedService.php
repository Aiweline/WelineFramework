<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\DefaultWarehouseResolverInterface;
use Weline\Inventory\Model\Warehouse;
use Weline\Shipping\Api\WarehouseShippingOriginInterface;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\CarrierRegion;
use Weline\Shipping\Model\DestinationRegion;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\ShippingCapabilityGate;
use Weline\Shipping\Service\ShippingCommercePolicyService;
use Weline\Shipping\Service\ShippingIncotermService;
use Weline\Shipping\Service\ReturnShippingQuoteService;

/**
 * 默认可达市场种子：承运商全球覆盖 + 默认站 website 分档航线（幂等 upsert）。
 */
final class DefaultShippingLaneSeedService
{
    private const CARRIER_CODE = 'WLS_STD';
    private const MARKETS_TSV = 'app/code/Weline/Shipping/data/default-markets/countries.tsv';

    /**
     * 九档默认可达市场航线元数据（费用一律站点基础货币，默认 CNY）。
     *
     * 计价假设：中国大陆仓发货 → 跨境经济小包/挂号小包量级（非 EMS/商业快递）。
     * `fee`+`weight_rate` 经 SeedWeightBracketFactory::fromLinear 生成阶梯；Americas 保持
     * base=45/rate=14（Ch1 契约 2kg=115.00），其余航线按中国发往该区常见经济线校准。
     *
     * @var array<string, array{
     *   name:string,
     *   fee:float,
     *   weight_rate:float,
     *   volume_rate:float,
     *   quantity_rate:float,
     *   calc:string,
     *   days_min:int,
     *   days_max:int,
     *   sort:int
     * }>
     */
    private const LANE_META = [
        'domestic' => [
            // 国内标快：首重约 12 元，续重约 4 元/kg
            'name' => '国内标快', 'fee' => 12.00, 'weight_rate' => 4.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 1, 'days_max' => 3, 'sort' => 10,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'greater_china' => [
            // 港澳台经济线
            'name' => '港澳台', 'fee' => 28.00, 'weight_rate' => 9.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 2, 'days_max' => 5, 'sort' => 20,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'asia_pacific' => [
            // 日韩/东南亚/南亚经济小包
            'name' => '亚太', 'fee' => 38.00, 'weight_rate' => 12.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 3, 'days_max' => 10, 'sort' => 30,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'americas' => [
            // 美加墨经济线（Ch1 锁定：2kg 档 115.00）
            'name' => '美洲', 'fee' => 45.00, 'weight_rate' => 14.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 7, 'days_max' => 15, 'sort' => 40,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'europe' => [
            // 西欧/北欧/中东欧经济小包（略低于美线）
            'name' => '欧洲', 'fee' => 42.00, 'weight_rate' => 13.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 6, 'days_max' => 14, 'sort' => 50,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'oceania' => [
            // 澳新经济线
            'name' => '大洋洲', 'fee' => 48.00, 'weight_rate' => 15.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 7, 'days_max' => 16, 'sort' => 60,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'latam' => [
            // 拉美/加勒比（运距与清关成本更高）
            'name' => '拉美', 'fee' => 58.00, 'weight_rate' => 18.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 10, 'days_max' => 22, 'sort' => 70,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'middle_east_africa' => [
            // 中东/非洲经济线
            'name' => '中东非洲', 'fee' => 55.00, 'weight_rate' => 17.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 8, 'days_max' => 20, 'sort' => 80,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
        'other' => [
            // 独联体/中亚等其它可达市场
            'name' => '其他可达市场', 'fee' => 60.00, 'weight_rate' => 18.00, 'volume_rate' => 0.0, 'quantity_rate' => 0.0,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 10, 'days_max' => 25, 'sort' => 90,
            'max_weight_kg' => 30.0, 'bracket_set' => 'general',
        ],
    ];

    private const HEAVY_META = [
        'heavy_domestic' => [
            // 国内重货专线（托盘/大件量级）
            'name' => '国内重货', 'fee' => 80.00, 'weight_rate' => 20.00,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 7, 'days_max' => 30, 'sort' => 200,
            'max_weight_kg' => 1000.0, 'bracket_set' => 'heavy', 'lane_group' => 'domestic',
        ],
        'heavy_international' => [
            // 国际重货/海运空运混运参考价
            'name' => '国际重货', 'fee' => 220.00, 'weight_rate' => 45.00,
            'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE, 'days_min' => 15, 'days_max' => 45, 'sort' => 210,
            'max_weight_kg' => 1000.0, 'bracket_set' => 'heavy', 'lane_group' => 'international',
        ],
    ];

    /** @return list<string> */
    public static function expectedSeedTemplateCodes(): array
    {
        $codes = [];
        foreach (array_keys(self::LANE_META) as $lane) {
            $codes[] = 'SEED_TPL_' . strtoupper((string)$lane);
        }
        foreach (array_keys(self::HEAVY_META) as $lane) {
            $codes[] = 'SEED_TPL_' . strtoupper((string)$lane);
        }

        return $codes;
    }
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
        // 种子航线必须从「当前仓」发货地址发货（不可 origin=0 通配异地）。
        $originShippingAddressId = $this->resolveCurrentWarehouseOriginId($websiteId);
        $this->bindDefaultWarehouseOrigin($websiteId, $originShippingAddressId);

        $generalServiceIds = [];
        foreach (self::LANE_META as $lane => $meta) {
            $countries = $markets[$lane] ?? [];
            $tplCode = 'SEED_TPL_' . strtoupper($lane);
            $svcCode = 'SEED_LANE_' . strtoupper($lane);
            // 九档模板始终落库；无国家的航线仅跳过服务/区域绑定。
            $templateId = $this->upsertTemplate($scope, $tplCode, $meta);
            ++$templates;
            if ($countries === []) {
                continue;
            }
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
                $originShippingAddressId,
            );
            ++$services;
            $regions += $this->replaceServiceCountries($serviceId, $countries);
            $generalServiceIds[] = $serviceId;
        }

        $heavyServiceIds = [];
        $intlCountries = [];
        foreach ($markets as $lane => $countries) {
            if ($lane === 'domestic' || !is_array($countries)) {
                continue;
            }
            foreach ($countries as $cc) {
                $intlCountries[strtoupper(trim((string)$cc))] = true;
            }
        }
        foreach (self::HEAVY_META as $lane => $meta) {
            $tplCode = 'SEED_TPL_' . strtoupper($lane);
            $svcCode = 'SEED_LANE_' . strtoupper($lane);
            $templateId = $this->upsertTemplate($scope, $tplCode, $meta);
            ++$templates;
            $countries = ($meta['lane_group'] ?? '') === 'domestic'
                ? ($markets['domestic'] ?? [])
                : array_keys($intlCountries);
            if ($countries === []) {
                continue;
            }
            $serviceId = $this->upsertService(
                $scope,
                $svcCode,
                $meta['name'],
                $carrierId,
                $templateId,
                0,
                (int)$meta['days_min'],
                (int)$meta['days_max'],
                (int)$meta['sort'],
                $originShippingAddressId,
            );
            ++$services;
            $regions += $this->replaceServiceCountries($serviceId, $countries);
            $heavyServiceIds[] = $serviceId;
        }

        $this->ensureProfile(
            $scope,
            \Weline\Shipping\Model\ShippingProfile::SEED_GENERAL,
            '默认配送',
            true,
            $generalServiceIds,
        );
        $this->ensureProfile(
            $scope,
            \Weline\Shipping\Model\ShippingProfile::SEED_HEAVY,
            '重货配送',
            false,
            $heavyServiceIds,
        );
        $surcharges = $this->ensureRemoteSurchargeSeeds($scope);
        $this->ensurePackingAndAddonSeeds($scope);
        $this->ensureCommerceChapter4Seeds($scope);

        return [
            'carrier_id' => $carrierId,
            'coverage' => $coverage,
            'destinations' => $destinations,
            'templates' => $templates,
            'services' => $services,
            'regions' => $regions,
            'origin_shipping_address_id' => $originShippingAddressId,
            'surcharges' => $surcharges,
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
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'country_code')) {
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
            $template = trim((string)$existing->getData(Carrier::schema_fields_TRACKING_URL_TEMPLATE));
            /** @var TrackingUrlResolver $urlResolver */
            $urlResolver = $this->objectManager->getInstance(TrackingUrlResolver::class);
            $sanitized = $urlResolver->sanitizeTemplate($template);
            if ($sanitized !== $template) {
                $existing->setData(Carrier::schema_fields_TRACKING_URL_TEMPLATE, $sanitized)->save();
            }

            return (int)$existing->getId();
        }

        /** @var Carrier $create */
        $create = $this->objectManager->getInstance(Carrier::class, [], false);
        $create->setData([
            Carrier::schema_fields_CARRIER_CODE => self::CARRIER_CODE,
            Carrier::schema_fields_CARRIER_NAME => 'Weline Standard',
            Carrier::schema_fields_CARRIER_TYPE => Carrier::TYPE_MANUAL,
            Carrier::schema_fields_TRACKING_URL_TEMPLATE => TrackingUrlResolver::DEFAULT_TEMPLATE,
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
     * 可售目的地：窄名单整表替换；已有较多国家时仅并入种子市场缺失国（不删商户额外项）。
     *
     * @param array<string, list<string>> $markets
     */
    private function ensureWebsiteDestinations(int $websiteId, array $markets): int
    {
        /** @var DestinationAdminService $admin */
        $admin = $this->objectManager->getInstance(DestinationAdminService::class);
        $existing = $admin->listForScope(DestinationRegion::SCOPE_WEBSITE, max(0, $websiteId));
        $seedRows = $this->marketCountryRows($markets);
        if ($seedRows === []) {
            return count($existing);
        }
        if (count($existing) <= 5) {
            return $admin->replaceForScope(DestinationRegion::SCOPE_WEBSITE, max(0, $websiteId), $seedRows);
        }

        $have = [];
        $merged = [];
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
            $type = (string)($row['region_type'] ?? DestinationRegion::TYPE_COUNTRY);
            $key = $type . ':' . $cc . ':' . (string)($row['region_code'] ?? $cc);
            if (isset($have[$key])) {
                continue;
            }
            $have[$key] = true;
            $merged[] = [
                'region_type' => $type !== '' ? $type : DestinationRegion::TYPE_COUNTRY,
                'country_code' => $cc,
                'region_id' => (int)($row['region_id'] ?? 0),
                'region_code' => (string)($row['region_code'] ?? $cc),
                'street_id' => (int)($row['street_id'] ?? 0),
            ];
        }
        $added = 0;
        foreach ($seedRows as $row) {
            $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
            $key = DestinationRegion::TYPE_COUNTRY . ':' . $cc . ':' . $cc;
            if ($cc === '' || isset($have[$key])) {
                continue;
            }
            $have[$key] = true;
            $merged[] = $row;
            ++$added;
        }
        if ($added === 0) {
            return count($existing);
        }

        return $admin->replaceForScope(DestinationRegion::SCOPE_WEBSITE, max(0, $websiteId), $merged);
    }

    /**
     * 承运商覆盖：空/极少时整表写入；已有覆盖时并入种子市场缺失国。
     *
     * @param array<string, list<string>> $markets
     */
    private function ensureCarrierWorldCoverage(int $carrierId, array $markets): int
    {
        if ($carrierId <= 0) {
            return 0;
        }
        /** @var CarrierCoverageAdminService $admin */
        $admin = $this->objectManager->getInstance(CarrierCoverageAdminService::class);
        $seedRows = $this->marketCoverageRows();
        if ($seedRows === []) {
            return 0;
        }
        $count = $admin->countForCarrier($carrierId);
        if ($count <= 5) {
            return $admin->replaceForCarrier($carrierId, $seedRows);
        }

        $existing = $admin->listForCarrier($carrierId);
        $have = [];
        $merged = [];
        foreach (is_array($existing) ? $existing : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
            $type = (string)($row['region_type'] ?? CarrierRegion::TYPE_COUNTRY);
            $key = $type . ':' . $cc;
            if ($cc === '' || isset($have[$key])) {
                continue;
            }
            $have[$key] = true;
            $merged[] = [
                'region_type' => $type !== '' ? $type : CarrierRegion::TYPE_COUNTRY,
                'country_code' => $cc,
                'region_id' => $row['region_id'] ?? null,
                'region_code' => (string)($row['region_code'] ?? $cc),
                'street_id' => $row['street_id'] ?? null,
            ];
        }
        $added = 0;
        foreach ($seedRows as $row) {
            $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
            $key = CarrierRegion::TYPE_COUNTRY . ':' . $cc;
            if ($cc === '' || isset($have[$key])) {
                continue;
            }
            $have[$key] = true;
            $merged[] = $row;
            ++$added;
        }
        if ($added === 0) {
            return $count;
        }

        return $admin->replaceForCarrier($carrierId, $merged);
    }

    /**
     * @param array<string, list<string>> $markets
     * @return list<array<string,mixed>>
     */
    private function marketCountryRows(array $markets): array
    {
        $rows = [];
        $seen = [];
        foreach ($markets as $codes) {
            foreach ($codes as $cc) {
                $cc = strtoupper(trim((string)$cc));
                if ($cc === '' || isset($seen[$cc])) {
                    continue;
                }
                $seen[$cc] = true;
                $rows[] = [
                    'region_type' => DestinationRegion::TYPE_COUNTRY,
                    'country_code' => $cc,
                    'region_id' => 0,
                    'region_code' => $cc,
                    'street_id' => 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function ensureFreeRule(array $scope): int
    {
        /** @var FreeShippingRuleSeedService $seeder */
        $seeder = $this->objectManager->getInstance(FreeShippingRuleSeedService::class);
        $seeder->seedDefaults((string)$scope['scope_type'], (int)$scope['scope_id']);

        return $seeder->findSeedId('SEED_FREE_99', (string)$scope['scope_type'], (int)$scope['scope_id']);
    }

    /**
     * @param array{scope_type:string,scope_id:int} $scope
     * @param array{
     *   name:string,
     *   fee:float,
     *   weight_rate:float,
     *   volume_rate:float,
     *   quantity_rate:float,
     *   calc:string,
     *   days_min:int,
     *   days_max:int,
     *   sort:int
     * } $meta
     */
    private function upsertTemplate(array $scope, string $code, array $meta): int
    {
        $name = trim((string)$meta['name']) . '运费';
        $calc = strtolower(trim((string)($meta['calc'] ?? RateTemplate::CALC_TYPE_FIXED)));
        $allowed = [
            RateTemplate::CALC_TYPE_FIXED,
            RateTemplate::CALC_TYPE_WEIGHT,
            RateTemplate::CALC_TYPE_VOLUME,
            RateTemplate::CALC_TYPE_QUANTITY,
            RateTemplate::CALC_TYPE_MIXED,
            RateTemplate::CALC_TYPE_WEIGHT_TABLE,
            RateTemplate::CALC_TYPE_PRICE_TABLE,
        ];
        if (!in_array($calc, $allowed, true)) {
            $calc = RateTemplate::CALC_TYPE_FIXED;
        }
        $maxWeight = isset($meta['max_weight_kg']) ? (float)$meta['max_weight_kg'] : null;
        $bracketsJson = null;
        if ($calc === RateTemplate::CALC_TYPE_WEIGHT_TABLE) {
            $bounds = (($meta['bracket_set'] ?? 'general') === 'heavy')
                ? SeedWeightBracketFactory::HEAVY_BOUNDS
                : SeedWeightBracketFactory::GENERAL_BOUNDS;
            $brackets = SeedWeightBracketFactory::fromLinear(
                (float)$meta['fee'],
                (float)$meta['weight_rate'],
                $bounds,
            );
            $bracketsJson = json_encode($brackets, JSON_UNESCAPED_UNICODE);
        }
        $payload = [
            RateTemplate::schema_fields_TEMPLATE_NAME => $name,
            RateTemplate::schema_fields_CALCULATION_TYPE => $calc,
            RateTemplate::schema_fields_BASE_FEE => $calc === RateTemplate::CALC_TYPE_WEIGHT_TABLE
                ? 0
                : max(0, (float)$meta['fee']),
            RateTemplate::schema_fields_WEIGHT_RATE => max(0, (float)($meta['weight_rate'] ?? 0)),
            RateTemplate::schema_fields_VOLUME_RATE => max(0, (float)($meta['volume_rate'] ?? 0)),
            RateTemplate::schema_fields_QUANTITY_RATE => max(0, (float)($meta['quantity_rate'] ?? 0)),
            RateTemplate::schema_fields_RATE_BRACKETS => $bracketsJson,
            RateTemplate::schema_fields_MAX_WEIGHT_KG => $maxWeight,
            RateTemplate::schema_fields_CURRENCY_CODE => $this->baseCurrencyCode(),
            RateTemplate::schema_fields_IS_ACTIVE => 1,
        ];

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
            $existing->setData($payload)->save();

            return (int)$existing->getId();
        }

        /** @var RateTemplate $create */
        $create = $this->objectManager->getInstance(RateTemplate::class, [], false);
        $create->setData(array_merge($payload, [
            RateTemplate::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            RateTemplate::schema_fields_SCOPE_ID => $scope['scope_id'],
            RateTemplate::schema_fields_TEMPLATE_CODE => $code,
        ]))->save();

        return (int)$create->getId();
    }

    /**
     * @param array{scope_type:string,scope_id:int} $scope
     * @param list<int> $serviceIds
     */
    private function ensureProfile(
        array $scope,
        string $code,
        string $name,
        bool $isGeneral,
        array $serviceIds,
    ): int {
        /** @var \Weline\Shipping\Model\ShippingProfile $model */
        $model = $this->objectManager->getInstance(\Weline\Shipping\Model\ShippingProfile::class, [], false);
        $items = $model->reset()
            ->where(\Weline\Shipping\Model\ShippingProfile::schema_fields_SCOPE_TYPE, $scope['scope_type'])
            ->where(\Weline\Shipping\Model\ShippingProfile::schema_fields_SCOPE_ID, $scope['scope_id'])
            ->where(\Weline\Shipping\Model\ShippingProfile::schema_fields_PROFILE_CODE, $code)
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;
        $payload = [
            \Weline\Shipping\Model\ShippingProfile::schema_fields_PROFILE_NAME => $name,
            \Weline\Shipping\Model\ShippingProfile::schema_fields_IS_GENERAL => $isGeneral ? 1 : 0,
            \Weline\Shipping\Model\ShippingProfile::schema_fields_IS_ACTIVE => 1,
        ];
        if ($existing instanceof \Weline\Shipping\Model\ShippingProfile && (int)$existing->getId() > 0) {
            $existing->setData($payload)->save();
            $profileId = (int)$existing->getId();
        } else {
            /** @var \Weline\Shipping\Model\ShippingProfile $create */
            $create = $this->objectManager->getInstance(\Weline\Shipping\Model\ShippingProfile::class, [], false);
            $create->setData(array_merge($payload, [
                \Weline\Shipping\Model\ShippingProfile::schema_fields_SCOPE_TYPE => $scope['scope_type'],
                \Weline\Shipping\Model\ShippingProfile::schema_fields_SCOPE_ID => $scope['scope_id'],
                \Weline\Shipping\Model\ShippingProfile::schema_fields_PROFILE_CODE => $code,
            ]))->save();
            $profileId = (int)$create->getId();
        }

        /** @var \Weline\Shipping\Model\ShippingProfileService $linkModel */
        $linkModel = $this->objectManager->getInstance(\Weline\Shipping\Model\ShippingProfileService::class, [], false);
        $existingLinks = $linkModel->reset()
            ->where(\Weline\Shipping\Model\ShippingProfileService::schema_fields_PROFILE_ID, $profileId)
            ->select()
            ->fetch()
            ->getItems();
        $keep = [];
        foreach (is_array($existingLinks) ? $existingLinks : [] as $link) {
            if (!$link instanceof \Weline\Shipping\Model\ShippingProfileService) {
                continue;
            }
            $sid = (int)$link->getData(\Weline\Shipping\Model\ShippingProfileService::schema_fields_SERVICE_ID);
            if (!in_array($sid, $serviceIds, true)) {
                $link->delete();
            } else {
                $keep[$sid] = true;
            }
        }
        foreach ($serviceIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0 || isset($keep[$sid])) {
                continue;
            }
            /** @var \Weline\Shipping\Model\ShippingProfileService $row */
            $row = $this->objectManager->getInstance(\Weline\Shipping\Model\ShippingProfileService::class, [], false);
            $row->setData([
                \Weline\Shipping\Model\ShippingProfileService::schema_fields_PROFILE_ID => $profileId,
                \Weline\Shipping\Model\ShippingProfileService::schema_fields_SERVICE_ID => $sid,
            ])->save();
        }

        return $profileId;
    }

    /**
     * CN 偏远省固定加价（站点基础货币主单位，如 CNY 元）。
     *
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function ensureRemoteSurchargeSeeds(array $scope): int
    {
        $provinces = [
            'SEED_SURCHARGE_CN_XJ' => ['新疆', 25.0],
            'SEED_SURCHARGE_CN_XZ' => ['西藏', 30.0],
            'SEED_SURCHARGE_CN_QH' => ['青海', 20.0],
            'SEED_SURCHARGE_CN_NM' => ['内蒙古', 15.0],
            'SEED_SURCHARGE_CN_NX' => ['宁夏', 15.0],
            'SEED_SURCHARGE_CN_GS' => ['甘肃', 15.0],
        ];
        $count = 0;
        $priority = 100;
        foreach ($provinces as $code => [$name, $amount]) {
            /** @var \Weline\Shipping\Model\ShippingSurchargeRule $model */
            $model = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingSurchargeRule::class,
                [],
                false,
            );
            $items = $model->reset()
                ->where(
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_TYPE,
                    $scope['scope_type'],
                )
                ->where(
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_ID,
                    $scope['scope_id'],
                )
                ->where(
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_RULE_CODE,
                    $code,
                )
                ->select()
                ->fetch()
                ->getItems();
            $existing = is_array($items) ? ($items[0] ?? null) : null;
            $payload = [
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_RULE_NAME => '偏远加价·' . $name,
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_MATCH_TYPE =>
                    \Weline\Shipping\Model\ShippingSurchargeRule::MATCH_PROVINCE,
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_COUNTRY_CODE => 'CN',
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_MATCH_VALUE => $name,
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_AMOUNT_TYPE =>
                    \Weline\Shipping\Model\ShippingSurchargeRule::AMOUNT_FIXED,
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_AMOUNT_VALUE => $amount,
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_IS_ACTIVE => 1,
                \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_PRIORITY => $priority,
            ];
            if ($existing instanceof \Weline\Shipping\Model\ShippingSurchargeRule
                && (int)$existing->getId() > 0
            ) {
                $existing->setData($payload)->save();
            } else {
                /** @var \Weline\Shipping\Model\ShippingSurchargeRule $create */
                $create = $this->objectManager->getInstance(
                    \Weline\Shipping\Model\ShippingSurchargeRule::class,
                    [],
                    false,
                );
                $create->setData(array_merge($payload, [
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_TYPE => $scope['scope_type'],
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_SCOPE_ID => $scope['scope_id'],
                    \Weline\Shipping\Model\ShippingSurchargeRule::schema_fields_RULE_CODE => $code,
                ]))->save();
            }
            ++$count;
            --$priority;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public static function expectedSeedSurchargeCodes(): array
    {
        return [
            'SEED_SURCHARGE_CN_XJ',
            'SEED_SURCHARGE_CN_XZ',
            'SEED_SURCHARGE_CN_QH',
            'SEED_SURCHARGE_CN_NM',
            'SEED_SURCHARGE_CN_NX',
            'SEED_SURCHARGE_CN_GS',
        ];
    }

    /**
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function ensurePackingAndAddonSeeds(array $scope): void
    {
        /** @var \Weline\Shipping\Model\ShippingPackingPolicy $policyModel */
        $policyModel = $this->objectManager->getInstance(
            \Weline\Shipping\Model\ShippingPackingPolicy::class,
            [],
            false,
        );
        $items = $policyModel->reset()
            ->where(\Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_SCOPE_TYPE, $scope['scope_type'])
            ->where(\Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_SCOPE_ID, $scope['scope_id'])
            ->select()
            ->fetch()
            ->getItems();
        $existing = is_array($items) ? ($items[0] ?? null) : null;
        $policyPayload = [
            \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_POLICY_CODE =>
                \Weline\Shipping\Model\ShippingPackingPolicy::SEED_CODE,
            \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_MAX_WEIGHT_KG => 30,
            \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_MAX_VOLUME_CM3 => 120000,
            \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_IS_ACTIVE => 1,
        ];
        if ($existing instanceof \Weline\Shipping\Model\ShippingPackingPolicy && (int)$existing->getId() > 0) {
            $existing->setData($policyPayload)->save();
        } else {
            /** @var \Weline\Shipping\Model\ShippingPackingPolicy $create */
            $create = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingPackingPolicy::class,
                [],
                false,
            );
            $create->setData(array_merge($policyPayload, [
                \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_SCOPE_TYPE => $scope['scope_type'],
                \Weline\Shipping\Model\ShippingPackingPolicy::schema_fields_SCOPE_ID => $scope['scope_id'],
            ]))->save();
        }

        $addons = [
            [
                'code' => \Weline\Shipping\Model\ShippingCheckoutAddon::SEED_SIGNATURE,
                'name' => '签收确认',
                'type' => \Weline\Shipping\Model\ShippingCheckoutAddon::TYPE_SIGNATURE,
                'amount_type' => \Weline\Shipping\Model\ShippingCheckoutAddon::AMOUNT_FIXED,
                'amount' => 5.0,
                'active' => 1,
                'priority' => 10,
            ],
            [
                'code' => \Weline\Shipping\Model\ShippingCheckoutAddon::SEED_INSURANCE,
                'name' => '运费保价',
                'type' => \Weline\Shipping\Model\ShippingCheckoutAddon::TYPE_INSURANCE,
                'amount_type' => \Weline\Shipping\Model\ShippingCheckoutAddon::AMOUNT_PERCENT,
                'amount' => 1.0,
                'active' => 1,
                'priority' => 5,
            ],
        ];
        foreach ($addons as $meta) {
            /** @var \Weline\Shipping\Model\ShippingCheckoutAddon $addonModel */
            $addonModel = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingCheckoutAddon::class,
                [],
                false,
            );
            $rows = $addonModel->reset()
                ->where(\Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_SCOPE_TYPE, $scope['scope_type'])
                ->where(\Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_SCOPE_ID, $scope['scope_id'])
                ->where(\Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ADDON_CODE, $meta['code'])
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
            $payload = [
                \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ADDON_NAME => $meta['name'],
                \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ADDON_TYPE => $meta['type'],
                \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_AMOUNT_TYPE => $meta['amount_type'],
                \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_AMOUNT_VALUE => $meta['amount'],
                \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_IS_ACTIVE => $meta['active'],
                \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_PRIORITY => $meta['priority'],
            ];
            if ($row instanceof \Weline\Shipping\Model\ShippingCheckoutAddon && (int)$row->getId() > 0) {
                $row->setData($payload)->save();
            } else {
                /** @var \Weline\Shipping\Model\ShippingCheckoutAddon $create */
                $create = $this->objectManager->getInstance(
                    \Weline\Shipping\Model\ShippingCheckoutAddon::class,
                    [],
                    false,
                );
                $create->setData(array_merge($payload, [
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_SCOPE_TYPE => $scope['scope_type'],
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_SCOPE_ID => $scope['scope_id'],
                    \Weline\Shipping\Model\ShippingCheckoutAddon::schema_fields_ADDON_CODE => $meta['code'],
                ]))->save();
            }
        }

        $year = (int)date('Y');
        $seasonals = [
            [
                'code' => \Weline\Shipping\Model\ShippingSeasonalRule::SEED_PEAK,
                'name' => '旺季附加（默认关）',
                'start' => sprintf('%d-11-01', $year),
                'end' => sprintf('%d-12-31', $year),
                'amount_type' => \Weline\Shipping\Model\ShippingSeasonalRule::AMOUNT_PERCENT,
                'amount' => 10.0,
            ],
            [
                'code' => \Weline\Shipping\Model\ShippingSeasonalRule::SEED_FUEL,
                'name' => '燃油附加（默认关）',
                'start' => sprintf('%d-01-01', $year),
                'end' => sprintf('%d-12-31', $year),
                'amount_type' => \Weline\Shipping\Model\ShippingSeasonalRule::AMOUNT_PERCENT,
                'amount' => 5.0,
            ],
        ];
        foreach ($seasonals as $meta) {
            /** @var \Weline\Shipping\Model\ShippingSeasonalRule $seasonModel */
            $seasonModel = $this->objectManager->getInstance(
                \Weline\Shipping\Model\ShippingSeasonalRule::class,
                [],
                false,
            );
            $rows = $seasonModel->reset()
                ->where(\Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_SCOPE_TYPE, $scope['scope_type'])
                ->where(\Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_SCOPE_ID, $scope['scope_id'])
                ->where(\Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_RULE_CODE, $meta['code'])
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
            $payload = [
                \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_RULE_NAME => $meta['name'],
                \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_START_DATE => $meta['start'],
                \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_END_DATE => $meta['end'],
                \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_AMOUNT_TYPE => $meta['amount_type'],
                \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_AMOUNT_VALUE => $meta['amount'],
                \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_IS_ACTIVE => 0,
                \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_PRIORITY => 0,
            ];
            if ($row instanceof \Weline\Shipping\Model\ShippingSeasonalRule && (int)$row->getId() > 0) {
                $row->setData($payload)->save();
            } else {
                /** @var \Weline\Shipping\Model\ShippingSeasonalRule $create */
                $create = $this->objectManager->getInstance(
                    \Weline\Shipping\Model\ShippingSeasonalRule::class,
                    [],
                    false,
                );
                $create->setData(array_merge($payload, [
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_SCOPE_TYPE => $scope['scope_type'],
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_SCOPE_ID => $scope['scope_id'],
                    \Weline\Shipping\Model\ShippingSeasonalRule::schema_fields_RULE_CODE => $meta['code'],
                ]))->save();
            }
        }
    }

    /**
     * Ch4: return templates + commerce policy (return_policy / split_shipment_shipping).
     *
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function ensureCommerceChapter4Seeds(array $scope): void
    {
        $returnMetas = [
            ReturnShippingQuoteService::TPL_DOMESTIC => [
                'name' => '退货·国内',
                'fee' => 10.00,
                'weight_rate' => 3.00,
                'volume_rate' => 0.0,
                'quantity_rate' => 0.0,
                'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE,
                'days_min' => 3,
                'days_max' => 10,
                'sort' => 900,
                'max_weight_kg' => 30.0,
                'bracket_set' => 'general',
            ],
            ReturnShippingQuoteService::TPL_INTL => [
                'name' => '退货·国际',
                'fee' => 30.00,
                'weight_rate' => 10.00,
                'volume_rate' => 0.0,
                'quantity_rate' => 0.0,
                'calc' => RateTemplate::CALC_TYPE_WEIGHT_TABLE,
                'days_min' => 7,
                'days_max' => 21,
                'sort' => 910,
                'max_weight_kg' => 30.0,
                'bracket_set' => 'general',
            ],
        ];
        foreach ($returnMetas as $code => $meta) {
            $this->upsertTemplate($scope, $code, $meta);
        }

        /** @var ShippingCommercePolicyService $commerce */
        $commerce = $this->objectManager->getInstance(ShippingCommercePolicyService::class);
        $commerce->setPolicies(
            \Weline\Shipping\Model\ShippingCommercePolicy::RETURN_BUYER,
            \Weline\Shipping\Model\ShippingCommercePolicy::SPLIT_FIRST_ONLY,
            (string)$scope['scope_type'],
            (int)$scope['scope_id'],
        );
    }

    private function baseCurrencyCode(): string
    {
        try {
            /** @var CurrencyRateService $rates */
            $rates = $this->objectManager->getInstance(CurrencyRateService::class);

            return strtoupper(trim($rates->getBaseCurrency())) ?: 'CNY';
        } catch (\Throwable) {
            return 'CNY';
        }
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
        int $originShippingAddressId = 0,
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
            ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID => max(0, $originShippingAddressId),
            ShippingService::schema_fields_ESTIMATED_DAYS_MIN => $daysMin,
            ShippingService::schema_fields_ESTIMATED_DAYS_MAX => $daysMax,
            ShippingService::schema_fields_IS_FREE_SHIPPING => 0,
            ShippingService::schema_fields_IS_ACTIVE => 1,
            ShippingService::schema_fields_SORT_ORDER => $sort,
            ShippingService::schema_fields_ALLOWED_POINT_TYPES => ShippingCapabilityGate::DEFAULT_ALLOWED_POINTS,
            ShippingService::schema_fields_ACCEPTED_HAZARD_CLASSES => '',
            ShippingService::schema_fields_INCOTERM => ShippingIncotermService::DDU,
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
     * 当前仓发货锚点：默认发货地址；缺失时按当前仓位置种子创建（禁止 origin=0 通配）。
     */
    private function resolveCurrentWarehouseOriginId(int $websiteId): int
    {
        try {
            /** @var ShippingAddressService $addresses */
            $addresses = $this->objectManager->getInstance(ShippingAddressService::class);
            $default = $addresses->getDefault();
            if ($default instanceof ShippingAddress && (int)$default->getId() > 0) {
                return (int)$default->getId();
            }
        } catch (\Throwable) {
            // fall through
        }
        try {
            /** @var ShippingAddress $model */
            $model = $this->objectManager->getInstance(ShippingAddress::class, [], false);
            $items = $model->reset()
                ->where(ShippingAddress::schema_fields_IS_ENABLED, 1)
                ->order(ShippingAddress::schema_fields_IS_DEFAULT, 'DESC')
                ->order(ShippingAddress::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($items) ? ($items[0] ?? null) : null;
            if ($row instanceof ShippingAddress && (int)$row->getId() > 0) {
                $pickedId = (int)$row->getId();
                // 种子航线要绑当前仓；若尚无默认发货地址，把选用地址提升为默认，供结账 resolveDefaultOriginId。
                if (!(bool)$row->getData(ShippingAddress::schema_fields_IS_DEFAULT)) {
                    try {
                        /** @var ShippingAddressService $addresses */
                        $addresses = $this->objectManager->getInstance(ShippingAddressService::class);
                        $addresses->setDefault($pickedId);
                    } catch (\Throwable) {
                        try {
                            $row->setData(ShippingAddress::schema_fields_IS_DEFAULT, 1)->save();
                        } catch (\Throwable) {
                            // 提升失败仍返回 id，运行时 lane 回退也可命中
                        }
                    }
                }

                return $pickedId;
            }
        } catch (\Throwable) {
            // no address yet
        }

        return $this->ensureSeedShippingAddressFromWarehouse($websiteId);
    }

    /**
     * 从当前仓（优先默认逻辑仓；否则首选带 country 的启用仓，CN 优先）创建默认发货地址。
     *
     * @return array{warehouse_id:int,country_code:string,name:string,region_code:string}
     */
    private function resolveCurrentWarehouseContext(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);
        $warehouseId = 0;
        $country = '';
        $name = '';
        $region = '';
        try {
            if (interface_exists(DefaultWarehouseResolverInterface::class)) {
                /** @var DefaultWarehouseResolverInterface $resolver */
                $resolver = $this->objectManager->getInstance(DefaultWarehouseResolverInterface::class);
                $assignment = $resolver->resolveDefault($websiteId, 0);
                $warehouseId = (int)$assignment->warehouseId;
            }
        } catch (\Throwable) {
            $warehouseId = 0;
        }
        if ($warehouseId > 0) {
            try {
                /** @var Warehouse $wh */
                $wh = $this->objectManager->getInstance(Warehouse::class, [], false)->load($warehouseId);
                if ((int)$wh->getId() > 0) {
                    $country = strtoupper(trim((string)$wh->getData(Warehouse::schema_fields_COUNTRY_CODE)));
                    $name = trim((string)$wh->getData(Warehouse::schema_fields_NAME));
                    $region = trim((string)$wh->getData(Warehouse::schema_fields_REGION_CODE));
                }
            } catch (\Throwable) {
                // continue pick
            }
        }
        if ($country === '') {
            $picked = $this->pickPhysicalWarehouseWithCountry($websiteId);
            if ($picked !== null) {
                $warehouseId = (int)$picked['warehouse_id'];
                $country = (string)$picked['country_code'];
                $name = (string)$picked['name'];
                $region = (string)$picked['region_code'];
            }
        }
        if ($country === '') {
            $country = 'CN';
        }
        if ($name === '') {
            $name = '系统默认仓';
        }

        return [
            'warehouse_id' => max(0, $warehouseId),
            'country_code' => $country,
            'name' => $name,
            'region_code' => $region,
        ];
    }

    /**
     * @return array{warehouse_id:int,country_code:string,name:string,region_code:string}|null
     */
    private function pickPhysicalWarehouseWithCountry(int $websiteId): ?array
    {
        try {
            /** @var Warehouse $wh */
            $wh = $this->objectManager->getInstance(Warehouse::class, [], false);
            $items = $wh->reset()
                ->where(Warehouse::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Warehouse::schema_fields_ENABLED, 1)
                ->where(Warehouse::schema_fields_NODE_KIND, Warehouse::NODE_WAREHOUSE)
                ->order(Warehouse::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            if (!is_array($items)) {
                return null;
            }
            $fallback = null;
            foreach ($items as $row) {
                if (!$row instanceof Warehouse) {
                    continue;
                }
                $cc = strtoupper(trim((string)$row->getData(Warehouse::schema_fields_COUNTRY_CODE)));
                if ($cc === '') {
                    continue;
                }
                $payload = [
                    'warehouse_id' => (int)$row->getId(),
                    'country_code' => $cc,
                    'name' => trim((string)$row->getData(Warehouse::schema_fields_NAME)),
                    'region_code' => trim((string)$row->getData(Warehouse::schema_fields_REGION_CODE)),
                ];
                if ($cc === 'CN') {
                    return $payload;
                }
                $fallback ??= $payload;
            }

            return $fallback;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureSeedShippingAddressFromWarehouse(int $websiteId): int
    {
        $ctx = $this->resolveCurrentWarehouseContext($websiteId);
        $country = $ctx['country_code'];
        $province = $ctx['region_code'] !== '' ? $ctx['region_code'] : ($country === 'CN' ? '广东' : 'N/A');
        if ($country === 'CN' && preg_match('/^[A-Z]{2}$/', $province) === 1) {
            // CN-GD → 可读省名兜底
            $province = match ($province) {
                'GD' => '广东',
                'BJ' => '北京',
                'SH' => '上海',
                'ZJ' => '浙江',
                'JS' => '江苏',
                default => $province,
            };
        }
        $payload = [
            ShippingAddress::schema_fields_NAME => '仓发货：' . $ctx['name'],
            ShippingAddress::schema_fields_CONTACT_NAME => 'Warehouse',
            ShippingAddress::schema_fields_CONTACT_PHONE => '13800000000',
            ShippingAddress::schema_fields_COUNTRY_CODE => $country,
            ShippingAddress::schema_fields_COUNTRY => $country === 'CN' ? '中国' : $country,
            ShippingAddress::schema_fields_PROVINCE => $province,
            ShippingAddress::schema_fields_CITY => $country === 'CN' ? '仓库所在城市' : 'Warehouse City',
            ShippingAddress::schema_fields_STREET => $country === 'CN' ? '仓库发货地址' : 'Warehouse ship-from',
            ShippingAddress::schema_fields_POSTAL_CODE => $country === 'CN' ? '510000' : '00000',
            ShippingAddress::schema_fields_IS_DEFAULT => 1,
            ShippingAddress::schema_fields_IS_ENABLED => 1,
        ];
        try {
            /** @var ShippingAddressService $addresses */
            $addresses = $this->objectManager->getInstance(ShippingAddressService::class);
            $created = $addresses->create($payload);

            return (int)$created->getId();
        } catch (\Throwable) {
            // 校验/禁运可能拦种子：直接落库保证航线有明确发货地
            try {
                /** @var ShippingAddress $create */
                $create = $this->objectManager->getInstance(ShippingAddress::class, [], false);
                $create->setData($payload)->save();

                return (int)$create->getId();
            } catch (\Throwable) {
                return 0;
            }
        }
    }

    /** 默认/当前仓 ↔ 发货地址权威绑定（多仓拆单可读）。 */
    private function bindDefaultWarehouseOrigin(int $websiteId, int $shippingAddressId): void
    {
        $websiteId = max(0, $websiteId);
        $shippingAddressId = max(0, $shippingAddressId);
        if ($shippingAddressId <= 0 || !interface_exists(WarehouseShippingOriginInterface::class)) {
            return;
        }
        $ctx = $this->resolveCurrentWarehouseContext($websiteId);
        $warehouseId = (int)$ctx['warehouse_id'];
        if ($warehouseId <= 0) {
            return;
        }
        try {
            /** @var WarehouseShippingOriginInterface $origins */
            $origins = $this->objectManager->getInstance(WarehouseShippingOriginInterface::class);
            $origins->bind($websiteId, $warehouseId, $shippingAddressId, true);
        } catch (\Throwable) {
            // 绑定失败不阻断种子模板/航线落库
        }
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
