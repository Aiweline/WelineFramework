<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\Zone;

/**
 * Validation and persistence boundary for the compact Shipping admin forms.
 */
final class ShippingConfigurationAdminService
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /** @param array<string,mixed> $data */
    public function createRegion(array $data): Region
    {
        $country = strtoupper(trim((string)($data['country_code'] ?? '')));
        $code = strtoupper(trim((string)($data['region_code'] ?? '')));
        $name = trim((string)($data['region_name'] ?? ''));
        $type = strtolower(trim((string)($data['region_type'] ?? Region::TYPE_PROVINCE)));
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) throw new \InvalidArgumentException((string)__('国家代码必须是 2 位大写字母。'));
        if ($code === '' || strlen($code) > 50 || preg_match('/^[A-Z0-9_-]+$/D', $code) !== 1) throw new \InvalidArgumentException((string)__('地区代码格式无效。'));
        if ($name === '' || mb_strlen($name) > 255) throw new \InvalidArgumentException((string)__('地区名称不能为空且不能超过 255 个字符。'));
        if (!in_array($type, [Region::TYPE_COUNTRY, Region::TYPE_PROVINCE, Region::TYPE_CITY, Region::TYPE_DISTRICT], true)) throw new \InvalidArgumentException((string)__('地区类型无效。'));
        $this->assertUnique(Region::class, Region::schema_fields_REGION_CODE, $code, [Region::schema_fields_COUNTRY_CODE => $country]);

        /** @var Region $model */
        $model = $this->fresh(Region::class);
        $model->setData([
            Region::schema_fields_COUNTRY_CODE => $country,
            Region::schema_fields_PARENT_REGION_ID => null,
            Region::schema_fields_REGION_CODE => $code,
            Region::schema_fields_REGION_NAME => $name,
            Region::schema_fields_REGION_TYPE => $type,
            Region::schema_fields_POSTAL_CODE_PATTERN => trim((string)($data['postal_code_pattern'] ?? '')) ?: null,
            Region::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
            Region::schema_fields_SORT_ORDER => max(0, (int)($data['sort_order'] ?? 0)),
        ])->save();
        return $model;
    }

    /** @param array<string,mixed> $data */
    public function createZone(array $data): Zone
    {
        $name = trim((string)($data['zone_name'] ?? ''));
        $code = strtoupper(trim((string)($data['zone_code'] ?? '')));
        $this->assertNameAndCode($name, $code, '配送区域');
        $this->assertUnique(Zone::class, Zone::schema_fields_ZONE_CODE, $code);
        /** @var Zone $model */
        $model = $this->fresh(Zone::class);
        $model->setData([
            Zone::schema_fields_ZONE_NAME => $name,
            Zone::schema_fields_ZONE_CODE => $code,
            Zone::schema_fields_DESCRIPTION => trim((string)($data['description'] ?? '')) ?: null,
            Zone::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
            Zone::schema_fields_SORT_ORDER => max(0, (int)($data['sort_order'] ?? 0)),
        ])->save();
        return $model;
    }

    /** @param array<string,mixed> $data */
    public function createRateTemplate(array $data): RateTemplate
    {
        $name = trim((string)($data['template_name'] ?? ''));
        $code = strtoupper(trim((string)($data['template_code'] ?? '')));
        $type = strtolower(trim((string)($data['calculation_type'] ?? RateTemplate::CALC_TYPE_FIXED)));
        $this->assertNameAndCode($name, $code, '费用模板');
        if (!in_array($type, [RateTemplate::CALC_TYPE_WEIGHT, RateTemplate::CALC_TYPE_VOLUME, RateTemplate::CALC_TYPE_QUANTITY, RateTemplate::CALC_TYPE_FIXED, RateTemplate::CALC_TYPE_MIXED], true)) throw new \InvalidArgumentException((string)__('费用计算类型无效。'));
        $this->assertUnique(RateTemplate::class, RateTemplate::schema_fields_TEMPLATE_CODE, $code);
        /** @var RateTemplate $model */
        $model = $this->fresh(RateTemplate::class);
        $model->setData([
            RateTemplate::schema_fields_TEMPLATE_NAME => $name,
            RateTemplate::schema_fields_TEMPLATE_CODE => $code,
            RateTemplate::schema_fields_CALCULATION_TYPE => $type,
            RateTemplate::schema_fields_BASE_FEE => max(0, (float)($data['base_fee'] ?? 0)),
            RateTemplate::schema_fields_CURRENCY_CODE => strtoupper(trim((string)($data['currency_code'] ?? 'CNY'))),
            RateTemplate::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
        ])->save();
        return $model;
    }

    /** @param array<string,mixed> $data */
    public function createFreeShippingRule(array $data): FreeShippingRule
    {
        $name = trim((string)($data['rule_name'] ?? ''));
        $code = strtoupper(trim((string)($data['rule_code'] ?? '')));
        $type = strtolower(trim((string)($data['condition_type'] ?? FreeShippingRule::CONDITION_ORDER_AMOUNT)));
        $this->assertNameAndCode($name, $code, '免邮规则');
        if (!in_array($type, [FreeShippingRule::CONDITION_ORDER_AMOUNT, FreeShippingRule::CONDITION_MEMBER_LEVEL, FreeShippingRule::CONDITION_REGION, FreeShippingRule::CONDITION_COUPON, FreeShippingRule::CONDITION_MIXED], true)) throw new \InvalidArgumentException((string)__('免邮条件类型无效。'));
        $this->assertUnique(FreeShippingRule::class, FreeShippingRule::schema_fields_RULE_CODE, $code);
        /** @var FreeShippingRule $model */
        $model = $this->fresh(FreeShippingRule::class);
        $model->setData([
            FreeShippingRule::schema_fields_RULE_NAME => $name,
            FreeShippingRule::schema_fields_RULE_CODE => $code,
            FreeShippingRule::schema_fields_CONDITION_TYPE => $type,
            FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT => max(0, (float)($data['min_order_amount'] ?? 0)),
            FreeShippingRule::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
            FreeShippingRule::schema_fields_PRIORITY => max(0, (int)($data['priority'] ?? 0)),
        ])->save();
        return $model;
    }

    /** @param array<string,mixed> $data */
    public function createShippingService(array $data): ShippingService
    {
        $name = trim((string)($data['service_name'] ?? ''));
        $code = strtoupper(trim((string)($data['service_code'] ?? '')));
        $carrierId = (int)($data['carrier_id'] ?? 0);
        $zoneId = (int)($data['zone_id'] ?? 0);
        $this->assertNameAndCode($name, $code, '配送服务');
        $this->assertReference(Carrier::class, $carrierId, '快递公司');
        $this->assertReference(Zone::class, $zoneId, '配送区域');
        $this->assertUnique(ShippingService::class, ShippingService::schema_fields_SERVICE_CODE, $code);
        $minDays = max(0, (int)($data['estimated_days_min'] ?? 0));
        $maxDays = max($minDays, (int)($data['estimated_days_max'] ?? $minDays));
        /** @var ShippingService $model */
        $model = $this->fresh(ShippingService::class);
        $model->setData([
            ShippingService::schema_fields_SERVICE_NAME => $name,
            ShippingService::schema_fields_SERVICE_CODE => $code,
            ShippingService::schema_fields_CARRIER_ID => $carrierId,
            ShippingService::schema_fields_ZONE_ID => $zoneId,
            ShippingService::schema_fields_RATE_TEMPLATE_ID => null,
            ShippingService::schema_fields_FREE_SHIPPING_RULE_ID => null,
            ShippingService::schema_fields_ESTIMATED_DAYS_MIN => $minDays,
            ShippingService::schema_fields_ESTIMATED_DAYS_MAX => $maxDays,
            ShippingService::schema_fields_IS_FREE_SHIPPING => 0,
            ShippingService::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
            ShippingService::schema_fields_SORT_ORDER => max(0, (int)($data['sort_order'] ?? 0)),
        ])->save();
        return $model;
    }

    private function assertNameAndCode(string $name, string $code, string $label): void
    {
        if ($name === '' || mb_strlen($name) > 255) throw new \InvalidArgumentException((string)__('%{1}名称不能为空且不能超过 255 个字符。', [$label]));
        if ($code === '' || strlen($code) > 50 || preg_match('/^[A-Z0-9_-]+$/D', $code) !== 1) throw new \InvalidArgumentException((string)__('%{1}代码格式无效。', [$label]));
    }

    /** @param class-string $class @param array<string,mixed> $extra */
    private function assertUnique(string $class, string $field, string $value, array $extra = []): void
    {
        $model = $this->fresh($class)->where($field, $value);
        foreach ($extra as $extraField => $extraValue) $model->where($extraField, $extraValue);
        if ($model->find()->fetch()->getId()) throw new \RuntimeException((string)__('代码已存在。'));
    }

    /** @param class-string $class */
    private function assertReference(string $class, int $id, string $label): void
    {
        if ($id <= 0 || !$this->fresh($class)->load($id)->getId()) throw new \InvalidArgumentException((string)__('%{1}不存在。', [$label]));
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function fresh(string $class): object
    {
        return $this->objectManager->getInstance($class, [], false);
    }
}
