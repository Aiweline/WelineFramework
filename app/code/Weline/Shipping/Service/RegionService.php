<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Cookie;
use Weline\I18n\Model\Countries;
use Weline\I18n\Model\Countries\Locale\Name;
use Weline\Shipping\Model\Region;
use Symfony\Component\Intl\Countries as IntlCountries;

/**
 * 地区服务
 * 
 * @package Weline_Shipping
 */
class RegionService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取地区模型实例
     * 
     * @return Region
     */
    private function getModel(): Region
    {
        return $this->objectManager->getInstance(Region::class);
    }

    /**
     * 构建地区树形结构
     * 
     * @param string|null $countryCode 国家代码，null表示获取所有国家
     * @return array
     */
    public function buildTree(?string $countryCode = null): array
    {
        $model = $this->getModel();
        $regions = [];
        
        if ($countryCode) {
            $countryRegions = $model->getByCountryCode($countryCode);
        } else {
            $countryRegions = $model->reset()
                ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY)
                ->where(Region::schema_fields_IS_ACTIVE, 1)
                ->order(Region::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch();
        }
        
        foreach ($countryRegions->getItems() as $region) {
            $regions[] = $this->buildNode($region);
        }
        
        return $regions;
    }

    /**
     * 构建单个节点（递归）
     * 
     * @param Region $region
     * @return array
     */
    private function buildNode(Region $region): array
    {
        $regionName = $this->localizedRegionName(
            (string)$region->getData(Region::schema_fields_COUNTRY_CODE),
            (string)$region->getData(Region::schema_fields_REGION_CODE),
            (string)$region->getData(Region::schema_fields_REGION_NAME),
            (string)$region->getData(Region::schema_fields_REGION_TYPE)
        );
        $node = [
            'region_id' => $region->getId(),
            'country_code' => $region->getData(Region::schema_fields_COUNTRY_CODE),
            'region_code' => $region->getData(Region::schema_fields_REGION_CODE),
            'region_name' => $regionName,
            'region_default_name' => $region->getData(Region::schema_fields_REGION_NAME),
            'region_locale' => $this->currentLocale(),
            'region_type' => $region->getData(Region::schema_fields_REGION_TYPE),
            'postal_code_pattern' => $region->getData(Region::schema_fields_POSTAL_CODE_PATTERN),
            'is_active' => $region->getData(Region::schema_fields_IS_ACTIVE),
            'sort_order' => $region->getData(Region::schema_fields_SORT_ORDER),
            'children' => [],
        ];
        
        $children = $region->getChildren($region->getId());
        foreach ($children->getItems() as $child) {
            $node['children'][] = $this->buildNode($child);
        }
        
        return $node;
    }

    /**
     * 根据国家代码获取地区树
     * 
     * @param string $countryCode ISO国家代码
     * @return array
     */
    public function getTreeByCountryCode(string $countryCode): array
    {
        return $this->buildTree($countryCode);
    }

    public function getCountries(): array
    {
        $countries = $this->getModel()->reset()
            ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY)
            ->where(Region::schema_fields_IS_ACTIVE, 1)
            ->order(Region::schema_fields_SORT_ORDER, 'ASC')
            ->order(Region::schema_fields_REGION_NAME, 'ASC')
            ->select()
            ->fetch();

        return $this->toRegionList($countries->getItems());
    }

    public function getChildrenList(?int $parentRegionId, ?string $countryCode = null): array
    {
        $model = $this->getModel()->reset();

        if ($parentRegionId !== null && $parentRegionId > 0) {
            $model->where(Region::schema_fields_PARENT_REGION_ID, $parentRegionId);
        } elseif ($countryCode) {
            $model->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_PROVINCE);
        } else {
            return $this->getCountries();
        }

        $regions = $model->where(Region::schema_fields_IS_ACTIVE, 1)
            ->order(Region::schema_fields_SORT_ORDER, 'ASC')
            ->order(Region::schema_fields_REGION_NAME, 'ASC')
            ->select()
            ->fetch();

        $regionList = $this->toRegionList($regions->getItems());
        if ($countryCode && empty($regionList)) {
            return $this->getFallbackChildren($parentRegionId, $countryCode);
        }

        return $regionList;
    }

    public function getAllActiveList(): array
    {
        $regions = $this->getModel()->reset()
            ->where(Region::schema_fields_IS_ACTIVE, 1)
            ->order(Region::schema_fields_SORT_ORDER, 'ASC')
            ->order(Region::schema_fields_REGION_NAME, 'ASC')
            ->select()
            ->fetch();

        $regionList = $this->toRegionList($regions->getItems());
        if (empty($regionList)) {
            $regionList = $this->getInstalledCountriesAsRegions();
        }

        return $this->mergeFallbackRegions($regionList);
    }

    private function toRegionList(array $regions): array
    {
        $result = [];
        foreach ($regions as $region) {
            if (!$region instanceof Region || !$region->getId()) {
                continue;
            }

            $countryCode = (string)$region->getData(Region::schema_fields_COUNTRY_CODE);
            $regionCode = (string)$region->getData(Region::schema_fields_REGION_CODE);
            $regionType = (string)$region->getData(Region::schema_fields_REGION_TYPE);
            $defaultName = (string)$region->getData(Region::schema_fields_REGION_NAME);
            $result[] = [
                'region_id' => (int)$region->getId(),
                'parent_region_id' => (int)$region->getData(Region::schema_fields_PARENT_REGION_ID),
                'country_code' => $countryCode,
                'region_code' => $regionCode,
                'region_name' => $this->localizedRegionName($countryCode, $regionCode, $defaultName, $regionType),
                'region_default_name' => $defaultName,
                'region_locale' => $this->currentLocale(),
                'region_type' => $regionType,
                'postal_code_pattern' => (string)$region->getData(Region::schema_fields_POSTAL_CODE_PATTERN),
            ];
        }

        return $result;
    }

    private function getInstalledCountriesAsRegions(): array
    {
        /** @var Countries $countryModel */
        $countryModel = $this->objectManager->getInstance(Countries::class);
        /** @var Name $nameModel */
        $nameModel = $this->objectManager->getInstance(Name::class);

        $countries = $countryModel->reset()
            ->where(Countries::schema_fields_IS_INSTALL, 1)
            ->where(Countries::schema_fields_IS_ACTIVE, 1)
            ->order(Countries::schema_fields_CODE, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $names = $nameModel->reset()
            ->where(Name::schema_fields_DISPLAY_LOCALE_CODE, Cookie::getLangLocal())
            ->select()
            ->fetch()
            ->getItems();

        $nameMap = [];
        foreach ($names as $name) {
            $nameMap[(string)$name->getData(Name::schema_fields_COUNTRY_CODE)] = (string)$name->getData(Name::schema_fields_DISPLAY_NAME);
        }

        $result = [];
        foreach ($countries as $country) {
            $code = (string)$country->getData(Countries::schema_fields_CODE);
            $countryName = $this->localizedCountryName($code, $nameMap[$code] ?? $code);
            $result[] = [
                'region_id' => 0,
                'parent_region_id' => 0,
                'country_code' => $code,
                'region_code' => $code,
                'region_name' => $countryName,
                'region_default_name' => $nameMap[$code] ?? $code,
                'region_locale' => $this->currentLocale(),
                'region_type' => Region::TYPE_COUNTRY,
                'postal_code_pattern' => '',
            ];
        }

        return $result;
    }

    private function mergeFallbackRegions(array $regions): array
    {
        $countryCodes = [];
        $provinceCodes = [];
        foreach ($regions as $region) {
            $countryCode = (string)($region['country_code'] ?? '');
            if ($countryCode === '') {
                continue;
            }
            if (($region['region_type'] ?? '') === Region::TYPE_COUNTRY) {
                $countryCodes[$countryCode] = true;
            }
            if (($region['region_type'] ?? '') === Region::TYPE_PROVINCE) {
                $provinceCodes[$countryCode] = true;
            }
        }

        foreach ($this->getFallbackRegions() as $countryCode => $fallbackRegions) {
            if (isset($countryCodes[$countryCode]) && !isset($provinceCodes[$countryCode])) {
                $regions = array_merge($regions, $fallbackRegions);
            }
        }

        return $regions;
    }

    private function getFallbackChildren(?int $parentRegionId, string $countryCode): array
    {
        $fallbackRegions = $this->getFallbackRegions()[$countryCode] ?? [];
        if ($parentRegionId !== null && $parentRegionId > 0) {
            return array_values(array_filter($fallbackRegions, static function (array $region) use ($parentRegionId): bool {
                return (int)($region['parent_region_id'] ?? 0) === $parentRegionId;
            }));
        }

        return array_values(array_filter($fallbackRegions, static function (array $region): bool {
            return ($region['region_type'] ?? '') === Region::TYPE_PROVINCE;
        }));
    }

    private function getFallbackRegions(): array
    {
        $usStates = [
            ['AL', 'Alabama', 'Montgomery'], ['AK', 'Alaska', 'Juneau'], ['AZ', 'Arizona', 'Phoenix'], ['AR', 'Arkansas', 'Little Rock'],
            ['CA', 'California', 'Sacramento'], ['CO', 'Colorado', 'Denver'], ['CT', 'Connecticut', 'Hartford'], ['DE', 'Delaware', 'Dover'],
            ['DC', 'District of Columbia', 'Washington'], ['FL', 'Florida', 'Tallahassee'], ['GA', 'Georgia', 'Atlanta'], ['HI', 'Hawaii', 'Honolulu'],
            ['ID', 'Idaho', 'Boise'], ['IL', 'Illinois', 'Springfield'], ['IN', 'Indiana', 'Indianapolis'], ['IA', 'Iowa', 'Des Moines'],
            ['KS', 'Kansas', 'Topeka'], ['KY', 'Kentucky', 'Frankfort'], ['LA', 'Louisiana', 'Baton Rouge'], ['ME', 'Maine', 'Augusta'],
            ['MD', 'Maryland', 'Annapolis'], ['MA', 'Massachusetts', 'Boston'], ['MI', 'Michigan', 'Lansing'], ['MN', 'Minnesota', 'Saint Paul'],
            ['MS', 'Mississippi', 'Jackson'], ['MO', 'Missouri', 'Jefferson City'], ['MT', 'Montana', 'Helena'], ['NE', 'Nebraska', 'Lincoln'],
            ['NV', 'Nevada', 'Carson City'], ['NH', 'New Hampshire', 'Concord'], ['NJ', 'New Jersey', 'Trenton'], ['NM', 'New Mexico', 'Santa Fe'],
            ['NY', 'New York', 'Albany'], ['NC', 'North Carolina', 'Raleigh'], ['ND', 'North Dakota', 'Bismarck'], ['OH', 'Ohio', 'Columbus'],
            ['OK', 'Oklahoma', 'Oklahoma City'], ['OR', 'Oregon', 'Salem'], ['PA', 'Pennsylvania', 'Harrisburg'], ['RI', 'Rhode Island', 'Providence'],
            ['SC', 'South Carolina', 'Columbia'], ['SD', 'South Dakota', 'Pierre'], ['TN', 'Tennessee', 'Nashville'], ['TX', 'Texas', 'Austin'],
            ['UT', 'Utah', 'Salt Lake City'], ['VT', 'Vermont', 'Montpelier'], ['VA', 'Virginia', 'Richmond'], ['WA', 'Washington', 'Olympia'],
            ['WV', 'West Virginia', 'Charleston'], ['WI', 'Wisconsin', 'Madison'], ['WY', 'Wyoming', 'Cheyenne'],
        ];

        $regions = [];
        foreach ($usStates as $index => [$code, $name, $capital]) {
            $provinceId = 840000 + $index + 1;
            $provinceName = $this->localizedRegionName('US', $code, $name, Region::TYPE_PROVINCE);
            $cityName = $this->localizedRegionName('US', $code . '-' . strtoupper(str_replace(' ', '-', $capital)), $capital, Region::TYPE_CITY);
            $regions[] = [
                'region_id' => $provinceId,
                'parent_region_id' => 0,
                'country_code' => 'US',
                'region_code' => $code,
                'region_name' => $provinceName,
                'region_default_name' => $name,
                'region_locale' => $this->currentLocale(),
                'region_type' => Region::TYPE_PROVINCE,
                'postal_code_pattern' => '',
            ];
            $regions[] = [
                'region_id' => 841000 + $index + 1,
                'parent_region_id' => $provinceId,
                'country_code' => 'US',
                'region_code' => $code . '-' . strtoupper(str_replace(' ', '-', $capital)),
                'region_name' => $cityName,
                'region_default_name' => $capital,
                'region_locale' => $this->currentLocale(),
                'region_type' => Region::TYPE_CITY,
                'postal_code_pattern' => '',
            ];
        }

        return ['US' => $regions];
    }

    private function currentLocale(): string
    {
        return Cookie::getLangLocal() ?: 'zh_Hans_CN';
    }

    private function intlLocale(): string
    {
        $locale = $this->currentLocale();
        if ($locale === 'zh_Hans_CN') {
            return 'zh_Hans';
        }
        if ($locale === 'zh_Hant_TW') {
            return 'zh_Hant';
        }

        return $locale;
    }

    private function localizedCountryName(string $countryCode, string $defaultName): string
    {
        if ($countryCode === '') {
            return $defaultName;
        }

        try {
            return IntlCountries::getName(strtoupper($countryCode), $this->intlLocale());
        } catch (\Throwable) {
            return $defaultName;
        }
    }

    private function localizedRegionName(string $countryCode, string $regionCode, string $defaultName, string $regionType): string
    {
        if ($regionType === Region::TYPE_COUNTRY) {
            return $this->localizedCountryName($countryCode, $defaultName);
        }

        $localizedName = $this->localizedSubdivisionName($countryCode, $regionCode);
        if ($localizedName !== '') {
            return $localizedName;
        }

        return $defaultName;
    }

    private function localizedSubdivisionName(string $countryCode, string $regionCode): string
    {
        $countryCode = strtoupper($countryCode);
        $regionCode = strtoupper($regionCode);
        $locale = $this->currentLocale();
        if ($countryCode === '' || $regionCode === '') {
            return '';
        }

        $map = $this->subdivisionTranslations($locale);
        return $map[$countryCode][$regionCode] ?? '';
    }

    private function subdivisionTranslations(string $locale): array
    {
        if (!str_starts_with($locale, 'zh')) {
            return [];
        }

        return [
            'US' => [
                'AL' => '亚拉巴马州',
                'AK' => '阿拉斯加州',
                'AZ' => '亚利桑那州',
                'AR' => '阿肯色州',
                'CA' => '加利福尼亚州',
                'CO' => '科罗拉多州',
                'CT' => '康涅狄格州',
                'DE' => '特拉华州',
                'DC' => '哥伦比亚特区',
                'FL' => '佛罗里达州',
                'GA' => '佐治亚州',
                'HI' => '夏威夷州',
                'ID' => '爱达荷州',
                'IL' => '伊利诺伊州',
                'IN' => '印第安纳州',
                'IA' => '艾奥瓦州',
                'KS' => '堪萨斯州',
                'KY' => '肯塔基州',
                'LA' => '路易斯安那州',
                'ME' => '缅因州',
                'MD' => '马里兰州',
                'MA' => '马萨诸塞州',
                'MI' => '密歇根州',
                'MN' => '明尼苏达州',
                'MS' => '密西西比州',
                'MO' => '密苏里州',
                'MT' => '蒙大拿州',
                'NE' => '内布拉斯加州',
                'NV' => '内华达州',
                'NH' => '新罕布什尔州',
                'NJ' => '新泽西州',
                'NM' => '新墨西哥州',
                'NY' => '纽约州',
                'NC' => '北卡罗来纳州',
                'ND' => '北达科他州',
                'OH' => '俄亥俄州',
                'OK' => '俄克拉荷马州',
                'OR' => '俄勒冈州',
                'PA' => '宾夕法尼亚州',
                'RI' => '罗德岛州',
                'SC' => '南卡罗来纳州',
                'SD' => '南达科他州',
                'TN' => '田纳西州',
                'TX' => '得克萨斯州',
                'UT' => '犹他州',
                'VT' => '佛蒙特州',
                'VA' => '弗吉尼亚州',
                'WA' => '华盛顿州',
                'WV' => '西弗吉尼亚州',
                'WI' => '威斯康星州',
                'WY' => '怀俄明州',
            ],
        ];
    }

    /**
     * 从i18n同步国家数据
     * 
     * @param array $countries 国家数据数组
     * @return int 同步的数量
     */
    public function syncFromI18n(array $countries): int
    {
        $count = 0;
        $model = $this->getModel();
        
        foreach ($countries as $country) {
            $countryCode = $country['code'] ?? null;
            $countryName = $country['name'] ?? '';
            
            if (!$countryCode) {
                continue;
            }
            
            // 检查是否已存在
            $existing = $model->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY)
                ->find()
                ->fetch();
            
            if (!$existing->getId()) {
                $model->reset()
                    ->setData([
                        Region::schema_fields_COUNTRY_CODE => $countryCode,
                        Region::schema_fields_PARENT_REGION_ID => null,
                        Region::schema_fields_REGION_CODE => $countryCode,
                        Region::schema_fields_REGION_NAME => $countryName,
                        Region::schema_fields_REGION_TYPE => Region::TYPE_COUNTRY,
                        Region::schema_fields_IS_ACTIVE => 1,
                        Region::schema_fields_SORT_ORDER => 0,
                    ])
                    ->save();
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * 验证地区是否存在
     * 
     * @param int $regionId 地区ID
     * @return bool
     */
    public function validateRegion(int $regionId): bool
    {
        $region = $this->getModel()->load($regionId);
        return $region->getId() > 0;
    }

    /**
     * 根据地区ID获取完整路径
     * 
     * @param int $regionId 地区ID
     * @return string
     */
    public function getFullPath(int $regionId): string
    {
        $region = $this->getModel()->load($regionId);
        if (!$region->getId()) {
            return '';
        }
        return $region->getFullPath();
    }

    /**
     * 根据位置信息查找地区
     * 
     * @param string $countryCode 国家代码
     * @param string|null $province 省/州
     * @param string|null $city 市
     * @param string|null $district 区县
     * @return Region|null
     */
    public function findByLocation(
        string $countryCode,
        ?string $province = null,
        ?string $city = null,
        ?string $district = null
    ): ?Region {
        $model = $this->getModel();
        
        // 优先匹配区县
        if ($district) {
            $region = $model->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_DISTRICT)
                ->where(Region::schema_fields_REGION_NAME, $district)
                ->where(Region::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($region->getId()) {
                return $region;
            }
        }
        
        // 其次匹配市
        if ($city) {
            $region = $model->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_CITY)
                ->where(Region::schema_fields_REGION_NAME, $city)
                ->where(Region::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($region->getId()) {
                return $region;
            }
        }
        
        // 再次匹配省/州
        if ($province) {
            $region = $model->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_PROVINCE)
                ->where(Region::schema_fields_REGION_NAME, $province)
                ->where(Region::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if ($region->getId()) {
                return $region;
            }
        }
        
        // 最后匹配国家
        $region = $model->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY)
            ->where(Region::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        
        return $region->getId() ? $region : null;
    }
}

