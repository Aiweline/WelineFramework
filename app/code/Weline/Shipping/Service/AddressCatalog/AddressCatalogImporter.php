<?php
declare(strict_types=1);

namespace Weline\Shipping\Service\AddressCatalog;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Model\PostalPlace;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\Street;
use Weline\Shipping\Service\RegionLocalSeedService;

/**
 * 只灌 address-catalog tsv.gz，不建表。
 */
final class AddressCatalogImporter
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @return array{wiped:bool,imported:array<string,int>,catalog_mode:bool,warnings:list<string>}
     */
    public function import(bool $wipe = false, ?string $onlyCountry = null, ?string $onlyLayer = null): array
    {
        $warnings = [];
        if ($wipe) {
            $warnings[] = 'wipe clears address region ids, streets and postal places';
            $this->wipe();
        }

        $imported = [
            'countries' => 0,
            'provinces' => 0,
            'cities' => 0,
            'districts' => 0,
            'postal' => 0,
            'streets' => 0,
            'postal_skipped' => 0,
            'streets_skipped' => 0,
        ];

        $layers = $onlyLayer !== null ? [$onlyLayer] : ['countries', 'provinces', 'cities', 'districts', 'postal', 'streets'];
        $countryFilter = $onlyCountry !== null ? strtoupper(trim($onlyCountry)) : null;

        if (in_array('countries', $layers, true) && ($countryFilter === null || $countryFilter === '')) {
            $imported['countries'] = $this->importCountries();
        }

        $countries = $this->discoverCountryCodes($countryFilter);
        foreach ($countries as $cc) {
            // Province/city parents need the country node even when --layer skips countries.
            if ($this->ensureCountryNode($cc)) {
                $imported['countries']++;
            }
            if (in_array('provinces', $layers, true)) {
                $imported['provinces'] += $this->importRegionLayer($cc, 'provinces', Region::TYPE_PROVINCE);
            }
            if (in_array('cities', $layers, true)) {
                $imported['cities'] += $this->importRegionLayer($cc, 'cities', Region::TYPE_CITY);
            }
            if (in_array('districts', $layers, true)) {
                $imported['districts'] += $this->importRegionLayer($cc, 'districts', Region::TYPE_DISTRICT);
            }
            if (in_array('postal', $layers, true)) {
                $r = $this->importPostal($cc);
                $imported['postal'] += $r['imported'];
                $imported['postal_skipped'] += $r['skipped'];
            }
            if (in_array('streets', $layers, true)) {
                $r = $this->importStreets($cc);
                $imported['streets'] += $r['imported'];
                $imported['streets_skipped'] += $r['skipped'];
            }
        }

        AddressCatalogMode::enable('catalog_import');
        $this->writeLayersReady($imported);

        try {
            /** @var RegionLocalSeedService $seeder */
            $seeder = $this->objectManager->getInstance(RegionLocalSeedService::class);
            $seeder->seedAll();
        } catch (\Throwable) {
            // Local tables may not exist until setup:upgrade.
        }

        return [
            'wiped' => $wipe,
            'imported' => $imported,
            'catalog_mode' => true,
            'warnings' => $warnings,
        ];
    }

    public function wipe(): void
    {
        foreach ([ShippingAddress::class, DeliveryAddress::class] as $class) {
            /** @var ShippingAddress|DeliveryAddress $addr */
            $addr = $this->objectManager->getInstance($class);
            $table = $class::schema_table;
            $addr->reset()->query(
                'UPDATE ' . $table
                . ' SET province_region_id=NULL, city_region_id=NULL, district_region_id=NULL, street_id=NULL'
            )->fetch();
        }

        /** @var Street $street */
        $street = $this->objectManager->getInstance(Street::class);
        $street->reset()->query('DELETE FROM ' . Street::schema_table)->fetch();

        /** @var PostalPlace $postal */
        $postal = $this->objectManager->getInstance(PostalPlace::class);
        $postal->reset()->query('DELETE FROM ' . PostalPlace::schema_table)->fetch();

        /** @var Region $region */
        $region = $this->objectManager->getInstance(Region::class);
        $region->reset()->query('DELETE FROM ' . Region::schema_table)->fetch();
    }

    private function importCountries(): int
    {
        $path = AddressCatalogPaths::countriesPath();
        if (!is_file($path)) {
            return 0;
        }
        $count = 0;
        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        foreach (TsvGzReader::rows($path) as $row) {
            $code = strtoupper(trim((string)($row['country_code'] ?? $row['region_code'] ?? '')));
            $name = trim((string)($row['region_name'] ?? $row['name'] ?? ''));
            if ($code === '' || $name === '' || !preg_match('/^[A-Z]{2}$/', $code)) {
                continue;
            }
            $this->upsertRegion($model, [
                Region::schema_fields_COUNTRY_CODE => $code,
                Region::schema_fields_PARENT_REGION_ID => null,
                Region::schema_fields_REGION_CODE => $code,
                Region::schema_fields_REGION_NAME => $name,
                Region::schema_fields_REGION_TYPE => Region::TYPE_COUNTRY,
                Region::schema_fields_IS_ACTIVE => 1,
                Region::schema_fields_SORT_ORDER => (int)($row['sort_order'] ?? 0),
                Region::schema_fields_POSTAL_CODE_PATTERN => trim((string)($row['postal_code_pattern'] ?? '')) ?: null,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Ensure TYPE_COUNTRY row exists for $countryCode (from countries.tsv.gz or code fallback).
     * @return bool true when a new country row was inserted
     */
    private function ensureCountryNode(string $countryCode): bool
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return false;
        }
        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        if ($this->findRegionId($model, $countryCode, $countryCode) !== null) {
            return false;
        }

        $name = $countryCode;
        $sort = 0;
        $pattern = null;
        $path = AddressCatalogPaths::countriesPath();
        if (is_file($path)) {
            foreach (TsvGzReader::rows($path) as $row) {
                $code = strtoupper(trim((string)($row['country_code'] ?? $row['region_code'] ?? '')));
                if ($code !== $countryCode) {
                    continue;
                }
                $label = trim((string)($row['region_name'] ?? $row['name'] ?? ''));
                if ($label !== '') {
                    $name = $label;
                }
                $sort = (int)($row['sort_order'] ?? 0);
                $pattern = trim((string)($row['postal_code_pattern'] ?? '')) ?: null;
                break;
            }
        }

        $this->upsertRegion($model, [
            Region::schema_fields_COUNTRY_CODE => $countryCode,
            Region::schema_fields_PARENT_REGION_ID => null,
            Region::schema_fields_REGION_CODE => $countryCode,
            Region::schema_fields_REGION_NAME => $name,
            Region::schema_fields_REGION_TYPE => Region::TYPE_COUNTRY,
            Region::schema_fields_IS_ACTIVE => 1,
            Region::schema_fields_SORT_ORDER => $sort,
            Region::schema_fields_POSTAL_CODE_PATTERN => $pattern,
        ]);

        return true;
    }

    private function importRegionLayer(string $countryCode, string $layer, string $type): int
    {
        $path = AddressCatalogPaths::layerFile($countryCode, $layer);
        if (!is_file($path)) {
            return 0;
        }

        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $table = Region::schema_table;
        $codeMap = $this->loadRegionCodeMap($countryCode);
        if (!isset($codeMap[$countryCode])) {
            $countryId = $this->findRegionId($model, $countryCode, $countryCode);
            if ($countryId !== null) {
                $codeMap[$countryCode] = $countryId;
            }
        }

        $link = $model->getConnection()->getConnector()->getLink();
        $count = 0;
        $batch = [];
        $flush = function () use (&$batch, &$count, &$codeMap, $link, $table): void {
            if ($batch === []) {
                return;
            }
            $sql = 'INSERT INTO ' . $table . ' ('
                . implode(',', [
                    Region::schema_fields_COUNTRY_CODE,
                    Region::schema_fields_PARENT_REGION_ID,
                    Region::schema_fields_REGION_CODE,
                    Region::schema_fields_REGION_NAME,
                    Region::schema_fields_REGION_TYPE,
                    Region::schema_fields_IS_ACTIVE,
                    Region::schema_fields_SORT_ORDER,
                    Region::schema_fields_POSTAL_CODE,
                ])
                . ') VALUES ' . implode(',', $batch);
            $affected = $link->exec($sql);
            if ($affected === false) {
                throw new \RuntimeException('region batch insert failed: ' . $table);
            }
            $count += count($batch);
            $batch = [];
        };

        foreach (TsvGzReader::rows($path) as $row) {
            $code = trim((string)($row['region_code'] ?? ''));
            $name = trim((string)($row['region_name'] ?? ''));
            $parentCode = trim((string)($row['parent_code'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            if (isset($codeMap[$code])) {
                // Already present: keep existing row (fill-missing path). Name/parent drift is rare.
                continue;
            }

            if ($parentCode === '') {
                $parentCode = $countryCode;
            }
            $parentId = $codeMap[$parentCode] ?? null;
            if ($parentId === null) {
                continue;
            }

            $sort = (int)($row['sort_order'] ?? 0);
            $postal = trim((string)($row['postal_code'] ?? ''));
            $postalSql = $postal === '' ? 'NULL' : $this->quote($postal);
            $batch[] = '('
                . $this->quote($countryCode) . ','
                . (int)$parentId . ','
                . $this->quote($code) . ','
                . $this->quote($name) . ','
                . $this->quote($type) . ','
                . '1,'
                . $sort . ','
                . $postalSql
                . ')';

            // Reserve slot so same-layer duplicates / later parents in file resolve.
            $codeMap[$code] = -1;

            if (count($batch) >= 200) {
                $flush();
                // Refresh ids for rows just inserted (needed if later rows parent to same-layer codes).
                $codeMap = $this->loadRegionCodeMap($countryCode) + $codeMap;
            }
        }
        $flush();

        return $count;
    }

    /**
     * @return array{imported:int,skipped:int}
     */
    private function importPostal(string $countryCode): array
    {
        $path = AddressCatalogPaths::layerFile($countryCode, 'postal');
        if (!is_file($path)) {
            return ['imported' => 0, 'skipped' => 0];
        }

        /** @var PostalPlace $postal */
        $postal = $this->objectManager->getInstance(PostalPlace::class);
        $table = PostalPlace::schema_table;
        // 按国替换：勿用 query(DELETE)->fetch（模型路径会按 SELECT 收集结果，>10k 行触发 Unbounded）
        $postal->reset()->where(PostalPlace::schema_fields_COUNTRY_CODE, $countryCode)->delete();

        $codeToId = $this->loadRegionCodeMap($countryCode);
        $imported = 0;
        $skipped = 0;
        $batch = [];
        $link = $postal->getConnection()->getConnector()->getLink();
        $flush = function () use (&$batch, &$imported, $link, $table): void {
            if ($batch === []) {
                return;
            }
            $sql = 'INSERT INTO ' . $table . ' ('
                . implode(',', [
                    PostalPlace::schema_fields_COUNTRY_CODE,
                    PostalPlace::schema_fields_POSTAL_CODE,
                    PostalPlace::schema_fields_POSTAL_CODE_NORM,
                    PostalPlace::schema_fields_PLACE_NAME,
                    PostalPlace::schema_fields_ATTACH_LEVEL,
                    PostalPlace::schema_fields_PROVINCE_CODE,
                    PostalPlace::schema_fields_CITY_CODE,
                    PostalPlace::schema_fields_DISTRICT_CODE,
                    PostalPlace::schema_fields_ATTACH_REGION_ID,
                    PostalPlace::schema_fields_PROVINCE_REGION_ID,
                    PostalPlace::schema_fields_CITY_REGION_ID,
                    PostalPlace::schema_fields_DISTRICT_REGION_ID,
                ])
                . ') VALUES ' . implode(',', $batch);
            $affected = $link->exec($sql);
            if ($affected === false) {
                throw new \RuntimeException('postal batch insert failed: ' . $table);
            }
            $imported += count($batch);
            $batch = [];
        };

        foreach (TsvGzReader::rows($path) as $row) {
            $raw = trim((string)($row['postal_code'] ?? ''));
            $norm = TsvGzReader::normalizePostal($raw !== '' ? $raw : (string)($row['postal_code_norm'] ?? ''));
            if ($norm === '') {
                $skipped++;
                continue;
            }
            $provinceCode = trim((string)($row['province_code'] ?? ''));
            $cityCode = trim((string)($row['city_code'] ?? ''));
            $districtCode = trim((string)($row['district_code'] ?? ''));
            $attachLevel = 'province';
            $attachCode = $provinceCode;
            if ($districtCode !== '') {
                $attachLevel = 'district';
                $attachCode = $districtCode;
            } elseif ($cityCode !== '') {
                $attachLevel = 'city';
                $attachCode = $cityCode;
            }
            $provinceId = $provinceCode !== '' ? ($codeToId[$provinceCode] ?? null) : null;
            $cityId = $cityCode !== '' ? ($codeToId[$cityCode] ?? null) : null;
            $districtId = $districtCode !== '' ? ($codeToId[$districtCode] ?? null) : null;
            $attachId = $attachCode !== '' ? ($codeToId[$attachCode] ?? null) : null;
            if ($attachId === null && $provinceId === null && $cityId === null && $districtId === null) {
                $skipped++;
            }
            $placeName = trim((string)($row['place_name'] ?? $row['region_name'] ?? ''));
            if ($placeName === '') {
                $placeName = $raw !== '' ? $raw : $norm;
            }
            $batch[] = '(' . implode(',', [
                $this->quote($countryCode),
                $this->quote($raw !== '' ? $raw : $norm),
                $this->quote($norm),
                $this->quote(mb_substr($placeName, 0, 255)),
                $this->quote($attachLevel),
                $provinceCode !== '' ? $this->quote($provinceCode) : 'NULL',
                $cityCode !== '' ? $this->quote($cityCode) : 'NULL',
                $districtCode !== '' ? $this->quote($districtCode) : 'NULL',
                $attachId !== null ? (string)(int)$attachId : 'NULL',
                $provinceId !== null ? (string)(int)$provinceId : 'NULL',
                $cityId !== null ? (string)(int)$cityId : 'NULL',
                $districtId !== null ? (string)(int)$districtId : 'NULL',
            ]) . ')';
            if (count($batch) >= 500) {
                $flush();
            }
        }
        $flush();

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return array<string, int> region_code => region_id
     */
    private function loadRegionCodeMap(string $countryCode): array
    {
        /** @var Region $region */
        $region = $this->objectManager->getInstance(Region::class);
        $table = Region::schema_table;
        $sql = 'SELECT ' . Region::schema_fields_ID . ',' . Region::schema_fields_REGION_CODE
            . ' FROM ' . $table
            . ' WHERE ' . Region::schema_fields_COUNTRY_CODE . '=' . $this->quote($countryCode);
        $map = [];
        $query = $region->reset()->query($sql);
        if (method_exists($query, 'fetchIterator')) {
            foreach ($query->fetchIterator() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row[Region::schema_fields_REGION_CODE] ?? $row['region_code'] ?? ''));
                $id = (int)($row[Region::schema_fields_ID] ?? $row['region_id'] ?? 0);
                if ($code !== '' && $id > 0) {
                    $map[$code] = $id;
                }
            }

            return $map;
        }
        // fallback: paged SELECT
        $offset = 0;
        $page = 5000;
        while (true) {
            $pageSql = $sql . ' ORDER BY ' . Region::schema_fields_ID . ' LIMIT ' . $page . ' OFFSET ' . $offset;
            $rows = $region->reset()->query($pageSql)->fetchArray();
            if (!is_array($rows) || $rows === []) {
                break;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row[Region::schema_fields_REGION_CODE] ?? $row['region_code'] ?? ''));
                $id = (int)($row[Region::schema_fields_ID] ?? $row['region_id'] ?? 0);
                if ($code !== '' && $id > 0) {
                    $map[$code] = $id;
                }
            }
            if (count($rows) < $page) {
                break;
            }
            $offset += $page;
        }

        return $map;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @return array{imported:int,skipped:int}
     */
    private function importStreets(string $countryCode): array
    {
        $path = AddressCatalogPaths::layerFile($countryCode, 'streets');
        if (!is_file($path)) {
            return ['imported' => 0, 'skipped' => 0];
        }
        $imported = 0;
        $skipped = 0;
        /** @var Region $region */
        $region = $this->objectManager->getInstance(Region::class);
        /** @var Street $street */
        $street = $this->objectManager->getInstance(Street::class);
        foreach (TsvGzReader::rows($path) as $row) {
            $code = trim((string)($row['street_code'] ?? ''));
            $name = trim((string)($row['street_name'] ?? ''));
            $parentCode = trim((string)($row['parent_code'] ?? ''));
            if ($code === '' || $name === '' || $parentCode === '') {
                $skipped++;
                continue;
            }
            $parentId = $this->findRegionId($region, $countryCode, $parentCode);
            if ($parentId === null) {
                $skipped++;
                continue;
            }
            $existing = $street->reset()
                ->where(Street::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Street::schema_fields_STREET_CODE, $code)
                ->find()
                ->fetch();
            $data = [
                Street::schema_fields_COUNTRY_CODE => $countryCode,
                Street::schema_fields_PARENT_REGION_ID => $parentId,
                Street::schema_fields_STREET_CODE => $code,
                Street::schema_fields_STREET_NAME => $name,
                Street::schema_fields_POSTAL_CODE => trim((string)($row['postal_code'] ?? '')) ?: null,
                Street::schema_fields_IS_ACTIVE => 1,
                Street::schema_fields_SORT_ORDER => (int)($row['sort_order'] ?? 0),
            ];
            if ($existing->getId()) {
                $existing->setData($data)->save();
            } else {
                $street->reset()->setData($data)->save();
            }
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function upsertRegion(Region $model, array $data): void
    {
        $existing = $model->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $data[Region::schema_fields_COUNTRY_CODE])
            ->where(Region::schema_fields_REGION_CODE, $data[Region::schema_fields_REGION_CODE])
            ->find()
            ->fetch();
        if ($existing->getId()) {
            $existing->setData($data)->save();
        } else {
            $model->reset()->setData($data)->save();
        }
    }

    private function findRegionId(Region $model, string $countryCode, string $regionCode): ?int
    {
        $row = $model->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(Region::schema_fields_REGION_CODE, $regionCode)
            ->find()
            ->fetch();
        $id = (int)$row->getId();

        return $id > 0 ? $id : null;
    }

    /**
     * @return list<string>
     */
    private function discoverCountryCodes(?string $only): array
    {
        if ($only !== null && $only !== '') {
            $codes = [];
            foreach (explode(',', $only) as $part) {
                $cc = strtoupper(trim($part));
                if (preg_match('/^[A-Z]{2}$/', $cc)) {
                    $codes[] = $cc;
                }
            }

            return array_values(array_unique($codes));
        }
        $root = AddressCatalogPaths::catalogRoot();
        if (!is_dir($root)) {
            return [];
        }
        $codes = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'MANIFEST.json' || str_contains($entry, '.')) {
                continue;
            }
            if (is_dir($root . '/' . $entry) && preg_match('/^[A-Z]{2}$/', $entry)) {
                $codes[] = $entry;
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * @param array<string, int> $imported
     */
    private function writeLayersReady(array $imported): void
    {
        $manifestPath = AddressCatalogPaths::manifestPath();
        $manifest = is_file($manifestPath)
            ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
            : [];
        $manifest['layers_ready'] = [
            'cascade' => ($imported['countries'] > 0 || AddressCatalogMode::isEnabled()) && ($imported['provinces'] > 0 || $imported['cities'] > 0 || is_dir(AddressCatalogPaths::catalogRoot())),
            'postal_lookup' => $imported['postal'] > 0 || $this->anyPostalFile(),
            'street_select' => $imported['streets'] > 0 || $this->anyStreetFile(),
        ];
        $manifest['imported_at'] = date('c');
        $dir = dirname($manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    private function anyPostalFile(): bool
    {
        foreach ($this->discoverCountryCodes(null) as $cc) {
            if (is_file(AddressCatalogPaths::layerFile($cc, 'postal'))) {
                return true;
            }
        }

        return false;
    }

    private function anyStreetFile(): bool
    {
        foreach ($this->discoverCountryCodes(null) as $cc) {
            if (is_file(AddressCatalogPaths::layerFile($cc, 'streets'))) {
                return true;
            }
        }

        return false;
    }
}
