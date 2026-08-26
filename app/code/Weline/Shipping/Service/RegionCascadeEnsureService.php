<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Region;

/**
 * 按需把模块内地区数据包写入 w_shipping_regions。
 * 权威数据在表；无包国家保持可手填，不伪造级联。
 */
final class RegionCascadeEnsureService
{
    private const PACK_DIR = __DIR__ . '/../data/regions';

    /** @var array<string, bool> */
    private static array $ensured = [];

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    public function hasPack(string $countryCode): bool
    {
        $path = $this->packPath($countryCode);
        return $path !== null && is_file($path);
    }

    /**
     * @return array{imported:int,skipped:bool,reason:string,country_code:string}
     */
    public function ensureCountry(string $countryCode): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'invalid_country', 'country_code' => $countryCode];
        }

        if (isset(self::$ensured[$countryCode])) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'already_ensured_in_process', 'country_code' => $countryCode];
        }

        $pack = $this->loadPack($countryCode);
        if ($pack === null) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'no_pack', 'country_code' => $countryCode];
        }

        if ($this->hasProvinceRows($countryCode)) {
            self::$ensured[$countryCode] = true;
            $this->syncPostalCodesFromPack($countryCode, $pack);

            return ['imported' => 0, 'skipped' => true, 'reason' => 'already_present', 'country_code' => $countryCode];
        }

        $lockPath = $this->lockPath($countryCode);
        $lockFp = @fopen($lockPath, 'c+');
        if ($lockFp === false) {
            return ['imported' => 0, 'skipped' => true, 'reason' => 'lock_failed', 'country_code' => $countryCode];
        }

        try {
            if (!flock($lockFp, LOCK_EX)) {
                return ['imported' => 0, 'skipped' => true, 'reason' => 'lock_failed', 'country_code' => $countryCode];
            }

            if ($this->hasProvinceRows($countryCode)) {
                self::$ensured[$countryCode] = true;
                $this->syncPostalCodesFromPack($countryCode, $pack);

                return ['imported' => 0, 'skipped' => true, 'reason' => 'already_present', 'country_code' => $countryCode];
            }

            $imported = $this->importPack($countryCode, $pack);
            self::$ensured[$countryCode] = true;

            return ['imported' => $imported, 'skipped' => false, 'reason' => 'imported', 'country_code' => $countryCode];
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    public function packPath(string $countryCode): ?string
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return null;
        }
        $path = self::PACK_DIR . '/' . $countryCode . '.json';

        return is_file($path) ? $path : null;
    }

    /**
     * @return array{country_code?:string,country_name?:string,regions?:list<array<string,mixed>>}|null
     */
    public function loadPack(string $countryCode): ?array
    {
        $path = $this->packPath($countryCode);
        if ($path === null) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['regions']) || !is_array($decoded['regions'])) {
            return null;
        }

        return $decoded;
    }

    private function hasProvinceRows(string $countryCode): bool
    {
        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $found = $model->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_PROVINCE)
            ->where(Region::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        return (int)$found->getId() > 0;
    }

    /**
     * @param array{country_name?:string,regions?:list<array<string,mixed>>} $pack
     */
    private function importPack(string $countryCode, array $pack): int
    {
        $imported = 0;
        $countryName = trim((string)($pack['country_name'] ?? $countryCode)) ?: $countryCode;
        $countryId = $this->ensureCountryRow($countryCode, $countryName);
        if ($countryId > 0) {
            $imported++;
        }

        foreach ($pack['regions'] as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $imported += $this->importNode($countryCode, $node, $countryId > 0 ? $countryId : null, (int)$index);
        }

        return $imported;
    }

    private function ensureCountryRow(string $countryCode, string $countryName): int
    {
        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $existing = $model->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(Region::schema_fields_REGION_TYPE, Region::TYPE_COUNTRY)
            ->find()
            ->fetch();
        if ((int)$existing->getId() > 0) {
            return (int)$existing->getId();
        }

        $model->reset()->clearData()->setData([
            Region::schema_fields_COUNTRY_CODE => $countryCode,
            Region::schema_fields_PARENT_REGION_ID => null,
            Region::schema_fields_REGION_CODE => $countryCode,
            Region::schema_fields_REGION_NAME => $countryName,
            Region::schema_fields_REGION_TYPE => Region::TYPE_COUNTRY,
            Region::schema_fields_IS_ACTIVE => 1,
            Region::schema_fields_SORT_ORDER => 0,
        ])->save();

        return (int)$model->getId();
    }

    /**
     * @param array<string,mixed> $node
     */
    private function importNode(string $countryCode, array $node, ?int $parentId, int $sortOrder): int
    {
        $code = strtoupper(trim((string)($node['code'] ?? '')));
        $name = trim((string)($node['name'] ?? ''));
        $type = strtolower(trim((string)($node['type'] ?? Region::TYPE_PROVINCE)));
        if ($code === '' || $name === '') {
            return 0;
        }
        if (!in_array($type, [Region::TYPE_PROVINCE, Region::TYPE_CITY, Region::TYPE_DISTRICT], true)) {
            $type = Region::TYPE_PROVINCE;
        }
        $postalCode = trim((string)($node['postal_code'] ?? ''));

        /** @var Region $model */
        $model = $this->objectManager->getInstance(Region::class);
        $existing = $model->reset()
            ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(Region::schema_fields_REGION_CODE, $code)
            ->find()
            ->fetch();

        $regionId = (int)$existing->getId();
        $imported = 0;
        if ($regionId <= 0) {
            $model->reset()->clearData()->setData([
                Region::schema_fields_COUNTRY_CODE => $countryCode,
                Region::schema_fields_PARENT_REGION_ID => $parentId,
                Region::schema_fields_REGION_CODE => $code,
                Region::schema_fields_REGION_NAME => $name,
                Region::schema_fields_REGION_TYPE => $type,
                Region::schema_fields_POSTAL_CODE => $postalCode !== '' ? $postalCode : null,
                Region::schema_fields_IS_ACTIVE => 1,
                Region::schema_fields_SORT_ORDER => max(0, $sortOrder),
            ])->save();
            $regionId = (int)$model->getId();
            $imported = 1;
        } elseif ($postalCode !== '') {
            $currentPostal = trim((string)$existing->getData(Region::schema_fields_POSTAL_CODE));
            if ($currentPostal !== $postalCode) {
                $existing->setData(Region::schema_fields_POSTAL_CODE, $postalCode)->save();
            }
        }

        $children = $node['children'] ?? [];
        if (!is_array($children) || $regionId <= 0) {
            return $imported;
        }

        foreach ($children as $childIndex => $child) {
            if (!is_array($child)) {
                continue;
            }
            $imported += $this->importNode($countryCode, $child, $regionId, (int)$childIndex);
        }

        return $imported;
    }

    /**
     * @param array{regions?:list<array<string,mixed>>} $pack
     */
    private function syncPostalCodesFromPack(string $countryCode, array $pack): void
    {
        foreach ($pack['regions'] ?? [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $this->syncNodePostal($countryCode, $node);
        }
    }

    /**
     * @param array<string,mixed> $node
     */
    private function syncNodePostal(string $countryCode, array $node): void
    {
        $postalCode = trim((string)($node['postal_code'] ?? ''));
        $code = strtoupper(trim((string)($node['code'] ?? '')));
        if ($postalCode !== '' && $code !== '') {
            /** @var Region $model */
            $model = $this->objectManager->getInstance(Region::class);
            $existing = $model->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->where(Region::schema_fields_REGION_CODE, $code)
                ->find()
                ->fetch();
            if ((int)$existing->getId() > 0) {
                $currentPostal = trim((string)$existing->getData(Region::schema_fields_POSTAL_CODE));
                if ($currentPostal !== $postalCode) {
                    $existing->setData(Region::schema_fields_POSTAL_CODE, $postalCode)->save();
                }
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->syncNodePostal($countryCode, $child);
            }
        }
    }

    private function lockPath(string $countryCode): string
    {
        $dir = (defined('BP') ? BP : dirname(__DIR__, 5)) . '/var/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/shipping-region-ensure-' . $countryCode . '.lock';
    }
}
