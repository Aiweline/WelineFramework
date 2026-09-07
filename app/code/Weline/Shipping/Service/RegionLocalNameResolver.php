<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\PostalPlace;
use Weline\Shipping\Model\PostalPlace\LocalDescription as PostalPlaceLocalDescription;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\Region\LocalDescription as RegionLocalDescription;
use Weline\Shipping\Model\Street;
use Weline\Shipping\Model\Street\LocalDescription as StreetLocalDescription;

/**
 * 按当前 locale 解析 Region / Street / PostalPlace 展示名（LocalDescription → 主表回退）。
 */
class RegionLocalNameResolver
{
    /** @var array<string, array<int, string>> */
    private array $regionCache = [];

    /** @var array<string, array<int, string>> */
    private array $streetCache = [];

    /** @var array<string, array<int, string>> */
    private array $postalPlaceCache = [];

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    public function currentLocale(): string
    {
        $locale = trim((string)Cookie::getLangLocal());

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * @param list<int|string> $regionIds
     * @return array<int, string>
     */
    public function namesByRegionIds(array $regionIds, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int)$id, $regionIds))));
        if ($ids === []) {
            return [];
        }

        $missing = [];
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->regionCache[$locale][$id])) {
                $out[$id] = $this->regionCache[$locale][$id];
            } else {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return $out;
        }

        $defaults = $this->loadRegionDefaults($missing);
        $locals = $this->loadRegionLocals($missing, $locale);
        foreach ($missing as $id) {
            $name = trim((string)($locals[$id] ?? ''));
            if ($name === '') {
                $name = trim((string)($defaults[$id] ?? ''));
            }
            $this->regionCache[$locale][$id] = $name;
            $out[$id] = $name;
        }

        return $out;
    }

    public function nameByRegionId(int $regionId, ?string $locale = null): string
    {
        if ($regionId <= 0) {
            return '';
        }

        return (string)($this->namesByRegionIds([$regionId], $locale)[$regionId] ?? '');
    }

    /**
     * @param list<int|string> $streetIds
     * @return array<int, string>
     */
    public function namesByStreetIds(array $streetIds, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int)$id, $streetIds))));
        if ($ids === []) {
            return [];
        }

        $missing = [];
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->streetCache[$locale][$id])) {
                $out[$id] = $this->streetCache[$locale][$id];
            } else {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return $out;
        }

        $defaults = $this->loadStreetDefaults($missing);
        $locals = $this->loadStreetLocals($missing, $locale);
        foreach ($missing as $id) {
            $name = trim((string)($locals[$id] ?? ''));
            if ($name === '') {
                $name = trim((string)($defaults[$id] ?? ''));
            }
            $this->streetCache[$locale][$id] = $name;
            $out[$id] = $name;
        }

        return $out;
    }

    public function nameByStreetId(int $streetId, ?string $locale = null): string
    {
        if ($streetId <= 0) {
            return '';
        }

        return (string)($this->namesByStreetIds([$streetId], $locale)[$streetId] ?? '');
    }

    /**
     * @param list<int|string> $postalPlaceIds
     * @return array<int, string>
     */
    public function namesByPostalPlaceIds(array $postalPlaceIds, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int)$id, $postalPlaceIds))));
        if ($ids === []) {
            return [];
        }

        $missing = [];
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->postalPlaceCache[$locale][$id])) {
                $out[$id] = $this->postalPlaceCache[$locale][$id];
            } else {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return $out;
        }

        $defaults = $this->loadPostalPlaceDefaults($missing);
        $locals = $this->loadPostalPlaceLocals($missing, $locale);
        foreach ($missing as $id) {
            $name = trim((string)($locals[$id] ?? ''));
            if ($name === '') {
                $name = trim((string)($defaults[$id] ?? ''));
            }
            $this->postalPlaceCache[$locale][$id] = $name;
            $out[$id] = $name;
        }

        return $out;
    }

    public function nameByPostalPlaceId(int $postalPlaceId, ?string $locale = null): string
    {
        if ($postalPlaceId <= 0) {
            return '';
        }

        return (string)($this->namesByPostalPlaceIds([$postalPlaceId], $locale)[$postalPlaceId] ?? '');
    }

    /**
     * 将地址快照中的国家/省/市/区/街字段替换为当前语言名称。
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function localizeAddressFields(array $address, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $countryCode = strtoupper(substr((string)preg_replace('/[^A-Za-z]/', '', (string)($address['country_code'] ?? '')), 0, 2));

        $provinceId = (int)($address['province_region_id'] ?? $address['province_id'] ?? 0);
        $cityId = (int)($address['city_region_id'] ?? $address['city_id'] ?? 0);
        $districtId = (int)($address['district_region_id'] ?? $address['district_id'] ?? 0);
        $streetId = (int)($address['street_id'] ?? 0);

        if ($provinceId <= 0) {
            $provinceId = $this->findRegionId(
                $countryCode,
                (string)($address['province_code'] ?? ''),
                (string)($address['province'] ?? ''),
                Region::TYPE_PROVINCE,
            );
        }
        if ($cityId <= 0) {
            $cityId = $this->findRegionId(
                $countryCode,
                (string)($address['city_code'] ?? ''),
                (string)($address['city'] ?? ''),
                Region::TYPE_CITY,
            );
        }
        if ($districtId <= 0) {
            $districtId = $this->findRegionId(
                $countryCode,
                (string)($address['district_code'] ?? ''),
                (string)($address['district'] ?? ''),
                Region::TYPE_DISTRICT,
            );
        }

        $names = $this->namesByRegionIds(
            array_values(array_filter([$provinceId, $cityId, $districtId])),
            $locale,
        );
        if ($provinceId > 0 && ($names[$provinceId] ?? '') !== '') {
            $address['province'] = $names[$provinceId];
            $address['province_region_id'] = $provinceId;
        }
        if ($cityId > 0 && ($names[$cityId] ?? '') !== '') {
            $address['city'] = $names[$cityId];
            $address['city_region_id'] = $cityId;
        }
        if ($districtId > 0 && ($names[$districtId] ?? '') !== '') {
            $address['district'] = $names[$districtId];
            $address['district_region_id'] = $districtId;
        }

        if ($streetId > 0) {
            $streetName = $this->nameByStreetId($streetId, $locale);
            if ($streetName !== '') {
                $address['street'] = $streetName;
                $address['street_id'] = $streetId;
            }
        }

        if ($countryCode !== '') {
            $countryId = $this->findRegionId($countryCode, $countryCode, (string)($address['country'] ?? ''), Region::TYPE_COUNTRY);
            if ($countryId > 0) {
                $countryName = $this->nameByRegionId($countryId, $locale);
                if ($countryName !== '') {
                    $address['country'] = $countryName;
                }
            }
        }

        return $address;
    }

    private function normalizeLocale(?string $locale): string
    {
        $locale = trim((string)$locale);
        if ($locale === '') {
            $locale = $this->currentLocale();
        }

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadRegionDefaults(array $ids): array
    {
        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $items = $model->reset()
            ->where(Region::schema_fields_ID, $ids, 'IN')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof Region || !(int)$item->getId()) {
                continue;
            }
            $out[(int)$item->getId()] = trim((string)$item->getData(Region::schema_fields_REGION_NAME));
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadRegionLocals(array $ids, string $locale): array
    {
        try {
            /** @var RegionLocalDescription $model */
            $model = $this->objectManager->getInstance(RegionLocalDescription::class);
            $items = $model->reset()
                ->where(RegionLocalDescription::schema_fields_ID, $ids, 'IN')
                ->where(RegionLocalDescription::schema_fields_local_code, $locale)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof RegionLocalDescription) {
                continue;
            }
            $id = (int)$item->getData(RegionLocalDescription::schema_fields_ID);
            $name = trim((string)$item->getData(RegionLocalDescription::schema_fields_REGION_NAME));
            if ($id > 0 && $name !== '') {
                $out[$id] = $name;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadStreetDefaults(array $ids): array
    {
        /** @var Street $model */
        $model = $this->objectManager->getInstance(Street::class);
        $items = $model->reset()
            ->where(Street::schema_fields_ID, $ids, 'IN')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof Street || !(int)$item->getId()) {
                continue;
            }
            $out[(int)$item->getId()] = trim((string)$item->getData(Street::schema_fields_STREET_NAME));
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadStreetLocals(array $ids, string $locale): array
    {
        try {
            /** @var StreetLocalDescription $model */
            $model = $this->objectManager->getInstance(StreetLocalDescription::class);
            $items = $model->reset()
                ->where(StreetLocalDescription::schema_fields_ID, $ids, 'IN')
                ->where(StreetLocalDescription::schema_fields_local_code, $locale)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof StreetLocalDescription) {
                continue;
            }
            $id = (int)$item->getData(StreetLocalDescription::schema_fields_ID);
            $name = trim((string)$item->getData(StreetLocalDescription::schema_fields_STREET_NAME));
            if ($id > 0 && $name !== '') {
                $out[$id] = $name;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadPostalPlaceDefaults(array $ids): array
    {
        /** @var PostalPlace $model */
        $model = $this->objectManager->getInstance(PostalPlace::class);
        $items = $model->reset()
            ->where(PostalPlace::schema_fields_ID, $ids, 'IN')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof PostalPlace || !(int)$item->getId()) {
                continue;
            }
            $out[(int)$item->getId()] = trim((string)$item->getData(PostalPlace::schema_fields_PLACE_NAME));
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadPostalPlaceLocals(array $ids, string $locale): array
    {
        try {
            /** @var PostalPlaceLocalDescription $model */
            $model = $this->objectManager->getInstance(PostalPlaceLocalDescription::class);
            $items = $model->reset()
                ->where(PostalPlaceLocalDescription::schema_fields_ID, $ids, 'IN')
                ->where(PostalPlaceLocalDescription::schema_fields_local_code, $locale)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof PostalPlaceLocalDescription) {
                continue;
            }
            $id = (int)$item->getData(PostalPlaceLocalDescription::schema_fields_ID);
            $name = trim((string)$item->getData(PostalPlaceLocalDescription::schema_fields_PLACE_NAME));
            if ($id > 0 && $name !== '') {
                $out[$id] = $name;
            }
        }

        return $out;
    }

    private function findRegionId(string $countryCode, string $regionCode, string $regionName, string $regionType): int
    {
        if ($countryCode === '') {
            return 0;
        }

        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $regionCode = trim($regionCode);
        if ($regionCode !== '') {
            $row = $model->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_CODE, $regionCode)
                ->where(Region::schema_fields_REGION_TYPE, $regionType)
                ->find()
                ->fetch();
            if ($row instanceof Region && (int)$row->getId() > 0) {
                return (int)$row->getId();
            }
        }

        $regionName = trim($regionName);
        if ($regionName === '') {
            return 0;
        }

        $row = $model->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(Region::schema_fields_REGION_NAME, $regionName)
            ->where(Region::schema_fields_REGION_TYPE, $regionType)
            ->find()
            ->fetch();
        if ($row instanceof Region && (int)$row->getId() > 0) {
            return (int)$row->getId();
        }

        return 0;
    }
}
