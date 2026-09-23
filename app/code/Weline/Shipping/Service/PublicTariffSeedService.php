<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\ShippingProfile;
use Weline\Shipping\Model\ShippingProfileService;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\ServiceRegion;

/** 已审核公开标快销售价种子；不重算倍率、不修改禁运。 */
final class PublicTariffSeedService
{
    private const LEGACY_LANES = [
        'GREATER_CHINA', 'ASIA_PACIFIC', 'AMERICAS', 'EUROPE',
        'OCEANIA', 'LATAM', 'MIDDLE_EAST_AFRICA', 'OTHER',
    ];

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /** @return array{carrier_id:int,coverage:int,templates:int,services:int,regions:int,skipped:array} */
    public function seedDefaultWebsite(int $websiteId = 0, ?array $countryCodes = null): array
    {
        if ($websiteId !== 0) {
            throw new \InvalidArgumentException('Public tariff seed only supports website 0.');
        }
        $source = json_decode(
            file_get_contents(dirname(__DIR__) . '/data/public-tariff/standard-20260923.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (($source['schema_version'] ?? null) !== 1 || ($source['currency'] ?? '') !== 'CNY'
            || empty($source['countries']) || !is_array($source['countries'])) {
            throw new \UnexpectedValueException('Invalid public tariff source.');
        }
        // 小样仅保存指定国家，不切换 General 绑定；全量成功后才切换。
        if ($countryCodes !== null) {
            $selected = [];
            foreach ($countryCodes as $countryCode) {
                $countryCode = strtoupper(trim((string)$countryCode));
                if (!isset($source['countries'][$countryCode])) {
                    throw new \InvalidArgumentException('Unknown public tariff country: ' . $countryCode);
                }
                $selected[$countryCode] = $source['countries'][$countryCode];
            }
            $source['countries'] = $selected;
        }
        $scope = ['scope_type' => ShippingService::SCOPE_WEBSITE, 'scope_id' => 0];
        $carrier = $this->find(Carrier::class, ['carrier_code' => 'WLS_STD', 'is_active' => 1]);
        $origin = $this->find(ShippingAddress::class, ['country_code' => 'CN', 'is_enabled' => 1, 'is_default' => 1]);
        $profile = $this->find(ShippingProfile::class, $scope + ['profile_code' => ShippingProfile::SEED_GENERAL]);
        if (!$carrier || !$origin || !$profile) {
            throw new \RuntimeException('Public tariff seed requires WLS_STD, a default CN origin and SEED_GENERAL.');
        }
        $suspended = $this->seedMerchantSuspensions($scope, (int)$profile->getId());
        $admin = $this->objectManager->getInstance(ShippingConfigurationAdminService::class);
        $lanes = $this->objectManager->getInstance(ServiceLaneAdminService::class);
        $embargo = $this->objectManager->getInstance(EmbargoService::class);
        $serviceIds = [];
        $skipped = [];
        $regions = 0;
        foreach ($source['countries'] as $country => $entry) {
            if (!preg_match('/^[A-Z]{2}$/D', (string)$country)
                || empty($entry['brackets']) || empty($entry['mixed_config']['public_tariff']['offers'])) {
                throw new \UnexpectedValueException('Invalid public tariff country: ' . $country);
            }
            $restriction = $embargo->evaluateAddress(
                ['country_code' => $country],
                ['website_id' => 0, 'store_id' => 0, 'channel_id' => 0],
            );
            if ($restriction['blocked']) {
                $skipped[$country] = [
                    'reason' => 'embargo',
                    'scope_type' => $restriction['scope_type'],
                    'matched' => $restriction['matched'],
                ];
                continue;
            }
            $code = 'PUBLIC_STD_' . $country;
            $existing = $this->find(RateTemplate::class, $scope + ['template_code' => $code]);
            $payload = $scope + [
                'template_name' => $code,
                'template_code' => $code,
                'calculation_type' => RateTemplate::CALC_TYPE_WEIGHT_TABLE,
                'currency_code' => 'CNY',
                'rate_brackets' => $entry['brackets'],
                'mixed_config' => $entry['mixed_config'],
                'max_weight_kg' => null,
                'is_active' => 1,
            ];
            $template = $existing
                ? $admin->updateRateTemplate($payload + ['template_id' => (int)$existing->getId()])
                : $admin->createRateTemplate($payload);
            $service = $this->find(ShippingService::class, $scope + ['service_code' => $code]);
            $servicePayload = $scope + [
                'service_code' => $code,
                'service_name' => '国际标快',
                'carrier_id' => (int)$carrier->getId(),
                'rate_template_id' => (int)$template->getId(),
                'origin_shipping_address_id' => (int)$origin->getId(),
                'free_shipping_rule_id' => null,
                'is_free_shipping' => 0,
                'is_active' => 1,
                'estimated_days_min' => 0,
                'estimated_days_max' => 0,
                'incoterm' => ShippingIncotermService::DDU,
                'sort_order' => 10,
            ];
            if ($service) {
                $service->setData($servicePayload)->save();
            } else {
                $service = $admin->createShippingService($servicePayload);
            }
            $serviceId = (int)$service->getId();
            $regions += $lanes->replaceForService($serviceId, [[
                'region_type' => ServiceRegion::TYPE_COUNTRY,
                'country_code' => $country,
                'region_id' => 0,
                'region_code' => $country,
            ]]);
            $serviceIds[] = $serviceId;
        }
        if ($countryCodes === null) {
            $this->replaceStandardLinks((int)$profile->getId(), $serviceIds, $scope);
        }
        return [
            'carrier_id' => (int)$carrier->getId(),
            'coverage' => count($serviceIds),
            'templates' => count($serviceIds),
            'services' => count($serviceIds),
            'regions' => $regions,
            'skipped' => $skipped,
            'merchant_suspended_countries' => $suspended,
        ];
    }

    /** Default-site merchant decision; this does not add system/legal restrictions. */
    private function seedMerchantSuspensions(array $scope, int $profileId): array
    {
        $source = json_decode(file_get_contents(dirname(__DIR__).'/data/public-tariff/website0-suspended-destinations.json'), true, 512, JSON_THROW_ON_ERROR);
        $countries = $source['countries'];
        foreach ($countries as $country) {
            $natural = $scope + ['region_type'=>EmbargoRegion::TYPE_COUNTRY, 'country_code'=>$country];
            $row = $this->find(EmbargoRegion::class, $natural);
            if (!$row) {
                $row = $this->objectManager->getInstance(EmbargoRegion::class, [], false);
                $row->setData($natural + ['region_id'=>0,'region_code'=>$country,'street_id'=>0,'origin'=>EmbargoRegion::ORIGIN_SEED]);
            }
            if (!(bool)$row->getData('is_active') || $row->getData('reason_code') !== $source['reason_code']) {
                $row->setData(['is_active'=>1,'reason_code'=>$source['reason_code'],'disabled_by'=>'','disabled_at'=>null])->save();
            }
            $legacy = $this->find(ShippingService::class, $scope + ['service_code'=>'LEGACY_STD_'.$country]);
            if (!$legacy) {
                continue;
            }
            if ((bool)$legacy->getData('is_active')) {
                $legacy->setData('is_active', 0)->save();
            }
            $links = $this->objectManager->getInstance(ShippingProfileService::class, [], false)
                ->reset()->where('profile_id', $profileId)->where('service_id', (int)$legacy->getId())->select()->fetch()->getItems();
            foreach ($links as $link) {
                $link->delete();
            }
        }
        return $countries;
    }

    /** 只撤下当前默认站 General 的八条旧国际绑定，保留国内、人工、重货及退货绑定。 */
    private function replaceStandardLinks(int $profileId, array $serviceIds, array $scope): void
    {
        $legacyIds = [];
        foreach (self::LEGACY_LANES as $lane) {
            $service = $this->find(ShippingService::class, $scope + ['service_code' => 'SEED_LANE_' . $lane]);
            if ($service) {
                $legacyIds[] = (int)$service->getId();
            }
        }
        $links = $this->objectManager->getInstance(ShippingProfileService::class, [], false)
            ->reset()->where('profile_id', $profileId)->select()->fetch()->getItems();
        $kept = [];
        foreach ($links as $link) {
            $serviceId = (int)$link->getData('service_id');
            if (in_array($serviceId, $legacyIds, true)) {
                $link->delete();
            } else {
                $kept[$serviceId] = true;
            }
        }
        foreach ($serviceIds as $serviceId) {
            if (!isset($kept[$serviceId])) {
                $this->objectManager->getInstance(ShippingProfileService::class, [], false)
                    ->setData(['profile_id' => $profileId, 'service_id' => $serviceId])->save();
            }
        }
    }

    private function find(string $class, array $filters): ?AbstractModel
    {
        $model = $this->objectManager->getInstance($class, [], false)->reset();
        foreach ($filters as $field => $value) {
            $model->where($field, $value);
        }
        $items = $model->select()->fetch()->getItems();
        return $items[0] ?? null;
    }
}
