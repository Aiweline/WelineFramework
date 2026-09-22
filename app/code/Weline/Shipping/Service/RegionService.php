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
use Weline\Shipping\Model\Region;
use Symfony\Component\Intl\Countries as IntlCountries;
use Weline\Shipping\Service\EmbargoService;

/**
 * 地区服务
 * 
 * @package Weline_Shipping
 */
class RegionService
{
    private ObjectManager $objectManager;
    private RegionCascadeEnsureService $cascadeEnsure;
    private AddressCountryProfileService $countryProfiles;
    private RegionLocalNameResolver $localNames;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->cascadeEnsure = $objectManager->getInstance(RegionCascadeEnsureService::class);
        $this->countryProfiles = $objectManager->getInstance(AddressCountryProfileService::class);
        $this->localNames = $objectManager->getInstance(RegionLocalNameResolver::class);
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
            (string)$region->getData(Region::schema_fields_REGION_TYPE),
            (int)$region->getId(),
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
            'postal_code' => (string)$region->getData(Region::schema_fields_POSTAL_CODE),
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

    /**
     * 后台地区列表用浅层树：仅省份（无递归子级），避免 CN 等大国整树 SSR 撑破 WLS 输出上限。
     *
     * @return list<array<string, mixed>>
     */
    public function getAdminListTreeByCountryCode(string $countryCode, int $limit = 500): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
            return [];
        }

        $nodes = [];
        foreach ($this->getChildrenList(null, $countryCode, $limit) as $row) {
            $nodes[] = [
                'region_id' => (int)($row['region_id'] ?? 0),
                'parent_region_id' => (int)($row['parent_region_id'] ?? 0),
                'country_code' => (string)($row['country_code'] ?? $countryCode),
                'region_code' => (string)($row['region_code'] ?? ''),
                'region_name' => (string)($row['region_name'] ?? ''),
                'region_default_name' => (string)($row['region_default_name'] ?? ''),
                'region_locale' => (string)($row['region_locale'] ?? ''),
                'region_type' => (string)($row['region_type'] ?? Region::TYPE_PROVINCE),
                'postal_code_pattern' => (string)($row['postal_code_pattern'] ?? ''),
                'postal_code' => (string)($row['postal_code'] ?? ''),
                'is_active' => 1,
                'sort_order' => (int)($row['sort_order'] ?? 0),
                'children' => [],
            ];
        }

        return $nodes;
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

    public function getChildrenList(?int $parentRegionId, ?string $countryCode = null, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        if ($countryCode) {
            $this->cascadeEnsure->ensureCountry($countryCode);
        }

        $model = $this->getModel()->reset();

        if ($parentRegionId !== null && $parentRegionId > 0) {
            $model->where(Region::schema_fields_PARENT_REGION_ID, $parentRegionId);
        } elseif ($countryCode) {
            $model->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_PROVINCE);
        } else {
            // S16: refuse unbounded dump; country list uses list/catalog=global
            return [];
        }

        $regions = $model->where(Region::schema_fields_IS_ACTIVE, 1)
            ->order(Region::schema_fields_SORT_ORDER, 'ASC')
            ->order(Region::schema_fields_REGION_NAME, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch();

        $regionList = $this->toRegionList($regions->getItems());
        if ($countryCode && empty($regionList)) {
            return $this->getFallbackChildren($parentRegionId, $countryCode);
        }

        return $regionList;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function postalLookup(string $countryCode, string $postalCode, int $limit = 20): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $norms = \Weline\Shipping\Service\AddressCatalog\TsvGzReader::postalLookupNorms($postalCode);
        $norm = $norms[0] ?? '';
        if ($countryCode === '' || !preg_match('/^[A-Z]{2}$/', $countryCode) || $norm === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        /** @var \Weline\Shipping\Model\PostalPlace $model */
        $model = $this->objectManager->getInstance(\Weline\Shipping\Model\PostalPlace::class);
        $rows = $model->reset()
            ->where(\Weline\Shipping\Model\PostalPlace::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(\Weline\Shipping\Model\PostalPlace::schema_fields_POSTAL_CODE_NORM, $norms, 'in')
            ->limit(max($limit * 3, 50))
            ->select()
            ->fetch()
            ->getItems();
        $normRank = array_flip($norms);
        usort($rows, static function ($a, $b) use ($normRank): int {
            $an = (string)$a->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_POSTAL_CODE_NORM);
            $bn = (string)$b->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_POSTAL_CODE_NORM);
            $ar = $normRank[$an] ?? 99;
            $br = $normRank[$bn] ?? 99;

            return $ar <=> $br;
        });
        $out = [];
        foreach ($rows as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $attachId = (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_ATTACH_REGION_ID);
            $out[] = [
                'postal_place_id' => (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_ID),
                'place_name' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PLACE_NAME),
                'postal_code' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_POSTAL_CODE),
                'postal_code_norm' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_POSTAL_CODE_NORM),
                'attach_level' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_ATTACH_LEVEL),
                'province_code' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PROVINCE_CODE),
                'city_code' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_CITY_CODE),
                'district_code' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_DISTRICT_CODE),
                'province_region_id' => (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PROVINCE_REGION_ID) ?: null,
                'city_region_id' => (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_CITY_REGION_ID) ?: null,
                'district_region_id' => (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_DISTRICT_REGION_ID) ?: null,
                'attach_region_id' => $attachId > 0 ? $attachId : null,
                'needs_cascade_confirm' => $attachId <= 0,
            ];
        }

        return $this->enrichPostalLookupNames($out);
    }

    /**
     * Distinct countries that have catalog rows for a postal code (cross-country disambiguation).
     *
     * @return list<array{country_code: string, country_name: string, place_name: string, supported: bool, embargoed: bool}>
     */
    public function postalCountries(string $postalCode): array
    {
        $norms = \Weline\Shipping\Service\AddressCatalog\TsvGzReader::postalLookupNorms($postalCode);
        $norm = $norms[0] ?? '';
        if ($norm === '') {
            return [];
        }
        /** @var \Weline\Shipping\Model\PostalPlace $model */
        $model = $this->objectManager->getInstance(\Weline\Shipping\Model\PostalPlace::class);
        $rows = $model->reset()
            ->where(\Weline\Shipping\Model\PostalPlace::schema_fields_POSTAL_CODE_NORM, $norms, 'in')
            ->limit(200)
            ->select()
            ->fetch()
            ->getItems();
        $normRank = array_flip($norms);
        $supported = $this->websiteSupportedCountryCodes();
        $countrySortRanks = $this->countrySortRanks();
        /** @var EmbargoService $embargo */
        $embargo = $this->objectManager->getInstance(EmbargoService::class);
        $seen = [];
        foreach ($rows as $row) {
            $cc = strtoupper(trim((string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_COUNTRY_CODE)));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $rowNorm = (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_POSTAL_CODE_NORM);
            $matchRank = (int)($normRank[$rowNorm] ?? 99);
            $cascadeRank = $this->postalPlaceCascadeRank($row);
            if (isset($seen[$cc])) {
                $prevMatch = (int)($seen[$cc]['_match_rank'] ?? 99);
                $prevCascade = (int)($seen[$cc]['_cascade_rank'] ?? 99);
                if ($matchRank > $prevMatch) {
                    continue;
                }
                if ($matchRank === $prevMatch && $cascadeRank >= $prevCascade) {
                    continue;
                }
            }
            $placeName = trim((string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PLACE_NAME));
            $eval = $embargo->evaluateAddress([
                'country_code' => $cc,
                'province_code' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PROVINCE_CODE),
                'province_region_id' => (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PROVINCE_REGION_ID),
                'city_code' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_CITY_CODE),
                'city_region_id' => (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_CITY_REGION_ID),
                'district_code' => (string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_DISTRICT_CODE),
                'district_region_id' => (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_DISTRICT_REGION_ID),
            ]);
            $isEmbargoed = !empty($eval['blocked']);
            $seen[$cc] = [
                'country_code' => $cc,
                'country_name' => $this->localizedCountryName($cc, $cc),
                'place_name' => $placeName !== ''
                    ? $placeName
                    : (string)($seen[$cc]['place_name'] ?? ''),
                'supported' => isset($supported[$cc]),
                'embargoed' => $isEmbargoed,
                'sort_order' => (int)($countrySortRanks[$cc] ?? 9000),
                '_match_rank' => $matchRank,
                '_cascade_rank' => $cascadeRank,
            ];
        }
        $out = array_values($seen);
        usort($out, static function (array $a, array $b): int {
            // 1) deliverable; 2) Region.sort_order（热门位次，越小越前）; 3) cascade; 4) alpha
            $aOk = !empty($a['supported']) && empty($a['embargoed']);
            $bOk = !empty($b['supported']) && empty($b['embargoed']);
            if ($aOk !== $bOk) {
                return $aOk ? -1 : 1;
            }
            $aSort = (int)($a['sort_order'] ?? 9000);
            $bSort = (int)($b['sort_order'] ?? 9000);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }
            $aCascade = (int)($a['_cascade_rank'] ?? 99);
            $bCascade = (int)($b['_cascade_rank'] ?? 99);
            if ($aCascade !== $bCascade) {
                return $aCascade <=> $bCascade;
            }

            return strcmp($a['country_code'], $b['country_code']);
        });
        foreach ($out as &$row) {
            unset($row['_cascade_rank'], $row['_match_rank']);
        }
        unset($row);

        return $out;
    }

    /**
     * Lower is better: city/district with codes = 0, province = 1, bare = 2.
     */
    private function postalPlaceCascadeRank(object $row): int
    {
        $level = strtolower(trim((string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_ATTACH_LEVEL)));
        $cityCode = trim((string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_CITY_CODE));
        $cityId = (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_CITY_REGION_ID);
        $districtCode = trim((string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_DISTRICT_CODE));
        $districtId = (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_DISTRICT_REGION_ID);
        if (
            $level === 'city'
            || $level === 'district'
            || $cityCode !== ''
            || $cityId > 0
            || $districtCode !== ''
            || $districtId > 0
        ) {
            return 0;
        }
        $provinceCode = trim((string)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PROVINCE_CODE));
        $provinceId = (int)$row->getData(\Weline\Shipping\Model\PostalPlace::schema_fields_PROVINCE_REGION_ID);
        if ($level === 'province' || $provinceCode !== '' || $provinceId > 0) {
            return 1;
        }

        return 2;
    }

    /**
     * Region TYPE_COUNTRY.sort_order — admin-tunable hot position (lower = earlier).
     *
     * @return array<string, int>
     */
    public function countrySortRanks(): array
    {
        $ranks = [];
        foreach ($this->getCountries() as $region) {
            $cc = strtoupper(trim((string)($region['country_code'] ?? $region['region_code'] ?? '')));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $ranks[$cc] = max(0, (int)($region['sort_order'] ?? 0));
        }

        return $ranks;
    }

    /**
     * Seed country sort_order from default-markets/countries.tsv (explicit sort_order or index×10).
     * Non-market countries get 9000+ when still at 0. Preserves non-zero admin edits when $onlyWhenZero.
     *
     * @return array{updated:int,skipped:int}
     */
    public function seedCountrySortFromDefaultMarkets(bool $onlyWhenZero = true): array
    {
        $marketSort = $this->defaultMarketCountrySortOrders();
        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $items = $model->reset()
            ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY)
            ->select()
            ->fetch()
            ->getItems();
        $updated = 0;
        $skipped = 0;
        $nonMarketBase = 9000;
        foreach ($items as $item) {
            $cc = strtoupper(trim((string)$item->getData(Region::schema_fields_COUNTRY_CODE)));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $current = (int)$item->getData(Region::schema_fields_SORT_ORDER);
            if ($onlyWhenZero && $current !== 0) {
                $skipped++;
                continue;
            }
            if (array_key_exists($cc, $marketSort)) {
                $next = max(0, (int)$marketSort[$cc]);
            } else {
                // Stable non-market band so hot markets stay ahead.
                $next = $nonMarketBase + (ord($cc[0]) * 100 + ord($cc[1]));
            }
            if ($current === $next) {
                $skipped++;
                continue;
            }
            $item->setData(Region::schema_fields_SORT_ORDER, $next)->save();
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @return list<array{country_code:string,country_name:string,sort_order:int,in_default_markets:bool}>
     */
    public function listCountrySortEditorRows(): array
    {
        $marketRanks = $this->defaultMarketCountrySortOrders();
        $out = [];
        foreach ($this->getCountries() as $region) {
            $cc = strtoupper(trim((string)($region['country_code'] ?? $region['region_code'] ?? '')));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $inMarket = array_key_exists($cc, $marketRanks);
            $sort = max(0, (int)($region['sort_order'] ?? 0));
            // Editor focuses on default markets + any already customized (<9000).
            if (!$inMarket && $sort >= 9000) {
                continue;
            }
            $out[] = [
                'country_code' => $cc,
                'country_name' => (string)($region['region_name'] ?? $cc),
                'sort_order' => $sort,
                'in_default_markets' => $inMarket,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['sort_order'] !== $b['sort_order']) {
                return $a['sort_order'] <=> $b['sort_order'];
            }

            return strcmp($a['country_code'], $b['country_code']);
        });

        return $out;
    }

    /**
     * @return array<string, int> ISO country_code => sort_order from default-markets/countries.tsv
     */
    private function defaultMarketCountrySortOrders(): array
    {
        static $ranks = null;
        if (is_array($ranks)) {
            return $ranks;
        }
        $ranks = [];
        $path = BP . 'app/code/Weline/Shipping/data/default-markets/countries.tsv';
        if (!is_file($path)) {
            return $ranks;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return $ranks;
        }
        $idx = 0;
        $header = null;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split("/\t+/", $line) ?: [];
            if ($header === null && str_starts_with($line, 'country_code')) {
                $header = array_map('strtolower', $parts);
                continue;
            }
            $cc = strtoupper(trim((string)($parts[0] ?? '')));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc) || isset($ranks[$cc])) {
                continue;
            }
            $sortCol = null;
            if (is_array($header)) {
                $sortIdx = array_search('sort_order', $header, true);
                if ($sortIdx !== false && isset($parts[$sortIdx]) && trim((string)$parts[$sortIdx]) !== '') {
                    $sortCol = max(0, (int)$parts[$sortIdx]);
                }
            } elseif (isset($parts[2]) && trim((string)$parts[2]) !== '' && ctype_digit(trim((string)$parts[2]))) {
                $sortCol = max(0, (int)$parts[2]);
            }
            $ranks[$cc] = $sortCol !== null ? $sortCol : ($idx * 10);
            $idx++;
        }
        fclose($fh);

        return $ranks;
    }

    /**
     * 配送模块已入库的启用国家（address-catalog / Region TYPE_COUNTRY）。
     *
     * @return array<string, true>
     */
    private function websiteSupportedCountryCodes(): array
    {
        $map = [];
        foreach ($this->getCountries() as $region) {
            $code = strtoupper(trim((string)($region['country_code'] ?? $region['region_code'] ?? '')));
            if ($code !== '' && preg_match('/^[A-Z]{2}$/', $code)) {
                $map[$code] = true;
            }
        }

        return $map;
    }

    public function hasStreets(int $parentRegionId): bool
    {
        if ($parentRegionId <= 0) {
            return false;
        }
        /** @var \Weline\Shipping\Model\Street $model */
        $model = $this->objectManager->getInstance(\Weline\Shipping\Model\Street::class);
        $row = $model->reset()
            ->where(\Weline\Shipping\Model\Street::schema_fields_PARENT_REGION_ID, $parentRegionId)
            ->where(\Weline\Shipping\Model\Street::schema_fields_IS_ACTIVE, 1)
            ->limit(1)
            ->find()
            ->fetch();

        return (int)$row->getId() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function streetsByParent(int $parentRegionId, int $limit = 200): array
    {
        if ($parentRegionId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        /** @var \Weline\Shipping\Model\Street $model */
        $model = $this->objectManager->getInstance(\Weline\Shipping\Model\Street::class);
        $rows = $model->reset()
            ->where(\Weline\Shipping\Model\Street::schema_fields_PARENT_REGION_ID, $parentRegionId)
            ->where(\Weline\Shipping\Model\Street::schema_fields_IS_ACTIVE, 1)
            ->order(\Weline\Shipping\Model\Street::schema_fields_SORT_ORDER, 'ASC')
            ->order(\Weline\Shipping\Model\Street::schema_fields_STREET_NAME, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'street_id' => (int)$row->getId(),
                'street_code' => (string)$row->getData(\Weline\Shipping\Model\Street::schema_fields_STREET_CODE),
                'street_name' => (string)$row->getData(\Weline\Shipping\Model\Street::schema_fields_STREET_NAME),
                'postal_code' => (string)$row->getData(\Weline\Shipping\Model\Street::schema_fields_POSTAL_CODE),
                'parent_region_id' => (int)$row->getData(\Weline\Shipping\Model\Street::schema_fields_PARENT_REGION_ID),
                'country_code' => (string)$row->getData(\Weline\Shipping\Model\Street::schema_fields_COUNTRY_CODE),
            ];
        }

        return $out;
    }

    public function getAllActiveList(?string $ensureCountryCode = null, string $countryCatalog = 'installed'): array
    {
        $countryCatalog = in_array($countryCatalog, ['installed', 'global'], true) ? $countryCatalog : 'installed';
        $ensureCountryCode = $ensureCountryCode !== null ? strtoupper(trim($ensureCountryCode)) : null;
        if ($ensureCountryCode === '') {
            $ensureCountryCode = null;
        }
        if ($ensureCountryCode) {
            $this->cascadeEnsure->ensureCountry($ensureCountryCode);
        }

        // S16 / catalog：禁止无界 dump。无 country → 仅国家节点；有 country → 该国 + 省（市/区走 children）。
        $model = $this->getModel()->reset()->where(Region::schema_fields_IS_ACTIVE, 1);
        if ($ensureCountryCode) {
            $model->where(Region::schema_fields_COUNTRY_CODE, $ensureCountryCode)
                ->where(Region::schema_fields_REGION_TYPE, [Region::TYPE_COUNTRY, Region::TYPE_PROVINCE], 'IN');
        } else {
            $model->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY);
        }
        $regions = $model
            ->order(Region::schema_fields_SORT_ORDER, 'ASC')
            ->order(Region::schema_fields_REGION_NAME, 'ASC')
            ->limit($ensureCountryCode ? 500 : 500)
            ->select()
            ->fetch();

        $regionList = $this->toRegionList($regions->getItems());
        if ($countryCatalog === 'global') {
            if ($regionList === [] || !$ensureCountryCode) {
                // 全球国家目录：Intl +（如有）库内国家行
                return $this->mergeFallbackRegions($this->applyGlobalCountryCatalog(
                    array_values(array_filter(
                        $regionList,
                        static fn(array $region): bool => ($region['region_type'] ?? '') === Region::TYPE_COUNTRY
                    ))
                ));
            }

            return $this->mergeFallbackRegions($regionList);
        }

        if ($regionList === []) {
            $regionList = $ensureCountryCode
                ? $this->getFallbackChildren(null, $ensureCountryCode)
                : $this->getInstalledCountriesAsRegions();
        }

        return $this->mergeFallbackRegions($regionList);
    }

    /** @return list<array<string, mixed>> */
    private function applyGlobalCountryCatalog(array $regions): array
    {
        $dbCountries = [];
        $subdivisions = [];
        foreach ($regions as $region) {
            if (($region['region_type'] ?? '') === Region::TYPE_COUNTRY) {
                $cc = strtoupper(trim((string)($region['country_code'] ?? $region['region_code'] ?? '')));
                if ($cc !== '' && preg_match('/^[A-Z]{2}$/', $cc)) {
                    $dbCountries[$cc] = $region;
                }
                continue;
            }
            $subdivisions[] = $region;
        }

        $marketSort = $this->defaultMarketCountrySortOrders();
        $merged = [];
        $seen = [];
        foreach ($this->getGlobalCountriesAsRegions() as $row) {
            $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            if (isset($dbCountries[$cc])) {
                $db = $dbCountries[$cc];
                $row['region_id'] = (int)($db['region_id'] ?? 0);
                $dbSort = max(0, (int)($db['sort_order'] ?? 0));
                if ($dbSort > 0) {
                    $row['sort_order'] = $dbSort;
                } elseif (array_key_exists($cc, $marketSort)) {
                    $row['sort_order'] = max(0, (int)$marketSort[$cc]);
                } else {
                    $row['sort_order'] = 9000 + (ord($cc[0]) * 100 + ord($cc[1]));
                }
                $dbName = trim((string)($db['region_name'] ?? ''));
                if ($dbName !== '') {
                    $row['region_name'] = $dbName;
                }
                $row['is_active'] = (int)($db['is_active'] ?? 1);
                unset($dbCountries[$cc]);
            } else {
                $row['sort_order'] = array_key_exists($cc, $marketSort)
                    ? max(0, (int)$marketSort[$cc])
                    : (9000 + (ord($cc[0]) * 100 + ord($cc[1])));
            }
            $merged[] = $row;
            $seen[$cc] = true;
        }
        foreach ($dbCountries as $cc => $db) {
            if (isset($seen[$cc])) {
                continue;
            }
            if (!isset($db['sort_order'])) {
                $db['sort_order'] = array_key_exists($cc, $marketSort)
                    ? max(0, (int)$marketSort[$cc])
                    : 9000;
            }
            $merged[] = $db;
        }
        usort($merged, static function (array $a, array $b): int {
            $sa = (int)($a['sort_order'] ?? 9000);
            $sb = (int)($b['sort_order'] ?? 9000);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return strcmp((string)($a['region_name'] ?? ''), (string)($b['region_name'] ?? ''));
        });

        return array_merge($merged, $subdivisions);
    }

    /** @return list<array<string, mixed>> */
    private function getGlobalCountriesAsRegions(): array
    {
        try {
            $names = IntlCountries::getNames($this->intlLocale());
        } catch (\Throwable) {
            $names = IntlCountries::getNames('en');
        }

        $result = [];
        foreach ($names as $code => $name) {
            $code = strtoupper((string)$code);
            $displayName = (string)$name;
            $result[] = [
                'region_id' => 0,
                'parent_region_id' => 0,
                'country_code' => $code,
                'region_code' => $code,
                'region_name' => $displayName,
                'region_default_name' => $displayName,
                'region_locale' => $this->currentLocale(),
                'region_type' => Region::TYPE_COUNTRY,
                'postal_code_pattern' => '',
                'postal_code' => '',
                'is_active' => 1,
                'sort_order' => 9000,
            ];
        }

        return $result;
    }

    /**
     * 用到某国家时按需把数据包写入 w_shipping_regions（幂等）。
     *
     * @return array{imported:int,skipped:bool,reason:string,country_code:string}
     */
    public function ensureCountryCascade(string $countryCode): array
    {
        return $this->cascadeEnsure->ensureCountry($countryCode);
    }

    /**
     * 本地地址自动完成：在本地区划表中按关键词检索，返回可回填路径。
     *
     * @return list<array{id:string,label:string,matched_level:string,formatted_address:array<string,mixed>}>
     */
    public function suggest(string $query, ?string $countryCode = null, int $limit = 8): array
    {
        $query = trim($query);
        $limit = max(1, min(20, $limit));
        if ($query === '' || mb_strlen($query) < 1) {
            return [];
        }

        if ($countryCode) {
            $this->cascadeEnsure->ensureCountry($countryCode);
        }

        $model = $this->getModel()->reset()
            ->where(Region::schema_fields_IS_ACTIVE, 1)
            ->where(Region::schema_fields_REGION_NAME, '%' . $query . '%', 'LIKE');

        if ($countryCode) {
            $model->where(Region::schema_fields_COUNTRY_CODE, strtoupper(trim($countryCode)));
        }

        $model->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY, '!=')
            ->order(Region::schema_fields_SORT_ORDER, 'ASC')
            ->order(Region::schema_fields_REGION_NAME, 'ASC')
            ->limit($limit * 4);

        $items = $model->select()->fetch()->getItems();
        $rank = [
            Region::TYPE_DISTRICT => 0,
            Region::TYPE_CITY => 1,
            Region::TYPE_PROVINCE => 2,
        ];
        usort($items, static function ($a, $b) use ($rank, $query): int {
            $aType = $a instanceof Region ? (string)$a->getData(Region::schema_fields_REGION_TYPE) : '';
            $bType = $b instanceof Region ? (string)$b->getData(Region::schema_fields_REGION_TYPE) : '';
            $typeCmp = ($rank[$aType] ?? 9) <=> ($rank[$bType] ?? 9);
            if ($typeCmp !== 0) {
                return $typeCmp;
            }
            $aName = $a instanceof Region ? (string)$a->getData(Region::schema_fields_REGION_NAME) : '';
            $bName = $b instanceof Region ? (string)$b->getData(Region::schema_fields_REGION_NAME) : '';
            $aExact = mb_stripos($aName, $query) === 0 ? 0 : 1;
            $bExact = mb_stripos($bName, $query) === 0 ? 0 : 1;
            if ($aExact !== $bExact) {
                return $aExact <=> $bExact;
            }

            return strcmp($aName, $bName);
        });

        $suggestions = [];
        foreach ($items as $region) {
            if (!$region instanceof Region || !(int)$region->getId()) {
                continue;
            }
            $formatted = $this->formatSuggestion((int)$region->getId());
            if ($formatted === null) {
                continue;
            }
            $suggestions[] = [
                'id' => (string)(int)$region->getId(),
                'label' => (string)$formatted['label'],
                'matched_level' => (string)$region->getData(Region::schema_fields_REGION_TYPE),
                'formatted_address' => $formatted['formatted_address'],
            ];
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @return array{label:string,formatted_address:array<string,mixed>}|null
     */
    public function formatSuggestion(int $regionId): ?array
    {
        if ($regionId <= 0) {
            return null;
        }

        $region = $this->getModel()->reset()->load($regionId);
        if (!(int)$region->getId() || !(int)$region->getData(Region::schema_fields_IS_ACTIVE)) {
            return null;
        }

        $chain = [];
        $currentId = $regionId;
        $guard = 0;
        while ($currentId > 0 && $guard++ < 8) {
            $node = $this->objectManager->make(Region::class);
            $node->load($currentId);
            if (!(int)$node->getId()) {
                break;
            }
            $chain[] = [
                'type' => (string)$node->getData(Region::schema_fields_REGION_TYPE),
                'name' => (string)$node->getData(Region::schema_fields_REGION_NAME),
                'code' => (string)$node->getData(Region::schema_fields_REGION_CODE),
                'id' => (int)$node->getId(),
                'country_code' => (string)$node->getData(Region::schema_fields_COUNTRY_CODE),
                'postal_code' => trim((string)$node->getData(Region::schema_fields_POSTAL_CODE)),
                'parent_id' => (int)$node->getData(Region::schema_fields_PARENT_REGION_ID),
            ];
            $currentId = (int)$node->getData(Region::schema_fields_PARENT_REGION_ID);
        }
        $chain = array_reverse($chain);

        $countryCode = '';
        $province = $city = $district = '';
        $provinceCode = $cityCode = $districtCode = '';
        $provinceId = $cityId = $districtId = 0;
        $postalCode = '';
        $labels = [];

        foreach ($chain as $item) {
            $type = $item['type'];
            $name = $item['name'];
            $code = $item['code'];
            $id = $item['id'];
            if ($item['postal_code'] !== '') {
                $postalCode = $item['postal_code'];
            }
            if ($type === Region::TYPE_COUNTRY) {
                $countryCode = $item['country_code'] !== '' ? $item['country_code'] : $code;
                $labels[] = $name;
                continue;
            }
            $countryCode = $countryCode !== '' ? $countryCode : $item['country_code'];
            $labels[] = $name;
            if ($type === Region::TYPE_PROVINCE) {
                $province = $name;
                $provinceCode = $code;
                $provinceId = $id;
            } elseif ($type === Region::TYPE_CITY) {
                $city = $name;
                $cityCode = $code;
                $cityId = $id;
            } elseif ($type === Region::TYPE_DISTRICT) {
                $district = $name;
                $districtCode = $code;
                $districtId = $id;
            }
        }

        if ($countryCode === '') {
            return null;
        }

        return [
            'label' => implode(' / ', $labels),
            'formatted_address' => [
                'country_code' => $countryCode,
                'province' => $province,
                'province_code' => $provinceCode,
                'province_region_id' => $provinceId,
                'city' => $city,
                'city_code' => $cityCode,
                'city_region_id' => $cityId,
                'district' => $district,
                'district_code' => $districtCode,
                'district_region_id' => $districtId,
                'postal_code' => $postalCode,
            ],
        ];
    }

    /**
     * @return array{levels:list<string>,autocomplete:bool,country_code:string}|array{default:array{levels:list<string>,autocomplete:bool},countries:array<string,array{levels:list<string>,autocomplete:bool}>}
     */
    public function getAddressCountryProfile(?string $countryCode = null): array
    {
        if ($countryCode === null || trim($countryCode) === '') {
            return $this->countryProfiles->allProfiles();
        }

        return $this->countryProfiles->profileFor($countryCode);
    }

    private function toRegionList(array $regions): array
    {
        $regionIds = [];
        foreach ($regions as $region) {
            if ($region instanceof Region && $region->getId()) {
                $regionIds[] = (int)$region->getId();
            }
        }
        // Localized names are requested with a fixed number of IN queries. Calling
        // nameByRegionId() inside the row loop turns a 500-row list into
        // hundreds of round trips to both the region and local tables.
        $localizedNames = $this->localNames->namesByRegionIds($regionIds);

        $result = [];
        foreach ($regions as $region) {
            if (!$region instanceof Region || !$region->getId()) {
                continue;
            }

            $countryCode = (string)$region->getData(Region::schema_fields_COUNTRY_CODE);
            $regionCode = (string)$region->getData(Region::schema_fields_REGION_CODE);
            $regionType = (string)$region->getData(Region::schema_fields_REGION_TYPE);
            $defaultName = (string)$region->getData(Region::schema_fields_REGION_NAME);
            $regionId = (int)$region->getId();
            $displayName = trim((string)($localizedNames[$regionId] ?? ''));
            if ($displayName === '') {
                $displayName = $this->localizedRegionName(
                    $countryCode,
                    $regionCode,
                    $defaultName,
                    $regionType,
                );
            }
            $result[] = [
                'region_id' => $regionId,
                'parent_region_id' => (int)$region->getData(Region::schema_fields_PARENT_REGION_ID),
                'country_code' => $countryCode,
                'region_code' => $regionCode,
                'region_name' => $displayName,
                'region_default_name' => $defaultName,
                'region_locale' => $this->currentLocale(),
                'region_type' => $regionType,
                'postal_code_pattern' => (string)$region->getData(Region::schema_fields_POSTAL_CODE_PATTERN),
                'postal_code' => (string)$region->getData(Region::schema_fields_POSTAL_CODE),
                'is_active' => (int)$region->getData(Region::schema_fields_IS_ACTIVE),
                'sort_order' => (int)$region->getData(Region::schema_fields_SORT_ORDER),
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichPostalLookupNames(array $rows): array
    {
        $ids = [];
        $placeIds = [];
        foreach ($rows as $row) {
            foreach (['province_region_id', 'city_region_id', 'district_region_id'] as $key) {
                $id = (int)($row[$key] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
            $placeId = (int)($row['postal_place_id'] ?? 0);
            if ($placeId > 0) {
                $placeIds[$placeId] = true;
            }
        }
        $names = $this->regionDisplayNamesByIds(array_keys($ids));
        $placeNames = $this->localNames->namesByPostalPlaceIds(array_keys($placeIds));
        foreach ($rows as &$row) {
            $provinceId = (int)($row['province_region_id'] ?? 0);
            $cityId = (int)($row['city_region_id'] ?? 0);
            $districtId = (int)($row['district_region_id'] ?? 0);
            $placeId = (int)($row['postal_place_id'] ?? 0);
            $placeName = trim((string)($placeNames[$placeId] ?? $row['place_name'] ?? ''));
            if ($placeName !== '') {
                $row['place_name'] = $placeName;
            }
            $attachLevel = trim((string)($row['attach_level'] ?? ''));
            $provinceName = (string)($names[$provinceId] ?? '');
            $cityName = (string)($names[$cityId] ?? '');
            $districtName = (string)($names[$districtId] ?? '');
            if ($attachLevel === 'province' && $placeName !== '') {
                $provinceName = $placeName;
            }
            if ($attachLevel === 'city' && $placeName !== '') {
                if ($cityName === '' || $this->isOpaqueRegionLabel($cityName, (string)($row['city_code'] ?? ''))) {
                    $cityName = $placeName;
                }
            }
            if ($attachLevel === 'district' && $placeName !== '') {
                if ($districtName === '' || $this->isOpaqueRegionLabel($districtName, (string)($row['district_code'] ?? ''))) {
                    $districtName = $placeName;
                }
            }
            if ($cityName === '' && $attachLevel === 'city') {
                $cityName = $placeName;
            }
            if ($districtName === '' && $attachLevel === 'district') {
                $districtName = $placeName;
            }
            $row['province_name'] = $provinceName;
            $row['city_name'] = $cityName;
            $row['district_name'] = $districtName;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int|string> $ids
     * @return array<int, string>
     */
    private function regionDisplayNamesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($id): int => (int)$id, $ids))));
        if ($ids === []) {
            return [];
        }
        $localMap = $this->localNames->namesByRegionIds($ids);
        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $items = $model->reset()
            ->where(Region::schema_fields_ID, $ids, 'IN')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $region) {
            if (!$region instanceof Region || !$region->getId()) {
                continue;
            }
            $regionId = (int)$region->getId();
            $countryCode = (string)$region->getData(Region::schema_fields_COUNTRY_CODE);
            $regionCode = (string)$region->getData(Region::schema_fields_REGION_CODE);
            $regionType = (string)$region->getData(Region::schema_fields_REGION_TYPE);
            $defaultName = (string)$region->getData(Region::schema_fields_REGION_NAME);
            $label = trim((string)($localMap[$regionId] ?? ''));
            if ($label === '') {
                $label = $this->localizedRegionName($countryCode, $regionCode, $defaultName, $regionType, $regionId);
            }
            if ($this->isOpaqueRegionLabel($label, $regionCode)) {
                $label = $defaultName !== '' && !$this->isOpaqueRegionLabel($defaultName, $regionCode)
                    ? $defaultName
                    : $label;
            }
            $out[$regionId] = $label;
        }

        return $out;
    }

    private function isOpaqueRegionLabel(string $name, string $code = ''): bool
    {
        $name = trim($name);
        $code = trim($code);
        if ($name === '') {
            return true;
        }
        if ($code !== '' && strcasecmp($name, $code) === 0) {
            return true;
        }

        return (bool)preg_match('/^[A-Z]{2}-C\d+$/i', $name);
    }

    private function getInstalledCountriesAsRegions(): array
    {
        $result = [];
        foreach ($this->getCountries() as $row) {
            $code = strtoupper(trim((string)($row['country_code'] ?? $row['region_code'] ?? '')));
            if ($code === '' || !preg_match('/^[A-Z]{2}$/', $code)) {
                continue;
            }
            $defaultName = trim((string)($row['region_default_name'] ?? $row['region_name'] ?? $code));
            $displayName = trim((string)($row['region_name'] ?? $defaultName));
            $result[] = [
                'region_id' => (int)($row['region_id'] ?? 0),
                'parent_region_id' => 0,
                'country_code' => $code,
                'region_code' => $code,
                'region_name' => $displayName !== '' ? $displayName : $this->localizedCountryName($code, $code),
                'region_default_name' => $defaultName !== '' ? $defaultName : $code,
                'region_locale' => $this->currentLocale(),
                'region_type' => Region::TYPE_COUNTRY,
                'postal_code_pattern' => (string)($row['postal_code_pattern'] ?? ''),
                'postal_code' => '',
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
                'postal_code' => '',
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
                'postal_code' => '',
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

    private function localizedRegionName(
        string $countryCode,
        string $regionCode,
        string $defaultName,
        string $regionType,
        int $regionId = 0,
    ): string {
        if ($regionId > 0) {
            $fromLocal = $this->localNames->nameByRegionId($regionId);
            if ($fromLocal !== '') {
                return $fromLocal;
            }
        }

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
     * 验证地区是否存在
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
