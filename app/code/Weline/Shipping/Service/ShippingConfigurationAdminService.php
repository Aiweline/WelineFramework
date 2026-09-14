<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\ShippingService;

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
        $mode = strtolower(trim((string)($data['create_mode'] ?? '')));
        if ($mode === 'manual_path'
            || isset($data['add_province_name'])
            || isset($data['add_district_name'])
            || isset($data['province_name'])
            || isset($data['district_name'])) {
            $result = $this->createManualProvinceDistrict($data);
            $primary = $result['regions'][0] ?? null;
            if (!$primary instanceof Region) {
                throw new \InvalidArgumentException((string)__('请填写要新增的省份或区县。'));
            }

            return $primary;
        }

        $country = strtoupper(trim((string)($data['country_code'] ?? '')));
        $code = strtoupper(trim((string)($data['region_code'] ?? '')));
        $name = trim((string)($data['region_name'] ?? ''));
        $type = strtolower(trim((string)($data['region_type'] ?? Region::TYPE_PROVINCE)));
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) throw new \InvalidArgumentException((string)__('国家代码必须是 2 位大写字母。'));
        if ($code === '' || strlen($code) > 50 || preg_match('/^[A-Z0-9_-]+$/D', $code) !== 1) throw new \InvalidArgumentException((string)__('地区代码格式无效。'));
        if ($name === '' || mb_strlen($name) > 255) throw new \InvalidArgumentException((string)__('地区名称不能为空且不能超过 255 个字符。'));
        if (!in_array($type, [Region::TYPE_COUNTRY, Region::TYPE_PROVINCE, Region::TYPE_CITY, Region::TYPE_DISTRICT], true)) throw new \InvalidArgumentException((string)__('地区类型无效。'));
        $this->assertUnique(Region::class, Region::schema_fields_REGION_CODE, $code, [Region::schema_fields_COUNTRY_CODE => $country]);

        $parentId = null;
        if ($type !== Region::TYPE_COUNTRY) {
            $parentId = $this->findRegionIdByCode($country, $country);
        }

        /** @var Region $model */
        $model = $this->fresh(Region::class);
        $model->setData([
            Region::schema_fields_COUNTRY_CODE => $country,
            Region::schema_fields_PARENT_REGION_ID => $parentId,
            Region::schema_fields_REGION_CODE => $code,
            Region::schema_fields_REGION_NAME => $name,
            Region::schema_fields_REGION_TYPE => $type,
            Region::schema_fields_POSTAL_CODE_PATTERN => trim((string)($data['postal_code_pattern'] ?? '')) ?: null,
            Region::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
            Region::schema_fields_SORT_ORDER => max(0, (int)($data['sort_order'] ?? 0)),
        ])->save();
        return $model;
    }

    /**
     * Update TYPE_COUNTRY sort_order (hot position for checkout postal ambiguity + country lists).
     * Lower numbers appear first.
     *
     * @param array<string, int|string> $sortByCountry ISO2 => sort_order
     * @return int number of rows updated
     */
    public function updateCountrySortOrders(array $sortByCountry): int
    {
        $updated = 0;
        foreach ($sortByCountry as $code => $sortRaw) {
            $cc = strtoupper(trim((string)$code));
            if (preg_match('/^[A-Z]{2}$/D', $cc) !== 1) {
                continue;
            }
            $sort = max(0, (int)$sortRaw);
            $id = $this->findRegionIdByCode($cc, $cc);
            if ($id === null) {
                continue;
            }
            /** @var Region $item */
            $item = $this->fresh(Region::class)->load($id);
            if ((int)$item->getId() <= 0) {
                continue;
            }
            if ((string)$item->getData(Region::schema_fields_REGION_TYPE) !== Region::TYPE_COUNTRY) {
                continue;
            }
            if ((int)$item->getData(Region::schema_fields_SORT_ORDER) === $sort) {
                continue;
            }
            $item->setData(Region::schema_fields_SORT_ORDER, $sort)->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * 弹窗新增：国家已选，省/区手填；已存在代码一律拒绝（不更新）。
     *
     * @param array<string,mixed> $data
     * @return array{created:int,regions:list<Region>,primary_country:string}
     */
    public function createManualProvinceDistrict(array $data): array
    {
        $country = strtoupper(trim((string)($data['country_code'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new \InvalidArgumentException((string)__('请先选择国家。'));
        }

        $provinceName = trim((string)($data['add_province_name'] ?? $data['province_name'] ?? ''));
        $provinceCode = strtoupper(trim((string)($data['add_province_code'] ?? $data['province_code'] ?? '')));
        $districtName = trim((string)($data['add_district_name'] ?? $data['district_name'] ?? ''));
        $districtCode = strtoupper(trim((string)($data['add_district_code'] ?? $data['district_code'] ?? '')));
        $isActive = !array_key_exists('is_active', $data) || !empty($data['is_active']) ? 1 : 0;
        $sortOrder = max(0, (int)($data['sort_order'] ?? 0));

        if ($provinceName === '') {
            throw new \InvalidArgumentException((string)__('请填写要新增的省份名称。'));
        }
        if ($provinceCode === '') {
            throw new \InvalidArgumentException((string)__('请填写要新增的省份代码。'));
        }
        if ($districtName !== '' && $districtCode === '') {
            throw new \InvalidArgumentException((string)__('请填写要新增的区县代码。'));
        }
        if ($districtCode !== '' && $districtName === '') {
            throw new \InvalidArgumentException((string)__('请填写要新增的区县名称。'));
        }

        $countryId = $this->ensureCountryRow($country, trim((string)($data['country_name'] ?? '')));
        $regions = [];
        $created = 0;

        // 已存在代码一律不更新；省已存在时仅允许继续新增「尚不存在」的区县。
        $existingProvinceId = $this->findRegionIdByCode($country, $provinceCode);
        if ($existingProvinceId !== null) {
            if ($districtName === '') {
                throw new \RuntimeException((string)__('该地区代码已存在，不能重复添加。'));
            }
            $provinceId = $existingProvinceId;
            /** @var Region $existingProvince */
            $existingProvince = $this->fresh(Region::class)->load($provinceId);
            if ((int)$existingProvince->getId() > 0) {
                $regions[] = $existingProvince;
            }
        } else {
            $this->assertRegionCodeAvailable($country, $provinceCode);
            /** @var Region $province */
            $province = $this->fresh(Region::class);
            $province->setData([
                Region::schema_fields_COUNTRY_CODE => $country,
                Region::schema_fields_PARENT_REGION_ID => $countryId,
                Region::schema_fields_REGION_CODE => $provinceCode,
                Region::schema_fields_REGION_NAME => $provinceName,
                Region::schema_fields_REGION_TYPE => Region::TYPE_PROVINCE,
                Region::schema_fields_IS_ACTIVE => $isActive,
                Region::schema_fields_SORT_ORDER => $sortOrder,
            ])->save();
            $provinceId = (int)$province->getId();
            $regions[] = $province;
            $created++;
        }

        if ($districtName !== '') {
            $this->assertRegionCodeAvailable($country, $districtCode);
            /** @var Region $district */
            $district = $this->fresh(Region::class);
            $district->setData([
                Region::schema_fields_COUNTRY_CODE => $country,
                Region::schema_fields_PARENT_REGION_ID => $provinceId,
                Region::schema_fields_REGION_CODE => $districtCode,
                Region::schema_fields_REGION_NAME => $districtName,
                Region::schema_fields_REGION_TYPE => Region::TYPE_DISTRICT,
                Region::schema_fields_IS_ACTIVE => $isActive,
                Region::schema_fields_SORT_ORDER => $sortOrder,
            ])->save();
            $regions[] = $district;
            $created++;
        }

        if ($created < 1) {
            throw new \RuntimeException((string)__('该地区代码已存在，不能重复添加。'));
        }

        return [
            'created' => $created,
            'regions' => $regions,
            'primary_country' => $country,
        ];
    }

    private function assertRegionCodeAvailable(string $countryCode, string $regionCode): void
    {
        if ($regionCode === '' || strlen($regionCode) > 50 || preg_match('/^[A-Z0-9_-]+$/D', $regionCode) !== 1) {
            throw new \InvalidArgumentException((string)__('地区代码格式无效。'));
        }
        if ($this->findRegionIdByCode($countryCode, $regionCode) !== null) {
            throw new \RuntimeException((string)__('该地区代码已存在，不能重复添加。'));
        }
    }

    private function ensureCountryRow(string $countryCode, string $countryName = ''): int
    {
        $existingId = $this->findRegionIdByCode($countryCode, $countryCode);
        if ($existingId !== null) {
            return $existingId;
        }
        $name = $countryName !== '' ? $countryName : $countryCode;
        /** @var Region $country */
        $country = $this->fresh(Region::class);
        $country->setData([
            Region::schema_fields_COUNTRY_CODE => $countryCode,
            Region::schema_fields_PARENT_REGION_ID => null,
            Region::schema_fields_REGION_CODE => $countryCode,
            Region::schema_fields_REGION_NAME => $name,
            Region::schema_fields_REGION_TYPE => Region::TYPE_COUNTRY,
            Region::schema_fields_IS_ACTIVE => 1,
            Region::schema_fields_SORT_ORDER => 0,
        ])->save();

        return (int)$country->getId();
    }

    /**
     * 从 theme:address multi selection 自动识别并幂等写入国家/省/市/区。
     *
     * @param list<array<string,mixed>> $selection
     * @param array<string,mixed> $options
     * @return array{created:int,updated:int,regions:list<Region>,primary_country:string}
     */
    public function createRegionsFromAddressSelection(array $selection, array $options = []): array
    {
        $rows = self::normalizeAddressSelection($selection);
        if ($rows === []) {
            throw new \InvalidArgumentException((string)__('请先在地址选择标签中选择国家/省份/区县。'));
        }

        $isActive = !array_key_exists('is_active', $options) || !empty($options['is_active']) ? 1 : 0;
        $sortOrder = max(0, (int)($options['sort_order'] ?? 0));
        $created = 0;
        $updated = 0;
        $regions = [];
        /** @var array<string,int> $idByKey country|type|code => id */
        $idByKey = [];
        /** @var array<string,int> $latestByCountryType country|type => id */
        $latestByCountryType = [];

        foreach ($rows as $row) {
            $country = $row['country_code'];
            $code = $row['region_code'];
            $type = $row['region_type'];
            $name = $row['region_name'];
            $parentId = $this->resolveSelectionParentId($row, $idByKey, $latestByCountryType);

            /** @var Region $finder */
            $finder = $this->fresh(Region::class);
            $existing = $finder->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $country)
                ->where(Region::schema_fields_REGION_CODE, $code)
                ->find()
                ->fetch();

            $payload = [
                Region::schema_fields_COUNTRY_CODE => $country,
                Region::schema_fields_PARENT_REGION_ID => $parentId,
                Region::schema_fields_REGION_CODE => $code,
                Region::schema_fields_REGION_NAME => $name,
                Region::schema_fields_REGION_TYPE => $type,
                Region::schema_fields_IS_ACTIVE => $isActive,
                Region::schema_fields_SORT_ORDER => $sortOrder,
            ];

            if ($existing->getId()) {
                $existing->setData($payload)->save();
                $model = $existing;
                $updated++;
            } else {
                /** @var Region $model */
                $model = $this->fresh(Region::class);
                $model->setData($payload)->save();
                $created++;
            }

            $id = (int)$model->getId();
            if ($id > 0) {
                $idByKey[$country . '|' . $type . '|' . $code] = $id;
                $latestByCountryType[$country . '|' . $type] = $id;
            }
            $regions[] = $model;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'regions' => $regions,
            'primary_country' => (string)($rows[0]['country_code'] ?? ''),
        ];
    }

    /**
     * @param list<array<string,mixed>> $selection
     * @return list<array{country_code:string,region_code:string,region_name:string,region_type:string,parent_region_id:int}>
     */
    public static function normalizeAddressSelection(array $selection): array
    {
        $order = [
            Region::TYPE_COUNTRY => 0,
            Region::TYPE_PROVINCE => 1,
            Region::TYPE_CITY => 2,
            Region::TYPE_DISTRICT => 3,
        ];
        $normalized = [];
        $seen = [];

        foreach ($selection as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = strtolower(trim((string)($item['region_type'] ?? '')));
            if ($type === 'city') {
                // city 按市级写入；部分目录把区挂在省下并用 district
            }
            if (!isset($order[$type])) {
                continue;
            }
            $country = strtoupper(trim((string)($item['country_code'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
                continue;
            }
            $code = strtoupper(trim((string)($item['region_code'] ?? '')));
            if ($type === Region::TYPE_COUNTRY && $code === '') {
                $code = $country;
            }
            if ($code === '' || strlen($code) > 50 || preg_match('/^[A-Z0-9_-]+$/D', $code) !== 1) {
                continue;
            }
            $name = trim((string)($item['region_name'] ?? $item['label'] ?? ''));
            if ($name === '') {
                $name = $code;
            }
            if (mb_strlen($name) > 255) {
                $name = mb_substr($name, 0, 255);
            }
            $key = $country . '|' . $type . '|' . $code;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = [
                'country_code' => $country,
                'region_code' => $code,
                'region_name' => $name,
                'region_type' => $type,
                'parent_region_id' => max(0, (int)($item['parent_region_id'] ?? 0)),
            ];
        }

        usort($normalized, static function (array $a, array $b) use ($order): int {
            $typeCmp = ($order[$a['region_type']] ?? 9) <=> ($order[$b['region_type']] ?? 9);
            if ($typeCmp !== 0) {
                return $typeCmp;
            }
            $countryCmp = strcmp($a['country_code'], $b['country_code']);
            if ($countryCmp !== 0) {
                return $countryCmp;
            }

            return strcmp($a['region_code'], $b['region_code']);
        });

        return $normalized;
    }

    /**
     * @param array{country_code:string,region_code:string,region_name:string,region_type:string,parent_region_id:int} $row
     * @param array<string,int> $idByKey
     * @param array<string,int> $latestByCountryType
     */
    private function resolveSelectionParentId(array $row, array $idByKey, array $latestByCountryType): ?int
    {
        $type = $row['region_type'];
        $country = $row['country_code'];
        if ($type === Region::TYPE_COUNTRY) {
            return null;
        }

        $hint = max(0, (int)($row['parent_region_id'] ?? 0));
        if ($hint > 0) {
            /** @var Region $probe */
            $probe = $this->fresh(Region::class);
            if ((int)$probe->load($hint)->getId() === $hint) {
                return $hint;
            }
        }

        if ($type === Region::TYPE_PROVINCE) {
            return $latestByCountryType[$country . '|' . Region::TYPE_COUNTRY]
                ?? $idByKey[$country . '|' . Region::TYPE_COUNTRY . '|' . $country]
                ?? $this->findRegionIdByCode($country, $country);
        }

        if ($type === Region::TYPE_CITY) {
            return $latestByCountryType[$country . '|' . Region::TYPE_PROVINCE] ?? null;
        }

        // district：优先市，其次省
        return $latestByCountryType[$country . '|' . Region::TYPE_CITY]
            ?? $latestByCountryType[$country . '|' . Region::TYPE_PROVINCE]
            ?? null;
    }

    private function findRegionIdByCode(string $countryCode, string $regionCode): ?int
    {
        /** @var Region $finder */
        $finder = $this->fresh(Region::class);
        $row = $finder->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(Region::schema_fields_REGION_CODE, $regionCode)
            ->find()
            ->fetch();
        $id = (int)$row->getId();

        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $data */
    public function createRateTemplate(array $data): RateTemplate
    {
        $name = trim((string)($data['template_name'] ?? ''));
        $code = strtoupper(trim((string)($data['template_code'] ?? '')));
        $type = strtolower(trim((string)($data['calculation_type'] ?? RateTemplate::CALC_TYPE_FIXED)));
        $this->assertNameAndCode($name, $code, '费用模板');
        if (!in_array($type, [
            RateTemplate::CALC_TYPE_WEIGHT,
            RateTemplate::CALC_TYPE_VOLUME,
            RateTemplate::CALC_TYPE_QUANTITY,
            RateTemplate::CALC_TYPE_FIXED,
            RateTemplate::CALC_TYPE_MIXED,
            RateTemplate::CALC_TYPE_WEIGHT_TABLE,
            RateTemplate::CALC_TYPE_PRICE_TABLE,
        ], true)) {
            throw new \InvalidArgumentException((string)__('费用计算类型无效。'));
        }
        $scope = $this->normalizeScopePayload($data);
        $this->assertUnique(RateTemplate::class, RateTemplate::schema_fields_TEMPLATE_CODE, $code, [
            RateTemplate::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            RateTemplate::schema_fields_SCOPE_ID => $scope['scope_id'],
        ]);
        /** @var RateTemplate $model */
        $model = $this->fresh(RateTemplate::class);
        $model->setData($this->rateTemplatePayload($data, $scope, $name, $code, $type))->save();
        return $model;
    }

    /** @param array<string,mixed> $data */
    public function updateRateTemplate(array $data): RateTemplate
    {
        $templateId = (int)($data['template_id'] ?? 0);
        if ($templateId <= 0) {
            throw new \InvalidArgumentException((string)__('费用模板不存在。'));
        }
        $scope = $this->normalizeScopePayload($data);
        $this->assertSameScope(RateTemplate::class, $templateId, $scope, '费用模板');
        /** @var RateTemplate $model */
        $model = $this->fresh(RateTemplate::class)->load($templateId);
        $name = trim((string)($data['template_name'] ?? ''));
        $code = strtoupper(trim((string)($data['template_code'] ?? $model->getData(RateTemplate::schema_fields_TEMPLATE_CODE))));
        $type = strtolower(trim((string)($data['calculation_type'] ?? $model->getData(RateTemplate::schema_fields_CALCULATION_TYPE))));
        $this->assertNameAndCode($name, $code, '费用模板');
        if (!in_array($type, [
            RateTemplate::CALC_TYPE_WEIGHT,
            RateTemplate::CALC_TYPE_VOLUME,
            RateTemplate::CALC_TYPE_QUANTITY,
            RateTemplate::CALC_TYPE_FIXED,
            RateTemplate::CALC_TYPE_MIXED,
            RateTemplate::CALC_TYPE_WEIGHT_TABLE,
            RateTemplate::CALC_TYPE_PRICE_TABLE,
        ], true)) {
            throw new \InvalidArgumentException((string)__('费用计算类型无效。'));
        }
        if ($code !== strtoupper((string)$model->getData(RateTemplate::schema_fields_TEMPLATE_CODE))) {
            $this->assertUnique(RateTemplate::class, RateTemplate::schema_fields_TEMPLATE_CODE, $code, [
                RateTemplate::schema_fields_SCOPE_TYPE => $scope['scope_type'],
                RateTemplate::schema_fields_SCOPE_ID => $scope['scope_id'],
            ]);
        }
        $model->setData($this->rateTemplatePayload($data, $scope, $name, $code, $type))->save();
        return $model;
    }

    public function deleteRateTemplate(int $templateId): void
    {
        /** @var RateTemplate $model */
        $model = $this->fresh(RateTemplate::class)->load($templateId);
        if ((int)$model->getId() <= 0) {
            throw new \InvalidArgumentException((string)__('费用模板不存在。'));
        }
        if ($model->isSeed()) {
            throw new \RuntimeException((string)__('系统种子不可删除'));
        }
        /** @var ShippingService $serviceProbe */
        $serviceProbe = $this->fresh(ShippingService::class);
        $refs = $serviceProbe->reset()
            ->where(ShippingService::schema_fields_RATE_TEMPLATE_ID, $templateId)
            ->select()
            ->fetch()
            ->getItems();
        if (is_array($refs) && $refs !== []) {
            throw new \RuntimeException((string)__('仍有配送服务引用该费用模板，请先解除绑定后再删除。'));
        }
        $model->delete();
    }

    /**
     * 费用一律按站点基础货币落库；忽略表单自由 currency_code。
     *
     * @param array<string,mixed> $data
     * @param array{scope_type:string,scope_id:int} $scope
     * @return array<string,mixed>
     */
    private function rateTemplatePayload(array $data, array $scope, string $name, string $code, string $type): array
    {
        /** @var CurrencyRateService $rates */
        $rates = $this->objectManager->getInstance(CurrencyRateService::class);
        $baseCurrency = strtoupper(trim($rates->getBaseCurrency())) ?: 'CNY';
        $maxWeight = isset($data['max_weight_kg']) && $data['max_weight_kg'] !== ''
            ? max(0, (float)$data['max_weight_kg'])
            : null;
        $bracketsJson = null;
        if ($type === RateTemplate::CALC_TYPE_WEIGHT_TABLE || $type === RateTemplate::CALC_TYPE_PRICE_TABLE) {
            $raw = $data['rate_brackets'] ?? [];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($raw)) {
                $raw = [];
            }
            /** @var RateBracketValidator $validator */
            $validator = $this->objectManager->getInstance(RateBracketValidator::class);
            $normalized = $validator->normalizeAndValidate($raw, $maxWeight);
            $bracketsJson = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        }

        return [
            RateTemplate::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            RateTemplate::schema_fields_SCOPE_ID => $scope['scope_id'],
            RateTemplate::schema_fields_TEMPLATE_NAME => $name,
            RateTemplate::schema_fields_TEMPLATE_CODE => $code,
            RateTemplate::schema_fields_CALCULATION_TYPE => $type,
            RateTemplate::schema_fields_BASE_FEE => ($type === RateTemplate::CALC_TYPE_WEIGHT_TABLE
                || $type === RateTemplate::CALC_TYPE_PRICE_TABLE)
                ? 0
                : max(0, (float)($data['base_fee'] ?? 0)),
            RateTemplate::schema_fields_WEIGHT_RATE => isset($data['weight_rate']) ? max(0, (float)$data['weight_rate']) : null,
            RateTemplate::schema_fields_VOLUME_RATE => isset($data['volume_rate']) ? max(0, (float)$data['volume_rate']) : null,
            RateTemplate::schema_fields_QUANTITY_RATE => isset($data['quantity_rate']) ? max(0, (float)$data['quantity_rate']) : null,
            RateTemplate::schema_fields_RATE_BRACKETS => $bracketsJson,
            RateTemplate::schema_fields_MAX_WEIGHT_KG => $maxWeight,
            RateTemplate::schema_fields_CURRENCY_CODE => $baseCurrency,
            RateTemplate::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public function createFreeShippingRule(array $data): FreeShippingRule
    {
        $name = trim((string)($data['rule_name'] ?? ''));
        $code = strtoupper(trim((string)($data['rule_code'] ?? '')));
        $type = strtolower(trim((string)($data['condition_type'] ?? FreeShippingRule::CONDITION_ORDER_AMOUNT)));
        $this->assertNameAndCode($name, $code, '免邮规则');
        if (!in_array($type, [FreeShippingRule::CONDITION_ORDER_AMOUNT, FreeShippingRule::CONDITION_MEMBER_LEVEL, FreeShippingRule::CONDITION_REGION, FreeShippingRule::CONDITION_COUPON, FreeShippingRule::CONDITION_MIXED], true)) throw new \InvalidArgumentException((string)__('免邮条件类型无效。'));
        $scope = $this->normalizeScopePayload($data);
        $this->assertUnique(FreeShippingRule::class, FreeShippingRule::schema_fields_RULE_CODE, $code, [
            FreeShippingRule::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            FreeShippingRule::schema_fields_SCOPE_ID => $scope['scope_id'],
        ]);
        $regionIds = $this->parseRegionIdsPayload($data['region_ids'] ?? $data['free_region_selection'] ?? null);
        /** @var FreeShippingRule $model */
        $model = $this->fresh(FreeShippingRule::class);
        $model->setData([
            FreeShippingRule::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            FreeShippingRule::schema_fields_SCOPE_ID => $scope['scope_id'],
            FreeShippingRule::schema_fields_RULE_NAME => $name,
            FreeShippingRule::schema_fields_RULE_CODE => $code,
            FreeShippingRule::schema_fields_CONDITION_TYPE => $type,
            FreeShippingRule::schema_fields_MIN_ORDER_AMOUNT => max(0, (float)($data['min_order_amount'] ?? 0)),
            FreeShippingRule::schema_fields_ORIGIN => FreeShippingRule::ORIGIN_MANUAL,
            FreeShippingRule::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
            FreeShippingRule::schema_fields_PRIORITY => max(0, (int)($data['priority'] ?? 0)),
        ]);
        $model->setRegionIds($regionIds);
        $model->save();
        return $model;
    }

    public function setFreeShippingRuleActive(int $ruleId, bool $active): FreeShippingRule
    {
        /** @var FreeShippingRule $model */
        $model = $this->fresh(FreeShippingRule::class)->load($ruleId);
        if ((int)$model->getId() <= 0) {
            throw new \InvalidArgumentException((string)__('免邮规则不存在。'));
        }
        $model->setData(FreeShippingRule::schema_fields_IS_ACTIVE, $active ? 1 : 0);
        $model->setData(FreeShippingRule::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        $model->save();

        return $model;
    }

    public function deleteFreeShippingRule(int $ruleId): void
    {
        /** @var FreeShippingRule $model */
        $model = $this->fresh(FreeShippingRule::class)->load($ruleId);
        if ((int)$model->getId() <= 0) {
            throw new \InvalidArgumentException((string)__('免邮规则不存在。'));
        }
        $origin = strtolower(trim((string)$model->getData(FreeShippingRule::schema_fields_ORIGIN)));
        if ($origin === FreeShippingRule::ORIGIN_SEED || $origin !== FreeShippingRule::ORIGIN_MANUAL) {
            throw new \RuntimeException((string)__('系统种子不可删除'));
        }
        $model->delete();
    }

    /** @param array<string,mixed> $data */
    public function createShippingService(array $data): ShippingService
    {
        $name = trim((string)($data['service_name'] ?? ''));
        $code = strtoupper(trim((string)($data['service_code'] ?? '')));
        $carrierId = (int)($data['carrier_id'] ?? 0);
        $this->assertNameAndCode($name, $code, '配送服务');
        $this->assertReference(Carrier::class, $carrierId, '快递公司');
        $scope = $this->normalizeScopePayload($data);
        $this->assertUnique(ShippingService::class, ShippingService::schema_fields_SERVICE_CODE, $code, [
            ShippingService::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            ShippingService::schema_fields_SCOPE_ID => $scope['scope_id'],
        ]);
        $minDays = max(0, (int)($data['estimated_days_min'] ?? 0));
        $maxDays = max($minDays, (int)($data['estimated_days_max'] ?? $minDays));
        $templateId = (int)($data['rate_template_id'] ?? 0);
        $freeRuleId = (int)($data['free_shipping_rule_id'] ?? 0);
        $originId = (int)($data['origin_shipping_address_id'] ?? 0);
        if ($templateId > 0) {
            $this->assertReference(RateTemplate::class, $templateId, '费用模板');
            $this->assertSameScope(RateTemplate::class, $templateId, $scope, '费用模板');
        }
        if ($freeRuleId > 0) {
            $this->assertReference(FreeShippingRule::class, $freeRuleId, '免邮规则');
            $this->assertSameScope(FreeShippingRule::class, $freeRuleId, $scope, '免邮规则');
        }
        if ($originId > 0) {
            $this->assertReference(ShippingAddress::class, $originId, '发货地址');
        }
        /** @var ShippingService $model */
        $model = $this->fresh(ShippingService::class);
        $model->setData([
            ShippingService::schema_fields_SCOPE_TYPE => $scope['scope_type'],
            ShippingService::schema_fields_SCOPE_ID => $scope['scope_id'],
            ShippingService::schema_fields_SERVICE_NAME => $name,
            ShippingService::schema_fields_SERVICE_CODE => $code,
            ShippingService::schema_fields_CARRIER_ID => $carrierId,
            ShippingService::schema_fields_RATE_TEMPLATE_ID => $templateId > 0 ? $templateId : null,
            ShippingService::schema_fields_FREE_SHIPPING_RULE_ID => $freeRuleId > 0 ? $freeRuleId : null,
            ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID => $originId > 0 ? $originId : null,
            ShippingService::schema_fields_ESTIMATED_DAYS_MIN => $minDays,
            ShippingService::schema_fields_ESTIMATED_DAYS_MAX => $maxDays,
            ShippingService::schema_fields_INCOTERM => $this->normalizeIncoterm(
                (string)($data['incoterm'] ?? \Weline\Shipping\Service\ShippingIncotermService::DDU),
            ),
            ShippingService::schema_fields_IS_FREE_SHIPPING => !empty($data['is_free_shipping']) ? 1 : 0,
            ShippingService::schema_fields_IS_ACTIVE => !empty($data['is_active']) ? 1 : 0,
            ShippingService::schema_fields_SORT_ORDER => max(0, (int)($data['sort_order'] ?? 0)),
        ])->save();

        $laneRows = [];
        if (!empty($data['lane_dest_selection'])) {
            /** @var ServiceLaneAdminService $laneAdmin */
            $laneAdmin = $this->objectManager->getInstance(ServiceLaneAdminService::class);
            $laneRows = $laneAdmin->rowsFromAddressSelection($data['lane_dest_selection']);
        } elseif (!empty($data['lane_dest_rows']) && is_array($data['lane_dest_rows'])) {
            /** @var ServiceLaneAdminService $laneAdmin */
            $laneAdmin = $this->objectManager->getInstance(ServiceLaneAdminService::class);
            $laneRows = $laneAdmin->normalizeRows($data['lane_dest_rows']);
        }
        if ($laneRows !== []) {
            /** @var ServiceLaneAdminService $laneAdmin */
            $laneAdmin = $this->objectManager->getInstance(ServiceLaneAdminService::class);
            $laneAdmin->replaceForService((int)$model->getId(), $laneRows);
        }

        return $model;
    }

    /**
     * Update lane Incoterm only (duty_notice metadata; does not change Local amount).
     *
     * @param array<string,mixed> $data
     */
    public function updateShippingServiceIncoterm(array $data): ShippingService
    {
        $id = (int)($data['service_id'] ?? $data['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException((string)__('配送服务不存在。'));
        }
        /** @var ShippingService $model */
        $model = $this->fresh(ShippingService::class)->load($id);
        if (!$model->getId()) {
            throw new \InvalidArgumentException((string)__('配送服务不存在。'));
        }
        $scope = $this->normalizeScopePayload($data);
        if (
            (string)$model->getData(ShippingService::schema_fields_SCOPE_TYPE) !== $scope['scope_type']
            || (int)$model->getData(ShippingService::schema_fields_SCOPE_ID) !== $scope['scope_id']
        ) {
            throw new \InvalidArgumentException((string)__('配送服务必须与当前作用范围一致。'));
        }
        $model->setData(
            ShippingService::schema_fields_INCOTERM,
            $this->normalizeIncoterm((string)($data['incoterm'] ?? '')),
        )->save();

        return $model;
    }

    private function normalizeIncoterm(string $raw): string
    {
        return (new \Weline\Shipping\Service\ShippingIncotermService())->normalize($raw);
    }

    /**
     * @return list<int>
     */
    private function parseRegionIdsPayload(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/\s*,\s*/', $raw) ?: [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        // address multi selection → ensure regions then collect ids
        if ($raw !== [] && isset($raw[0]) && is_array($raw[0]) && isset($raw[0]['region_type'])) {
            try {
                $created = $this->createRegionsFromAddressSelection($raw);
                $ids = [];
                foreach ($created['regions'] as $region) {
                    $id = (int)$region->getId();
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }

                return array_values(array_unique($ids));
            } catch (\Throwable) {
                return [];
            }
        }
        $ids = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $id = (int)($item['region_id'] ?? $item['id'] ?? 0);
            } else {
                $id = (int)$item;
            }
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function assertNameAndCode(string $name, string $code, string $label): void
    {
        if ($name === '' || mb_strlen($name) > 255) throw new \InvalidArgumentException((string)__('%{1}名称不能为空且不能超过 255 个字符。', [$label]));
        if ($code === '' || strlen($code) > 50 || preg_match('/^[A-Z0-9_-]+$/D', $code) !== 1) throw new \InvalidArgumentException((string)__('%{1}代码格式无效。', [$label]));
    }

    /**
     * @param array<string,mixed> $data
     * @return array{scope_type:string,scope_id:int}
     */
    private function normalizeScopePayload(array $data): array
    {
        $type = strtolower(trim((string)($data['scope_type'] ?? ShippingService::SCOPE_WEBSITE)));
        if (!in_array($type, [ShippingService::SCOPE_WEBSITE, ShippingService::SCOPE_STORE, ShippingService::SCOPE_CHANNEL], true)) {
            $type = ShippingService::SCOPE_WEBSITE;
        }
        $id = max(0, (int)($data['scope_id'] ?? 0));
        if (
            ($type === ShippingService::SCOPE_STORE || $type === ShippingService::SCOPE_CHANNEL)
            && $id <= 0
            && trim((string)($data['target_scope'] ?? '')) !== ''
        ) {
            /** @var ShippingConfigScopeService $scopeSvc */
            $scopeSvc = $this->objectManager->getInstance(ShippingConfigScopeService::class);
            $resolved = $scopeSvc->resolveAdminTarget($data, false);

            return [
                'scope_type' => $resolved['scope_type'],
                'scope_id' => $resolved['scope_id'],
            ];
        }

        return ['scope_type' => $type, 'scope_id' => $id];
    }

    /**
     * @param class-string $class
     * @param array{scope_type:string,scope_id:int} $scope
     */
    private function assertSameScope(string $class, int $id, array $scope, string $label): void
    {
        $row = $this->fresh($class)->load($id);
        if (!$row->getId()) {
            throw new \InvalidArgumentException((string)__('%{1}不存在。', [$label]));
        }
        $type = (string)$row->getData('scope_type');
        $sid = (int)$row->getData('scope_id');
        if ($type !== $scope['scope_type'] || $sid !== $scope['scope_id']) {
            throw new \InvalidArgumentException((string)__('%{1}必须与当前作用范围一致。', [$label]));
        }
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
